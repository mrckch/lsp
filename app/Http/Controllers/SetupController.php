<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Setup\SetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function __construct(private readonly SetupService $setup) {}

    public function show(): View|RedirectResponse
    {
        if ($this->setup->isInitialized()) {
            return redirect('/admin');
        }

        return view('setup.show');
    }

    public function process(Request $request): View|RedirectResponse
    {
        if ($this->setup->isInitialized()) {
            return redirect('/admin');
        }

        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'school_short_name' => ['nullable', 'string', 'max:50'],
            'admin_username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'admin_display_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'confirmed'],
            'clearname_password' => ['required', 'string', 'min:12', 'confirmed'],
            'understand_recovery' => ['required', 'accepted'],
        ]);

        $result = $this->setup->initialize(
            adminUsername: $data['admin_username'],
            adminDisplayName: $data['admin_display_name'],
            adminEmail: $data['admin_email'] ?? null,
            adminPassword: $data['admin_password'],
            schoolName: $data['school_name'],
            schoolShortName: $data['school_short_name'] ?? null,
            clearnamePassword: $data['clearname_password'],
        );

        // Recovery-Key in Flash-Session – wird auf der nächsten Seite einmalig angezeigt
        Session::flash('recovery_key', $result['recovery_key']);

        // Admin direkt einloggen
        Auth::login($result['user']);

        return redirect()->route('setup.recovery');
    }

    public function recovery(): View|RedirectResponse
    {
        if (! Session::has('recovery_key')) {
            return redirect('/admin');
        }

        return view('setup.recovery', [
            'recoveryKey' => Session::get('recovery_key'),
        ]);
    }

    public function recoveryAck(Request $request): RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);

        Session::forget('recovery_key');

        return redirect('/admin');
    }
}
