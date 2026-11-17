<?php

namespace App\Http\Controllers;

use App\Actions\CancelSale;
use App\Actions\RecordSale;
use App\Actions\RefundSale;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\StoreSetting;
use App\Support\PrintPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        $settings = StoreSetting::current();
        $products = Product::query()
            ->with('category', 'wholesaleGroup')
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('tracks_stock', false)->orWhere('stock_qty', '>', 0))
            ->when($request->filled('product_q'), function ($query) use ($request) {
                $search = $request->string('product_q')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->orderBy('name')
            ->paginate(18)
            ->withQueryString();
        $currentShift = $request->user()->role === 'cashier'
            ? CashierShift::query()->whereBelongsTo($request->user())->whereNull('closed_at')->first()
            : null;

        $request->validate([
            'sales_date' => ['nullable', 'date'],
        ]);

        return view('kasir', [
            'products' => $products,
            'printAndServiceProducts' => Product::query()
                ->with('category', 'wholesaleGroup')
                ->whereHas('category', fn ($query) => $query->where('name', 'Cetak dan Jasa'))
                ->where('is_active', true)
                ->where(fn ($query) => $query->where('tracks_stock', false)->orWhere('stock_qty', '>', 0))
                ->orderBy('name')
                ->get(),
            'settings' => $settings,
            'submissionToken' => (string) Str::uuid(),
            'currentShift' => $currentShift,
            'currentShiftCashSales' => $currentShift
                ? (int) $currentShift->sales()->notCancelled()->where('payment_method', 'cash')->sum(DB::raw('total + tip_amount'))
                : 0,
            'customers' => Customer::query()->select(['id', 'name', 'phone'])->orderBy('name')->get(),
            'categories' => ProductCategory::query()
                ->whereHas('products', fn ($query) => $query->where('is_active', true))
                ->orderBy('name')
                ->get(),
            'sales' => Sale::query()
                ->with('user:id,name')
                ->withCount('items')
                ->when($request->filled('sales_q'), function ($query) use ($request) {
                    $search = $request->string('sales_q')->toString();
                    $query->where(function ($query) use ($search) {
                        $query->where('transaction_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%");
                    });
                })
                ->when(
                    $request->user()->role === 'cashier',
                    fn ($query) => $query->where('user_id', $request->user()->id),
                )
                ->whereDate('created_at', $request->input('sales_date', today()->toDateString()))
                ->latest()
                ->paginate(10, ['*'], 'sales_page')
                ->withQueryString(),
            'selectedCategory' => $request->integer('category') ?: null,
            'printProducts' => $this->printProducts($settings),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function printProducts(StoreSetting $settings): array
    {
        $products = Product::query()
            ->with('wholesaleGroup')
            ->whereIn('name', array_column(PrintPricing::CATEGORIES, 'product_name'))
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('tracks_stock', false)->orWhere('stock_qty', '>', 0))
            ->get()
            ->keyBy('id');
        $printProducts = [];

        foreach (PrintPricing::CATEGORIES as $category) {
            $product = $products->firstWhere('name', $category['product_name']);
            if (! $product) {
                continue;
            }

            $printProducts[$product->id] = PrintPricing::productData($product, $settings);
        }

        return $printProducts;
    }

    public function store(Request $request, RecordSale $recordSale): RedirectResponse
    {
        $settings = StoreSetting::current();
        $data = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'payment_method' => ['required', Rule::in($settings->payment_methods)],
            'amount_received' => ['required_if:payment_method,cash', 'nullable', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'tip_amount' => ['nullable', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'discount_type' => ['required', 'in:nominal,persen'],
            'discount_value' => ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ], [
            'items.min' => 'Keranjang masih kosong. Tambahkan produk atau jasa terlebih dahulu.',
            'items.max' => 'Maksimal 50 baris produk dalam satu transaksi.',
            'items.*.product_id.distinct' => 'Produk yang sama hanya boleh dicantumkan satu kali di keranjang.',
            'amount_received.required_if' => 'Masukkan jumlah uang yang diterima untuk pembayaran tunai.',
        ]);

        if ($data['discount_type'] === 'persen' && $data['discount_value'] > 100) {
            return back()->withErrors([
                'discount_value' => 'Diskon persentase harus antara 0 dan 100.',
            ])->withInput();
        }

        try {
            $sale = $recordSale->handle($data, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return to_route('kasir')->with([
            'success' => "Transaksi {$sale->transaction_number} berhasil disimpan.",
            'receipt_url' => route('kasir.receipt', $sale),
        ]);
    }

    public function cancel(Request $request, Sale $sale, CancelSale $cancelSale): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'refund_confirmed' => ['required', 'accepted'],
        ], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.max' => 'Alasan pembatalan maksimal 500 karakter.',
            'refund_confirmed.required' => 'Konfirmasi refund wajib dicentang setelah dana benar-benar dikembalikan.',
            'refund_confirmed.accepted' => 'Konfirmasi refund wajib dicentang setelah dana benar-benar dikembalikan.',
        ]);

        $cancelSale->handle($sale, $request->user(), $data['reason']);

        return to_route('kasir.detail', $sale)->with('success', "Transaksi {$sale->transaction_number} berhasil dibatalkan.");
    }

    public function refund(Request $request, Sale $sale, RefundSale $refundSale): RedirectResponse
    {
        $request->validate([
            'refund_confirmed' => ['required', 'accepted'],
        ], [
            'refund_confirmed.required' => 'Konfirmasi refund wajib dicentang setelah dana benar-benar dikembalikan.',
            'refund_confirmed.accepted' => 'Konfirmasi refund wajib dicentang setelah dana benar-benar dikembalikan.',
        ]);

        $refundSale->handle($sale, $request->user());

        $refundAmount = $sale->total + $sale->tip_amount;

        return to_route('kasir.detail', $sale)->with('success', 'Refund Rp '.number_format($refundAmount, 0, ',', '.')." untuk {$sale->transaction_number} berhasil dicatat.");
    }

    public function show(Request $request, Sale $sale): View
    {
        abort_if(
            $request->user()->role === 'cashier' && (int) $sale->user_id !== $request->user()->id,
            404,
        );

        return view('kasir-detail', [
            'sale' => $sale->load(['items', 'user:id,name', 'canceledBy:id,name', 'refundEntry']),
            'backQuery' => $request->only(['sales_q', 'sales_date', 'sales_page']),
        ]);
    }

    public function receipt(Request $request, Sale $sale): View
    {
        abort_if(
            $request->user()->role === 'cashier' && (int) $sale->user_id !== $request->user()->id,
            404,
        );

        return view('kasir-struk', [
            'sale' => $sale->load(['items', 'user:id,name', 'canceledBy:id,name']),
            'settings' => StoreSetting::current(),
        ]);
    }
}
