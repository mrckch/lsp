<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\TwoFactorService;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\LoginTwoFactor;
use App\Http\Middleware\EnforceLoginTwoFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use ReflectionMethod;
use Tests\TestCase;

class LoginTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(bool $twoFactor = false): User
    {
        $user = User::create([
            'username' => 'lehrer1',
            'display_name' => 'Lehrer Eins',
            'email' => 'lehrer1@schule.de',
            'password' => Hash::make('pw-1234567890'),
            'is_active' => true,
            'two_factor_enabled' => $twoFactor,
        ]);

        return $user;
    }

    #[Test]
    public function login_maps_email_or_username_to_the_right_column(): void
    {
        $page = new Login;
        $method = new ReflectionMethod($page, 'getCredentialsFromFormData');
        $method->setAccessible(true);

        $byName = $method->invoke($page, ['email' => 'lehrer1', 'password' => 'x']);
        $this->assertSame(['username' => 'lehrer1', 'password' => 'x'], $byName);

        $byMail = $method->invoke($page, ['email' => 'lehrer1@schule.de', 'password' => 'x']);
        $this->assertSame(['email' => 'lehrer1@schule.de', 'password' => 'x'], $byMail);
    }

    #[Test]
    public function gate_redirects_a_two_factor_user_without_confirmation(): void
    {
        $user = $this->makeUser(twoFactor: true);
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());

        $response = (new EnforceLoginTwoFactor)->handle($request, fn () => new Response('OK'));

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('/admin/login-two-factor', $response->headers->get('Location'));
    }

    #[Test]
    public function gate_lets_through_once_confirmed_and_users_without_2fa(): void
    {
        $withFlag = Request::create('/admin', 'GET');
        $withFlag->setUserResolver(fn () => $this->makeUser(twoFactor: true));
        $session = $this->app['session']->driver();
        $session->put('login_2fa_ok', true);
        $withFlag->setLaravelSession($session);
        $this->assertEquals('OK', (new EnforceLoginTwoFactor)->handle($withFlag, fn () => new Response('OK'))->getContent());

        $noTwoFactor = Request::create('/admin', 'GET');
        $noTwoFactor->setUserResolver(fn () => User::create([
            'username' => 'ohne2fa', 'display_name' => 'Ohne', 'password' => Hash::make('pw-1234567890'),
            'is_active' => true, 'two_factor_enabled' => false,
        ]));
        $noTwoFactor->setLaravelSession($this->app['session']->driver());
        $this->assertEquals('OK', (new EnforceLoginTwoFactor)->handle($noTwoFactor, fn () => new Response('OK'))->getContent());
    }

    #[Test]
    public function challenge_confirms_a_valid_code_and_opens_the_session(): void
    {
        $user = $this->makeUser();
        $result = app(TwoFactorService::class)->startEnrollment($user, 'LSP');
        $this->assertTrue(app(TwoFactorService::class)->confirmEnrollment($user, app(Google2FA::class)->getCurrentOtp($result['secret'])));
        session()->forget('login_2fa_ok');

        $validCode = app(Google2FA::class)->getCurrentOtp($result['secret']);

        Livewire::actingAs($user->refresh())
            ->test(LoginTwoFactor::class)
            ->fillForm(['code' => $validCode])
            ->call('verify')
            ->assertRedirect('/admin');

        $this->assertTrue(session('login_2fa_ok'));
    }

    #[Test]
    public function challenge_rejects_a_wrong_code(): void
    {
        $user = $this->makeUser();
        $result = app(TwoFactorService::class)->startEnrollment($user, 'LSP');
        app(TwoFactorService::class)->confirmEnrollment($user, app(Google2FA::class)->getCurrentOtp($result['secret']));
        session()->forget('login_2fa_ok');

        Livewire::actingAs($user->refresh())
            ->test(LoginTwoFactor::class)
            ->fillForm(['code' => '000000'])
            ->call('verify');

        $this->assertNotTrue(session('login_2fa_ok'));
    }
}
