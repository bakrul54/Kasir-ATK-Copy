<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Models\CashEntry;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftController extends Controller
{
    public function store(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
        ]);

        DB::transaction(function () use ($request, $data, $recordActivity): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if ($user->cashierShifts()->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'shift' => 'Anda sudah memiliki shift yang masih berjalan.',
                ]);
            }

            $shift = CashierShift::create([
                'user_id' => $user->id,
                'opening_cash' => $data['opening_cash'],
                'opened_at' => now(),
            ]);
            $recordActivity->handle(
                $user,
                'shift.opened',
                'Membuka shift dengan kas awal Rp '.number_format($shift->opening_cash, 0, ',', '.').'.',
                $shift,
                ['opening_cash' => $shift->opening_cash],
            );
        });

        return to_route('kasir')->with('success', 'Shift kasir berhasil dibuka.');
    }

    public function close(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $request->validate([
            'closing_cash' => ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
        ]);

        DB::transaction(function () use ($request, $data, $recordActivity): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $shift = CashierShift::query()
                ->whereBelongsTo($user)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw ValidationException::withMessages([
                    'shift' => 'Tidak ada shift berjalan untuk ditutup.',
                ]);
            }

            $cashSales = (int) $shift->sales()->notCancelled()->where('payment_method', 'cash')->sum(DB::raw('total + tip_amount'));
            if ($cashSales > PHP_INT_MAX - $shift->opening_cash) {
                throw ValidationException::withMessages([
                    'closing_cash' => 'Saldo kas yang diharapkan melebihi kapasitas yang dapat dihitung.',
                ]);
            }

            $cashRefunds = (int) CashEntry::query()
                ->where('type', 'keluar')
                ->whereHas('sale', fn ($query) => $query
                    ->whereBelongsTo($shift, 'cashierShift')
                    ->where('payment_method', 'cash'))
                ->sum('amount');
            $cashBeforeRefunds = $shift->opening_cash + $cashSales;
            if ($cashRefunds > $cashBeforeRefunds) {
                throw ValidationException::withMessages([
                    'closing_cash' => 'Refund tunai melebihi saldo kas shift yang dapat dihitung.',
                ]);
            }

            $expectedCash = $cashBeforeRefunds - $cashRefunds;
            $difference = $data['closing_cash'] - $expectedCash;
            $shift->update([
                'expected_cash' => $expectedCash,
                'closing_cash' => $data['closing_cash'],
                'cash_difference' => $difference,
                'closed_at' => now(),
            ]);

            $recordActivity->handle(
                $user,
                'shift.closed',
                'Menutup shift. Selisih kas Rp '.number_format($difference, 0, ',', '.').'.',
                $shift,
                [
                    'expected_cash' => $expectedCash,
                    'closing_cash' => $data['closing_cash'],
                    'cash_difference' => $difference,
                ],
            );
        });

        return to_route('kasir')->with('success', 'Shift ditutup dan rekonsiliasi kas berhasil disimpan.');
    }
}
