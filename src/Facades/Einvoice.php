<?php

namespace Vju\EinvoiceClient\Facades;

use Illuminate\Support\Facades\Facade;
use Vju\EinvoiceClient\Contracts\EinvoiceService;
use Vju\EinvoiceClient\Support\Inbox;
use Vju\EinvoiceClient\Testing\FakeEinvoice;

/**
 * @method static \Vju\EinvoiceClient\Data\Document createDocument(\Vju\EinvoiceClient\Data\DocumentRequest $request)
 * @method static \Vju\EinvoiceClient\Data\Document upsert(\Vju\EinvoiceClient\Data\DocumentRequest $request)
 * @method static \Vju\EinvoiceClient\Data\Document getDocument(string $key, bool $refresh = false)
 * @method static \Vju\EinvoiceClient\Data\Document cancelDocument(string $key)
 * @method static \Vju\EinvoiceClient\Data\DocumentPage changes(int $cursor = 0, ?int $limit = null)
 *
 * @see \Vju\EinvoiceClient\Client\EinvoiceClient
 * @see \Vju\EinvoiceClient\Testing\FakeEinvoice
 */
class Einvoice extends Facade
{
    /**
     * Thay dịch vụ thật bằng bản giả trong bộ nhớ cho tới hết bài kiểm.
     */
    public static function fake(): FakeEinvoice
    {
        $fake = new FakeEinvoice(
            static::getFacadeApplication()->make(Inbox::class),
            config('einvoice.default_issuer'),
        );

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return EinvoiceService::class;
    }
}
