<?php

namespace Vju\EinvoiceClient\Data;

use Vju\EinvoiceClient\Exceptions\InvalidPayloadException;
use Vju\EinvoiceClient\Support\Cast;

/**
 * Một trang của GET /documents (nguồn thay đổi). Mỗi Document mang changeId tăng dần.
 */
final class DocumentPage
{
    /**
     * @param  list<Document>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body, int $requestedCursor = 0): self
    {
        $documents = [];

        foreach (Cast::map($body['data'] ?? null) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $document = Document::fromArray($row);

            if ($document->changeId === null) {
                throw new InvalidPayloadException("Document {$document->key} trong nguồn thay đổi thiếu change_id.");
            }

            $documents[] = $document;
        }

        $next = Cast::int($body['next_cursor'] ?? null);

        if ($next === null) {
            $next = $documents === [] ? $requestedCursor : (int) end($documents)->changeId;
        }

        return new self($documents, $next, Cast::bool($body['has_more'] ?? false));
    }
}
