<?php

namespace Vju\EinvoiceClient\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Vju\EinvoiceClient\Events\DocumentDrafted;
use Vju\EinvoiceClient\Tests\TestCase;

class ConfigurationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('einvoice.webhook.path', 'hooks/hoa-don');
        $app['config']->set('einvoice.pull.schedule', true);
    }

    public function test_custom_webhook_path(): void
    {
        Event::fake([DocumentDrafted::class]);

        $this->assertTrue(Route::has('einvoice.webhook'));
        $this->post('/einvoice/webhook')->assertNotFound();
        $this->deliverWebhook($this->envelope('document.drafted', $this->document()), ['path' => '/hooks/hoa-don'])->assertOk();

        Event::assertDispatchedTimes(DocumentDrafted::class, 1);
    }

    public function test_pull_scheduled_when_enabled(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'einvoice:pull'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
    }
}
