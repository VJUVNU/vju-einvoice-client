<?php

namespace Vju\EinvoiceClient\Events;

use Vju\EinvoiceClient\Data\Document;

/**
 * Nhà cung cấp từ chối hoặc hết lượt thử. Sửa nội dung rồi gửi lại cùng key (createDocument) — event này
 * sẽ bắn lại nếu lần sau cũng hỏng.
 */
final class DocumentFailed extends DocumentEvent
{
    public readonly ?string $error;

    public function __construct(Document $document, string $source, ?string $eventId = null)
    {
        parent::__construct($document, $source, $eventId);
        $this->error = $document->error;
    }

    public static function status(): string
    {
        return 'failed';
    }
}
