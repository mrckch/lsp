<?php

declare(strict_types=1);

namespace App\Domain\PrintJob;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Erzeugt ein druckfertiges A4-Kartenblatt mit ausschneidbaren Zugangs-Karten
 * (Visitenkarten-Format) für einen Testdurchlauf.
 *
 * Jede Karte: Klasse, Name, 10-stelliger Login-Code und ein persönlicher
 * QR-Code, der direkt auf den Test mit vorbefülltem Code zeigt
 * (`<APP_URL>/t?code=…`) – ideal für die Durchführung am iPad.
 *
 * Das Raster ist **auf jeder Seite geometrisch identisch** (feste Ränder und
 * Kartengröße, Schnittlinien an denselben Koordinaten). So lassen sich alle
 * Seiten einer Klasse stapeln und in einem Durchgang am Schneidgerät trennen.
 */
final class LoginCardSheetGenerator
{
    // A4 in mm
    private const PAGE_W = 210.0;

    private const PAGE_H = 297.0;

    private const MARGIN = 8.0;      // Außenrand (Platz für Schnittmarken)

    private const COLS = 2;

    private const ROWS = 5;          // 10 Karten pro Seite

    private const TICK = 4.0;        // Länge der Schnittmarken in mm

    /**
     * @param  list<array{name: string, group: string, code: string}>  $cards
     */
    public function html(string $schoolName, string $runName, array $cards, string $appUrl): string
    {
        $cardW = (self::PAGE_W - 2 * self::MARGIN) / self::COLS;
        $cardH = (self::PAGE_H - 2 * self::MARGIN) / self::ROWS;
        $perPage = self::COLS * self::ROWS;
        $pages = array_chunk($cards, $perPage);

        $qr = $this->qrWriter();
        $base = rtrim($appUrl, '/');

        $pagesHtml = '';
        foreach ($pages as $pageCards) {
            $pagesHtml .= $this->page($pageCards, $cardW, $cardH, $schoolName, $runName, $base, $qr);
        }

        $css = $this->css($cardW, $cardH);

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'.$css.'</style></head><body>'
            .$pagesHtml.'</body></html>';
    }

    /**
     * @param  list<array{name: string, group: string, code: string}>  $cards
     */
    private function page(array $cards, float $cardW, float $cardH, string $schoolName, string $runName, string $base, Writer $qr): string
    {
        $cells = '';
        foreach ($cards as $i => $card) {
            $col = $i % self::COLS;
            $row = intdiv($i, self::COLS);
            $x = self::MARGIN + $col * $cardW;
            $y = self::MARGIN + $row * $cardH;
            $cells .= $this->card($card, $x, $y, $cardW, $cardH, $schoolName, $runName, $base, $qr);
        }

        return '<div class="page">'.$this->cropMarks($cardW, $cardH).$cells.'</div>';
    }

    /**
     * @param  array{name: string, group: string, code: string}  $card
     */
    private function card(array $card, float $x, float $y, float $w, float $h, string $schoolName, string $runName, string $base, Writer $qr): string
    {
        $url = self::loginUrl($base, $card['code']);
        $svg = $this->qrSvg($qr, $url);
        $name = e($card['name'] !== '' ? $card['name'] : '—');
        $group = e($card['group']);
        $code = e($card['code']);
        $school = e($schoolName);

        return sprintf(
            '<div class="card" style="left:%.2fmm;top:%.2fmm;width:%.2fmm;height:%.2fmm;">'
            .'<div class="qr">%s</div>'
            .'<div class="info">'
            .'<div class="school">%s</div>'
            .'<div class="name">%s</div>'
            .'<div class="group">Klasse %s</div>'
            .'<div class="code">%s</div>'
            .'<div class="hint">QR scannen &rarr; Test startet</div>'
            .'</div></div>',
            $x, $y, $w, $h, $svg, $school, $name, $group, $code,
        );
    }

    private function cropMarks(float $cardW, float $cardH): string
    {
        $marks = '';
        // Vertikale Schnittlinien-Positionen (Spaltenränder inkl. außen)
        $xs = [];
        for ($c = 0; $c <= self::COLS; $c++) {
            $xs[] = self::MARGIN + $c * $cardW;
        }
        // Horizontale Schnittlinien-Positionen (Zeilenränder inkl. außen)
        $ys = [];
        for ($r = 0; $r <= self::ROWS; $r++) {
            $ys[] = self::MARGIN + $r * $cardH;
        }

        // Ecken-Schnittmarken je Kreuzungspunkt (kurze Striche in den Rand hinein)
        foreach ($xs as $x) {
            foreach ($ys as $y) {
                // vertikaler Tick
                $marks .= sprintf('<div class="tick v" style="left:%.2fmm;top:%.2fmm;height:%.2fmm;"></div>', $x, $y - self::TICK / 2, self::TICK);
                // horizontaler Tick
                $marks .= sprintf('<div class="tick h" style="left:%.2fmm;top:%.2fmm;width:%.2fmm;"></div>', $x - self::TICK / 2, $y, self::TICK);
            }
        }

        return $marks;
    }

    /**
     * Direkt-Login-URL eines Codes (Ziel des QR-Codes).
     */
    public static function loginUrl(string $appUrl, string $code): string
    {
        return rtrim($appUrl, '/').'/t?code='.rawurlencode($code);
    }

    /**
     * Einzelner QR-Code als Inline-SVG (z. B. für die Code-Anzeige am Bildschirm).
     */
    public function qrSvgForUrl(string $url): string
    {
        return $this->qrSvg($this->qrWriter(), $url);
    }

    private function qrWriter(): Writer
    {
        return new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));
    }

    private function qrSvg(Writer $writer, string $url): string
    {
        $svg = $writer->writeString($url);
        // XML-Deklaration entfernen, damit das SVG inline eingebettet werden kann
        $svg = preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg;

        return trim($svg);
    }

    private function css(float $cardW, float $cardH): string
    {
        return sprintf(<<<'CSS'
            @page { size: A4; margin: 0; }
            * { box-sizing: border-box; }
            body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #111827; }
            .page { position: relative; width: 210mm; height: 297mm; page-break-after: always; overflow: hidden; }
            .page:last-child { page-break-after: auto; }
            .card {
                position: absolute; border: 0.2mm solid #9ca3af;
                padding: 3mm; display: flex; align-items: center; gap: 3mm;
            }
            .card .qr { width: %1$.2fmm; height: %1$.2fmm; flex: none; }
            .card .qr svg { width: 100%%; height: 100%%; display: block; }
            .card .info { flex: 1; min-width: 0; }
            .card .school { font-size: 7pt; color: #6b7280; }
            .card .name { font-size: 12pt; font-weight: bold; margin-top: 1mm; }
            .card .group { font-size: 8pt; color: #374151; }
            .card .code { font-family: Courier, monospace; font-size: 15pt; font-weight: bold; letter-spacing: 1px; margin-top: 2mm; color: #1e3a8a; }
            .card .hint { font-size: 6.5pt; color: #9ca3af; margin-top: 1.5mm; }
            .tick { position: absolute; background: #111827; }
            .tick.v { width: 0.2mm; }
            .tick.h { height: 0.2mm; }
            CSS,
            min($cardH - 6.0, 34.0),
        );
    }
}
