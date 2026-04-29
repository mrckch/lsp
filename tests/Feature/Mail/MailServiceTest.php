<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Mail\MailService;
use App\Domain\Mail\Models\MailMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'username' => 'u', 'display_name' => 'U',
            'password' => Hash::make('pw-12345678901'), 'is_active' => true,
        ]);
    }

    #[Test]
    public function it_logs_mail_message_on_send(): void
    {
        Mail::fake();

        $service = app(MailService::class);
        $msg = $service->send([
            'to' => ['eltern@example.com'],
            'subject' => 'Test',
            'body_html' => '<p>Hallo</p>',
            'includes_clearnames' => false,
        ], $this->user->id);

        $this->assertEquals('sent', $msg->status);
        $this->assertEquals(1, MailMessage::count());
    }

    #[Test]
    public function it_marks_failed_on_exception(): void
    {
        // Wir mocken den Mail-Send so, dass er failt
        Mail::shouldReceive('send')->andThrow(new \Exception('SMTP down'));

        $service = app(MailService::class);
        $msg = $service->send([
            'to' => ['x@example.com'],
            'subject' => 'Fehlt',
            'body_html' => 'X',
        ], $this->user->id);

        $this->assertEquals('failed', $msg->status);
        $this->assertStringContainsString('SMTP down', $msg->error_message);
    }
}
