<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\FeedbackSet\Models\FeedbackSet;
use App\Domain\FeedbackSet\Models\FeedbackSetRange;
use Illuminate\Database\Seeder;

/**
 * Basis-Rückmeldeset als Bestand — auch bei Neuinstallation vorhanden.
 *
 * Die LQ-Bänder sind auf die Default-Förderbedarfs-Schwellen abgestimmt
 * (siehe DefaultSupportThresholdsSeeder): LQ < 70 = Förderbedarf,
 * 70–84 = auffällig, ab 85 = im Normbereich.
 *
 * Idempotent: existiert das Set (auch bearbeitet) bereits, bleibt es
 * unangetastet. Alles kann wie gewohnt bearbeitet/gelöscht/neu angelegt werden.
 */
class DefaultFeedbackSetsSeeder extends Seeder
{
    public function run(): void
    {
        $set = FeedbackSet::query()->firstOrCreate(
            ['name' => 'SLS-Standardrückmeldung (LQ)'],
            ['status' => 'aktiv', 'is_default' => true, 'created_by_user_id' => null],
        );

        // Nur ein frisch angelegtes Set befüllen — bestehende (ggf. bearbeitete)
        // Sets nicht überschreiben.
        if (! $set->wasRecentlyCreated) {
            return;
        }

        $ranges = [
            [
                'sort_order' => 1,
                'name' => 'Förderbedarf (LQ unter 70)',
                'min_value' => 0,
                'max_value' => 69,
                'template_html' => '<p>Der Lesequotient liegt im Bereich eines besonderen Förderbedarfs '
                    .'(LQ&nbsp;unter&nbsp;70). Eine gezielte Leseförderung wird empfohlen. Bitte sprechen Sie '
                    .'die Ergebnisse mit der Klassen- bzw. Förderlehrkraft durch.</p>',
            ],
            [
                'sort_order' => 2,
                'name' => 'Auffällig (LQ 70–84)',
                'min_value' => 70,
                'max_value' => 84,
                'template_html' => '<p>Der Lesequotient liegt im unteren Bereich (LQ&nbsp;70–84, auffällig). '
                    .'Regelmäßiges Lesetraining und eine weitere Beobachtung der Leseentwicklung sind ratsam.</p>',
            ],
            [
                'sort_order' => 3,
                'name' => 'Im Normbereich (LQ ab 85)',
                'min_value' => 85,
                'max_value' => 200,
                'template_html' => '<p>Der Lesequotient liegt im erwarteten Bereich für die Klassenstufe '
                    .'(LQ&nbsp;ab&nbsp;85). Die Lesegeschwindigkeit ist altersgemäß entwickelt – weiter so!</p>',
            ],
        ];

        foreach ($ranges as $range) {
            FeedbackSetRange::query()->create([
                'feedback_set_id' => $set->id,
                'match_type' => 'lq',
                'is_active' => true,
                ...$range,
            ]);
        }
    }
}
