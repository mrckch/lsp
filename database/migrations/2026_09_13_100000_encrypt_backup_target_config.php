<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * backup_targets.config_encrypted war trotz Name nur als Klartext-JSON gespeichert.
 * Seit dem Cast `encrypted:array` werden bestehende Klartext-Werte hier verschlüsselt.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('backup_targets')->whereNotNull('config_encrypted')->orderBy('id')->get()
            ->each(function (object $row) {
                $raw = (string) $row->config_encrypted;
                if (json_validate($raw)) {
                    DB::table('backup_targets')->where('id', $row->id)
                        ->update(['config_encrypted' => Crypt::encryptString($raw)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('backup_targets')->whereNotNull('config_encrypted')->orderBy('id')->get()
            ->each(function (object $row) {
                $raw = (string) $row->config_encrypted;
                if (! json_validate($raw)) {
                    DB::table('backup_targets')->where('id', $row->id)
                        ->update(['config_encrypted' => Crypt::decryptString($raw)]);
                }
            });
    }
};
