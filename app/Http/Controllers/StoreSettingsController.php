<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Models\StoreSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class StoreSettingsController extends Controller
{
    public function index(): View
    {
        return view('pengaturan', [
            'settings' => StoreSetting::current(),
        ]);
    }

    public function update(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]+$/'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'printer_paper_size' => ['required', Rule::in(['58mm', '80mm'])],
            'receipt_header' => ['nullable', 'string', 'max:255'],
            'receipt_footer' => ['nullable', 'string', 'max:255'],
            'receipt_show_logo' => ['nullable', 'boolean'],
            'receipt_auto_print' => ['nullable', 'boolean'],
            'payment_methods' => ['required', 'array', 'min:1', 'max:3'],
            'payment_methods.*' => ['required', 'string', 'distinct', Rule::in(StoreSetting::PAYMENT_METHODS)],
            'payment_information' => ['nullable', 'string', 'max:255'],
            'wholesale_enabled' => ['nullable', 'boolean'],
            'promo_enabled' => ['nullable', 'boolean'],
            'default_minimum_stock' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'units' => ['required', 'array', 'min:1', 'max:20'],
            'units.*' => ['required', 'string', 'max:30', 'distinct'],
        ], [
            'whatsapp.regex' => 'Nomor WhatsApp hanya boleh berisi angka, spasi, dan tanda + ( ) . -.',
            'payment_methods.min' => 'Pilih sedikitnya satu metode pembayaran.',
            'units.min' => 'Simpan sedikitnya satu satuan barang.',
            'units.*.distinct' => 'Setiap satuan barang harus unik.',
            'logo.max' => 'Ukuran logo maksimal 2 MB.',
        ]);

        $settings = StoreSetting::current();
        $oldLogoPath = $settings->logo_path;
        $newLogoPath = null;

        if ($request->hasFile('logo')) {
            $newLogoPath = $request->file('logo')->store('store', 'public');
            if ($newLogoPath === false) {
                throw new RuntimeException('Logo toko gagal disimpan. Periksa konfigurasi penyimpanan publik.');
            }
        }

        $data['units'] = array_values(array_map('trim', $data['units']));
        $data['receipt_show_logo'] = $request->boolean('receipt_show_logo');
        $data['receipt_auto_print'] = $request->boolean('receipt_auto_print');
        $data['wholesale_enabled'] = $request->boolean('wholesale_enabled');
        $data['promo_enabled'] = $request->boolean('promo_enabled');
        $data['logo_path'] = $newLogoPath
            ?? ($request->boolean('remove_logo') ? null : $oldLogoPath);
        unset($data['logo'], $data['remove_logo']);

        DB::transaction(function () use ($settings, $data, $request, $recordActivity): void {
            $settings->update($data);
            $recordActivity->handle(
                $request->user(),
                'store_settings.updated',
                "Memperbarui pengaturan toko {$settings->store_name}.",
                $settings,
                [
                    'payment_methods' => $settings->payment_methods,
                    'default_minimum_stock' => $settings->default_minimum_stock,
                ],
            );
        });

        $logoWasChanged = $oldLogoPath !== $settings->logo_path;
        if ($logoWasChanged && $oldLogoPath && Storage::disk('public')->exists($oldLogoPath)) {
            if (! Storage::disk('public')->delete($oldLogoPath)) {
                throw new RuntimeException('Logo lama gagal dihapus dari penyimpanan publik.');
            }
        }

        return to_route('pengaturan')->with('success', 'Pengaturan toko berhasil disimpan.');
    }
}
