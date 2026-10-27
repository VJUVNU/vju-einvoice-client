<?php

namespace Vju\EinvoiceClient\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Vju\EinvoiceClient\Contracts\EinvoiceService;
use Vju\EinvoiceClient\Data\Buyer;
use Vju\EinvoiceClient\Data\DocumentRequest;
use Vju\EinvoiceClient\Data\Item;
use Vju\EinvoiceClient\Data\VatRate;
use Vju\EinvoiceClient\Events\DocumentCancelled;
use Vju\EinvoiceClient\Events\DocumentDrafted;
use Vju\EinvoiceClient\Events\DocumentFailed;
use Vju\EinvoiceClient\Events\DocumentIssued;
use Vju\EinvoiceClient\Exceptions\DocumentLockedException;
use Vju\EinvoiceClient\Exceptions\IssuerNotAllowedException;
use Vju\EinvoiceClient\Exceptions\NotFoundException;
use Vju\EinvoiceClient\Facades\Einvoice;
use Vju\EinvoiceClient\Tests\TestCase;

class FakeEinvoiceTest extends TestCase
{
    private function request(string $key = 'HD-HP1', int $price = 1200000): DocumentRequest
    {
        return DocumentRequest::make($key, 'vju-hocphi')
            ->buyer(Buyer::make('Nguyễn Văn A')->email('a@vju.ac.vn'))
            ->item(Item::make('Học phí học kỳ 1', unitPrice: $price, vatRate: VatRate::KCT))
            ->item(Item::make('Phí ký túc xá', unitPrice: 500000, vatRate: VatRate::EIGHT, quantity: 2));
    }

    public function test_create_and_assertions(): void
    {
        Http::fake();
        $fake = Einvoice::fake();

        $doc = Einvoice::createDocument($this->request());

        $this->assertSame('queued', $doc->status);
        $this->assertSame(2200000, $doc->subtotal);
        $this->assertSame(80000, $doc->vatAmount);
        $this->assertSame(2280000, $doc->total);
        $this->assertSame(1000000, $doc->items[1]->amount);
        $this->assertSame('CK', $doc->paymentMethod);

        $fake->assertDocumentCreated('HD-HP1');
        $fake->assertDocumentCreated(fn (DocumentRequest $r) => $r->buyer?->email === 'a@vju.ac.vn' && count($r->items) === 2);
        $fake->assertDocumentCreatedTimes(1);
        $fake->assertDocumentNotCreated('HD-HP2');
        $this->assertSame($fake, $this->app->make(EinvoiceService::class));
        Http::assertNothingSent();
    }

    public function test_assertion_failures(): void
    {
        $fake = Einvoice::fake();

        try {
            $fake->assertDocumentCreated();
            $this->fail('Phải trượt.');
        } catch (AssertionFailedError) {
        }

        Einvoice::createDocument($this->request());

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingSent();
    }

    public function test_full_lifecycle_fires_each_event_once(): void
    {
        Event::fake([DocumentDrafted::class, DocumentIssued::class]);
        $fake = Einvoice::fake();
        Einvoice::createDocument($this->request());

        $fake->simulateDrafted('HD-HP1');
        $issued = $fake->simulateIssued('HD-HP1', '0000042');

        $this->assertSame('0000042', $issued->number);
        $this->assertTrue(Einvoice::getDocument('HD-HP1')->isIssued());
        Event::assertDispatchedTimes(DocumentDrafted::class, 1);
        Event::assertDispatchedTimes(DocumentIssued::class, 1);
        Event::assertDispatched(DocumentIssued::class, fn (DocumentIssued $e) => $e->number === '0000042' && $e->source === 'fake');

        // pull trên bản giả thấy trạng thái mới nhất nhưng không bắn lại.
        $this->artisan('einvoice:pull')->assertSuccessful();
        Event::assertDispatchedTimes(DocumentIssued::class, 1);
    }

