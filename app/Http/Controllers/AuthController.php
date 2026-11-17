<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        $authenticatedUser = Auth::user();
        if ($authenticatedUser instanceof User) {
            return to_route($authenticatedUser->role === 'cashier' ? 'kasir' : 'dashboard');
        }

        if (! User::query()->exists()) {
            return to_route('setup');
        }

        return view('auth.login');
    }

    public function store(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($request, $key));

            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam satu menit.',
            ]);
        }

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user = $request->user();
        if ($user instanceof User) {
            $recordActivity->handle($user, 'auth.login', 'Masuk ke aplikasi.');
        }

        $defaultRoute = $user instanceof User && $user->role === 'cashier'
            ? route('kasir')
            : route('dashboard');

        return redirect()->intended($defaultRoute);
    }

    public function destroy(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        if ($request->user() instanceof User) {
            $recordActivity->handle($request->user(), 'auth.logout', 'Keluar dari aplikasi.');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
