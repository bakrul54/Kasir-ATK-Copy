@extends('layouts.app')
@section('title', 'Dashboard')
@section('subtitle', 'Ringkasan operasional toko · '.$today->format('d/m/Y'))

@section('content')
@php
  $money = fn ($amount) => 'Rp '.number_format($amount, 0, ',', '.');
  $trendClass = fn ($direction) => match ($direction) {
    'up' => 'c-green',
    'down' => 'c-red',
    default => 'c-gray',
  };
  $axisLabel = function ($amount) {
    if ($amount >= 1000000) return number_format($amount / 1000000, 1, ',', '.').' jt';
    if ($amount >= 1000) return number_format($amount / 1000, 0, ',', '.').' rb';
    return number_format($amount, 0, ',', '.');
  };
@endphp

<div class="grid g4">
  <article class="card stat">
    <div class="top"><div class="ic c-green"><x-icon name="dollar" /></div><span class="pill {{ $trendClass($todayRevenueTrend['direction']) }}">{{ $todayRevenueTrend['percentage'] === null ? ($todayRevenueTrend['direction'] === 'up' ? 'Baru' : '—') : number_format(abs($todayRevenueTrend['percentage']), 1, ',', '.'). '%' }}</span></div>
    <div class="lbl">Pendapatan Hari Ini</div>
    <div class="val">{{ $money($todayRevenue) }}</div>
    <div class="note {{ $todayRevenueTrend['direction'] === 'down' ? 't-red' : 't-green' }}">{{ $todayRevenueTrend['label'] }}</div>
  </article>
  <article class="card stat">
    <div class="top"><div class="ic c-blue"><x-icon name="file" /></div><span class="pill {{ $trendClass($todayTransactionsTrend['direction']) }}">{{ $todayTransactionsTrend['percentage'] === null ? ($todayTransactionsTrend['direction'] === 'up' ? 'Baru' : '—') : number_format(abs($todayTransactionsTrend['percentage']), 1, ',', '.'). '%' }}</span></div>
    <div class="lbl">Transaksi Hari Ini</div>
    <div class="val">{{ number_format($todayTransactions, 0, ',', '.') }} Transaksi</div>
    <div class="note {{ $todayTransactionsTrend['direction'] === 'down' ? 't-red' : 't-blue' }}">{{ $todayTransactionsTrend['label'] }}</div>
  </article>
  <article class="card stat">
    <div class="top"><div class="ic c-orange"><x-icon name="bag" /></div><span class="pill {{ $trendClass($todayPurchasesTrend['direction']) }}">{{ $todayPurchaseCount }} Nota</span></div>
    <div class="lbl">Belanja Hari Ini</div>
    <div class="val">{{ $money($todayPurchases) }}</div>
    <div class="note t-orange">{{ $todayPurchasesTrend['label'] }}</div>
  </article>
  <article class="card stat">
    <div class="top"><div class="ic c-purple"><x-icon name="trend" /></div><span class="pill c-purple">Estimasi</span></div>
    <div class="lbl">Laba Bersih Hari Ini</div>
    <div class="val">{{ $money($estimatedNetProfit) }}</div>
    <div class="note t-purple">Penjualan − HPP − pengeluaran operasional</div>
  </article>
</div>

