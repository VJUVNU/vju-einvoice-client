<?php

namespace Vju\EinvoiceClient\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Vju\EinvoiceClient\Data\Buyer;
use Vju\EinvoiceClient\Data\Document;
use Vju\EinvoiceClient\Data\DocumentPage;
use Vju\EinvoiceClient\Data\DocumentRequest;
use Vju\EinvoiceClient\Data\Item;
use Vju\EinvoiceClient\Data\VatRate;
use Vju\EinvoiceClient\Exceptions\InvalidPayloadException;
use Vju\EinvoiceClient\Tests\TestCase;

class DataParsingTest extends TestCase
{
    public function test_document_example_from_contract(): void
    {
        $doc = Document::fromArray($this->fixture('document.json'));

        $this->assertSame('HD-HP1234', $doc->key);
        $this->assertSame('invoice', $doc->type);
        $this->assertSame('vju-hocphi', $doc->issuer);
        $this->assertSame('drafted', $doc->status);
        $this->assertTrue($doc->isDrafted());
        $this->assertFalse($doc->isIssued());
        $this->assertSame('vnpt', $doc->provider);
        $this->assertSame('VJU-ENR-HD-HP1234', $doc->providerKey);
        $this->assertSame('1/001', $doc->pattern);
        $this->assertSame('C26TAB', $doc->serial);
        $this->assertNull($doc->number);
        $this->assertSame('2026-10-27', $doc->issuedOn?->format('Y-m-d'));

        $this->assertSame('22110001', $doc->buyer?->code);
        $this->assertSame('Nguyễn Văn A', $doc->buyer?->name);
        $this->assertNull($doc->buyer?->legalName);
        $this->assertNull($doc->buyer?->taxCode);
        $this->assertSame('001204000001', $doc->buyer?->idNumber);
        $this->assertSame('Hà Nội', $doc->buyer?->address);
        $this->assertSame('a@vju.ac.vn', $doc->buyer?->email);
        $this->assertNull($doc->buyer?->phone);

        $this->assertSame('CK', $doc->paymentMethod);
        $this->assertSame('VND', $doc->currency);
        $this->assertCount(1, $doc->items);
        $item = $doc->items[0];
        $this->assertSame('Học phí học kỳ 1 năm học 2026-2027', $item->name);
        $this->assertSame('Khoản', $item->unit);
        $this->assertSame(1, $item->quantity);
        $this->assertSame(1200000, $item->unitPrice);
        $this->assertSame(1200000, $item->amount);
        $this->assertSame(VatRate::KCT, $item->vatRate);

        $this->assertSame(1200000, $doc->subtotal);
        $this->assertSame(0, $doc->vatAmount);
        $this->assertSame(1200000, $doc->total);
        $this->assertSame('Một triệu hai trăm nghìn đồng chẵn', $doc->totalInWords);
        $this->assertSame(['payment_ref' => 'HP1234', 'payment_id' => 5012], $doc->reference);
        $this->assertNull($doc->viewUrl);
        $this->assertNull($doc->error);
        $this->assertSame([], $doc->metadata);
        $this->assertNull($doc->changeId);
        $this->assertNull($doc->createdAt);
    }

    public function test_number_keeps_leading_zeros(): void
    {
        $this->assertSame('0000123', Document::fromArray($this->document(['number' => '0000123']))->number);
    }

    public function test_error_accepts_string_or_object(): void
    {
        $this->assertSame('Sai MST', Document::fromArray($this->document(['error' => 'Sai MST']))->error);
        $this->assertSame('Sai MST', Document::fromArray($this->document(['error' => ['code' => 'ERR:3', 'message' => 'Sai MST']]))->error);
        $this->assertSame('{"code":"ERR:3"}', Document::fromArray($this->document(['error' => ['code' => 'ERR:3']]))->error);
    }

    public function test_document_requires_key(): void
    {
        $this->expectException(InvalidPayloadException::class);

        Document::fromArray(['status' => 'drafted']);
    }

    public function test_documents_page_example_from_contract(): void
    {
        $page = DocumentPage::fromArray($this->pageFromTemplate());

        $this->assertCount(1, $page->data);
        $this->assertSame(812, $page->data[0]->changeId);
        $this->assertSame('HD-HP1234', $page->data[0]->key);
        $this->assertSame(812, $page->nextCursor);
        $this->assertFalse($page->hasMore);
    }

