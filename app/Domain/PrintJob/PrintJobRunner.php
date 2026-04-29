<?php

declare(strict_types=1);

namespace App\Domain\PrintJob;

use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Domain\PrintJob\Models\PrintJob;
use Illuminate\Support\Facades\Storage;

/**
 * Render eines Print-Jobs:
 *  1. Template-Version laden
 *  2. Variablen ersetzen (einfaches {{var}}-Replacement; in Phase 5 ggf. Twig)
 *  3. Gotenberg → PDF
 *  4. Als generated_document persistieren
 */
final class PrintJobRunner
{
    public function __construct(private readonly GotenbergClient $gotenberg) {}

    public function run(PrintJob $job): PrintJob
    {
        $job->update(['status' => 'running', 'started_at' => now()]);
        try {
            $version = $job->templateVersion;
            if ($version === null) {
                throw new \RuntimeException('Template-Version fehlt.');
            }

            $vars = $job->parameters ?? [];
            $html = $this->renderTemplate($version->html_content, $vars);

            $pdf = $this->gotenberg->htmlToPdf($html, $version->css_content);

            $disk = Storage::disk('local');
            $filename = 'pdf_'.now()->format('Ymd_His').'_'.$job->id.'.pdf';
            $path = 'lsp/print-jobs/'.$filename;
            $disk->put($path, $pdf);

            $doc = GeneratedDocument::create([
                'file_name' => $filename,
                'file_path' => $path,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'includes_clearnames' => (bool) ($vars['_includes_clearnames'] ?? false),
                'sha256' => hash('sha256', $pdf),
                'expires_at' => now()->addDays((int) config('lsp.pdf.document_retention_days', 30)),
                'created_by_user_id' => $job->requested_by_user_id,
            ]);

            $job->update([
                'status' => 'done',
                'finished_at' => now(),
                'output_document_id' => $doc->id,
            ]);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }

        return $job->refresh();
    }

    /**
     * Sehr einfaches {{var}}-Replacement.
     * Verschachtelte Strukturen (Listen) als JSON dump in Container.
     */
    public function renderTemplate(string $html, array $vars): string
    {
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', function ($m) use ($vars) {
            $key = $m[1];
            $value = data_get($vars, $key);
            if ($value === null) {
                return '';
            }
            if (is_array($value)) {
                return e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            return e((string) $value);
        }, $html) ?? $html;
    }
}
