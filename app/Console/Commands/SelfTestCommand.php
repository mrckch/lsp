<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Crypto\CryptoService;
use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * End-to-End-Selbsttest aller kritischen Services.
 *
 * Sinnvoll nach Deployment, vor Setup-Wizard, oder zur Diagnose bei
 * Production-Problemen. Läuft strikt READ-ONLY — keine DB-Writes,
 * keine Mails versendet.
 *
 * Exit-Code = Anzahl Failures. Per Default 0 wenn alles OK.
 */
class SelfTestCommand extends Command
{
    protected $signature = 'lsp:selftest {--json : Statt Tabelle JSON ausgeben}';

    protected $description = 'Prüft DB, Cache, Queue, Mail, Crypto, Storage, Gotenberg auf Erreichbarkeit/Funktion.';

    /** @var list<array{name:string, status:'ok'|'fail'|'skip', detail:string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->check('db', fn () => $this->checkDb());
        $this->check('cache', fn () => $this->checkCache());
        $this->check('queue', fn () => $this->checkQueue());
        $this->check('mail', fn () => $this->checkMail());
        $this->check('storage', fn () => $this->checkStorage());
        $this->check('crypto', fn () => $this->checkCrypto());
        $this->check('app_setting', fn () => $this->checkAppSetting());
        $this->check('gotenberg', fn () => $this->checkGotenberg());

        $failures = count(array_filter($this->results, fn ($r) => $r['status'] === 'fail'));

        if ($this->option('json')) {
            $this->line(json_encode([
                'failures' => $failures,
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable();
            $this->newLine();
            if ($failures === 0) {
                $this->info('Alle Checks OK.');
            } else {
                $this->error("$failures Check(s) fehlgeschlagen.");
            }
        }

        return $failures;
    }

    private function check(string $name, callable $fn): void
    {
        try {
            $detail = $fn();
            $this->results[] = ['name' => $name, 'status' => 'ok', 'detail' => (string) $detail];
        } catch (SkipCheck $e) {
            $this->results[] = ['name' => $name, 'status' => 'skip', 'detail' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->results[] = ['name' => $name, 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    private function checkDb(): string
    {
        $driver = DB::connection()->getDriverName();
        DB::select('SELECT 1');

        return "$driver erreichbar";
    }

    private function checkCache(): string
    {
        $key = 'lsp.selftest.'.bin2hex(random_bytes(4));
        Cache::put($key, 'ok', 5);
        $val = Cache::get($key);
        Cache::forget($key);
        if ($val !== 'ok') {
            throw new \RuntimeException('Cache get/put roundtrip fehlgeschlagen');
        }

        return config('cache.default').' roundtrip ok';
    }

    private function checkQueue(): string
    {
        $driver = (string) config('queue.default');
        // Connection auflösen (kein push, nur Verbindung prüfen)
        Queue::connection($driver)->size();

        return "$driver erreichbar";
    }

    private function checkMail(): string
    {
        $driver = (string) config('mail.default');
        Mail::mailer($driver); // wirft bei misconfig

        return "$driver konfiguriert";
    }

    private function checkStorage(): string
    {
        $disk = Storage::disk('local');
        $name = 'lsp/.selftest-'.bin2hex(random_bytes(4));
        $disk->put($name, 'ok');
        $back = $disk->get($name);
        $disk->delete($name);
        if ($back !== 'ok') {
            throw new \RuntimeException('Storage roundtrip fehlgeschlagen');
        }

        return "local disk roundtrip ok ({$disk->path('')})";
    }

    private function checkCrypto(): string
    {
        // CryptoService aus Container resolvable
        app(CryptoService::class);
        // App-Key-basierte encrypt/decrypt-Roundtrip (DEK-basiert würde Setup benötigen)
        $payload = 'selftest-payload-'.time();
        if (decrypt(encrypt($payload)) !== $payload) {
            throw new \RuntimeException('encrypt/decrypt-Roundtrip fehlgeschlagen');
        }

        return 'AppKey-Crypto + CryptoService ok';
    }

    private function checkAppSetting(): string
    {
        $s = AppSetting::singleton();
        if (! $s->is_initialized) {
            throw new SkipCheck('Setup-Wizard noch nicht durchlaufen');
        }

        return 'Schule '.($s->school_name ?? '?').' (initialisiert '.optional($s->initialized_at)->format('Y-m-d').')';
    }

    private function checkGotenberg(): string
    {
        $url = (string) config('lsp.pdf.gotenberg_url', '');
        if ($url === '') {
            throw new SkipCheck('GOTENBERG_URL nicht konfiguriert');
        }

        try {
            $response = Http::timeout(3)->get(rtrim($url, '/').'/health');
            if (! $response->successful()) {
                throw new \RuntimeException("HTTP {$response->status()} von $url/health");
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Gotenberg unter $url nicht erreichbar: ".$e->getMessage());
        }

        return "$url/health ok";
    }

    private function renderTable(): void
    {
        $rows = [];
        foreach ($this->results as $r) {
            $icon = match ($r['status']) {
                'ok' => '<fg=green>✓</>',
                'fail' => '<fg=red>✗</>',
                'skip' => '<fg=yellow>~</>',
                default => '?',
            };
            $rows[] = [$icon, $r['name'], $r['detail']];
        }
        $this->table(['', 'Check', 'Detail'], $rows);
    }
}

/**
 * Interne Markierung: dieser Check ist (wegen fehlender Konfiguration o. ä.)
 * nicht durchführbar, aber kein Fehler.
 */
class SkipCheck extends \RuntimeException {}
