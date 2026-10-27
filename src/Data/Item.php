<?php

namespace Vju\EinvoiceClient\Data;

use Vju\EinvoiceClient\Support\Cast;

/**
 * Một dòng hoá đơn. Bất biến: mỗi hàm dựng trả bản mới.
 *
 *     Item::make('Học phí học kỳ 1 năm học 2026-2027', unitPrice: 1200000, vatRate: VatRate::KCT)->unit('Khoản')
 *
 * vatRate bắt buộc, không có mặc định: app tự quyết dòng nào chịu thuế (hợp đồng), gói không đoán hộ.
 *
 * $amount chỉ có ở dòng đọc về từ dịch vụ (= quantity × unit_price, làm tròn tới đồng); khi gửi lên thì
 * dịch vụ tự tính, gói không gửi trường này.
 */
final class Item
{
    public function __construct(
        public readonly string $name,
        public readonly int|float $quantity,
        public readonly int $unitPrice,
        public readonly string $vatRate,
        public readonly ?string $unit = null,
        public readonly ?int $amount = null,
    ) {}

    public static function make(
        string $name,
        int $unitPrice,
        string|int $vatRate,
        int|float $quantity = 1,
        ?string $unit = null,
    ): self {
        return new self($name, $quantity, $unitPrice, (string) $vatRate, $unit);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $quantity = $data['quantity'] ?? 1;

        return new self(
            name: (string) Cast::str($data['name'] ?? ''),
            quantity: is_int($quantity) || is_float($quantity) ? $quantity : (is_numeric($quantity) ? $quantity + 0 : 1),
            unitPrice: Cast::int($data['unit_price'] ?? null) ?? 0,
            vatRate: (string) Cast::str($data['vat_rate'] ?? null),
            unit: Cast::str($data['unit'] ?? null),
            amount: Cast::int($data['amount'] ?? null),
        );
    }

    public function name(string $name): self
    {
        return $this->with(['name' => $name]);
    }

    public function quantity(int|float $quantity): self
    {
        return $this->with(['quantity' => $quantity]);
    }

    public function unitPrice(int $unitPrice): self
    {
        return $this->with(['unitPrice' => $unitPrice]);
    }

    public function vatRate(string|int $vatRate): self
    {
        return $this->with(['vatRate' => (string) $vatRate]);
    }

    public function unit(?string $unit): self
    {
        return $this->with(['unit' => $unit]);
    }

    /**
     * quantity × unit_price làm tròn tới đồng — đúng cách dịch vụ tính.
     */
    public function lineAmount(): int
    {
        return $this->amount ?? (int) round($this->quantity * $this->unitPrice);
    }

    public function vatAmount(): int
    {
        return (int) round($this->lineAmount() * VatRate::percent($this->vatRate) / 100);
    }

    /**
     * Thân gửi lên (không có amount — dịch vụ tự tính).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'vat_rate' => $this->vatRate,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
