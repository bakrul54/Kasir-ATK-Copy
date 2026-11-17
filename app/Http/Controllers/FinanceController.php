<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Models\CashEntry;
use App\Models\Purchase;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $from = Carbon::parse($filters['from'] ?? today()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? today()->toDateString())->endOfDay();
        $fromDateTime = $from->toDateTimeString();
        $toDateTime = $to->toDateTimeString();
        $query = trim($filters['q'] ?? '');
        $salePeriod = fn () => Sale::query()->notCancelled()->whereBetween('created_at', [$from, $to]);
        $grossSalePeriod = fn () => Sale::query()->whereBetween('created_at', [$from, $to]);
        $purchasePeriod = fn () => Purchase::query()->whereBetween('purchase_date', [$fromDateTime, $toDateTime]);
        $cashPeriod = fn () => CashEntry::query()->whereBetween('transaction_date', [$fromDateTime, $toDateTime]);

        $salesQuery = DB::table('sales')
            ->select([
                'created_at as entry_date',
                'id as source_id',
                'transaction_number as reference',
                DB::raw("COALESCE(customer_name, 'Penjualan') as description"),
                DB::raw("'masuk' as type"),
                DB::raw('(total + tip_amount) as amount'),
                DB::raw("'Penjualan' as category"),
                DB::raw("CASE WHEN payment_method = 'cash' THEN 'laci' ELSE 'non_tunai' END as account"),
                DB::raw('NULL as to_account'),
                DB::raw("'sale' as source"),
            ])
            ->whereBetween('created_at', [$fromDateTime, $toDateTime])
            ->when($query !== '', fn ($builder) => $builder->where(function ($builder) use ($query) {
                $builder->where('transaction_number', 'like', "%{$query}%")
                    ->orWhere('customer_name', 'like', "%{$query}%");
            }));

        $purchasesQuery = DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->select([
                'purchases.purchase_date as entry_date',
                'purchases.id as source_id',
                'purchases.purchase_number as reference',
                'suppliers.name as description',
                DB::raw("'keluar' as type"),
                'purchases.total as amount',
                DB::raw("'Belanja stok' as category"),
                'purchases.payment_account as account',
                DB::raw('NULL as to_account'),
                DB::raw("'purchase' as source"),
            ])
            ->where('purchases.payment_status', 'lunas')
            ->whereBetween('purchases.purchase_date', [$fromDateTime, $toDateTime])
            ->when($query !== '', fn ($builder) => $builder->where(function ($builder) use ($query) {
                $builder->where('purchases.purchase_number', 'like', "%{$query}%")
                    ->orWhere('purchases.invoice_number', 'like', "%{$query}%")
                    ->orWhere('suppliers.name', 'like', "%{$query}%");
            }));

        $cashEntriesQuery = DB::table('cash_entries')
            ->leftJoin('sales', 'sales.id', '=', 'cash_entries.sale_id')
            ->select([
                'cash_entries.transaction_date as entry_date',
                'cash_entries.id as source_id',
                'sales.transaction_number as reference',
                'cash_entries.description',
                'cash_entries.type',
                'cash_entries.amount',
                'cash_entries.category',
                'cash_entries.account',
                'cash_entries.to_account',
                DB::raw("CASE WHEN cash_entries.sale_id IS NULL THEN 'manual' ELSE 'refund' END as source"),
            ])
            ->whereBetween('cash_entries.transaction_date', [$fromDateTime, $toDateTime])
            ->when($query !== '', fn ($builder) => $builder->where(function ($builder) use ($query) {
                $builder->where('cash_entries.description', 'like', "%{$query}%")
                    ->orWhere('cash_entries.category', 'like', "%{$query}%")
                    ->orWhere('sales.transaction_number', 'like', "%{$query}%");
            }));

        $ledgerQuery = $salesQuery->unionAll($purchasesQuery)->unionAll($cashEntriesQuery);
        $entries = DB::query()
            ->fromSub($ledgerQuery, 'cash_book')
            ->orderByDesc('entry_date')
            ->orderByDesc('source_id')
            ->orderBy('source')
            ->paginate(15)
            ->withQueryString();

        $periodRevenue = (int) $salePeriod()->sum('total');
        $costOfGoodsSold = (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereBetween('sales.created_at', [$from, $to])
            ->whereNull('sales.canceled_at')
            ->sum(DB::raw('sale_items.cost_price * sale_items.quantity'));
        $purchaseOutflow = (int) $purchasePeriod()->where('payment_status', 'lunas')->sum('total');
        $unpaidPurchases = (int) $purchasePeriod()->where('payment_status', 'hutang')->sum('total');
        $periodCashIn = (int) $grossSalePeriod()->sum(DB::raw('total + tip_amount'))
            + (int) $cashPeriod()->where('type', 'masuk')->sum('amount');
        $periodCashOut = (int) $cashPeriod()->whereIn('type', ['keluar', 'pengeluaran'])->sum('amount');
        $operatingExpenses = (int) $cashPeriod()->where('type', 'pengeluaran')->sum('amount');
        $accountBalances = array_fill_keys(array_keys(CashEntry::accountLabels()), 0);

        foreach (DB::table('sales')
            ->selectRaw('payment_method, SUM(total + tip_amount) as total')
            ->groupBy('payment_method')
            ->get() as $saleTotal) {
            $account = $saleTotal->payment_method === 'cash'
                ? CashEntry::ACCOUNT_DRAWER
                : CashEntry::ACCOUNT_NON_CASH;
            $accountBalances[$account] += (int) $saleTotal->total;
        }

        foreach (Purchase::query()
            ->where('payment_status', 'lunas')
            ->selectRaw('payment_account, SUM(total) as total')
            ->groupBy('payment_account')
            ->get() as $purchaseTotal) {
            $accountBalances[$purchaseTotal->payment_account] -= (int) $purchaseTotal->total;
        }

        foreach (CashEntry::query()
            ->selectRaw('account, type, SUM(amount) as total')
            ->whereIn('type', ['masuk', 'keluar', 'pengeluaran', 'transfer'])
            ->groupBy('account', 'type')
            ->get() as $cashTotal) {
            $accountBalances[$cashTotal->account] += $cashTotal->type === 'masuk'
                ? (int) $cashTotal->total
                : -(int) $cashTotal->total;
        }

        foreach (CashEntry::query()
            ->where('type', 'transfer')
            ->selectRaw('to_account, SUM(amount) as total')
            ->groupBy('to_account')
            ->get() as $transferTotal) {
            $accountBalances[$transferTotal->to_account] += (int) $transferTotal->total;
        }

        return view('keuangan', [
            'entries' => $entries,
            'accounts' => CashEntry::accountLabels(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'q' => $query,
            ],
            'stats' => [
                'accounts' => $accountBalances,
                'cash_in' => $periodCashIn,
                'cash_out' => $purchaseOutflow + $periodCashOut,
                'revenue' => $periodRevenue,
                'cost_of_goods_sold' => $costOfGoodsSold,
                'gross_profit' => $periodRevenue - $costOfGoodsSold,
                'operating_expenses' => $operatingExpenses,
                'net_profit' => $periodRevenue - $costOfGoodsSold - $operatingExpenses,
                'unpaid_purchases' => $unpaidPurchases,
            ],
            'expenseCategories' => $cashPeriod()
                ->where('type', 'pengeluaran')
                ->select('category')
                ->selectRaw('SUM(amount) as total')
                ->groupBy('category')
                ->orderBy('category')
                ->get(),
        ]);
    }

    public function store(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:masuk,keluar,pengeluaran,transfer'],
            'category' => ['nullable', 'required_unless:type,transfer', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'account' => ['nullable', Rule::in(array_keys(CashEntry::accountLabels()))],
            'to_account' => [
                'nullable',
                'required_if:type,transfer',
                Rule::in(array_keys(CashEntry::accountLabels())),
                'different:account',
            ],
        ], [
            'to_account.required_if' => 'Pilih saldo tujuan untuk transfer.',
            'to_account.different' => 'Saldo tujuan harus berbeda dari saldo asal.',
        ]);
        $data['account'] ??= CashEntry::ACCOUNT_DRAWER;
        $data['to_account'] = $data['type'] === 'transfer' ? $data['to_account'] : null;
        if ($data['type'] === 'transfer') {
            $data['category'] = 'Transfer saldo';
            $data['description'] = ($data['description'] ?? null)
                ?: 'Transfer '.$this->accountLabel($data['account']).' ke '.$this->accountLabel($data['to_account']);
        }

        DB::transaction(function () use ($data, $request, $recordActivity): void {
            $entry = CashEntry::create([
                ...$data,
                'user_id' => $request->user()->id,
            ]);
            $recordActivity->handle(
                $request->user(),
                'cash_entry.created',
                "Mencatat {$data['type']} kategori {$data['category']}.",
                $entry,
                ['amount' => $data['amount']],
            );
        });

        return to_route('keuangan')->with(
            'success',
            $data['type'] === 'transfer'
                ? 'Transfer antar saldo berhasil dicatat.'
                : 'Catatan kas berhasil disimpan.',
        );
    }

    private function accountLabel(string $account): string
    {
        return CashEntry::accountLabels()[$account];
    }
}
