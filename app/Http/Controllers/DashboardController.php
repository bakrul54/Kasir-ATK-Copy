<?php

namespace App\Http\Controllers;

use App\Models\CashEntry;
use App\Models\CashierShift;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const CHART_COLORS = ['#2563eb', '#22b8cf', '#7c4dcc', '#f59e42', '#16a34a'];

    public function index(): View
    {
        $today = CarbonImmutable::today();
        $tomorrow = $today->addDay();
        $yesterday = $today->subDay();
        $weekStart = $today->subDays(6);
        $previousWeekStart = $weekStart->subDays(7);

        $todaySales = $this->salesSummary($today, $tomorrow);
        $yesterdaySales = $this->salesSummary($yesterday, $today);
        $todayPurchases = (int) Purchase::query()
            ->whereDate('purchase_date', $today->toDateString())
            ->sum('total');
        $todayPurchaseCount = Purchase::query()
            ->whereDate('purchase_date', $today->toDateString())
            ->count();
        $yesterdayPurchases = (int) Purchase::query()
            ->whereDate('purchase_date', $yesterday->toDateString())
            ->sum('total');
        $todayCostOfGoodsSold = (int) SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.canceled_at')
            ->where('sales.created_at', '>=', $today)
            ->where('sales.created_at', '<', $tomorrow)
            ->sum(DB::raw('sale_items.cost_price * sale_items.quantity'));
        $todayOperatingExpenses = (int) CashEntry::query()
            ->where('type', 'pengeluaran')
            ->whereDate('transaction_date', $today->toDateString())
            ->sum('amount');
        $estimatedNetProfit = $todaySales['revenue'] - $todayCostOfGoodsSold - $todayOperatingExpenses;

        $weeklyRows = DB::table('sales')
            ->selectRaw('DATE(created_at) as sales_day, SUM(total) as revenue, COUNT(*) as transactions')
            ->whereNull('canceled_at')
            ->where('created_at', '>=', $weekStart)
            ->where('created_at', '<', $tomorrow)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('sales_day')
            ->get()
            ->keyBy('sales_day');

        $weeklyChart = collect(range(0, 6))
            ->map(function (int $offset) use ($weekStart, $weeklyRows): array {
                $date = $weekStart->addDays($offset);
                $dayData = $weeklyRows->get($date->toDateString());

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->format('d/m'),
                    'revenue' => (int) ($dayData->revenue ?? 0),
                    'transactions' => (int) ($dayData->transactions ?? 0),
                ];
            });

        $weeklyRevenueTotal = (int) $weeklyChart->sum('revenue');
        $weeklyTransactionCount = (int) $weeklyChart->sum('transactions');
        $weeklyAverageTransaction = $weeklyTransactionCount > 0
            ? (int) round($weeklyRevenueTotal / $weeklyTransactionCount)
            : 0;
        $previousWeekRevenue = (int) Sale::query()
            ->notCancelled()
            ->where('created_at', '>=', $previousWeekStart)
            ->where('created_at', '<', $weekStart)
            ->sum('total');
        $chartMaximum = (int) $weeklyChart->max('revenue');
        $chartScale = max(1, $chartMaximum);
        $chartPoints = $weeklyChart->map(fn (array $day, int $index): array => [
            ...$day,
            'x' => 50 + ($index * (490 / 6)),
            'y' => 170 - (($day['revenue'] / $chartScale) * 140),
        ]);
        $chartLine = $chartPoints
            ->map(fn (array $point): string => round($point['x'], 2).','.round($point['y'], 2))
            ->implode(' ');
        $chartArea = 'M'.$chartPoints->first()['x'].',170 L'.$chartLine
            .' L'.$chartPoints->last()['x'].',170 Z';

        $categoryRows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->select([
                DB::raw("COALESCE(product_categories.name, 'Cetak Coverage') as category_name"),
                DB::raw('SUM(sale_items.subtotal) as revenue'),
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
            ])
            ->where('sales.created_at', '>=', $weekStart)
            ->where('sales.created_at', '<', $tomorrow)
            ->whereNull('sales.canceled_at')
            ->groupByRaw("COALESCE(product_categories.name, 'Cetak Coverage')")
            ->orderByDesc('revenue')
            ->get();
        $categoryRevenueTotal = (int) $categoryRows->sum('revenue');
        if ($categoryRows->count() > 5) {
            $categoryRemainder = (int) $categoryRows->slice(4)->sum('revenue');
            $categoryRows = $categoryRows->take(4)->values()->push((object) [
                'category_name' => 'Lainnya',
                'revenue' => $categoryRemainder,
                'quantity_sold' => 0,
            ]);
        }
        $categoryBreakdown = $categoryRows
            ->values()
            ->map(function (object $row, int $index) use ($categoryRevenueTotal): array {
                return [
                    'name' => $row->category_name,
                    'revenue' => (int) $row->revenue,
                    'quantity_sold' => (int) $row->quantity_sold,
                    'percentage' => $categoryRevenueTotal > 0
                        ? (int) round(((int) $row->revenue / $categoryRevenueTotal) * 100)
                        : 0,
                    'color' => self::CHART_COLORS[$index % count(self::CHART_COLORS)],
                ];
            });
        $donutStops = [];
        $donutOffset = 0;
        foreach ($categoryBreakdown as $category) {
            $nextOffset = $donutOffset + ($categoryRevenueTotal > 0
                ? ((int) $category['revenue'] / $categoryRevenueTotal) * 100
                : 0);
            $donutStops[] = "{$category['color']} {$donutOffset}% {$nextOffset}%";
            $donutOffset = $nextOffset;
        }
        $donutGradient = $donutStops === []
            ? 'conic-gradient(#e8edf5 0 100%)'
            : 'conic-gradient('.implode(', ', $donutStops).')';

        $bestSellers = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->select([
                'sale_items.product_name',
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
                DB::raw('SUM(sale_items.subtotal) as revenue'),
            ])
            ->where('sales.created_at', '>=', $today)
            ->where('sales.created_at', '<', $tomorrow)
            ->whereNull('sales.canceled_at')
            ->groupBy('sale_items.product_name')
            ->orderByDesc('quantity_sold')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $lowStockCount = Product::query()
            ->where('is_active', true)
            ->where('tracks_stock', true)
            ->whereColumn('stock_qty', '<=', 'minimum_stock')
            ->count();
        $lowStockProducts = Product::query()
            ->where('is_active', true)
            ->where('tracks_stock', true)
            ->whereColumn('stock_qty', '<=', 'minimum_stock')
            ->orderBy('stock_qty')
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'stock_qty', 'minimum_stock', 'unit']);

        return view('dashboard', [
            'today' => $today,
            'todayRevenue' => $todaySales['revenue'],
            'todayTransactions' => $todaySales['transactions'],
            'yesterdayRevenue' => $yesterdaySales['revenue'],
            'yesterdayTransactions' => $yesterdaySales['transactions'],
            'todayPurchases' => $todayPurchases,
            'todayPurchaseCount' => $todayPurchaseCount,
            'estimatedNetProfit' => $estimatedNetProfit,
            'todayRevenueTrend' => $this->trend($todaySales['revenue'], $yesterdaySales['revenue'], 'pendapatan', 'kemarin'),
            'todayTransactionsTrend' => $this->trend($todaySales['transactions'], $yesterdaySales['transactions'], 'transaksi', 'kemarin'),
            'todayPurchasesTrend' => $this->trend($todayPurchases, $yesterdayPurchases, 'belanja', 'kemarin'),
            'weeklyChart' => $weeklyChart,
            'chartPoints' => $chartPoints,
            'chartLine' => $chartLine,
            'chartArea' => $chartArea,
            'chartMaximum' => $chartMaximum,
            'weeklyRevenueTotal' => $weeklyRevenueTotal,
            'categoryRevenueTotal' => $categoryRevenueTotal,
            'weeklyTransactionCount' => $weeklyTransactionCount,
            'weeklyAverageTransaction' => $weeklyAverageTransaction,
            'previousWeekRevenue' => $previousWeekRevenue,
            'weeklyRevenueTrend' => $this->trend($weeklyRevenueTotal, $previousWeekRevenue, 'pendapatan', 'minggu sebelumnya'),
            'categoryBreakdown' => $categoryBreakdown,
            'donutGradient' => $donutGradient,
            'bestSellers' => $bestSellers,
            'lowStockProducts' => $lowStockProducts,
            'lowStockCount' => $lowStockCount,
            'activeProductCount' => Product::query()->where('is_active', true)->count(),
            'openShiftCount' => CashierShift::query()->whereNull('closed_at')->count(),
            'unpaidPurchaseTotal' => (int) Purchase::query()->where('payment_status', 'hutang')->sum('total'),
        ]);
    }

    private function salesSummary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summary = Sale::query()
            ->notCancelled()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('COUNT(*) as transactions, COALESCE(SUM(total), 0) as revenue')
            ->first();

        return [
            'revenue' => (int) $summary->revenue,
            'transactions' => (int) $summary->transactions,
        ];
    }

    private function trend(int $current, int $previous, string $subject, string $period): array
    {
        if ($previous === 0) {
            return [
                'label' => $current === 0 ? "Belum ada {$subject}" : "Mulai ada {$subject}",
                'percentage' => null,
                'direction' => $current === 0 ? 'neutral' : 'up',
            ];
        }

        $change = (($current - $previous) / $previous) * 100;
        if ($change === 0.0) {
            return [
                'label' => 'Sama dengan periode sebelumnya',
                'percentage' => 0,
                'direction' => 'neutral',
            ];
        }

        return [
            'label' => ($change > 0 ? 'Naik ' : ($change < 0 ? 'Turun ' : 'Sama '))
                .number_format(abs($change), 1, ',', '.')
                .'%'
            .' dibanding '.$period,
            'percentage' => round($change, 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
        ];
    }
}
