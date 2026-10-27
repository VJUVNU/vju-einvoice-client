<?php

namespace Vju\EinvoiceClient\Exceptions;

/**
 * Dịch vụ từ chối xác thực (401) hoặc app bị tắt / IP không được phép (403): unknown_client,
 * signature_expired, nonce_missing, signature_invalid, replay_detected, client_disabled, ip_not_allowed.
 */
class AuthenticationException extends EinvoiceException
{
}
