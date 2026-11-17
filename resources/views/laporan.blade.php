@extends('layouts.app')
@section('title', 'Laporan')
@section('subtitle', 'Laporan usaha berdasarkan data transaksi dan stok tersimpan')

@section('content')
@php
  $money = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
  $summaryLabels = $reportData['summaryLabels'] ?? [];
  $currencySummary = [
    'Pendapatan', 'Total pendapatan', 'Total pembelian', 'Belum lunas',
    'Total pengeluaran', 'Nilai persediaan', 'Nilai baris penjualan sebelum diskon',
    'Kas masuk', 'Kas keluar', 'Perubahan kas bersih',
    'revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses', 'net_profit',
  ];
  $filtersForLinks = ['from' => $filters['from'], 'to' => $filters['to']];
  $presets = [
    'Hari ini' => [today()->toDateString(), today()->toDateString()],
    '7 hari' => [today()->subDays(6)->toDateString(), today()->toDateString()],
    'Bulan ini' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
    'Tahun ini' => [today()->startOfYear()->toDateString(), today()->toDateString()],
  ];
@endphp

@if($errors->any())
  <div class="notice error" role="alert">{{ $errors->first() }}</div>
@endif

<section class="card">
  <form class="report-filter-form" method="GET" action="{{ route('laporan') }}">
    <div class="field">
      <label for="report-type">Jenis laporan</label>
      <select class="input" id="report-type" name="report" required>
        @foreach($reports as $key => $label)
          <option value="{{ $key }}" @selected($selectedReport === $key)>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="report-from">Dari tanggal</label>
      <input class="input" id="report-from" type="date" name="from" value="{{ $filters['from'] }}" required>
    </div>
    <div class="field">
      <label for="report-to">Sampai tanggal</label>
      <input class="input" id="report-to" type="date" name="to" value="{{ $filters['to'] }}" required>
    </div>
    <button class="btn primary" type="submit">Tampilkan Laporan</button>
    <div class="report-presets" aria-label="Pilih periode cepat">
      @foreach($presets as $label => [$from, $to])
        <a class="btn sm {{ $filters['from'] === $from && $filters['to'] === $to ? 'primary' : '' }}"
          href="{{ route('laporan', ['report' => $selectedReport, 'from' => $from, 'to' => $to]) }}">{{ $label }}</a>
      @endforeach
    </div>
  </form>
</section>

<div class="grid g4 mt report-cards">
  @foreach($reports as $key => $label)
    @php
      $reportIcons = ['sales' => 'chart', 'income' => 'dollar', 'purchases' => 'bag', 'expenses' => 'card', 'stock' => 'box', 'bestsellers' => 'trend', 'profit' => 'trend', 'cash' => 'card'];
      $reportColors = ['sales' => 'c-blue', 'income' => 'c-green', 'purchases' => 'c-orange', 'expenses' => 'c-red', 'stock' => 'c-purple', 'bestsellers' => 'c-yellow', 'profit' => 'c-green', 'cash' => 'c-blue'];
      $reportUrl = route('laporan', ['report' => $key] + $filtersForLinks);
      $exportUrl = route('laporan.export', ['report' => $key] + $filtersForLinks);
      $printUrl = route('laporan.print', ['report' => $key] + $filtersForLinks);
    @endphp
    <article class="card report-card {{ $selectedReport === $key ? 'report-card-selected' : '' }}">
      <a class="report-card-main" href="{{ $reportUrl }}">
        <span class="ic {{ $reportColors[$key] }}"><x-icon :name="$reportIcons[$key]" /></span>
        <strong>{{ $label }}</strong>
      </a>
      <div class="sub">{{ $selectedReport === $key ? 'Pratinjau saat ini' : 'Buka pratinjau laporan' }}</div>
      <div class="acts">
        <a class="btn sm" href="{{ $exportUrl }}"><x-icon name="download" /> Excel / CSV</a>
        <a class="btn sm" href="{{ $printUrl }}" target="_blank" rel="noopener"><x-icon name="printer" /> PDF / Cetak</a>
      </div>
    </article>
  @endforeach
</div>

<section class="card mt">
  <div class="card-head report-preview-heading">
    <div>
      <h2>Laporan {{ $reports[$selectedReport] }}</h2>
      <div class="sub">Periode {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d M Y') }}</div>
      @if(!empty($reportData['note']))
        <div class="report-note">{{ $reportData['note'] }}</div>
      @endif
    </div>
    <div class="report-actions">
      <a class="btn" href="{{ route('laporan.export', ['report' => $selectedReport] + $filtersForLinks) }}"><x-icon name="download" /> Unduh CSV</a>
      <a class="btn primary" href="{{ route('laporan.print', ['report' => $selectedReport] + $filtersForLinks) }}" target="_blank" rel="noopener"><x-icon name="printer" /> Cetak / Simpan PDF</a>
    </div>
  </div>

  @if(!empty($reportData['summary']))
    <div class="report-summary">
      @foreach($reportData['summary'] as $key => $value)
        @php $label = $summaryLabels[$key] ?? $key; @endphp
        <div class="report-summary-item">
          <span>{{ $label }}</span>
          <strong>{{ in_array($label, $currencySummary, true) ? $money($value) : number_format((int) $value, 0, ',', '.') }}</strong>
        </div>
      @endforeach
    </div>
  @endif

  <div class="tbl-wrap">
    <table>
      <thead>
        <tr>
          @foreach($reportData['columns'] as $column)
            <th>{{ $column }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
      @forelse($reportData['rows'] as $row)
        <tr>
          @foreach($row as $index => $value)
            <td class="{{ in_array($index, $reportData['currencyColumns'] ?? [], true) ? 'r' : '' }}">
              @if(in_array($index, $reportData['currencyColumns'] ?? [], true))
                {{ $money($value) }}
              @else
                {{ $value }}
              @endif
            </td>
          @endforeach
        </tr>
      @empty
        <tr><td colspan="{{ count($reportData['columns']) }}" class="report-empty">Tidak ada data untuk laporan dan periode ini.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
