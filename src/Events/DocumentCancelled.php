<?php

namespace Vju\EinvoiceClient\Events;

/**
 * Chứng từ đã huỷ trước lúc ký (app gọi cancelDocument).
 */
final class DocumentCancelled extends DocumentEvent
{
    public static function status(): string
    {
        return 'cancelled';
    }
}
