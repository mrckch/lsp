<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Mail\MailService;
use App\Jobs\Concerns\LogsFailureToAudit;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Versendet die Welcome-Mail mit Initial-Passwort und Login-Link
 * an einen frisch angelegten User.
 *
 * Das Klartext-Passwort wird ausschließlich als Konstruktor-Argument
 * übergeben (nie persistiert) und nur in dieser einen Mail verwendet.
 */
class SendWelcomeMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use LogsFailureToAudit;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public readonly int $newUserId,
        public readonly string $initialPassword,
        public readonly ?int $issuedByUserId,
    ) {
        $this->onQueue('mail');
    }

    protected function failureContext(): array
    {
        return ['new_user_id' => $this->newUserId, 'kind' => 'welcome_mail'];
    }

    public function handle(MailService $mail): void
    {
        $user = User::query()->find($this->newUserId);
        if ($user === null || $user->email === null) {
            return;
        }

        $school = AppSetting::singleton()->school_name ?? 'LSP';
        $loginUrl = config('app.url').'/admin/login';

        $body = view('emails.welcome', [
            'user' => $user,
            'initialPassword' => $this->initialPassword,
            'loginUrl' => $loginUrl,
            'schoolName' => $school,
        ])->render();

        $mail->send([
            'to' => [$user->email],
            'subject' => "Ihr LSP-Zugang ($school)",
            'body_html' => $body,
            'related_entity_type' => 'user',
            'related_entity_id' => $user->id,
            'includes_clearnames' => false,
        ], $this->issuedByUserId);
    }
}
