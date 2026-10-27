<?php

namespace Vju\EinvoiceClient\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Vju\EinvoiceClient\Client\EinvoiceClient;
use Vju\EinvoiceClient\Contracts\EinvoiceService;
use Vju\EinvoiceClient\Data\Buyer;
use Vju\EinvoiceClient\Data\DocumentRequest;
use Vju\EinvoiceClient\Data\Item;
use Vju\EinvoiceClient\Data\VatRate;
use Vju\EinvoiceClient\Exceptions\AuthenticationException;
use Vju\EinvoiceClient\Exceptions\ConflictException;
use Vju\EinvoiceClient\Exceptions\DocumentLockedException;
use Vju\EinvoiceClient\Exceptions\EinvoiceException;
use Vju\EinvoiceClient\Exceptions\InvalidPayloadException;
use Vju\EinvoiceClient\Exceptions\IssuerNotAllowedException;
use Vju\EinvoiceClient\Exceptions\NotFoundException;
use Vju\EinvoiceClient\Exceptions\ValidationException;
use Vju\EinvoiceClient\Facades\Einvoice;
use Vju\EinvoiceClient\Support\Inbox;
use Vju\EinvoiceClient\Tests\TestCase;
use Vju\SatelliteContracts\Signing\RequestSignature;

class EinvoiceClientTest extends TestCase
{
    private function request(): DocumentRequest
    {
        return DocumentRequest::make('HD-HP1234', 'vju-hocphi')
            ->buyer(Buyer::make('Nguyễn Văn A')->code('22110001'))
            ->item(Item::make('Học phí học kỳ 1', unitPrice: 1200000, vatRate: VatRate::KCT)->unit('Khoản'));
    }

    private function refresh(): void
    {
        $this->app->forgetInstance(EinvoiceClient::class);
        $this->app->forgetInstance(EinvoiceService::class);
        Einvoice::clearResolvedInstances();
    }

    public function test_create_document_signed_post_with_exact_body(): void
    {
        Http::fake([self::URL.'/api/v1/documents' => Http::response($this->document(['status' => 'queued']), 202)]);

        $doc = Einvoice::createDocument($this->request()->sendEmail()->metadata([]));

        $this->assertSame('queued', $doc->status);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'POST', '/api/v1/documents');
            $this->assertStringContainsString('"metadata":{}', $request->body());
            $this->assertSame([
                'key' => 'HD-HP1234',
                'issuer' => 'vju-hocphi',
                'buyer' => ['code' => '22110001', 'name' => 'Nguyễn Văn A'],
                'items' => [['name' => 'Học phí học kỳ 1', 'unit' => 'Khoản', 'quantity' => 1, 'unit_price' => 1200000, 'vat_rate' => 'KCT']],
                'send_email' => true,
                'metadata' => [],
            ], json_decode($request->body(), true));

