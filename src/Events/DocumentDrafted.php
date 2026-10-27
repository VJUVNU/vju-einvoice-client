<?php

namespace Vju\EinvoiceClient\Events;

use Vju\EinvoiceClient\Data\Document;

/**
 * Đã lập nháp ở nhà cung cấp, chờ kiểm soát viên ký tay trên portal VNPT. $viewUrl có thể đã có.
 */
final class DocumentDrafted extends DocumentEvent
{
    public readonly ?string $viewUrl;

    public function __construct(Document $document, string $source, ?string $eventId = null)
    {
        parent::__construct($document, $source, $eventId);
        $this->viewUrl = $document->viewUrl;
    }

    public static function status(): string
    {
        return 'drafted';
    }
}
