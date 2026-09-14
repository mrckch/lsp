<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\PrintJob\GotenbergClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        $this->configureReverseProxy();
        $this->configureStudentRateLimits();
    }

    /**
     * Hinter einem Reverse-Proxy (z. B. Nginx Proxy Manager) liefern erst die
     * X-Forwarded-*-Header die echte Client-IP und das https-Schema.
     */
    private function configureReverseProxy(): void
    {
        $proxies = trim((string) config('lsp.trusted_proxies', ''));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }

        // TLS terminiert vor der App — Links/Assets trotzdem immer als https erzeugen
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    private function configureStudentRateLimits(): void
    {
        RateLimiter::for('student-login', function (Request $request) {
            $limits = [
                Limit::perMinute((int) config('lsp.rate_limits.student_login_per_ip', 300))
                    ->by('student-login-ip:'.$request->ip()),
            ];

            $code = strtoupper(trim((string) $request->input('login_code', '')));
            if ($code !== '') {
                $limits[] = Limit::perMinute((int) config('lsp.rate_limits.student_login_per_code', 10))
                    ->by('student-login-code:'.$code);
            }

            return $limits;
        });

        RateLimiter::for('student-answer', function (Request $request) {
            $attemptId = $request->hasSession() ? $request->session()->get('student_attempt_id') : null;

            return $attemptId !== null
                ? Limit::perMinute((int) config('lsp.rate_limits.student_answers_per_attempt', 120))
                    ->by('student-answer-attempt:'.$attemptId)
                : Limit::perMinute((int) config('lsp.rate_limits.student_answers_per_ip', 60))
                    ->by('student-answer-ip:'.$request->ip());
        });
    }
}
