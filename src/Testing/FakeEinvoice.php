<?php

namespace Vju\EinvoiceClient\Testing;

use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;
use Vju\EinvoiceClient\Contracts\EinvoiceService;
use Vju\EinvoiceClient\Data\Document;
use Vju\EinvoiceClient\Data\DocumentPage;
use Vju\EinvoiceClient\Data\DocumentRequest;
use Vju\EinvoiceClient\Data\Item;
use Vju\EinvoiceClient\Exceptions\ConflictException;
use Vju\EinvoiceClient\Exceptions\DocumentLockedException;
use Vju\EinvoiceClient\Exceptions\NotFoundException;
use Vju\EinvoiceClient\Support\Inbox;

/**
 * Dịch vụ hoá đơn điện tử giả trong bộ nhớ: Einvoice::fake().
 *
 * Làm theo luật hợp đồng: createDocument mới → queued; gửi lại khi queued/drafted/failed → queued lại;
 * gửi lại chứng từ đã issued với nội dung khác → DocumentLockedException; huỷ chứng từ đã issued → cũng
 * vậy. Các simulate*() đổi trạng thái và bắn event qua đúng đường chống trùng của webhook (cần migration
 * của gói trong CSDL kiểm thử). cancelDocument() KHÔNG tự bắn DocumentCancelled — dịch vụ thật báo qua
 * webhook sau đó; gọi simulateCancelled() nếu cần.
 */
class FakeEinvoice implements EinvoiceService
{
    /** @var array<string, array<string, mixed>> */
    protected array $documents = [];

    /** @var array<string, string> */
    protected array $fingerprints = [];

    /** @var list<DocumentRequest> */
    protected array $created = [];

    /** @var list<string> */
    protected array $cancelled = [];

    /** @var list<Throwable> */
    protected array $failures = [];

    protected int $nextChangeId = 1;

    protected int $nextEventId = 1;

    public function __construct(
        protected Inbox $inbox,
        protected ?string $defaultIssuer = null,
    ) {}

    // ---- EinvoiceService -----------------------------------------------------------------------

    public function createDocument(DocumentRequest $request): Document
    {
        $this->throwIfFailing();

        if ($request->issuer === null && $this->defaultIssuer) {
            $request = $request->issuer($this->defaultIssuer);
        }

        $request->validate();
        $this->created[] = $request;

        $existing = $this->documents[$request->key] ?? null;

        if ($existing !== null && $existing['status'] === Document::STATUS_ISSUED) {
            if ($this->fingerprints[$request->key] !== $request->contentFingerprint()) {
                throw new DocumentLockedException("Chứng từ {$request->key} đã phát hành.", 409, 'document_locked');
            }

            return Document::fromArray($existing);
        }

        $this->fingerprints[$request->key] = $request->contentFingerprint();
        $this->documents[$request->key] = $this->build($request, $existing);
        $this->inbox->forget($request->key);

        return Document::fromArray($this->documents[$request->key]);
    }

    public function upsert(DocumentRequest $request): Document
    {
        return $this->createDocument($request);
    }

    public function getDocument(string $key, bool $refresh = false): Document
    {
        $this->throwIfFailing();

        return Document::fromArray($this->find($key));
    }

    public function cancelDocument(string $key): Document
    {
        $this->throwIfFailing();

        $document = $this->find($key);

        if ($document['status'] === Document::STATUS_ISSUED) {
            throw new DocumentLockedException("Chứng từ {$key} đã phát hành.", 409, 'document_locked');
        }

        $this->cancelled[] = $key;
        $this->change($key, ['status' => Document::STATUS_CANCELLED]);

        return Document::fromArray($this->documents[$key]);
    }

    public function changes(int $cursor = 0, ?int $limit = null): DocumentPage
    {
        $this->throwIfFailing();

        $limit = max(1, min(500, $limit ?? 100));
        $after = array_values(array_filter($this->documents, fn (array $d) => $d['change_id'] > $cursor));
        usort($after, fn (array $a, array $b) => $a['change_id'] <=> $b['change_id']);
        $page = array_slice($after, 0, $limit);

        return new DocumentPage(
            array_map(fn (array $d) => Document::fromArray($d), $page),
            $page === [] ? $cursor : end($page)['change_id'],
            count($after) > count($page),
        );
    }

    public function pdf(string $key): string
    {
        $this->throwIfFailing();

        $document = $this->find($key);
        if ($document['status'] !== Document::STATUS_ISSUED) {
            throw new ConflictException("Chứng từ {$key} chưa được ký/phát hành nên chưa có PDF.", 409, 'document_not_issued');
        }

        return "%PDF-1.4
% hoá đơn giả {$key} số {$document['number']}
%%EOF
";
    }

    // ---- Điều khiển bản giả --------------------------------------------------------------------

    public function simulateDrafted(string $key, ?string $viewUrl = null): Document
    {
        return $this->transition($key, Document::STATUS_DRAFTED, [
            'view_url' => $viewUrl ?? 'https://einvoice.test/view/'.$key,
            'error' => null,
        ]);
    }

