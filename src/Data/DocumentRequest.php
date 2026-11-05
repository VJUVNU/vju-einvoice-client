<?php

namespace Vju\EinvoiceClient\Data;

use DateTimeInterface;
use InvalidArgumentException;
use Vju\EinvoiceClient\Support\Cast;

/**
 * Thân của POST /documents (hợp đồng §3). Bất biến: mỗi hàm dựng trả bản mới.
 *
 *     DocumentRequest::make('HD-HP1234', issuer: 'vju-hocphi')
 *         ->buyer(Buyer::make('Nguyễn Văn A')->code('22110001')->email('a@vju.ac.vn'))
 *         ->item(Item::make('Học phí học kỳ 1', unitPrice: 1200000, vatRate: VatRate::KCT)->unit('Khoản'))
 *         ->reference(['payment_ref' => 'HP1234', 'payment_id' => 5012])
 *         ->sendEmail();
 *
 * App gửi ĐỦ nội dung (đã lọc khoản thu hộ, tên khoản chính thức, thuế suất) — dịch vụ không tự quyết.
 * Kiểm sơ bộ khi gửi (validate()) để lỗi lập trình hiện ngay chỗ gọi.
 */
final class DocumentRequest
{
    public const KEY_PATTERN = '/^[A-Za-z0-9._-]{1,40}$/';

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_RECEIPT = 'receipt';

    public const PAYMENT_METHODS = ['TM', 'CK', 'TM/CK'];

    /**
     * @param  list<Item>  $items
     * @param  array<string, mixed>|null  $reference
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $issuer = null,
        public readonly ?Buyer $buyer = null,
        public readonly array $items = [],
        public readonly ?string $type = null,
        public readonly ?string $issuedOn = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?array $reference = null,
        public readonly ?bool $sendEmail = null,
        public readonly ?array $metadata = null,
        public readonly ?string $providerKey = null,
    ) {}

    /**
     * @param  iterable<Item>  $items
     * @param  array<string, mixed>|null  $reference
     * @param  array<string, mixed>|null  $metadata
     */
    public static function make(
        string $key,
        ?string $issuer = null,
        ?Buyer $buyer = null,
        iterable $items = [],
        ?string $type = null,
        DateTimeInterface|string|null $issuedOn = null,
        ?string $paymentMethod = null,
        ?array $reference = null,
        ?bool $sendEmail = null,
        ?array $metadata = null,
        ?string $providerKey = null,
    ): self {
        return new self(
            key: $key,
            issuer: $issuer,
            buyer: $buyer,
            items: self::itemList($items),
            type: $type,
            issuedOn: Cast::dateOnly($issuedOn),
            paymentMethod: $paymentMethod,
            reference: $reference,
            sendEmail: $sendEmail,
            metadata: $metadata,
            providerKey: $providerKey,
        );
    }

    public function issuer(string $issuer): self
    {
        return $this->with(['issuer' => $issuer]);
    }

    public function buyer(Buyer $buyer): self
    {
        return $this->with(['buyer' => $buyer]);
    }

    public function item(Item $item): self
    {
        return $this->with(['items' => [...$this->items, $item]]);
    }

    /**
     * Thay toàn bộ danh sách dòng.
     *
     * @param  iterable<Item>  $items
     */
    public function items(iterable $items): self
    {
        return $this->with(['items' => self::itemList($items)]);
    }

    public function type(string $type): self
    {
        return $this->with(['type' => $type]);
    }

    public function receipt(): self
    {
        return $this->type(self::TYPE_RECEIPT);
    }

    public function issuedOn(DateTimeInterface|string $date): self
    {
        return $this->with(['issuedOn' => Cast::dateOnly($date)]);
    }

    /**
     * TM · CK · TM/CK (mặc định phía dịch vụ: CK).
     */
    public function paymentMethod(string $method): self
    {
        return $this->with(['paymentMethod' => $method]);
    }

    /**
     * @param  array<string, mixed>  $reference  vd. ['payment_ref' => 'HP1234', 'payment_id' => 5012]
     */
    public function reference(array $reference): self
    {
        return $this->with(['reference' => $reference]);
    }

    public function sendEmail(bool $send = true): self
    {
        return $this->with(['sendEmail' => $send]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        return $this->with(['metadata' => $metadata]);
    }

    /**
     * Giữ khoá chống trùng đã dùng ở nhà cung cấp (fkey VNPT) khi chuyển từ hệ thống cũ — chỉ được
     * khi app bật "Cho app tự đặt provider_key" trên dịch vụ.
     */
    public function providerKey(string $providerKey): self
    {
        return $this->with(['providerKey' => $providerKey]);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        if (preg_match(self::KEY_PATTERN, $this->key) !== 1) {
            throw new InvalidArgumentException("key \"{$this->key}\" phải gồm 1–40 ký tự [A-Za-z0-9._-].");
        }

        if ($this->issuer === null || $this->issuer === '') {
            throw new InvalidArgumentException('Thiếu issuer (đơn vị phát hành). Truyền issuer hoặc đặt EINVOICE_ISSUER.');
        }

        if ($this->buyer === null || trim($this->buyer->name) === '') {
            throw new InvalidArgumentException('Thiếu buyer.name.');
        }

        if ($this->items === []) {
            throw new InvalidArgumentException('Chứng từ cần ít nhất một dòng.');
        }

        foreach ($this->items as $i => $item) {
            $n = $i + 1;

            if (trim($item->name) === '') {
                throw new InvalidArgumentException("Dòng {$n}: thiếu name.");
            }

            if ($item->quantity <= 0) {
                throw new InvalidArgumentException("Dòng {$n}: quantity phải lớn hơn 0.");
            }

            if ($item->unitPrice < 0) {
                throw new InvalidArgumentException("Dòng {$n}: unit_price không được âm.");
            }

            if ($item->vatRate === '') {
                throw new InvalidArgumentException("Dòng {$n}: thiếu vat_rate.");
            }
        }

        if ($this->paymentMethod !== null && ! in_array($this->paymentMethod, self::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException("payment_method \"{$this->paymentMethod}\" phải là TM, CK hoặc TM/CK.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type,
            'issuer' => $this->issuer,
            'provider_key' => $this->providerKey,
            'issued_on' => $this->issuedOn,
            'buyer' => $this->buyer?->toArray(),
            'payment_method' => $this->paymentMethod,
            'items' => array_map(fn (Item $i) => $i->toArray(), $this->items),
            'reference' => $this->reference,
            'send_email' => $this->sendEmail,
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null);
    }

    /**
     * Dấu vân tay của phần nội dung (bỏ key, send_email, metadata) — bản giả dùng để biết gửi lại
     * chứng từ đã phát hành có khác nội dung không.
     *
     * @internal
     */
    public function contentFingerprint(): string
    {
        $data = $this->toArray();
        unset($data['key'], $data['send_email'], $data['metadata']);

        return hash('sha256', (string) json_encode($data));
    }

    /**
     * @param  iterable<Item>  $items
     * @return list<Item>
     */
    private static function itemList(iterable $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! $item instanceof Item) {
                throw new InvalidArgumentException('items chỉ nhận Item (dùng Item::make()).');
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
