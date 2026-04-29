<?php

declare(strict_types=1);

namespace Tests\Feature\Print;

use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Domain\PrintJob\Models\PrintJob;
use App\Domain\PrintJob\PrintJobRunner;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrintJobRunnerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::create([
            'username' => 'u', 'display_name' => 'U',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);
    }

    #[Test]
    public function it_replaces_simple_variables(): void
    {
        $runner = new PrintJobRunner($this->fakeGotenberg());
        $html = $runner->renderTemplate('Hallo {{name}}!', ['name' => 'Welt']);
        $this->assertEquals('Hallo Welt!', $html);
    }

    #[Test]
    public function it_escapes_variables_to_prevent_xss(): void
    {
        $runner = new PrintJobRunner($this->fakeGotenberg());
        $html = $runner->renderTemplate('{{x}}', ['x' => '<script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function it_supports_dot_notation_for_nested_data(): void
    {
        $runner = new PrintJobRunner($this->fakeGotenberg());
        $html = $runner->renderTemplate('{{student.name}}', ['student' => ['name' => 'Anna']]);
        $this->assertEquals('Anna', $html);
    }

    #[Test]
    public function run_creates_generated_document_and_marks_done(): void
    {
        $tpl = PrintTemplate::create(['key' => 't', 'name' => 'Test', 'type' => 'feedback', 'is_system' => false]);
        $version = PrintTemplateVersion::create([
            'print_template_id' => $tpl->id, 'version_number' => 1,
            'html_content' => '<h1>{{title}}</h1>', 'css_content' => 'h1{color:red}',
            'created_by_user_id' => $this->user->id,
        ]);
        $tpl->update(['current_version_id' => $version->id]);

        $job = PrintJob::create([
            'print_template_version_id' => $version->id,
            'context_type' => 'custom', 'context_id' => null,
            'parameters' => ['title' => 'Hallo'],
            'status' => 'pending',
            'requested_by_user_id' => $this->user->id,
            'requested_at' => now(),
        ]);

        $runner = new PrintJobRunner($this->fakeGotenberg('FAKE-PDF-BYTES'));
        $job = $runner->run($job);

        $this->assertEquals('done', $job->status);
        $this->assertNotNull($job->output_document_id);
        $doc = GeneratedDocument::find($job->output_document_id);
        $this->assertNotNull($doc);
        $this->assertEquals('application/pdf', $doc->mime_type);
    }

    #[Test]
    public function run_marks_failed_on_gotenberg_error(): void
    {
        $tpl = PrintTemplate::create(['key' => 't2', 'name' => 'Test2', 'type' => 'feedback', 'is_system' => false]);
        $version = PrintTemplateVersion::create([
            'print_template_id' => $tpl->id, 'version_number' => 1,
            'html_content' => 'X', 'created_by_user_id' => $this->user->id,
        ]);

        $job = PrintJob::create([
            'print_template_version_id' => $version->id,
            'context_type' => 'custom',
            'parameters' => [],
            'status' => 'pending',
            'requested_by_user_id' => $this->user->id,
            'requested_at' => now(),
        ]);

        $broken = new class('http://x') extends GotenbergClient
        {
            public function htmlToPdf(string $html, ?string $css = null, array $options = []): string
            {
                throw new \RuntimeException('Gotenberg down');
            }
        };

        $runner = new PrintJobRunner($broken);
        $job = $runner->run($job);
        $this->assertEquals('failed', $job->status);
        $this->assertStringContainsString('Gotenberg down', $job->error_message);
    }

    private function fakeGotenberg(string $body = 'PDFBYTES'): GotenbergClient
    {
        return new class('http://x', $body) extends GotenbergClient
        {
            public function __construct(string $url, private readonly string $body = 'PDFBYTES')
            {
                parent::__construct($url);
            }

            public function htmlToPdf(string $html, ?string $css = null, array $options = []): string
            {
                return $this->body;
            }
        };
    }
}