    /**
     * Kiểm soát viên đã ký: chứng từ có số, bắn DocumentIssued.
     */
    public function simulateIssued(string $key, string $number, ?string $serial = null, ?string $pattern = null): Document
    {
        $document = $this->find($key);

        return $this->transition($key, Document::STATUS_ISSUED, [
            'number' => $number,
            'serial' => $serial ?? $document['serial'],
            'pattern' => $pattern ?? $document['pattern'],
            'view_url' => $document['view_url'] ?? 'https://einvoice.test/view/'.$key,
            'error' => null,
        ]);
    }

    public function simulateFailed(string $key, string $error = 'Nhà cung cấp từ chối chứng từ.'): Document
    {
        return $this->transition($key, Document::STATUS_FAILED, ['error' => $error]);
    }

    public function simulateCancelled(string $key): Document
    {
        return $this->transition($key, Document::STATUS_CANCELLED, []);
    }

    /**
     * Lời gọi kế tiếp (bất kỳ) ném $e.
     */
    public function failNext(Throwable $e): static
    {
        $this->failures[] = $e;

        return $this;
    }

    // ---- Khẳng định ----------------------------------------------------------------------------

    /**
     * @param  (callable(DocumentRequest): bool)|string|null  $callback  callback, hoặc key
     */
    public function assertDocumentCreated(callable|string|null $callback = null): void
    {
        PHPUnit::assertNotEmpty($this->createdMatching($callback), 'Không có lời gọi lập chứng từ nào khớp điều kiện.');
    }

    /**
     * @param  (callable(DocumentRequest): bool)|string|null  $callback
     */
    public function assertDocumentCreatedTimes(int $times, callable|string|null $callback = null): void
    {
        $count = count($this->createdMatching($callback));

        PHPUnit::assertSame($times, $count, "Mong {$times} lời gọi lập chứng từ khớp điều kiện, thực tế {$count}.");
    }

    /**
     * @param  (callable(DocumentRequest): bool)|string|null  $callback
     */
    public function assertDocumentNotCreated(callable|string|null $callback = null): void
    {
        PHPUnit::assertEmpty($this->createdMatching($callback), 'Có lời gọi lập chứng từ khớp điều kiện mà lẽ ra không được có.');
    }

    public function assertDocumentCancelled(string $key): void
    {
        PHPUnit::assertContains($key, $this->cancelled, "Chứng từ {$key} không bị huỷ.");
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertSame([], $this->created, 'Có chứng từ được lập.');
        PHPUnit::assertSame([], $this->cancelled, 'Có chứng từ bị huỷ.');
    }

    /**
     * @return list<DocumentRequest>
     */
    public function createdDocuments(): array
    {
        return $this->created;
    }

    /**
     * @return array<string, Document>
     */
    public function documents(): array
    {
        return array_map(fn (array $d) => Document::fromArray($d), $this->documents);
    }

    // ---- Nội bộ --------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function transition(string $key, string $status, array $changes): Document
    {
        $this->find($key);
        $this->change($key, ['status' => $status] + $changes);
        $document = Document::fromArray($this->documents[$key]);

        $this->inbox->receive($document, $status, 'fake', 'evt_fake_'.$this->nextEventId++);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function change(string $key, array $changes): void
    {
        $this->documents[$key] = array_merge($this->documents[$key], $changes, [
            'change_id' => $this->nextChangeId++,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    protected function build(DocumentRequest $request, ?array $existing): array
    {
        $items = array_map(fn (Item $i) => $i->toArray() + ['amount' => $i->lineAmount()], $request->items);
        $subtotal = array_sum(array_map(fn (Item $i) => $i->lineAmount(), $request->items));
        $vat = array_sum(array_map(fn (Item $i) => $i->vatAmount(), $request->items));
        $now = now()->toIso8601String();

        return [
            'key' => $request->key,
            'type' => $request->type ?? DocumentRequest::TYPE_INVOICE,
            'issuer' => $request->issuer,
            'status' => Document::STATUS_QUEUED,
            'provider' => 'vnpt',
            'provider_key' => $existing['provider_key'] ?? $request->providerKey ?? substr('VJU-FAKE-'.$request->key, 0, 50),
            'pattern' => $existing['pattern'] ?? '1/001',
            'serial' => $existing['serial'] ?? 'C26TAB',
            'number' => null,
            'issued_on' => $request->issuedOn ?? now('Asia/Ho_Chi_Minh')->format('Y-m-d'),
            'buyer' => $request->buyer?->toArray(),
            'payment_method' => $request->paymentMethod ?? 'CK',
            'currency' => 'VND',
            'items' => $items,
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => $subtotal + $vat,
            'total_in_words' => null,
            'reference' => $request->reference ?? [],
            'view_url' => null,
            'error' => null,
            'metadata' => $request->metadata ?? [],
            'change_id' => $this->nextChangeId++,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return list<DocumentRequest>
     */
    protected function createdMatching(callable|string|null $callback): array
    {
        return array_values(array_filter($this->created, fn (DocumentRequest $r) => match (true) {
            $callback === null => true,
            is_string($callback) => $r->key === $callback,
            default => (bool) $callback($r),
        }));
    }

    /**
     * @return array<string, mixed>
     */
    protected function find(string $key): array
    {
        return $this->documents[$key] ?? throw new NotFoundException("Không có chứng từ {$key}.", 404, 'document_not_found');
    }

    protected function throwIfFailing(): void
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }
    }
}
