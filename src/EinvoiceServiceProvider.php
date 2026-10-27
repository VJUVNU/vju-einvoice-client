<?php

namespace Vju\EinvoiceClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vju\EinvoiceClient\Client\EinvoiceClient;
use Vju\EinvoiceClient\Console\PullDocuments;
use Vju\EinvoiceClient\Contracts\EinvoiceService;
use Vju\EinvoiceClient\Support\CursorStore;
use Vju\EinvoiceClient\Support\Inbox;
use Vju\EinvoiceClient\Support\NonceStore;

class EinvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/einvoice.php', 'einvoice');

        $this->app->singleton(EinvoiceClient::class, fn (Application $app) => new EinvoiceClient(
            (array) $app['config']->get('einvoice', []),
            $app->make(Inbox::class),
        ));
        $this->app->singleton(EinvoiceService::class, fn (Application $app) => $app->make(EinvoiceClient::class));

        $this->app->singleton(Inbox::class, fn (Application $app) => new Inbox(
            $app['db'],
            $app['config']->get('einvoice.connection'),
        ));
        $this->app->singleton(CursorStore::class, fn (Application $app) => new CursorStore(
            $app['db'],
            $app['config']->get('einvoice.connection'),
        ));
        $this->app->singleton(NonceStore::class, fn (Application $app) => new NonceStore(
            $app['db'],
            $app['config']->get('einvoice.connection'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/einvoice.php' => config_path('einvoice.php'),
        ], 'einvoice-config');

        // Giữ nguyên tên tệp khi publish để migrator không chạy hai lần cùng một migration.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'einvoice-migrations');

        if (config('einvoice.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('einvoice.webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PullDocuments::class]);
        }

        if (config('einvoice.pull.schedule', false)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('einvoice:pull')
                    ->cron((string) config('einvoice.pull.cron', '*/5 * * * *'))
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }
}
