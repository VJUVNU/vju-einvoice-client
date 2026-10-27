<?php

namespace Vju\EinvoiceClient\Data;

use Carbon\CarbonImmutable;
use Vju\EinvoiceClient\Exceptions\InvalidPayloadException;
use Vju\EinvoiceClient\Support\Cast;

/**
 * Đối tượng Document của hợp đồng §3.
 *
 * Vòng đời: queued → drafted (nháp ở nhà cung cấp, chờ kiểm soát viên ký tay) → issued (có $number).
 * failed khi nhà cung cấp từ chối ($error có lý do); cancelled khi app huỷ trước lúc ký.
 */
final class Document
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_DRAFTED = 'drafted';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param  list<Item>  $items
     * @param  array<string, mixed>  $reference
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $type,
        public readonly ?string $issuer,
        public readonly ?string $status,
        public readonly ?string $provider,
        public readonly ?string $providerKey,
        public readonly ?string $pattern,
        public readonly ?string $serial,
        public readonly ?string $number,
        public readonly ?CarbonImmutable $issuedOn,
        public readonly ?Buyer $buyer,
        public readonly ?string $paymentMethod,
        public readonly string $currency,
        public readonly array $items,
        public readonly ?int $subtotal,
        public readonly ?int $vatAmount,
        public readonly ?int $total,
        public readonly ?string $totalInWords,
        public readonly array $reference,
        public readonly ?string $viewUrl,
        public readonly ?string $error,
        public readonly array $metadata,
        public readonly ?int $changeId,
        public readonly ?CarbonImmutable $createdAt,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = Cast::str($data['key'] ?? null);

        if ($key === null || $key === '') {
            throw new InvalidPayloadException('Document thiếu trường "key".');
        }

        $items = [];

        foreach (Cast::map($data['items'] ?? null) as $row) {
            if (is_array($row)) {
                $items[] = Item::fromArray($row);
            }
        }

        return new self(
            key: $key,
            type: Cast::str($data['type'] ?? null),
            issuer: Cast::str($data['issuer'] ?? null),
            status: Cast::str($data['status'] ?? null),
            provider: Cast::str($data['provider'] ?? null),
            providerKey: Cast::str($data['provider_key'] ?? null),
            pattern: Cast::str($data['pattern'] ?? null),
            serial: Cast::str($data['serial'] ?? null),
            // Số hoá đơn giữ dạng chuỗi: có thể có số 0 đứng đầu.
            number: Cast::str($data['number'] ?? null),
            issuedOn: Cast::date($data['issued_on'] ?? null),
            buyer: is_array($data['buyer'] ?? null) ? Buyer::fromArray($data['buyer']) : null,
            paymentMethod: Cast::str($data['payment_method'] ?? null),
            currency: Cast::str($data['currency'] ?? null) ?? 'VND',
            items: $items,
            subtotal: Cast::int($data['subtotal'] ?? null),
            vatAmount: Cast::int($data['vat_amount'] ?? null),
            total: Cast::int($data['total'] ?? null),
            totalInWords: Cast::str($data['total_in_words'] ?? null),
            reference: Cast::map($data['reference'] ?? null),
            viewUrl: Cast::str($data['view_url'] ?? null),
            error: self::errorText($data['error'] ?? null),
            metadata: Cast::map($data['metadata'] ?? null),
            changeId: Cast::int($data['change_id'] ?? null),
            createdAt: Cast::date($data['created_at'] ?? null),
            updatedAt: Cast::date($data['updated_at'] ?? null),
            raw: $data,
        );
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isDrafted(): bool
    {
        return $this->status === self::STATUS_DRAFTED;
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Hợp đồng chỉ nói "error có lý do" — nhận cả chuỗi lẫn đối tượng {message, …}.
     */
    private static function errorText(mixed $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        if (is_array($error)) {
            $message = $error['message'] ?? null;

            return is_string($message) && $message !== '' ? $message : (string) json_encode($error, JSON_UNESCAPED_UNICODE);
        }

        return is_scalar($error) ? (string) $error : null;
    }
}
