<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OwnerSetupController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return to_route('login');
        }

        return view('auth.setup');
    }

    public function store(Request $request): RedirectResponse
    {
        if (User::query()->exists()) {
            return to_route('login');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data) {
            if (User::query()->exists()) {
                return null;
            }

            return User::create([...$data, 'role' => 'owner', 'is_active' => true]);
        });

        if (! $user) {
            return to_route('login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard')->with('success', 'Akun pemilik berhasil dibuat.');
    }
}
