<?php

namespace Vju\EinvoiceClient\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Vju\EinvoiceClient\Events\DocumentCancelled;
use Vju\EinvoiceClient\Events\DocumentDrafted;
use Vju\EinvoiceClient\Events\DocumentEvent;
use Vju\EinvoiceClient\Events\DocumentFailed;
use Vju\EinvoiceClient\Events\DocumentIssued;
use Vju\EinvoiceClient\Support\CursorStore;
use Vju\EinvoiceClient\Tests\TestCase;

class PullDocumentsTest extends TestCase
{
    /** @var list<string> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([DocumentDrafted::class, DocumentIssued::class, DocumentFailed::class, DocumentCancelled::class] as $class) {
            Event::listen($class, function (DocumentEvent $e) {
                $this->fired[] = $e->key.':'.$e->status;
            });
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $rows  [key, status, change_id]
     * @return array<string, mixed>
     */
    private function page(array $rows, bool $hasMore, ?int $next = null): array
    {
        return [
            'data' => array_map(fn (array $r) => $this->document([
                'key' => $r[0], 'status' => $r[1], 'change_id' => $r[2], 'number' => $r[1] === 'issued' ? '00001' : null,
            ]), $rows),
            'next_cursor' => $next ?? ($rows === [] ? 0 : end($rows)[2]),
            'has_more' => $hasMore,
        ];
    }

    private function cursor(): int
    {
        return $this->app->make(CursorStore::class)->get();
    }

    public function test_pull_fires_by_current_status_and_advances_cursor(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->page([
            ['K1', 'drafted', 801], ['K2', 'queued', 802], ['K3', 'issued', 805], ['K4', 'failed', 806], ['K5', 'cancelled', 810],
        ], false))]);

        $this->artisan('einvoice:pull')->assertSuccessful();

        $this->assertSame(['K1:drafted', 'K3:issued', 'K4:failed', 'K5:cancelled'], $this->fired);
        $this->assertSame(810, $this->cursor());
        Http::assertSent(function (Request $r) {
            $this->assertSignedRequest($r, 'GET', '/api/v1/documents?cursor=0&limit=100');

            return true;
        });
    }

    public function test_has_more_paging(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()
            ->push($this->page([['K1', 'drafted', 1], ['K2', 'drafted', 2]], true))
            ->push($this->page([['K1', 'issued', 3]], false)),
        ]);

        $this->artisan('einvoice:pull', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(['K1:drafted', 'K2:drafted', 'K1:issued'], $this->fired);
        $this->assertSame(3, $this->cursor());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/documents?cursor=2&limit=2'));
    }

    public function test_pull_and_webhook_do_not_double_fire(): void
    {
        $this->deliverWebhook($this->envelope('document.issued', $this->document(['key' => 'K9', 'status' => 'issued'])))->assertOk();

        Http::fake([self::URL.'/*' => Http::response($this->page([['K9', 'issued', 50], ['K8', 'drafted', 51]], false))]);
        $this->artisan('einvoice:pull')->assertSuccessful();

        $this->deliverWebhook($this->envelope('document.drafted', $this->document(['key' => 'K8'])))
            ->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(['K9:issued', 'K8:drafted'], $this->fired);
        $this->assertSame(2, DB::table('einvoice_client_events')->count());
    }

    public function test_listener_failure_stops_cursor(): void
    {
        $failKey = 'K2';
        Event::listen(DocumentIssued::class, function (DocumentIssued $e) use (&$failKey) {
            if ($e->key === $failKey) {
                throw new RuntimeException('hỏng');
            }
        });

        Http::fake([self::URL.'/*' => Http::response($this->page([['K1', 'issued', 10], ['K2', 'issued', 11], ['K3', 'issued', 12]], false))]);

        $this->artisan('einvoice:pull')->assertFailed();
        $this->assertSame(10, $this->cursor());
        $this->assertFalse(DB::table('einvoice_client_events')->where('document_key', 'K2')->exists());

        $failKey = null;
        Http::fake([self::URL.'/*' => Http::response($this->page([['K2', 'issued', 11], ['K3', 'issued', 12]], false))]);

        $this->artisan('einvoice:pull')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cursor=10&'));
        $this->assertSame(12, $this->cursor());
        $this->assertSame(3, DB::table('einvoice_client_events')->count());
    }

    public function test_service_error_fails_command(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['error' => ['code' => 'unknown_client', 'message' => 'x']], 401)]);

        $this->artisan('einvoice:pull')->assertFailed();
        $this->assertSame(0, $this->cursor());
    }

    public function test_empty_page_with_has_more_stops(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->page([], true, 99))]);

        $this->artisan('einvoice:pull')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(99, $this->cursor());
    }

    public function test_cursor_option_resets(): void
    {
        $this->app->make(CursorStore::class)->put(700);
        Http::fake([self::URL.'/*' => Http::response($this->page([], false, 0))]);

        $this->artisan('einvoice:pull', ['--cursor' => 0])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cursor=0&'));
    }
}
