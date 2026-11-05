<?php

namespace Vju\EinvoiceClient\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use stdClass;
use Vju\EinvoiceClient\Contracts\EinvoiceService;
use Vju\EinvoiceClient\Data\Document;
use Vju\EinvoiceClient\Data\DocumentPage;
use Vju\EinvoiceClient\Data\DocumentRequest;
use Vju\EinvoiceClient\Exceptions\EinvoiceException;
use Vju\EinvoiceClient\Exceptions\InvalidPayloadException;
use Vju\EinvoiceClient\Support\Inbox;
use Vju\SatelliteContracts\Signing\RequestSignature;

/**
 * Client HTTP của Dịch vụ hoá đơn điện tử (hợp đồng einvoice-api-v1). Mọi lời gọi ký bằng
 * RequestSignature với đúng đường dẫn chuẩn hoá + thân thô được gửi đi.
 *
 * Chỉ GET được thử lại, và chỉ khi lỗi kết nối. POST /documents idempotent theo key nên app tự gọi
 * lại được khi gặp connection_failed.
 */
class EinvoiceClient implements EinvoiceService
{
    public const API_PREFIX = '/api/v1';

    /**
     * @param  array<string, mixed>  $config  nội dung config/einvoice.php
     */
    public function __construct(
        protected array $config,
        protected ?Inbox $inbox = null,
        protected ?HttpFactory $http = null,
    ) {}

    public function createDocument(DocumentRequest $request): Document
    {
        if ($request->issuer === null && ($this->config['default_issuer'] ?? null)) {
            $request = $request->issuer((string) $this->config['default_issuer']);
        }

        $request->validate();

        $document = $this->documentFrom($this->request('POST', '/documents', [], $request->toArray()));

        // Gửi lại = vòng đời mới: nháp/lỗi/huỷ của lần trước không được chặn event của lần này.
        if (! $document->isIssued()) {
            $this->inbox?->forget($document->key);
        }

        return $document;
    }

    public function upsert(DocumentRequest $request): Document
    {
        return $this->createDocument($request);
    }

    public function getDocument(string $key, bool $refresh = false): Document
    {
        return $this->documentFrom($this->request('GET', '/documents/'.rawurlencode($key), $refresh ? ['refresh' => 1] : []));
    }

    public function cancelDocument(string $key): Document
    {
        return $this->documentFrom($this->request('POST', '/documents/'.rawurlencode($key).'/cancel'));
    }

    public function changes(int $cursor = 0, ?int $limit = null): DocumentPage
    {
        $limit = max(1, min(500, $limit ?? (int) ($this->config['pull']['limit'] ?? 100)));
        $body = $this->request('GET', '/documents', ['cursor' => max(0, $cursor), 'limit' => $limit]);

        return DocumentPage::fromArray($body, $cursor);
    }

    public function pdf(string $key): string
    {
        $response = $this->call('GET', '/documents/'.rawurlencode($key).'/pdf');

        if (! $response->successful()) {
            throw ErrorMapper::map($response->status(), $this->decode($response));
        }

        $body = $response->body();
        if (! str_starts_with($body, '%PDF')) {
            throw new InvalidPayloadException('Dịch vụ hoá đơn điện tử trả về không phải PDF.', $response->status());
        }

        return $body;
    }

    /**
     * GET /api/health — không ký.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return (array) ($this->pending()->get($this->origin().'/api/health')->json() ?? []);
    }

    /**
     * Gửi một lời gọi đã ký và trả thân JSON. Mỗi lần thử ký lại với timestamp + nonce mới.
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $payload  null = thân rỗng
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $response = $this->call($method, $path, $query, $payload);
        $json = $this->decode($response);

        if (! $response->successful()) {
            throw ErrorMapper::map($response->status(), $json);
        }

        if ($json === null) {
            throw new InvalidPayloadException(
                "Dịch vụ hoá đơn điện tử trả HTTP {$response->status()} nhưng thân không phải JSON.",
                $response->status(),
            );
        }

        return $json;
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $payload
     */
    protected function call(string $method, string $path, array $query = [], ?array $payload = null): Response
    {
        $method = strtoupper($method);
        $key = (string) ($this->config['key'] ?? '');
        $secret = (string) ($this->config['secret'] ?? '');

        if ($this->origin() === '' || $key === '' || $secret === '') {
            throw new EinvoiceException(
                'Chưa cấu hình dịch vụ hoá đơn điện tử: cần EINVOICE_URL, EINVOICE_KEY, EINVOICE_SECRET.',
                0,
                'not_configured',
            );
        }

        $signedPath = self::API_PREFIX.'/'.ltrim($path, '/');
        $canonical = RequestSignature::canonicalPath($signedPath, $query);
        $body = $payload === null ? '' : $this->encode($payload);
        $url = $this->origin().$canonical;

        $attempts = $method === 'GET' ? 1 + max(0, (int) ($this->config['retries'] ?? 0)) : 1;

        for ($attempt = 1; ; $attempt++) {
            $headers = RequestSignature::headers($key, $secret, $method, $signedPath, $query, $body);

            try {
                return $this->send($method, $url, $headers, $body);
            } catch (ConnectionException $e) {
                if ($attempt >= $attempts) {
                    throw new EinvoiceException(
                        'Không kết nối được dịch vụ hoá đơn điện tử: '.$e->getMessage(),
                        0,
                        'connection_failed',
                        previous: $e,
                    );
                }

                usleep(max(0, (int) ($this->config['retry_sleep_ms'] ?? 0)) * 1000 * $attempt);
            }
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function send(string $method, string $url, array $headers, string $body): Response
    {
        $request = $this->pending()->withHeaders($headers);

        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        return $request->send($method, $url);
    }

    protected function pending(): PendingRequest
    {
        // Lấy Factory từ container mỗi lần để Http::fake() gọi sau khi client đã dựng vẫn ăn.
        $http = $this->http ?? app(HttpFactory::class);

        return $http->acceptJson()
            ->timeout(max(1, (int) ($this->config['timeout'] ?? 15)))
            ->connectTimeout(max(1, (int) ($this->config['connect_timeout'] ?? 5)));
    }

    protected function origin(): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        // Đối tượng rỗng phải ra {} như ví dụ hợp đồng, không phải [].
        foreach (['metadata', 'reference'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] === []) {
                $payload[$field] = new stdClass;
            }
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decode(Response $response): ?array
    {
        $raw = $response->body();

        if ($raw === '') {
            return null;
        }

        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    /**
     * Hợp đồng nói "trả Document" mà không nói có bọc "data" hay không — nhận cả hai.
     *
     * @param  array<string, mixed>  $body
     */
    protected function documentFrom(array $body): Document
    {
        $data = is_array($body['data'] ?? null) && isset($body['data']['key']) ? $body['data'] : $body;

        return Document::fromArray($data);
    }
}
