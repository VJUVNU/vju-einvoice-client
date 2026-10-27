<?php

use Illuminate\Support\Facades\Route;
use Vju\EinvoiceClient\Http\VerifyWebhookSignature;
use Vju\EinvoiceClient\Http\WebhookController;

Route::post(
    (string) config('einvoice.webhook.path', 'einvoice/webhook'),
    WebhookController::class,
)
    ->middleware([...(array) config('einvoice.webhook.middleware', []), VerifyWebhookSignature::class])
    ->name('einvoice.webhook');
