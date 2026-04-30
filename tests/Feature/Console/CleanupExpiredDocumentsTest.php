<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\PrintJob\Models\GeneratedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupExpiredDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeDoc(string $name, ?\DateTimeInterface $expiresAt, bool $createFile = true): GeneratedDocument
    {
        if ($createFile) {
            Storage::disk('local')->put('lsp/print-jobs/'.$name, 'PDFBYTES');
        }

        $doc = GeneratedDocument::create([
            'file_name' => $name,
            'file_path' => 'lsp/print-jobs/'.$name,
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'includes_clearnames' => false,
            'sha256' => str_repeat('a', 64),
            'expires_at' => $expiresAt,
        ]);

        return $doc;
    }

    #[Test]
    public function it_does_nothing_when_no_documents_expired(): void
    {
        $this->makeDoc('future.pdf', now()->addDays(10));

        $this->artisan('documents:cleanup')
            ->expectsOutputToContain('Keine abgelaufenen')
            ->assertSuccessful();

        $this->assertEquals(1, GeneratedDocument::count());
        Storage::disk('local')->assertExists('lsp/print-jobs/future.pdf');
    }

    #[Test]
    public function it_deletes_expired_documents_and_files(): void
    {
        $this->makeDoc('old1.pdf', now()->subDays(2));
        $this->makeDoc('old2.zip', now()->subDay());
        $kept = $this->makeDoc('keep.pdf', now()->addDay());

        $this->artisan('documents:cleanup')->assertSuccessful();

        $this->assertEquals(1, GeneratedDocument::count());
        $this->assertNotNull(GeneratedDocument::find($kept->id));
        Storage::disk('local')->assertMissing('lsp/print-jobs/old1.pdf');
        Storage::disk('local')->assertMissing('lsp/print-jobs/old2.zip');
        Storage::disk('local')->assertExists('lsp/print-jobs/keep.pdf');
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $this->makeDoc('old.pdf', now()->subDay());

        $this->artisan('documents:cleanup --dry-run')
            ->expectsOutputToContain('Dry-Run')
            ->assertSuccessful();

        $this->assertEquals(1, GeneratedDocument::count());
        Storage::disk('local')->assertExists('lsp/print-jobs/old.pdf');
    }

    #[Test]
    public function it_writes_audit_log_entry(): void
    {
        $this->makeDoc('old.pdf', now()->subDay());
        $this->artisan('documents:cleanup')->assertSuccessful();

        $audit = AuditLog::query()->where('action', 'documents.cleanup')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('system', $audit->actor_type);
        $this->assertEquals(1, $audit->context['deleted']);
    }

    #[Test]
    public function it_handles_missing_files_gracefully(): void
    {
        $this->makeDoc('exists.pdf', now()->subDay(), createFile: true);
        $this->makeDoc('missing.pdf', now()->subDay(), createFile: false);

        $this->artisan('documents:cleanup')->assertSuccessful();

        $this->assertEquals(0, GeneratedDocument::count());
        $audit = AuditLog::query()->where('action', 'documents.cleanup')->first();
        $this->assertEquals(2, $audit->context['deleted']);
        $this->assertEquals(1, $audit->context['missing_files']);
    }
}
