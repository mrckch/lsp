<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\OnboardingService;
use App\Domain\Mail\MailService;
use App\Domain\Mail\Models\MailMessage;
use App\Filament\Pages\ForcePasswordChange;
use App\Jobs\SendWelcomeMailJob;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'BSP']);
    }

    private function makeUser(?string $email = 'lehrer@example.com'): User
    {
        return User::create([
            'username' => 'lehrer', 'display_name' => 'Lehrer L.',
            'email' => $email, 'password' => Hash::make('initial-tmp-pw-1234'),
            'is_active' => true,
        ]);
    }

    #[Test]
    public function generated_initial_password_has_expected_format(): void
    {
        $pw = (new OnboardingService)->generateInitialPassword();
        // 16 Zeichen + 3 Bindestriche zwischen 4er-Blöcken = 19
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}$/',
            $pw,
        );
    }

    #[Test]
    public function mark_for_forced_change_sets_flag_and_clears_password_changed_at(): void
    {
        $user = $this->makeUser();
        $user->update(['password_changed_at' => now()->subYear()]);

        (new OnboardingService)->markForForcedChange($user, Hash::make('init-pw-1234567890'));
        $user->refresh();

        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
    }

    #[Test]
    public function apply_chosen_password_clears_flag_and_sets_timestamp(): void
    {
        $user = $this->makeUser();
        $user->update(['must_change_password' => true]);

        (new OnboardingService)->applyChosenPassword($user, 'mein-neues-pw-12345');
        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('mein-neues-pw-12345', $user->password));
    }

    #[Test]
    public function welcome_mail_job_dispatches_to_mail_queue(): void
    {
        Bus::fake();
        $user = $this->makeUser();

        SendWelcomeMailJob::dispatch($user->id, 'INIT-PW01-XYZA-BC23', null);

        Bus::assertDispatched(SendWelcomeMailJob::class, function ($job) use ($user) {
            return $job->newUserId === $user->id
                && $job->initialPassword === 'INIT-PW01-XYZA-BC23'
                && $job->queue === 'mail';
        });
    }

    #[Test]
    public function welcome_mail_handle_persists_mail_message_with_initial_password(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $job = new SendWelcomeMailJob($user->id, 'INIT-PW01-XYZA-BC23', null);
        $job->handle(app(MailService::class));

        $msg = MailMessage::query()->first();
        $this->assertNotNull($msg);
        $this->assertEquals('sent', $msg->status);
        $this->assertStringContainsString('INIT-PW01-XYZA-BC23', $msg->body_html);
        $this->assertEquals('user', $msg->related_entity_type);
        $this->assertEquals($user->id, $msg->related_entity_id);
    }

    #[Test]
    public function welcome_mail_skips_when_user_has_no_email(): void
    {
        Mail::fake();
        $user = $this->makeUser(email: null);

        $job = new SendWelcomeMailJob($user->id, 'X', null);
        $job->handle(app(MailService::class));

        $this->assertEquals(0, MailMessage::count());
    }

    #[Test]
    public function force_change_page_can_access_only_when_flag_set(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->assertFalse(ForcePasswordChange::canAccess());

        $user->update(['must_change_password' => true]);
        $this->actingAs($user->refresh());
        $this->assertTrue(ForcePasswordChange::canAccess());
    }

    #[Test]
    public function middleware_redirects_when_flag_set(): void
    {
        $user = $this->makeUser();
        $user->update(['must_change_password' => true]);

        $this->actingAs($user);
        // Beliebige Filament-Seite → Redirect zur Force-Change-Page
        $response = $this->get('/admin');
        $response->assertRedirect('/admin/force-password-change');
    }

    #[Test]
    public function middleware_does_not_redirect_when_flag_unset(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        // Filament-Dashboard ist vorhanden – Middleware lässt durch (kein Redirect zu force-password-change)
        $response = $this->get('/admin');
        $response->assertStatus(200);
    }
}
