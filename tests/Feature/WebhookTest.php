<?php

namespace Vju\EinvoiceClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Vju\EinvoiceClient\Events\DocumentCancelled;
use Vju\EinvoiceClient\Events\DocumentDrafted;
use Vju\EinvoiceClient\Events\DocumentEvent;
use Vju\EinvoiceClient\Events\DocumentFailed;
use Vju\EinvoiceClient\Events\DocumentIssued;
use Vju\EinvoiceClient\Tests\TestCase;
use Vju\SatelliteContracts\Signing\RequestSignature;

class WebhookTest extends TestCase
{
    /** @var list<DocumentEvent> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([DocumentDrafted::class, DocumentIssued::class, DocumentFailed::class, DocumentCancelled::class] as $class) {
            Event::listen($class, function (DocumentEvent $e) {
                $this->received[] = $e;
            });
        }
    }

    private function contractEnvelope(): array
    {
        return $this->templateFixture('webhook-document-issued.template.txt', '{ …Document }', 'document.json');
    }

    public function test_contract_example_fires_document_issued_once(): void
    {
        $this->deliverWebhook($this->contractEnvelope())->assertOk()->assertJson(['status' => 'processed']);

        $this->assertCount(1, $this->received);
        $e = $this->received[0];
        $this->assertInstanceOf(DocumentIssued::class, $e);
        $this->assertSame('HD-HP1234', $e->key);
        // Trạng thái theo tên event, không theo data.status của ví dụ (ví dụ để "drafted").
        $this->assertSame('issued', $e->status);
        $this->assertSame('drafted', $e->document->status);
        $this->assertSame('C26TAB', $e->serial);
        $this->assertSame('1/001', $e->pattern);
        $this->assertSame('webhook', $e->source);
        $this->assertSame('evt_…', $e->eventId);

        $row = DB::table('einvoice_client_events')->first();
        $this->assertSame('HD-HP1234:issued', $row->dedupe_key);
    }

    /**
     * @return array<string, array{0: string, 1: class-string<DocumentEvent>}>
     */
    public static function events(): array
    {
        return [
            'document.drafted' => ['document.drafted', DocumentDrafted::class],
            'document.issued' => ['document.issued', DocumentIssued::class],
            'document.failed' => ['document.failed', DocumentFailed::class],
            'document.cancelled' => ['document.cancelled', DocumentCancelled::class],
        ];
    }

    #[DataProvider('events')]
    public function test_each_event_maps_to_its_class(string $event, string $class): void
    {
        $status = substr($event, strlen('document.'));
        $doc = $this->document(['status' => $status, 'number' => $status === 'issued' ? '0000123' : null, 'error' => $status === 'failed' ? 'Sai MST người mua' : null, 'view_url' => 'https://vnpt.test/x']);

        $this->deliverWebhook($this->envelope($event, $doc))->assertOk();

        $this->assertCount(1, $this->received);
        $this->assertInstanceOf($class, $this->received[0]);
        $this->assertSame($status, $this->received[0]->status);

        match ($class) {
            DocumentIssued::class => $this->assertSame('0000123', $this->received[0]->number),
            DocumentFailed::class => $this->assertSame('Sai MST người mua', $this->received[0]->error),
            DocumentDrafted::class => $this->assertSame('https://vnpt.test/x', $this->received[0]->viewUrl),
            default => null,
        };
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['secret' => 'nope'])
            ->assertStatus(401)->assertJsonPath('error.code', 'signature_invalid');

        $this->assertSame([], $this->received);
    }

    public function test_tampered_body_is_rejected(): void
    {
        $body = json_encode($this->contractEnvelope());
        $ts = time();
        $nonce = 'n-tamper';
        $sig = RequestSignature::sign(self::WEBHOOK_SECRET, 'POST', '/einvoice/webhook', $ts, $nonce, $body);
        $tampered = $this->contractEnvelope();
        $tampered['data']['number'] = '9999999';

        $this->deliverWebhook($tampered, ['signature' => $sig, 'timestamp' => $ts, 'nonce' => $nonce])
            ->assertStatus(401);

        $this->assertSame([], $this->received);
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['timestamp' => time() - 301])
            ->assertStatus(401)->assertJsonPath('error.code', 'signature_expired');

        $this->assertSame([], $this->received);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $o = ['nonce' => 'n-same', 'timestamp' => time()];

        $this->deliverWebhook($this->contractEnvelope(), $o)->assertOk();
        $this->deliverWebhook($this->contractEnvelope(), $o)->assertStatus(401)->assertJsonPath('error.code', 'replay_detected');

        $this->assertCount(1, $this->received);
    }

    public function test_missing_nonce_and_wrong_sender(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['nonce' => ''])->assertStatus(401)->assertJsonPath('error.code', 'nonce_missing');
        $this->deliverWebhook($this->contractEnvelope(), ['key' => 'payment-gateway'])->assertStatus(401)->assertJsonPath('error.code', 'unknown_sender');

        $this->assertSame([], $this->received);
    }

    public function test_unconfigured_secret_refuses(): void
    {
        config(['einvoice.webhook.secret' => '']);

        $this->deliverWebhook($this->contractEnvelope())->assertStatus(500)->assertJsonPath('error.code', 'webhook_not_configured');
    }

    public function test_duplicate_delivery_fires_once(): void
    {
        $doc = $this->document(['status' => 'issued', 'number' => '1']);

        $this->deliverWebhook($this->envelope('document.issued', $doc, 'evt_1'))->assertJson(['status' => 'processed']);
        $this->deliverWebhook($this->envelope('document.issued', $doc, 'evt_2'))->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->received);
    }

    public function test_different_statuses_of_same_key_each_fire(): void
    {
        $this->deliverWebhook($this->envelope('document.drafted', $this->document()))->assertOk();
        $this->deliverWebhook($this->envelope('document.issued', $this->document(['status' => 'issued', 'number' => '7'])))->assertOk();

        $this->assertSame(['drafted', 'issued'], array_map(fn (DocumentEvent $e) => $e->status, $this->received));
    }

    public function test_listener_failure_returns_500_and_rolls_back(): void
    {
        $fail = true;
        Event::listen(DocumentIssued::class, function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('không ghi được số hoá đơn');
            }
        });

        $this->deliverWebhook($this->contractEnvelope())->assertStatus(500)->assertJsonPath('error.code', 'listener_failed');
        $this->assertSame(0, DB::table('einvoice_client_events')->count());

        $fail = false;
        $this->deliverWebhook($this->contractEnvelope())->assertOk()->assertJson(['status' => 'processed']);
        $this->assertSame(1, DB::table('einvoice_client_events')->count());

        $this->deliverWebhook($this->contractEnvelope())->assertOk()->assertJson(['status' => 'duplicate']);
    }

    public function test_unknown_event_is_ignored(): void
    {
        $this->deliverWebhook($this->envelope('document.queued', $this->document(['status' => 'queued'])))
            ->assertOk()->assertJson(['status' => 'ignored']);
        $this->deliverWebhook($this->envelope('invoice.replaced', $this->document()))
            ->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame([], $this->received);
    }

    public function test_malformed_payload_is_422(): void
    {
        $this->deliverWebhook('{"event":"document.issued"}')->assertStatus(422);
        $this->deliverWebhook($this->envelope('document.issued', ['status' => 'issued']))->assertStatus(422);

        $this->assertSame([], $this->received);
    }
}
