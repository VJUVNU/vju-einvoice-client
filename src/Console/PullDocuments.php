<?php

namespace Vju\EinvoiceClient\Console;

use Illuminate\Console\Command;
use Throwable;
use Vju\EinvoiceClient\Contracts\EinvoiceService;
use Vju\EinvoiceClient\Exceptions\EinvoiceException;
use Vju\EinvoiceClient\Support\CursorStore;
use Vju\EinvoiceClient\Support\Inbox;

/**
 * Đường dự phòng của webhook (hợp đồng §3, GET /documents): kéo nguồn thay đổi từ con trỏ đã lưu, bắn
 * event theo trạng thái HIỆN TẠI của từng chứng từ, tiến con trỏ theo change_id. Dùng chung bảng chống
 * trùng với webhook nên hai đường không bắn trùng.
 *
 * Nguồn thay đổi chỉ cho trạng thái mới nhất: webhook rơi cả drafted lẫn issued thì lần kéo chỉ bắn
 * DocumentIssued. queued không có event.
 */
class PullDocuments extends Command
{
    protected $signature = 'einvoice:pull
        {--limit= : Số chứng từ mỗi trang (1–500, mặc định theo cấu hình)}
        {--max-pages= : Số trang tối đa cho một lần chạy}
        {--cursor= : Đặt lại con trỏ về change_id này trước khi kéo (kể cả lùi)}';

    protected $description = 'Kéo thay đổi chứng từ từ Dịch vụ hoá đơn điện tử (dự phòng cho webhook)';

    public function handle(EinvoiceService $service, Inbox $inbox, CursorStore $cursors): int
    {
        if ($this->option('cursor') !== null) {
            $cursors->put((int) $this->option('cursor'));
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : (int) config('einvoice.pull.limit', 100);
        $maxPages = max(1, $this->option('max-pages') !== null ? (int) $this->option('max-pages') : (int) config('einvoice.pull.max_pages', 20));

        $cursor = $cursors->get();
        $fired = 0;
        $skipped = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $result = $service->changes($cursor, $limit);
            } catch (EinvoiceException $e) {
                $this->error("Không kéo được /documents (con trỏ {$cursor}): ".$e->getMessage());

                return self::FAILURE;
            }

            foreach ($result->data as $document) {
                try {
                    $outcome = $inbox->receive($document, (string) $document->status, 'pull');
                } catch (Throwable $e) {
                    report($e);
                    $cursors->advance($cursor);
                    $this->error("Xử lý chứng từ {$document->key} (change {$document->changeId}) thất bại: ".$e->getMessage());
                    $this->line("Đã bắn {$fired}, bỏ qua {$skipped}. Con trỏ dừng ở {$cursor}.");

                    return self::FAILURE;
                }

                $outcome === true ? $fired++ : $skipped++;
                $cursor = max($cursor, (int) $document->changeId);
            }

            $cursor = max($cursor, $result->nextCursor);
            $cursors->advance($cursor);

            if (! $result->hasMore || $result->data === []) {
                break;
            }
        }

        $this->info("Đã bắn {$fired} event, bỏ qua {$skipped} (trùng hoặc chưa có event). Con trỏ: {$cursor}.");

        return self::SUCCESS;
    }
}
