<?php

namespace App\Http\Controllers;

use App\Actions\CreateStoreBackup;
use App\Actions\RestoreStoreBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StoreBackupController extends Controller
{
    public function download(CreateStoreBackup $createStoreBackup): BinaryFileResponse
    {
        $backup = $createStoreBackup->handle();

        return response()
            ->download($backup['path'], $backup['filename'], ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    public function restore(Request $request, RestoreStoreBackup $restoreStoreBackup): RedirectResponse
    {
        $data = $request->validate([
            'backup_file' => ['required', 'file', 'mimes:zip', 'max:51200'],
            'confirmation' => ['required', 'string', Rule::in(['PULIHKAN'])],
        ], [
            'backup_file.max' => 'Ukuran file backup maksimal 50 MB.',
            'confirmation.in' => 'Ketik PULIHKAN untuk mengonfirmasi restore penuh.',
        ]);

        $restoreStoreBackup->handle($data['backup_file']);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('success', 'Backup berhasil dipulihkan. Silakan masuk kembali.');
    }
}
