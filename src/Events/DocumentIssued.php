<?php

namespace Vju\EinvoiceClient\Events;

use Vju\EinvoiceClient\Data\Document;

/**
 * Kiểm soát viên đã ký — hoá đơn có số. Đây là lúc ghi số hoá đơn vào chứng từ thu của app.
 */
final class DocumentIssued extends DocumentEvent
{
    public readonly ?string $number;

    public readonly ?string $serial;

    public readonly ?string $pattern;

    public readonly ?string $viewUrl;

    public function __construct(Document $document, string $source, ?string $eventId = null)
    {
        parent::__construct($document, $source, $eventId);
        $this->number = $document->number;
        $this->serial = $document->serial;
        $this->pattern = $document->pattern;
        $this->viewUrl = $document->viewUrl;
    }

    public static function status(): string
    {
        return 'issued';
    }
}