    public function test_issued_document_is_locked(): void
    {
        Event::fake([DocumentIssued::class]);
        $fake = Einvoice::fake();
        Einvoice::createDocument($this->request());
        $fake->simulateIssued('HD-HP1', '1');

        // Gửi lại đúng nội dung: trả nguyên.
        $this->assertSame('1', Einvoice::createDocument($this->request())->number);

        try {
            Einvoice::createDocument($this->request(price: 999));
            $this->fail('Sửa chứng từ đã phát hành phải ném DocumentLockedException.');
        } catch (DocumentLockedException $e) {
            $this->assertSame('document_locked', $e->errorCode);
        }

        $this->expectException(DocumentLockedException::class);
        Einvoice::cancelDocument('HD-HP1');
    }

    public function test_failed_then_resubmitted_fires_failed_again(): void
    {
        Event::fake([DocumentFailed::class, DocumentDrafted::class]);
        $fake = Einvoice::fake();

        Einvoice::createDocument($this->request());
        $fake->simulateDrafted('HD-HP1');
        $fake->simulateFailed('HD-HP1', 'Sai MST');

        Einvoice::createDocument($this->request(price: 1100000));
        $fake->simulateDrafted('HD-HP1');
        $fake->simulateFailed('HD-HP1', 'Vẫn sai MST');

        Event::assertDispatchedTimes(DocumentFailed::class, 2);
        Event::assertDispatchedTimes(DocumentDrafted::class, 2);
        Event::assertDispatched(DocumentFailed::class, fn (DocumentFailed $e) => $e->error === 'Vẫn sai MST');
    }

    public function test_cancel(): void
    {
        Event::fake([DocumentCancelled::class]);
        $fake = Einvoice::fake();
        Einvoice::createDocument($this->request());

        $this->assertTrue(Einvoice::cancelDocument('HD-HP1')->isCancelled());
        $fake->assertDocumentCancelled('HD-HP1');
        Event::assertNotDispatched(DocumentCancelled::class);

        $fake->simulateCancelled('HD-HP1');
        Event::assertDispatchedTimes(DocumentCancelled::class, 1);
    }

    public function test_unknown_document(): void
    {
        Einvoice::fake();

        $this->expectException(NotFoundException::class);

        Einvoice::getDocument('HD-404');
    }

    public function test_fail_next(): void
    {
        $fake = Einvoice::fake();
        $fake->failNext(new IssuerNotAllowedException('Không được dùng đơn vị này', 403, 'issuer_not_allowed'));

        try {
            Einvoice::createDocument($this->request());
            $this->fail('Phải ném lỗi đã cài.');
        } catch (IssuerNotAllowedException) {
        }

        $this->assertSame('queued', Einvoice::createDocument($this->request())->status);
    }

    public function test_changes_paging_on_fake(): void
    {
        Event::fake([DocumentDrafted::class]);
        $fake = Einvoice::fake();
        Einvoice::createDocument($this->request('K1'));
        Einvoice::createDocument($this->request('K2'));
        $fake->simulateDrafted('K1');

        $page = Einvoice::changes(0, 1);
        $this->assertSame(['K2'], array_map(fn ($d) => $d->key, $page->data));
        $this->assertTrue($page->hasMore);

        $page = Einvoice::changes($page->nextCursor, 10);
        $this->assertSame(['K1'], array_map(fn ($d) => $d->key, $page->data));
        $this->assertSame('drafted', $page->data[0]->status);
        $this->assertFalse($page->hasMore);
    }

    public function test_default_issuer_on_fake(): void
    {
        config(['einvoice.default_issuer' => 'vju-dichvu']);
        $fake = Einvoice::fake();

        $doc = Einvoice::createDocument(DocumentRequest::make('K1')->buyer(Buyer::make('A'))->item(Item::make('x', 1, VatRate::KCT)));

        $this->assertSame('vju-dichvu', $doc->issuer);
        $fake->assertDocumentCreated(fn (DocumentRequest $r) => $r->issuer === 'vju-dichvu');
    }
}
