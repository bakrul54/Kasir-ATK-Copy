<?php

namespace App\Actions;

use App\Models\CashEntry;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class BuildBusinessReport
{
    private const REPORTS = [
        'sales' => 'Penjualan',
        'income' => 'Pendapatan',
        'purchases' => 'Pembelian',
        'expenses' => 'Pengeluaran',
        'stock' => 'Stok',
        'bestsellers' => 'Produk Terlaris',
        'profit' => 'Laba Rugi',
        'cash' => 'Kas',
    ];

    public function reportNames(): array
    {
        return self::REPORTS;
    }

    public function handle(string $type, string $from, string $to): array
    {
        return match ($type) {
            'sales' => $this->sales($from, $to),
            'income' => $this->income($from, $to),
            'purchases' => $this->purchases($from, $to),
            'expenses' => $this->expenses($from, $to),
            'stock' => $this->stock($from, $to),
            'bestsellers' => $this->bestsellers($from, $to),
            'profit' => $this->profit($from, $to),
            'cash' => $this->cash($from, $to),
        };
    }

    private function sales(string $from, string $to): array
    {
        $rows = Sale::query()
            ->notCancelled()
            ->withCount('items')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'transaction_number', 'customer_name', 'payment_method', 'total', 'created_at'])
            ->map(fn (Sale $sale): array => [
                $sale->created_at->format('Y-m-d H:i'),
                $sale->transaction_number,
                $sale->customer_name ?: 'Umum',
                $sale->items_count,
                strtoupper($sale->payment_method),
                $sale->total,
            ])->all();

        return [
            'columns' => ['Tanggal', 'No. Transaksi', 'Pelanggan', 'Baris Item', 'Pembayaran', 'Pendapatan (Rp)'],
            'currencyColumns' => [5],
            'rows' => $rows,
            'summary' => [
                'Jumlah transaksi' => Sale::query()->notCancelled()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
                'Pendapatan' => (int) Sale::query()->notCancelled()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('total'),
            ],
        ];
    }

    private function income(string $from, string $to): array
    {
        $rows = Sale::query()
            ->notCancelled()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('DATE(created_at) as day, payment_method, COUNT(*) as transaction_count, SUM(total) as revenue')
            ->groupBy('day', 'payment_method')
            ->orderByDesc('day')
            ->orderBy('payment_method')
            ->get()
            ->map(fn ($row): array => [
                $row->day,
                strtoupper($row->payment_method),
                (int) $row->transaction_count,
                (int) $row->revenue,
            ])->all();

        return [
            'columns' => ['Tanggal', 'Metode Pembayaran', 'Transaksi', 'Pendapatan (Rp)'],
            'currencyColumns' => [3],
            'rows' => $rows,
            'summary' => [
                'Total transaksi' => Sale::query()->notCancelled()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
                'Total pendapatan' => (int) Sale::query()->notCancelled()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('total'),
            ],
        ];
    }

    private function purchases(string $from, string $to): array
    {
        $query = Purchase::query()->whereBetween('purchase_date', [$from.' 00:00:00', $to.' 23:59:59']);
        $rows = (clone $query)
            ->with('supplier:id,name')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Purchase $purchase): array => [
                $purchase->purchase_date->toDateString(),
                $purchase->purchase_number,
                $purchase->supplier->name,
                $purchase->invoice_number ?: '—',
                strtoupper($purchase->payment_status),
                $purchase->total,
            ])->all();

        return [
            'columns' => ['Tanggal', 'No. Pembelian', 'Supplier', 'No. Nota', 'Status', 'Total (Rp)'],
            'currencyColumns' => [5],
            'rows' => $rows,
            'summary' => [
                'Jumlah pembelian' => (clone $query)->count(),
                'Total pembelian' => (int) (clone $query)->sum('total'),
                'Belum lunas' => (int) (clone $query)->where('payment_status', 'hutang')->sum('total'),
            ],
        ];
    }

    private function expenses(string $from, string $to): array
    {
        $query = CashEntry::query()
            ->where('type', 'pengeluaran')
            ->whereBetween('transaction_date', [$from.' 00:00:00', $to.' 23:59:59']);
        $rows = (clone $query)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get(['transaction_date', 'category', 'description', 'amount'])
            ->map(fn (CashEntry $entry): array => [
                $entry->transaction_date->toDateString(),
                $entry->category,
                $entry->description ?: '—',
                $entry->amount,
            ])->all();

        return [
            'columns' => ['Tanggal', 'Kategori', 'Keterangan', 'Jumlah (Rp)'],
            'currencyColumns' => [3],
            'rows' => $rows,
            'summary' => [
                'Jumlah catatan' => (clone $query)->count(),
                'Total pengeluaran' => (int) (clone $query)->sum('amount'),
            ],
        ];
    }

    private function stock(string $from, string $to): array
    {
        $rows = Product::query()
            ->with('category:id,name')
            ->where('tracks_stock', true)
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'sku', 'unit', 'stock_qty', 'minimum_stock', 'cost_price'])
            ->map(fn (Product $product): array => [
                $product->name,
                $product->sku ?: '—',
                $product->category->name,
                $product->stock_qty,
                $product->unit,
                $product->minimum_stock,
                $product->cost_price,
                $product->stock_qty * $product->cost_price,
                $product->stock_qty <= $product->minimum_stock ? 'Menipis' : 'Aman',
            ])->all();
        $stockProducts = Product::query()->where('tracks_stock', true);

        return [
            'columns' => ['Produk', 'SKU', 'Kategori', 'Stok', 'Satuan', 'Stok Minimum', 'Harga Modal (Rp)', 'Nilai Stok (Rp)', 'Status'],
            'currencyColumns' => [6, 7],
            'rows' => $rows,
            'summary' => [
                'Jenis produk stok' => (clone $stockProducts)->count(),
                'Produk menipis / habis' => (clone $stockProducts)->whereColumn('stock_qty', '<=', 'minimum_stock')->count(),
                'Nilai persediaan' => (int) (clone $stockProducts)->sum(DB::raw('stock_qty * cost_price')),
                'Pergerakan periode' => ProductStockMovement::query()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
            ],
            'note' => 'Jumlah dan nilai stok menunjukkan posisi saat ini. Pergerakan stok dibatasi sesuai periode yang dipilih.',
        ];
    }

    private function bestsellers(string $from, string $to): array
    {
        $sales = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.canceled_at')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->select([
                'sale_items.product_name',
                'sale_items.unit',
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
                DB::raw('SUM(sale_items.subtotal) as gross_sales'),
                DB::raw('SUM(sale_items.cost_price * sale_items.quantity) as cost_of_goods_sold'),
            ])
            ->groupBy('sale_items.product_name', 'sale_items.unit')
            ->orderByDesc('quantity_sold')
            ->orderBy('sale_items.product_name')
            ->get();

        return [
            'columns' => ['Produk / Jasa', 'Satuan', 'Jumlah Terjual', 'Nilai Baris Sebelum Diskon (Rp)', 'HPP (Rp)'],
            'currencyColumns' => [3, 4],
            'rows' => $sales->map(fn ($row): array => [
                $row->product_name,
                $row->unit,
                (int) $row->quantity_sold,
                (int) $row->gross_sales,
                (int) $row->cost_of_goods_sold,
            ])->all(),
            'summary' => [
                'Jenis produk / jasa terjual' => $sales->count(),
                'Unit terjual' => (int) $sales->sum('quantity_sold'),
                'Nilai baris penjualan sebelum diskon' => (int) $sales->sum('gross_sales'),
            ],
            'note' => 'Nilai penjualan per produk dihitung sebelum diskon transaksi, karena diskon dicatat pada tingkat transaksi.',
        ];
    }

    private function profit(string $from, string $to): array
    {
        $sales = DB::table('sales')
            ->whereNull('canceled_at')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('DATE(sales.created_at) as day, COUNT(*) as transaction_count, SUM(sales.total) as revenue')
            ->groupBy('day')
            ->get()
            ->keyBy('day');
        $costs = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.canceled_at')
            ->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('DATE(sales.created_at) as day, SUM(sale_items.cost_price * sale_items.quantity) as cost_of_goods_sold')
            ->groupBy('day')
            ->get()
            ->keyBy('day');
        $expenses = CashEntry::query()
            ->where('type', 'pengeluaran')
            ->whereBetween('transaction_date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('transaction_date as day, SUM(amount) as expenses')
            ->groupBy('transaction_date')
            ->get()
            ->keyBy('day');
        $days = $sales->keys()->merge($costs->keys())->merge($expenses->keys())->unique()->sortDesc();
        $rows = $days->map(function (string $day) use ($sales, $costs, $expenses): array {
            $sale = $sales->get($day);
            $revenue = (int) ($sale?->revenue ?? 0);
            $cost = (int) ($costs->get($day)?->cost_of_goods_sold ?? 0);
            $expense = (int) ($expenses->get($day)?->expenses ?? 0);

            return [$day, (int) ($sale?->transaction_count ?? 0), $revenue, $cost, $revenue - $cost, $expense, $revenue - $cost - $expense];
        });
        $summary = [
            'revenue' => (int) $sales->sum('revenue'),
            'cost_of_goods_sold' => (int) $costs->sum('cost_of_goods_sold'),
            'operating_expenses' => (int) $expenses->sum('expenses'),
        ];
        $summary['gross_profit'] = $summary['revenue'] - $summary['cost_of_goods_sold'];
        $summary['net_profit'] = $summary['gross_profit'] - $summary['operating_expenses'];

        return [
            'columns' => ['Tanggal', 'Transaksi', 'Pendapatan (Rp)', 'HPP (Rp)', 'Laba Kotor (Rp)', 'Biaya Operasional (Rp)', 'Laba Bersih (Rp)'],
            'currencyColumns' => [2, 3, 4, 5, 6],
            'rows' => $rows->values()->all(),
            'summary' => $summary,
            'summaryLabels' => [
                'revenue' => 'Pendapatan',
                'cost_of_goods_sold' => 'HPP',
                'gross_profit' => 'Laba kotor',
                'operating_expenses' => 'Biaya operasional',
                'net_profit' => 'Laba bersih',
            ],
        ];
    }

    private function cash(string $from, string $to): array
    {
        $accounts = CashEntry::accountLabels();
        $sales = DB::table('sales')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw(
                "created_at as entry_date, transaction_number as reference, COALESCE(customer_name, ?) as description, ? as category, ? as type, (total + tip_amount) as amount, CASE WHEN payment_method = 'cash' THEN 'laci' ELSE 'non_tunai' END as account, NULL as to_account",
                ['Penjualan', 'Penjualan', 'masuk'],
            );
        $purchases = DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.payment_status', 'lunas')
            ->whereBetween('purchases.purchase_date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw(
                'purchases.purchase_date as entry_date, purchases.purchase_number as reference, suppliers.name as description, ? as category, ? as type, purchases.total as amount, purchases.payment_account as account, NULL as to_account',
                ['Belanja stok', 'keluar'],
            );
        $cashEntries = DB::table('cash_entries')
            ->leftJoin('sales', 'sales.id', '=', 'cash_entries.sale_id')
            ->whereBetween('cash_entries.transaction_date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('cash_entries.transaction_date as entry_date, sales.transaction_number as reference, COALESCE(cash_entries.description, cash_entries.category) as description, cash_entries.category, cash_entries.type, cash_entries.amount, cash_entries.account, cash_entries.to_account');
        $rows = $sales->unionAll($purchases)->unionAll($cashEntries)
            ->orderByDesc('entry_date')
            ->get()
            ->map(function ($row) use ($accounts): array {
                $account = $accounts[$row->account] ?? $row->account;
                if ($row->type === 'transfer') {
                    $account .= ' → '.($accounts[$row->to_account] ?? $row->to_account);
                }

                return [
                    substr((string) $row->entry_date, 0, 16),
                    $row->description,
                    $row->category,
                    $account,
                    $row->type === 'masuk'
                        ? 'Kas Masuk'
                        : ($row->type === 'pengeluaran' ? 'Operasional' : ($row->type === 'transfer' ? 'Transfer' : 'Kas Keluar')),
                    $row->reference ?: '—',
                    $row->type === 'masuk' ? (int) $row->amount : -(int) $row->amount,
                ];
            });

        return [
            'columns' => ['Tanggal', 'Keterangan', 'Kategori', 'Saldo', 'Jenis', 'Referensi', 'Jumlah (+/- Rp)'],
            'currencyColumns' => [6],
            'rows' => $rows->all(),
            'summary' => [
                'Kas masuk' => (int) $rows->filter(fn (array $row): bool => $row[4] === 'Kas Masuk')->sum(fn (array $row): int => $row[6]),
                'Kas keluar' => abs((int) $rows->filter(fn (array $row): bool => in_array($row[4], ['Kas Keluar', 'Operasional'], true))->sum(fn (array $row): int => $row[6])),
                'Perubahan kas bersih' => (int) $rows->reject(fn (array $row): bool => $row[4] === 'Transfer')->sum(fn (array $row): int => $row[6]),
            ],
        ];
    }
}
