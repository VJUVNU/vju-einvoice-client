<?php

namespace Vju\EinvoiceClient\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Lỗi gốc của mọi lời gọi dịch vụ hoá đơn điện tử. Bắt lớp này là bắt hết.
 *
 * $status là HTTP status (0 khi không tới được dịch vụ), $errorCode là error.code của envelope lỗi,
 * $fields là error.fields (chỉ có ở 422), $body là toàn bộ phản hồi đã giải JSON.
 */
class EinvoiceException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly array $fields = [],
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function is(string $errorCode): bool
    {
        return $this->errorCode === $errorCode;
    }
}
