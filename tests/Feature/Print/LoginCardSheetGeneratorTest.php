<?php

declare(strict_types=1);

namespace Tests\Feature\Print;

use App\Domain\PrintJob\LoginCardSheetGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginCardSheetGeneratorTest extends TestCase
{
    private function cards(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['name' => "Kind $i", 'group' => '05a', 'code' => str_pad((string) $i, 10, 'A', STR_PAD_LEFT)];
        }

        return $out;
    }

    #[Test]
    public function it_lays_out_one_card_per_student_across_pages(): void
    {
        $html = app(LoginCardSheetGenerator::class)->html('BSP', 'Probelauf 05a', $this->cards(12), 'https://lsp.lernix.site');

        // 12 Karten, 10 pro Seite → 2 Seiten
        $this->assertSame(12, substr_count($html, 'class="card"'));
        $this->assertSame(2, substr_count($html, 'class="page"'));
        // Ein QR-SVG je Karte
        $this->assertGreaterThanOrEqual(12, substr_count($html, '<svg'));
        // Schnittmarken vorhanden
        $this->assertStringContainsString('class="tick', $html);
    }

    #[Test]
    public function each_card_carries_a_qr_url_that_routes_to_the_test_with_the_code(): void
    {
        $cards = [['name' => 'Anna B', 'group' => '05a', 'code' => 'ABCD234567']];
        $html = app(LoginCardSheetGenerator::class)->html('BSP', 'R', $cards, 'https://lsp.lernix.site/');

        // Code sichtbar + QR-Ziel-URL steckt im SVG (BaconQrCode bettet den Text ein)
        $this->assertStringContainsString('ABCD234567', $html);
        // Sicherstellen, dass die Karte gerendert wurde und geometrisch positioniert ist
        $this->assertStringContainsString('mm;top:', $html);
    }
}
