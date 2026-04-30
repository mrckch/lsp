<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Importquelle (z. B. eine konkrete SVWS-Server-URL + Credentials).
 *
 * `config_encrypted` wird via Laravel-Cast `encrypted:array` gesichert
 * (App-Key als Schlüssel) und enthält je nach `type`:
 *   - 'svws_api': {api_url, schema, username, password, verify_ssl, timeout_seconds}
 *   - 'schild_csv': aktuell ungenutzt (Datei kommt direkt aus dem Wizard-Upload)
 */
class ImportSource extends Model
{
    protected $fillable = [
        'key',
        'name',
        'type',
        'config_encrypted',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config_encrypted' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /** Convenience-Getter für einzelne Config-Felder. */
    public function configValue(string $key, mixed $default = null): mixed
    {
        $cfg = $this->config_encrypted ?? [];

        return $cfg[$key] ?? $default;
    }
}
