<?php

namespace Vju\EinvoiceClient\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Vju\EinvoiceClient\Tests\TestCase;

class WebhookDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('einvoice.webhook.enabled', false);
    }

    public function test_route_not_registered_and_schedule_off_by_default(): void
    {
        $this->assertFalse(Route::has('einvoice.webhook'));
        $this->deliverWebhook($this->envelope('document.drafted', $this->document()))->assertNotFound();

        $this->assertEmpty(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'einvoice:pull'),
        ));
    }
}