<section class="grid g-main mt">
  <article class="card">
    <div class="card-head">
      <div><h2>Pendapatan &amp; Penjualan</h2><div class="sub">Data aktual per hari selama 7 hari terakhir</div></div>
      <span class="pill {{ $trendClass($weeklyRevenueTrend['direction']) }}">{{ $weeklyRevenueTrend['label'] }}</span>
    </div>
    <div class="chart-box">
      <div class="chart">
        <svg viewBox="0 0 560 220" width="100%" height="220" role="img" aria-label="Grafik pendapatan aktual 7 hari terakhir">
          <defs><linearGradient id="dashboard-chart-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2563eb" stop-opacity=".18"/><stop offset="1" stop-color="#2563eb" stop-opacity="0"/></linearGradient></defs>
          <g stroke="#eef2f8">
            <line x1="40" y1="30" x2="550" y2="30"/>
            <line x1="40" y1="100" x2="550" y2="100"/>
            <line x1="40" y1="170" x2="550" y2="170"/>
          </g>
          <g fill="#9aa7bd" font-size="10">
            <text x="4" y="34">{{ $axisLabel($chartMaximum) }}</text>
            <text x="4" y="104">{{ $axisLabel((int) round($chartMaximum / 2)) }}</text>
            <text x="4" y="174">0</text>
          </g>
          <path d="{{ $chartArea }}" fill="url(#dashboard-chart-fill)"/>
          <polyline points="{{ $chartLine }}" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          <g fill="#fff" stroke="#2563eb" stroke-width="2">
            @foreach($chartPoints as $point)
              <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.5">
                <title>{{ $point['label'] }}: {{ $money($point['revenue']) }} · {{ $point['transactions'] }} transaksi</title>
              </circle>
            @endforeach
          </g>
          <g fill="#9aa7bd" font-size="10" text-anchor="middle">
            @foreach($chartPoints as $point)
              <text x="{{ $point['x'] }}" y="195">{{ $point['label'] }}</text>
            @endforeach
          </g>
        </svg>
      </div>
      <div class="side">
        <div><small>Pendapatan 7 hari</small><b>{{ $money($weeklyRevenueTotal) }}</b><small class="t-blue">{{ $weeklyRevenueTrend['label'] }}</small></div>
        <div><small>Jumlah transaksi</small><b>{{ number_format($weeklyTransactionCount, 0, ',', '.') }}</b></div>
        <div><small>Rata-rata transaksi</small><b>{{ $money($weeklyAverageTransaction) }}</b></div>
      </div>
    </div>
  </article>

  <article class="card">
    <div class="card-head"><div><h2>Penjualan per Kategori</h2><div class="sub">Komposisi nilai barang/jasa 7 hari, sebelum diskon transaksi</div></div></div>
    <div class="donut-wrap">
      <div class="donut" style="background:{{ $donutGradient }}">
        <div><span>{{ $categoryRevenueTotal > 0 ? '100%' : '0%' }}<small>Nilai item</small></span></div>
      </div>
      <div class="legend">
        @forelse($categoryBreakdown as $category)
          <div><i style="background:{{ $category['color'] }}"></i><span>{{ $category['name'] }}</span><b>{{ $category['percentage'] }}%</b></div>
        @empty
          <p class="muted dashboard-empty">Belum ada penjualan kategori dalam 7 hari terakhir.</p>
        @endforelse
      </div>
    </div>
    <div class="dashboard-category-total">Nilai item terjual: <b>{{ $money($categoryRevenueTotal) }}</b></div>
  </article>
</section>

<section class="grid g-main mt">
  <article class="card">
    <div class="card-head">
      <div><h2>Produk Terlaris</h2><div class="sub">Peringkat berdasarkan jumlah terjual hari ini</div></div>
      <a href="{{ route('laporan', ['report' => 'bestsellers']) }}" class="t-blue dashboard-link">Lihat Laporan</a>
    </div>
    @forelse($bestSellers as $index => $product)
      <div class="list-item">
        <span class="n">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
        <span class="grow">{{ $product->product_name }}</span>
        <span class="muted">{{ number_format($product->quantity_sold, 0, ',', '.') }} terjual</span>
        <b class="dashboard-product-revenue">{{ $money($product->revenue) }}</b>
      </div>
    @empty
      <p class="dashboard-empty">Belum ada produk terjual hari ini.</p>
    @endforelse
  </article>

  <article class="card">
    <div class="card-head">
      <div><h2>Stok Menipis</h2><div class="sub">{{ number_format($lowStockCount, 0, ',', '.') }} produk perlu diperiksa</div></div>
    </div>
    @forelse($lowStockProducts as $product)
      <div class="stock-row">
        <div><b>{{ $product->name }}</b><small>{{ number_format($product->stock_qty, 0, ',', '.') }} {{ $product->unit }} tersisa · batas {{ number_format($product->minimum_stock, 0, ',', '.') }}</small></div>
        <span class="pill {{ $product->stock_qty === 0 ? 'c-red' : 'c-yellow' }}">{{ $product->stock_qty === 0 ? 'Habis' : 'Menipis' }}</span>
      </div>
    @empty
      <p class="dashboard-empty">Tidak ada produk dengan stok menipis.</p>
    @endforelse
    <a href="{{ route('stok') }}" class="btn block mt t-blue">Lihat Semua Stok</a>
  </article>
</section>

<section class="grid g4 mt dashboard-health">
  <article class="card"><div class="lbl">Produk Aktif</div><div class="val">{{ number_format($activeProductCount, 0, ',', '.') }}</div></article>
  <article class="card"><div class="lbl">Shift Kasir Berjalan</div><div class="val">{{ number_format($openShiftCount, 0, ',', '.') }}</div></article>
  <article class="card"><div class="lbl">Nilai Pembelian Belum Lunas</div><div class="val">{{ $money($unpaidPurchaseTotal) }}</div></article>
  <article class="card"><div class="lbl">Perlu Perhatian</div><div class="val">{{ number_format($lowStockCount, 0, ',', '.') }} Produk</div></article>
</section>
@endsection
