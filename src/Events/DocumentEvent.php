<?php

namespace Vju\EinvoiceClient\Events;

use Vju\EinvoiceClient\Data\Document;

/**
 * Gốc của bốn event chứng từ. Bắn đúng MỘT lần cho mỗi cặp (key, trạng thái) — hợp đồng: app idempotent
 * theo data.key + data.status — dù tới bằng webhook, einvoice:pull hay cả hai.
 *
 * Listener đồng bộ chạy BÊN TRONG transaction ghi dòng chống trùng: ném lỗi thì dòng đó bị huỷ, webhook
 * trả 500 để dịch vụ gửi lại và lần kéo sau cũng lấy lại được.
 */
abstract class DocumentEvent
{
    public readonly string $key;

    /** Trạng thái event báo (= static::status()); trạng thái lúc dịch vụ gửi nằm ở $document->status. */
    public readonly string $status;

    /**
     * @param  string  $source  webhook | pull | fake
     */
    public function __construct(
        public readonly Document $document,
        public readonly string $source,
        public readonly ?string $eventId = null,
    ) {
        $this->key = $document->key;
        $this->status = static::status();
    }

    /**
     * Trạng thái mà event này báo (drafted/issued/failed/cancelled).
     */
    abstract public static function status(): string;
}
