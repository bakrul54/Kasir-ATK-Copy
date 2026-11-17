<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Laporan {{ $title }} — Spot Print</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="report-print-page">
  @php
    $money = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
    $currencySummary = ['Pendapatan', 'Total pendapatan', 'Total pembelian', 'Belum lunas', 'Total pengeluaran', 'Nilai persediaan', 'Nilai baris penjualan sebelum diskon', 'Kas masuk', 'Kas keluar', 'Perubahan kas bersih', 'revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses', 'net_profit'];
  @endphp
  <main class="report-print">
    <header class="report-print-header">
      <div>
        <h1>Spot Print</h1>
        <p>Fotocopy • Cetak Foto • ATK</p>
      </div>
      <button class="btn primary report-print-button" type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </header>
    <h2>Laporan {{ $title }}</h2>
    <p class="report-print-period">Periode {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d M Y') }}</p>
    @if(!empty($reportData['note']))
      <p class="report-note">{{ $reportData['note'] }}</p>
    @endif
    @if(!empty($reportData['summary']))
      <div class="report-summary">
        @foreach($reportData['summary'] as $key => $value)
          @php $label = $reportData['summaryLabels'][$key] ?? $key; @endphp
          <div class="report-summary-item"><span>{{ $label }}</span><strong>{{ in_array($label, $currencySummary, true) ? $money($value) : number_format((int) $value, 0, ',', '.') }}</strong></div>
        @endforeach
      </div>
    @endif
    <div class="tbl-wrap">
      <table>
        <thead><tr>@foreach($reportData['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($reportData['rows'] as $row)
          <tr>@foreach($row as $index => $value)<td>{{ in_array($index, $reportData['currencyColumns'] ?? [], true) ? $money($value) : $value }}</td>@endforeach</tr>
        @empty
          <tr><td colspan="{{ count($reportData['columns']) }}" class="report-empty">Tidak ada data untuk laporan dan periode ini.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <footer class="report-print-footer">Dicetak {{ now()->format('d M Y H:i') }} · Spot Print</footer>
  </main>
</body>
</html>
