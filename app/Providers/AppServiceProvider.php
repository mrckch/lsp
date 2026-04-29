<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\PrintJob\GotenbergClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GotenbergClient::class, function () {
            return new GotenbergClient(config('lsp.pdf.gotenberg_url', 'http://pdf:3000'));
        });
    }

    public function boot(): void
    {
        //
    }
}
