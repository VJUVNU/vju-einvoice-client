<?php

namespace Vju\EinvoiceClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vju\EinvoiceClient\Data\Document;
use Vju\EinvoiceClient\Exceptions\InvalidPayloadException;
use Vju\EinvoiceClient\Support\Inbox;

/**
 * Nhận webhook đã qua VerifyWebhookSignature.
 *
 * Trạng thái lấy theo TÊN event (document.issued → issued), không theo data.status: lần gửi lại muộn
 * của document.drafted vẫn là "drafted" dù lúc gửi chứng từ đã sang trạng thái khác.
 *
 * 200 = đã xử lý / trùng / event lạ (bỏ qua để dịch vụ không gửi lại mãi). 422 = chữ ký đúng nhưng thân
 * hỏng. 500 = listener ném lỗi; dòng chống trùng đã rollback nên lần gửi lại (hoặc einvoice:pull) bắn lại.
 */
class WebhookController
{
    public function __invoke(Request $request, Inbox $inbox): JsonResponse
    {
        $envelope = json_decode((string) $request->getContent(), true);

        if (! is_array($envelope) || ! is_string($envelope['event'] ?? null) || ! is_array($envelope['data'] ?? null)) {
            return VerifyWebhookSignature::error('invalid_payload', 'Thân webhook phải có "event" và "data".', 422);
        }

        $event = $envelope['event'];
        $eventId = is_scalar($envelope['id'] ?? null) ? (string) $envelope['id'] : null;
        $status = str_starts_with($event, 'document.') ? substr($event, strlen('document.')) : '';

        if (! array_key_exists($status, Inbox::EVENTS)) {
            Log::info('vju/einvoice-client: bỏ qua webhook có event lạ', ['event' => $event, 'id' => $eventId]);

            return new JsonResponse(['status' => 'ignored']);
        }

        try {
            $fired = $inbox->receive(Document::fromArray($envelope['data']), $status, 'webhook', $eventId);
        } catch (InvalidPayloadException $e) {
            return VerifyWebhookSignature::error('invalid_payload', $e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return VerifyWebhookSignature::error('listener_failed', 'Xử lý sự kiện thất bại; dịch vụ sẽ gửi lại.', 500);
        }

        return new JsonResponse(['status' => $fired ? 'processed' : 'duplicate']);
    }
}
