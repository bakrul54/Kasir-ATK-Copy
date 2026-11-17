<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Models\ActivityLog;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(): View
    {
        return view('pengguna', [
            'users' => User::query()
                ->withCount(['cashierShifts as open_shifts_count' => fn ($query) => $query->whereNull('closed_at')])
                ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
                ->orderBy('name')
                ->paginate(15),
            'shifts' => CashierShift::query()
                ->with('user:id,name,role')
                ->latest('opened_at')
                ->paginate(10, ['*'], 'shifts_page'),
            'activities' => ActivityLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(30)
                ->get(),
        ]);
    }

    public function store(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'cashier'])],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data, $request, $recordActivity): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'is_active' => true,
                'password' => $data['password'],
            ]);

            $recordActivity->handle(
                $request->user(),
                'user.created',
                "Membuat akun {$user->name} dengan peran {$user->role}.",
                $user,
                ['role' => $user->role],
            );

            return $user;
        });

        return to_route('pengguna')->with('success', "Akun {$user->name} berhasil dibuat.");
    }

    public function update(Request $request, User $user, RecordActivity $recordActivity): RedirectResponse
    {
        if ($user->role === 'owner' || $user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'Akun pemilik dan akun yang sedang digunakan tidak dapat diubah dari halaman ini.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'cashier'])],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $isActive = $request->boolean('is_active');
        $hasOpenShift = $user->cashierShifts()->whereNull('closed_at')->exists();
        if ($hasOpenShift && (! $isActive || $data['role'] !== 'cashier')) {
            throw ValidationException::withMessages([
                'user' => 'Tutup shift kasir yang masih berjalan sebelum menonaktifkan akun atau mengubah perannya.',
            ]);
        }

        $changes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => $isActive,
        ];
        if (! empty($data['password'])) {
            $changes['password'] = $data['password'];
        }

        DB::transaction(function () use ($user, $changes, $request, $recordActivity): void {
            $user->update($changes);
            $recordActivity->handle(
                $request->user(),
                'user.updated',
                "Memperbarui akun {$user->name}.",
                $user,
                ['role' => $user->role, 'is_active' => $user->is_active],
            );
        });

        return to_route('pengguna')->with('success', "Akun {$user->name} berhasil diperbarui.");
    }
}
