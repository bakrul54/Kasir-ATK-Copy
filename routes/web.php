<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashierShiftController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EtalaseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\OwnerSetupController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PriceManagementController;
use App\Http\Controllers\PrintPricingController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\StoreBackupController;
use App\Http\Controllers\StoreSettingsController;
use App\Http\Controllers\UserManagementController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->to(User::query()->exists() ? '/login' : '/setup'));

Route::get('/setup', [OwnerSetupController::class, 'create'])->name('setup');
Route::post('/setup', [OwnerSetupController::class, 'store'])->name('setup.store');
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');

Route::middleware('auth')->group(function () {
    Route::middleware('active')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('role:owner,admin')
            ->name('dashboard');

        Route::post('/kasir/shift', [CashierShiftController::class, 'store'])
            ->middleware('role:cashier')
            ->name('kasir.shifts.store');
        Route::post('/kasir/shift/tutup', [CashierShiftController::class, 'close'])
            ->middleware('role:cashier')
            ->name('kasir.shifts.close');
        Route::post('/kasir', [PosController::class, 'store'])->name('kasir.store');
        Route::post('/kasir/{sale}/batalkan', [PosController::class, 'cancel'])
            ->middleware('role:owner,admin')
            ->name('kasir.cancel');
        Route::post('/kasir/{sale}/refund', [PosController::class, 'refund'])
            ->middleware('role:owner,admin')
            ->name('kasir.refund');
        Route::get('/kasir/{sale}/detail', [PosController::class, 'show'])->name('kasir.detail');
        Route::get('/kasir/{sale}/struk', [PosController::class, 'receipt'])->name('kasir.receipt');
        Route::get('/kasir', [PosController::class, 'index'])->name('kasir');
        Route::get('/hitung-print', [PrintPricingController::class, 'index'])->name('print-pricing');

        Route::middleware('role:owner,admin')->group(function (): void {
            Route::get('/etalase', [EtalaseController::class, 'index'])->name('etalase');
            Route::get('/etalase/tambah', [EtalaseController::class, 'create'])->name('etalase.create');
            Route::post('/etalase', [EtalaseController::class, 'store'])->name('etalase.store');
            Route::get('/etalase/{product}/ubah', [EtalaseController::class, 'edit'])->name('etalase.edit');
            Route::put('/etalase/{product}', [EtalaseController::class, 'update'])->name('etalase.update');
            Route::delete('/etalase/{product}', [EtalaseController::class, 'destroy'])->name('etalase.destroy');
            Route::post('/etalase/kategori', [EtalaseController::class, 'storeCategory'])->name('etalase.categories.store');
            Route::delete('/etalase/kategori/{category}', [EtalaseController::class, 'destroyCategory'])->name('etalase.categories.destroy');
            Route::get('/harga', [PriceManagementController::class, 'index'])->name('harga');
            Route::patch('/harga/{product}', [PriceManagementController::class, 'update'])->name('harga.update');
            Route::post('/harga/grup-grosir', [PriceManagementController::class, 'storeWholesaleGroup'])->name('harga.wholesale-groups.store');
            Route::patch('/harga/grup-grosir/{wholesaleGroup}', [PriceManagementController::class, 'updateWholesaleGroup'])->name('harga.wholesale-groups.update');
            Route::delete('/harga/grup-grosir/{wholesaleGroup}', [PriceManagementController::class, 'destroyWholesaleGroup'])->name('harga.wholesale-groups.destroy');
            Route::get('/harga/{product}/riwayat', [PriceManagementController::class, 'history'])->name('harga.history');
            Route::post('/stok/pergerakan', [StockManagementController::class, 'storeMovement'])->name('stok.movements.store');
            Route::get('/stok', [StockManagementController::class, 'index'])->name('stok');
            Route::post('/pembelian/supplier', [PurchaseController::class, 'storeSupplier'])->name('pembelian.suppliers.store');
            Route::post('/pembelian', [PurchaseController::class, 'store'])->name('pembelian.store');
            Route::get('/pembelian/{purchase}', [PurchaseController::class, 'show'])->name('pembelian.show');
            Route::get('/pembelian', [PurchaseController::class, 'index'])->name('pembelian');
            Route::post('/keuangan', [FinanceController::class, 'store'])->name('keuangan.store');
            Route::get('/keuangan', [FinanceController::class, 'index'])->name('keuangan');
            Route::post('/pelanggan', [CustomerController::class, 'store'])->name('pelanggan.store');
            Route::get('/pelanggan/{customer}', [CustomerController::class, 'show'])->name('pelanggan.show');
            Route::put('/pelanggan/{customer}', [CustomerController::class, 'update'])->name('pelanggan.update');
            Route::get('/pelanggan', [CustomerController::class, 'index'])->name('pelanggan');
            Route::get('/laporan/export', [ReportController::class, 'export'])->name('laporan.export');
            Route::get('/laporan/cetak', [ReportController::class, 'print'])->name('laporan.print');
            Route::get('/laporan', [ReportController::class, 'index'])->name('laporan');
        });

        Route::middleware('role:owner')->group(function (): void {
            Route::get('/pengguna', [UserManagementController::class, 'index'])->name('pengguna');
            Route::post('/pengguna', [UserManagementController::class, 'store'])->name('pengguna.store');
            Route::put('/pengguna/{user}', [UserManagementController::class, 'update'])->name('pengguna.update');
            Route::get('/pengaturan', [StoreSettingsController::class, 'index'])->name('pengaturan');
            Route::put('/pengaturan', [StoreSettingsController::class, 'update'])->name('pengaturan.update');
            Route::get('/pengaturan/backup', [StoreBackupController::class, 'download'])->name('pengaturan.backup.download');
            Route::post('/pengaturan/restore', [StoreBackupController::class, 'restore'])
                ->middleware('throttle:3,1')
                ->name('pengaturan.backup.restore');
        });
    });
});
