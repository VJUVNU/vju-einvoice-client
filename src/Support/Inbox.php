<?php

namespace Vju\EinvoiceClient\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Vju\EinvoiceClient\Data\Document;
use Vju\EinvoiceClient\Events\DocumentCancelled;
use Vju\EinvoiceClient\Events\DocumentDrafted;
use Vju\EinvoiceClient\Events\DocumentEvent;
use Vju\EinvoiceClient\Events\DocumentFailed;
use Vju\EinvoiceClient\Events\DocumentIssued;

/**
 * Cửa duy nhất để thay đổi của dịch vụ thành event Laravel — webhook, einvoice:pull và fake đều đi qua
 * đây, nên không đường nào bắn trùng đường nào.
 *
 * Khoá chống trùng là {key}:{status} (hợp đồng §4). Ghi dòng chống trùng (chỉ mục duy nhất, trùng thì bỏ
 * qua im lặng) và dispatch event trong CÙNG một transaction: listener đồng bộ ném lỗi → dòng mất → lần
 * giao sau bắn lại.
 *
 * Khi app gửi lại chứng từ (createDocument cùng key) thì vòng đời bắt đầu lại: forget() xoá các dòng
 * drafted/failed/cancelled của key đó để lần nháp/lỗi mới vẫn bắn event. issued không bao giờ bị xoá.
 *
 * @internal
 */
class Inbox
{
    public const TABLE = 'einvoice_client_events';

    /** @var array<string, class-string<DocumentEvent>> */
    public const EVENTS = [
        Document::STATUS_DRAFTED => DocumentDrafted::class,
        Document::STATUS_ISSUED => DocumentIssued::class,
        Document::STATUS_FAILED => DocumentFailed::class,
        Document::STATUS_CANCELLED => DocumentCancelled::class,
    ];

    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection = null,
        protected ?Dispatcher $events = null,
    ) {}

    /**
     * @param  string  $status  trạng thái mà lần giao này báo (theo tên event webhook, hoặc document.status khi kéo)
     * @return bool|null true đã bắn, false trùng, null nếu trạng thái không có event (queued, lạ)
     */
    public function receive(Document $document, string $status, string $source, ?string $eventId = null): ?bool
    {
        $class = self::EVENTS[$status] ?? null;

        if ($class === null) {
            return null;
        }

        $connection = $this->connection();
        $now = now();
        $row = [
            'dedupe_key' => self::dedupeKey($document->key, $status),
            'document_key' => $document->key,
            'status' => $status,
            'event_id' => $eventId,
            'change_id' => $document->changeId,
            'source' => $source,
            'payload' => json_encode($document->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $connection->transaction(function () use ($connection, $row, $class, $document, $source, $eventId): bool {
            if ($connection->table(self::TABLE)->insertOrIgnore($row) === 0) {
                return false;
            }

            $this->events()->dispatch(new $class($document, $source, $eventId));

            return true;
        });
    }

    /**
     * App gửi lại chứng từ: cho drafted/failed/cancelled của key này bắn lại được.
     */
    public function forget(string $key): void
    {
        $this->connection()->table(self::TABLE)
            ->where('document_key', $key)
            ->where('status', '!=', Document::STATUS_ISSUED)
            ->delete();
    }

    public function has(string $key, string $status): bool
    {
        return $this->connection()->table(self::TABLE)->where('dedupe_key', self::dedupeKey($key, $status))->exists();
    }

    public static function dedupeKey(string $key, string $status): string
    {
        return $key.':'.$status;
    }

    protected function events(): Dispatcher
    {
        return $this->events ?? app('events');
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
