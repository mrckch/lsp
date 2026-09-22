<?php

declare(strict_types=1);

namespace Tests\Feature\Infra;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressionsschutz für den Produktions-Deploy-Blocker, bei dem queue-/scheduler-
 * Container (User lsp, uid/gid 1000) einen leeren APP_KEY in den geteilten
 * bootstrap/cache cachten, weil sie die root:root-600-.env nicht lesen konnten.
 *
 * Getestet wird der Inhalt des Entrypoints statt eines echten Container-Laufs —
 * die beiden Härtungen sollen nicht versehentlich wieder entfernt werden.
 */
class EntrypointHardeningTest extends TestCase
{
    private function entrypoint(): string
    {
        $path = base_path('infra/app/entrypoint.sh');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    #[Test]
    public function root_branch_macht_env_fuer_gruppe_lsp_lesbar_ohne_world_read(): void
    {
        $script = $this->entrypoint();

        // Gruppen-Leserecht wird gesetzt …
        $this->assertMatchesRegularExpression('/chgrp\s+lsp\s+\.env/', $script);
        $this->assertMatchesRegularExpression('/chmod\s+g\+r,o-rwx\s+\.env/', $script);

        // … und zwar VOR dem gosu-Wechsel zu lsp, sonst greift es für
        // queue/scheduler nicht mehr rechtzeitig.
        $chgrpPos = strpos($script, 'chgrp lsp .env');
        $gosuPos = strpos($script, 'gosu lsp:lsp');
        $this->assertNotFalse($chgrpPos);
        $this->assertNotFalse($gosuPos);
        $this->assertLessThan($gosuPos, $chgrpPos);
    }

    #[Test]
    public function production_cache_nur_bei_verfuegbarem_app_key(): void
    {
        $script = $this->entrypoint();

        // config:cache steht hinter einem APP_KEY-Guard (Umgebung ODER lesbare .env) …
        $this->assertMatchesRegularExpression(
            '/\[ -n "\$\{APP_KEY:-\}" \].*grep -q "\^APP_KEY=base64:" \.env/s',
            $script
        );

        // … und ohne Key wird geleert statt einen leeren Key zu cachen.
        $this->assertStringContainsString('php artisan config:clear', $script);
    }
}
