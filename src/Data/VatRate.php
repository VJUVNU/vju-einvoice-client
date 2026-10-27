<?php

namespace Vju\EinvoiceClient\Data;

/**
 * Giá trị vat_rate của hợp đồng. App tự quyết dòng nào chịu thuế — dịch vụ không đoán.
 */
final class VatRate
{
    /** Không chịu thuế (học phí…). */
    public const KCT = 'KCT';

    /** Không kê khai, tính nộp thuế. */
    public const KKKNT = 'KKKNT';

    public const ZERO = '0';

    public const FIVE = '5';

    public const EIGHT = '8';

    public const TEN = '10';

    public const ALL = [self::KCT, self::KKKNT, self::ZERO, self::FIVE, self::EIGHT, self::TEN];

    /**
     * Phần trăm thuế của một mức; KCT/KKKNT là 0.
     */
    public static function percent(string $rate): int
    {
        return is_numeric($rate) ? (int) $rate : 0;
    }
}
