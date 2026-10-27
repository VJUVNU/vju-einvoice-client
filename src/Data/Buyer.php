<?php

namespace Vju\EinvoiceClient\Data;

use Vju\EinvoiceClient\Support\Cast;

/**
 * Người mua. Bất biến: mỗi hàm dựng trả bản mới.
 *
 *     Buyer::make('Nguyễn Văn A')->code('22110001')->idNumber('001204000001')->email('a@vju.ac.vn')
 *
 * Tổ chức (xuất hoá đơn cho công ty): legalName + taxCode.
 */
final class Buyer
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $code = null,
        public readonly ?string $legalName = null,
        public readonly ?string $taxCode = null,
        public readonly ?string $idNumber = null,
        public readonly ?string $address = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
    ) {}

    public static function make(
        string $name,
        ?string $code = null,
        ?string $legalName = null,
        ?string $taxCode = null,
        ?string $idNumber = null,
        ?string $address = null,
        ?string $email = null,
        ?string $phone = null,
    ): self {
        return new self($name, $code, $legalName, $taxCode, $idNumber, $address, $email, $phone);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) Cast::str($data['name'] ?? ''),
            code: Cast::str($data['code'] ?? null),
            legalName: Cast::str($data['legal_name'] ?? null),
            taxCode: Cast::str($data['tax_code'] ?? null),
            idNumber: Cast::str($data['id_number'] ?? null),
            address: Cast::str($data['address'] ?? null),
            email: Cast::str($data['email'] ?? null),
            phone: Cast::str($data['phone'] ?? null),
        );
    }

    public function name(string $name): self
    {
        return $this->with(['name' => $name]);
    }

    public function code(?string $code): self
    {
        return $this->with(['code' => $code]);
    }

    public function legalName(?string $legalName): self
    {
        return $this->with(['legalName' => $legalName]);
    }

    public function taxCode(?string $taxCode): self
    {
        return $this->with(['taxCode' => $taxCode]);
    }

    public function idNumber(?string $idNumber): self
    {
        return $this->with(['idNumber' => $idNumber]);
    }

    public function address(?string $address): self
    {
        return $this->with(['address' => $address]);
    }

    public function email(?string $email): self
    {
        return $this->with(['email' => $email]);
    }

    public function phone(?string $phone): self
    {
        return $this->with(['phone' => $phone]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'tax_code' => $this->taxCode,
            'id_number' => $this->idNumber,
            'address' => $this->address,
            'email' => $this->email,
            'phone' => $this->phone,
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
