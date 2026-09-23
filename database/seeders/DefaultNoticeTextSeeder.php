<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\NoticeText\Models\NoticeText;
use Illuminate\Database\Seeder;

/**
 * Standard-Hinweistext als Bestand — auch bei Neuinstallation vorhanden.
 * Wird für neue Testdurchläufe vorausgewählt und im Schüler-Test angezeigt,
 * wenn ein Durchlauf keinen eigenen Hinweistext hat.
 *
 * Idempotent: existiert der Text (auch bearbeitet) bereits, bleibt er unangetastet.
 */
class DefaultNoticeTextSeeder extends Seeder
{
    public const NAME = 'Standard-Hinweis Lesetest';

    public const CONTENT = <<<'TXT'
        Gleich siehst du nacheinander einzelne Sätze. Lies jeden Satz genau und entscheide:
        Stimmt der Satz? Dann tippe auf „richtig“. Stimmt er nicht? Dann tippe auf „falsch“.

        Arbeite so schnell und so genau, wie du kannst. Du musst nicht alle Sätze schaffen – das schafft fast niemand.

        Hast du dich vertippt? Scrolle zurück und tippe die andere Antwort an.
        Wenn die Zeit um ist, endet der Test automatisch. Viel Erfolg!
        TXT;

    public function run(): void
    {
        $exists = NoticeText::query()->where('name', self::NAME)->exists();
        if ($exists) {
            return;
        }

        NoticeText::query()->create([
            'name' => self::NAME,
            'content' => self::CONTENT,
            'status' => 'aktiv',
            // Nur Standard werden, wenn noch kein anderer Standard gepflegt ist
            'is_default' => ! NoticeText::query()->where('is_default', true)->exists(),
            'created_by_user_id' => null,
        ]);
    }
}
