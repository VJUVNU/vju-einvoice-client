<?php

namespace Vju\EinvoiceClient\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Vju\EinvoiceClient\EinvoiceServiceProvider;
use Vju\SatelliteContracts\Signing\RequestSignature;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const URL = 'https://einvoice.test';

    public const KEY = 'enrollment';

    public const SECRET = 'einvoice-api-secret';

    public const WEBHOOK_SECRET = 'einvoice-webhook-secret';

    protected function getPackageProviders($app): array
    {
        return [EinvoiceServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Einvoice' => \Vju\EinvoiceClient\Facades\Einvoice::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('einvoice.url', self::URL);
        $app['config']->set('einvoice.key', self::KEY);
        $app['config']->set('einvoice.secret', self::SECRET);
        $app['config']->set('einvoice.webhook.secret', self::WEBHOOK_SECRET);
        $app['config']->set('einvoice.retry_sleep_ms', 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        return json_decode($this->fixtureText($name), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function fixtureText(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    /**
     * Ví dụ trong hợp đồng viết "{ …Document }" — ghép Document mẫu vào cho ra JSON thật.
     *
     * @return array<string, mixed>
     */
    protected function templateFixture(string $name, string $placeholder, string $fixture): array
    {
        $text = str_replace($placeholder, $this->fixtureText($fixture), $this->fixtureText($name));

        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Kiểm chữ ký của một request đi ra bằng chính RequestSignature::verify, dựng lại đường dẫn chuẩn
     * hoá từ URL thật đã gửi (bỏ phần gốc self::URL).
     */
    protected function assertSignedRequest(Request $request, string $expectedMethod, string $expectedCanonical): void
    {
        $parts = parse_url($request->url());
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);
        $canonical = RequestSignature::canonicalPath($path, $query);

        $this->assertSame($expectedMethod, $request->method());
        $this->assertSame($expectedCanonical, $canonical);
        $this->assertSame(self::KEY, $request->header(RequestSignature::HEADER_KEY)[0] ?? null);

        $timestamp = (int) ($request->header(RequestSignature::HEADER_TIMESTAMP)[0] ?? 0);
        $nonce = (string) ($request->header(RequestSignature::HEADER_NONCE)[0] ?? '');
        $signature = (string) ($request->header(RequestSignature::HEADER_SIGNATURE)[0] ?? '');

        $this->assertTrue(RequestSignature::withinWindow($timestamp));
        $this->assertNotSame('', $nonce);
        $this->assertLessThanOrEqual(RequestSignature::MAX_NONCE_LENGTH, strlen($nonce));
        $this->assertTrue(
            RequestSignature::verify(self::SECRET, $signature, $request->method(), $canonical, $timestamp, $nonce, $request->body()),
            "Chữ ký không khớp với {$request->method()} {$canonical}",
        );
    }

    /**
     * Gửi webhook đã ký như dịch vụ gửi.
     *
     * @param  array<string, mixed>  $overrides  timestamp, nonce, key, secret, signature, path
     */
    protected function deliverWebhook(array|string $envelope, array $overrides = []): TestResponse
    {
        $body = is_string($envelope) ? $envelope : json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = $overrides['path'] ?? '/einvoice/webhook';
        $timestamp = $overrides['timestamp'] ?? time();
        $nonce = $overrides['nonce'] ?? RequestSignature::nonce();
        $signature = $overrides['signature'] ?? RequestSignature::sign(
            $overrides['secret'] ?? self::WEBHOOK_SECRET,
            'POST',
            RequestSignature::canonicalPath($path),
            $timestamp,
            $nonce,
            $body,
        );

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VJU_KEY' => $overrides['key'] ?? 'einvoice',
            'HTTP_X_VJU_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_VJU_NONCE' => $nonce,
            'HTTP_X_VJU_SIGNATURE' => $signature,
        ];

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    protected function envelope(string $event, array $document, ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(6)),
            'event' => $event,
            'occurred_at' => '2026-10-27T10:00:00+07:00',
            'data' => $document,
        ];
    }

    /**
     * Document mẫu của hợp đồng với vài trường đổi.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function document(array $overrides = []): array
    {
        return array_merge($this->fixture('document.json'), $overrides);
    }
}
