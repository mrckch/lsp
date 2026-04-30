<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\SupportListCsvExporter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportListCsvExporterTest extends TestCase
{
    private function rows(): array
    {
        return [
            [
                'student_code' => 'BSP-00001',
                'student_name' => 'Anna Müller',
                'group' => '5a', 'grade_level' => '5', 'date' => '15.04.2026',
                'lq' => 60, 'severity' => 'foerderbedarf', 'threshold_name' => 'LQ < 70',
            ],
            [
                'student_code' => 'BSP-00002',
                'student_name' => 'Bob Schmidt',
                'group' => '5b', 'grade_level' => '5', 'date' => '15.04.2026',
                'lq' => 80, 'severity' => 'auffaellig', 'threshold_name' => 'LQ < 85',
            ],
        ];
    }

    #[Test]
    public function csv_starts_with_utf8_bom(): void
    {
        $csv = (new SupportListCsvExporter)->toCsv($this->rows());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    #[Test]
    public function csv_uses_semicolon_separator_with_header_row(): void
    {
        $csv = (new SupportListCsvExporter)->toCsv($this->rows());
        $lines = explode("\n", trim(substr($csv, 3))); // BOM weg

        $this->assertEquals('Code;Name;Klasse;Stufe;"Letzter Test";LQ;Schweregrad;Schwelle', trim($lines[0]));
    }

    #[Test]
    public function csv_contains_all_rows_with_translated_severity(): void
    {
        $csv = (new SupportListCsvExporter)->toCsv($this->rows());

        $this->assertStringContainsString('BSP-00001;"Anna Müller";5a;5;15.04.2026;60;Förderbedarf;"LQ < 70"', $csv);
        $this->assertStringContainsString('BSP-00002;"Bob Schmidt";5b;5;15.04.2026;80;auffällig;"LQ < 85"', $csv);
    }

    #[Test]
    public function empty_rows_produce_csv_with_only_header(): void
    {
        $csv = (new SupportListCsvExporter)->toCsv([]);
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('Code;Name', $lines[0]);
    }

    #[Test]
    public function null_lq_is_emitted_as_empty_field(): void
    {
        $csv = (new SupportListCsvExporter)->toCsv([
            ['student_code' => 'X-1', 'student_name' => 'Test',
                'group' => '5a', 'grade_level' => '5', 'date' => '01.01.2026',
                'lq' => null, 'severity' => 'hinweis', 'threshold_name' => 'X'],
        ]);
        $this->assertStringContainsString('X-1;Test;5a;5;01.01.2026;;Hinweis;X', $csv);
    }
}
