<?php

namespace Vju\EinvoiceClient\Client;

use Vju\EinvoiceClient\Exceptions\AuthenticationException;
use Vju\EinvoiceClient\Exceptions\ConflictException;
use Vju\EinvoiceClient\Exceptions\DocumentLockedException;
use Vju\EinvoiceClient\Exceptions\EinvoiceException;
use Vju\EinvoiceClient\Exceptions\IssuerNotAllowedException;
use Vju\EinvoiceClient\Exceptions\NotFoundException;
use Vju\EinvoiceClient\Exceptions\ValidationException;

/**
 * Đổi envelope lỗi `{"error":{"code","message","fields"}}` (hợp đồng §2) thành exception có kiểu.
 * Ưu tiên theo error.code; mã lạ thì theo HTTP status; còn lại là EinvoiceException.
 *
 * @internal
 */
final class ErrorMapper
{
    /** @var array<string, class-string<EinvoiceException>> */
    public const BY_CODE = [
        'unknown_client' => AuthenticationException::class,
        'signature_expired' => AuthenticationException::class,
        'nonce_missing' => AuthenticationException::class,
        'signature_invalid' => AuthenticationException::class,
        'replay_detected' => AuthenticationException::class,
        'client_disabled' => AuthenticationException::class,
        'ip_not_allowed' => AuthenticationException::class,
        'issuer_not_allowed' => IssuerNotAllowedException::class,
        'document_not_found' => NotFoundException::class,
        'document_locked' => DocumentLockedException::class,
        'validation_failed' => ValidationException::class,
    ];

    /** @var array<int, class-string<EinvoiceException>> */
    private const BY_STATUS = [
        401 => AuthenticationException::class,
        403 => AuthenticationException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        422 => ValidationException::class,
    ];

    /**
     * @param  array<string, mixed>|null  $body
     */
    public static function map(int $status, ?array $body): EinvoiceException
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
        $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== ''
            ? $error['message']
            : "Dịch vụ hoá đơn điện tử trả HTTP {$status}".($code ? " ({$code})" : '').'.';

        $class = ($code !== null ? (self::BY_CODE[$code] ?? null) : null)
            ?? self::BY_STATUS[$status]
            ?? EinvoiceException::class;

        return new $class($message, $status, $code, self::fields($error['fields'] ?? null), $body);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function fields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $out = [];

        foreach ($fields as $name => $messages) {
            $out[(string) $name] = array_values(array_map('strval', (array) $messages));
        }

        return $out;
    }
}
