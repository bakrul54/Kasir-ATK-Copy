<?php

namespace App\Http\Controllers;

use App\Actions\RecordPurchase;
use App\Models\CashEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        $period = $request->query('period', 'month');
        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'user:id,name'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = $request->string('q')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('purchase_number', 'like', "%{$search}%")
                        ->orWhere('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(in_array($period, ['month', 'last_month'], true), function ($query) use ($period) {
                $date = $period === 'month' ? now() : now()->subMonth();
                $query->whereBetween('purchase_date', [
                    $date->copy()->startOfMonth()->toDateString(),
                    $date->copy()->endOfMonth()->toDateString(),
                ]);
            })
            ->when($request->query('payment_status') === 'lunas', fn ($query) => $query->where('payment_status', 'lunas'))
            ->when($request->query('payment_status') === 'hutang', fn ($query) => $query->where('payment_status', 'hutang'))
            ->latest('purchase_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('pembelian', [
            'purchases' => $purchases,
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::query()
                ->select(['id', 'name', 'sku', 'unit', 'cost_price'])
                ->where('tracks_stock', true)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'period' => $period,
            'stats' => [
                'this_month' => Purchase::query()
                    ->whereBetween('purchase_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->sum('total'),
                'unpaid' => Purchase::query()->where('payment_status', 'hutang')->sum('total'),
                'count' => Purchase::query()
                    ->whereBetween('purchase_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request, RecordPurchase $recordPurchase): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('is_active', true),
            ],
            'invoice_number' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('purchases', 'invoice_number')
                    ->where('supplier_id', $request->input('supplier_id')),
            ],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_status' => ['required', 'in:lunas,hutang'],
            'payment_account' => ['nullable', Rule::in(array_keys(CashEntry::accountLabels()))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'receipt_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ], [
            'invoice_number.unique' => 'Nomor nota tersebut sudah pernah dicatat untuk supplier ini.',
            'purchase_date.before_or_equal' => 'Tanggal pembelian tidak boleh melebihi hari ini.',
            'items.min' => 'Tambahkan minimal satu barang ke pembelian.',
            'items.max' => 'Maksimal 100 jenis barang dalam satu pembelian.',
            'items.*.product_id.distinct' => 'Produk yang sama hanya boleh dicantumkan satu kali. Gabungkan jumlahnya.',
        ]);

        $data = $validator->validate();
        $data['payment_account'] ??= CashEntry::ACCOUNT_DRAWER;
        $receiptPath = null;

        if ($request->hasFile('receipt_photo')) {
            $receiptPath = $request->file('receipt_photo')->store('purchase-receipts', 'public');
            $data['receipt_path'] = $receiptPath;
        }

        unset($data['receipt_photo']);

        try {
            $recordPurchase->handle($data, $request->user());
        } catch (Throwable $exception) {
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }

            throw $exception;
        }

        return to_route('pembelian')->with('success', 'Pembelian berhasil disimpan dan stok produk telah diperbarui.');
    }

    public function show(Purchase $purchase): View
    {
        return view('pembelian-detail', [
            'purchase' => $purchase->load([
                'supplier:id,name,phone,address',
                'user:id,name',
                'items.product:id,name,sku,unit',
            ]),
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        Supplier::create($data);

        return to_route('pembelian')->with('success', 'Supplier berhasil ditambahkan.');
    }
}