    public function test_page_row_without_change_id_is_rejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        DocumentPage::fromArray(['data' => [$this->document()], 'next_cursor' => 1, 'has_more' => false]);
    }

    public function test_request_builder_matches_contract_body(): void
    {
        $request = DocumentRequest::make('HD-HP1234')
            ->type('invoice')
            ->issuer('vju-hocphi')
            ->issuedOn(new \DateTimeImmutable('2026-10-27 08:00', new \DateTimeZone('Asia/Ho_Chi_Minh')))
            ->buyer(Buyer::make('Nguyễn Văn A')->code('22110001')->idNumber('001204000001')->address('Hà Nội')->email('a@vju.ac.vn'))
            ->paymentMethod('CK')
            ->item(Item::make('Học phí học kỳ 1', unitPrice: 1200000, vatRate: VatRate::KCT)->unit('Khoản'))
            ->reference(['payment_ref' => 'HP1234', 'payment_id' => 5012])
            ->sendEmail()
            ->metadata([]);

        $request->validate();
        $this->assertSame($this->fixture('document-request.json'), $request->toArray());
    }

    public function test_builders_are_immutable(): void
    {
        $buyer = Buyer::make('A');
        $withEmail = $buyer->email('a@vju.ac.vn');
        $this->assertNull($buyer->email);
        $this->assertSame('a@vju.ac.vn', $withEmail->email);

        $request = DocumentRequest::make('K1', 'vju-hocphi');
        $withItem = $request->item(Item::make('x', 1, VatRate::TEN));
        $this->assertSame([], $request->items);
        $this->assertCount(1, $withItem->items);
    }

    public function test_item_amount_and_vat(): void
    {
        $item = Item::make('Tài liệu', unitPrice: 33333, vatRate: 8, quantity: 1.5);

        $this->assertSame('8', $item->vatRate);
        $this->assertSame(50000, $item->lineAmount());
        $this->assertSame(4000, $item->vatAmount());
        $this->assertSame(0, Item::make('Học phí', 100, VatRate::KKKNT)->vatAmount());
    }

    /**
     * @return array<string, array{0: callable(DocumentRequest): DocumentRequest}>
     */
    public static function invalidRequests(): array
    {
        return [
            'key có ký tự lạ' => [fn (DocumentRequest $r) => new DocumentRequest('HD 1', $r->issuer, $r->buyer, $r->items)],
            'key quá 40 ký tự' => [fn (DocumentRequest $r) => new DocumentRequest(str_repeat('a', 41), $r->issuer, $r->buyer, $r->items)],
            'thiếu issuer' => [fn (DocumentRequest $r) => new DocumentRequest($r->key, null, $r->buyer, $r->items)],
            'thiếu buyer' => [fn (DocumentRequest $r) => new DocumentRequest($r->key, $r->issuer, null, $r->items)],
            'buyer không tên' => [fn (DocumentRequest $r) => $r->buyer(Buyer::make(' '))],
            'không có dòng' => [fn (DocumentRequest $r) => $r->items([])],
            'quantity = 0' => [fn (DocumentRequest $r) => $r->items([Item::make('x', 1, VatRate::KCT, quantity: 0)])],
            'unit_price âm' => [fn (DocumentRequest $r) => $r->items([Item::make('x', -1, VatRate::KCT)])],
            'thiếu vat_rate' => [fn (DocumentRequest $r) => $r->items([Item::make('x', 1, '')])],
            'payment_method lạ' => [fn (DocumentRequest $r) => $r->paymentMethod('ATM')],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_request_rejects_programmer_errors(callable $mutate): void
    {
        $valid = DocumentRequest::make('HD-1', 'vju-hocphi', Buyer::make('A'), [Item::make('x', 1, VatRate::KCT)]);
        $valid->validate();

        $this->expectException(InvalidArgumentException::class);

        $mutate($valid)->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function pageFromTemplate(): array
    {
        $document = substr(trim($this->fixtureText('document.json')), 1);
        $text = str_replace('…Document }', $document, $this->fixtureText('documents-page.template.txt'));

        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
}