            return true;
        });
    }

    public function test_contract_request_body_round_trips(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->document(), 200)]);

        $fixture = $this->fixture('document-request.json');
        $request = DocumentRequest::make(
            key: $fixture['key'],
            issuer: $fixture['issuer'],
            buyer: Buyer::fromArray($fixture['buyer']),
            items: array_map(fn ($i) => Item::fromArray($i), $fixture['items']),
            type: $fixture['type'],
            issuedOn: $fixture['issued_on'],
            paymentMethod: $fixture['payment_method'],
            reference: $fixture['reference'],
            sendEmail: $fixture['send_email'],
            metadata: $fixture['metadata'],
        );

        Einvoice::upsert($request);

        Http::assertSent(function (Request $r) use ($fixture) {
            $this->assertSignedRequest($r, 'POST', '/api/v1/documents');
            $this->assertSame($fixture, json_decode($r->body(), true));

            return true;
        });
    }

    public function test_default_issuer_from_config(): void
    {
        config(['einvoice.default_issuer' => 'vju-dichvu']);
        $this->refresh();
        Http::fake([self::URL.'/*' => Http::response($this->document(), 202)]);

        Einvoice::createDocument(DocumentRequest::make('K1')->buyer(Buyer::make('A'))->item(Item::make('x', 1, VatRate::KCT)));

        Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['issuer'] === 'vju-dichvu');
    }

    public function test_invalid_request_is_not_sent(): void
    {
        Http::fake();

        try {
            Einvoice::createDocument(DocumentRequest::make('K1', 'vju-hocphi')->buyer(Buyer::make('A')));
            $this->fail('Thiếu dòng phải ném InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }

        Http::assertNothingSent();
    }

    public function test_get_document_and_refresh_query_are_signed(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['data' => $this->document(['status' => 'issued', 'number' => '0000042'])])]);

        $this->assertSame('0000042', Einvoice::getDocument('HD-HP1234')->number);
        Einvoice::getDocument('HD-HP1234', refresh: true);

        $paths = [];
        Http::assertSent(function (Request $r) use (&$paths) {
            $paths[] = substr($r->url(), strlen(self::URL));
            $this->assertSame('', $r->body());

            return true;
        });
        $this->assertSame(['/api/v1/documents/HD-HP1234', '/api/v1/documents/HD-HP1234?refresh=1'], $paths);

        Http::assertSent(function (Request $r) {
            $this->assertSignedRequest($r, 'GET', str_contains($r->url(), 'refresh') ? '/api/v1/documents/HD-HP1234?refresh=1' : '/api/v1/documents/HD-HP1234');

            return true;
        });
    }

    public function test_cancel_is_signed_post_with_empty_body(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->document(['status' => 'cancelled']))]);

        $this->assertTrue(Einvoice::cancelDocument('HD-HP1234')->isCancelled());

        Http::assertSent(function (Request $r) {
            $this->assertSignedRequest($r, 'POST', '/api/v1/documents/HD-HP1234/cancel');
            $this->assertSame('', $r->body());

            return true;
        });
    }

    public function test_changes_signs_sorted_query(): void
    {
        Http::fake([self::URL.'/*' => Http::response([
            'data' => [$this->document(['change_id' => 812])], 'next_cursor' => 812, 'has_more' => false,
        ])]);

        $page = Einvoice::changes(800, 25);

        $this->assertSame(812, $page->nextCursor);
        Http::assertSent(function (Request $r) {
            $this->assertSignedRequest($r, 'GET', '/api/v1/documents?cursor=800&limit=25');

            return true;
        });
    }

    public function test_resubmission_resets_non_issued_dedupe(): void
    {
        $inbox = $this->app->make(Inbox::class);
        DB::table('einvoice_client_events')->insert([
            ['dedupe_key' => 'HD-HP1234:failed', 'document_key' => 'HD-HP1234', 'status' => 'failed', 'source' => 'webhook', 'created_at' => now(), 'updated_at' => now()],
            ['dedupe_key' => 'HD-OTHER:failed', 'document_key' => 'HD-OTHER', 'status' => 'failed', 'source' => 'webhook', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Http::fake([self::URL.'/*' => Http::response($this->document(['status' => 'queued']))]);

        Einvoice::createDocument($this->request());

        $this->assertFalse($inbox->has('HD-HP1234', 'failed'));
        $this->assertTrue($inbox->has('HD-OTHER', 'failed'));
    }

    public function test_issued_response_keeps_dedupe(): void
    {
        DB::table('einvoice_client_events')->insert([
            'dedupe_key' => 'HD-HP1234:issued', 'document_key' => 'HD-HP1234', 'status' => 'issued', 'source' => 'webhook', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake([self::URL.'/*' => Http::response($this->document(['status' => 'issued', 'number' => '1']))]);

        Einvoice::createDocument($this->request());

        $this->assertTrue($this->app->make(Inbox::class)->has('HD-HP1234', 'issued'));
    }

    public function test_get_retried_on_connection_error_with_fresh_nonce(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()->pushFailedConnection()->push($this->document())]);

        Einvoice::getDocument('HD-HP1234');

        $nonces = [];
        Http::assertSent(function (Request $r) use (&$nonces) {
            $this->assertSignedRequest($r, 'GET', '/api/v1/documents/HD-HP1234');
            $nonces[] = $r->header(RequestSignature::HEADER_NONCE)[0];

            return true;
        });
        $this->assertCount(2, array_unique($nonces));
    }

    public function test_post_not_retried(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()->pushFailedConnection()->push($this->document())]);

        try {
            Einvoice::createDocument($this->request());
            $this->fail('Phải ném EinvoiceException.');
        } catch (EinvoiceException $e) {
            $this->assertSame('connection_failed', $e->errorCode);
        }

        try {
            Einvoice::cancelDocument('HD-HP1234');
        } catch (EinvoiceException) {
        }

        Http::assertSentCount(2);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: class-string}>
     */
    public static function documentedErrors(): array
    {
        return [
            'unknown_client' => [401, 'unknown_client', AuthenticationException::class],
            'signature_expired' => [401, 'signature_expired', AuthenticationException::class],
            'nonce_missing' => [401, 'nonce_missing', AuthenticationException::class],
            'signature_invalid' => [401, 'signature_invalid', AuthenticationException::class],
            'replay_detected' => [401, 'replay_detected', AuthenticationException::class],
            'client_disabled' => [403, 'client_disabled', AuthenticationException::class],
            'ip_not_allowed' => [403, 'ip_not_allowed', AuthenticationException::class],
            'issuer_not_allowed' => [403, 'issuer_not_allowed', IssuerNotAllowedException::class],
            'document_not_found' => [404, 'document_not_found', NotFoundException::class],
            'document_locked' => [409, 'document_locked', DocumentLockedException::class],
            'validation_failed' => [422, 'validation_failed', ValidationException::class],
            'mã lạ 409' => [409, 'something_new', ConflictException::class],
            'mã lạ 503' => [503, 'maintenance', EinvoiceException::class],
        ];
    }

    #[DataProvider('documentedErrors')]
    public function test_error_envelope_from_contract_maps_to_typed_exception(int $status, string $code, string $class): void
    {
        $text = str_replace(['"code": "…"', '{…}'], ['"code": "'.$code.'"', '{"items.0.vat_rate": ["…"]}'], $this->fixtureText('error.template.txt'));
        Http::fake([self::URL.'/*' => Http::response($text, $status, ['Content-Type' => 'application/json'])]);

        try {
            Einvoice::getDocument('HD-HP1234');
            $this->fail('Phải ném exception.');
        } catch (EinvoiceException $e) {
            $this->assertSame($class, $e::class);
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame('…', $e->getMessage());
            $this->assertSame(['items.0.vat_rate' => ['…']], $e->fields);
        }
    }

    public function test_document_locked_is_a_conflict(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['error' => ['code' => 'document_locked', 'message' => 'Đã ký']], 409)]);

        $this->expectException(ConflictException::class);

        Einvoice::cancelDocument('HD-HP1234');
    }

    public function test_non_json_success_is_invalid_payload(): void
    {
        Http::fake([self::URL.'/*' => Http::response('', 202)]);

        $this->expectException(InvalidPayloadException::class);

        Einvoice::createDocument($this->request());
    }

    public function test_missing_configuration_fails_before_sending(): void
    {
        config(['einvoice.url' => null]);
        $this->refresh();
        Http::fake();

        try {
            Einvoice::getDocument('K1');
            $this->fail('Phải ném EinvoiceException.');
        } catch (EinvoiceException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_health_is_unsigned(): void
    {
        Http::fake([self::URL.'/api/health' => Http::response($this->fixture('health.json'))]);

        $this->assertSame('ok', $this->app->make(EinvoiceClient::class)->health()['checks']['providers']['vnpt']);
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('X-Vju-Signature'));
    }
}
