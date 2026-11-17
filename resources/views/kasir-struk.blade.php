<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Struk {{ $sale->transaction_number }} — {{ $settings->store_name }}</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <style>
    .receipt{width:{{ $settings->printer_paper_size }};max-width:100%}
    @page{size:{{ $settings->printer_paper_size }};margin:0}
    @media print{.receipt{width:{{ $settings->printer_paper_size }};padding:2mm}}
  </style>
</head>
<body class="receipt-page {{ $settings->printer_paper_size === '58mm' ? 'receipt-page-58mm' : '' }}">
  <main class="receipt {{ $settings->printer_paper_size === '58mm' ? 'receipt-58mm' : '' }}">
    <header class="receipt-header">
      @if($settings->receipt_show_logo && $settings->logo_path)
        <img class="receipt-logo" src="{{ \Illuminate\Support\Facades\Storage::url($settings->logo_path) }}" alt="Logo {{ $settings->store_name }}">
      @endif
      <h1>{{ $settings->store_name }}</h1>
      @if($settings->address)<p>{{ $settings->address }}</p>@endif
      @if($settings->whatsapp)<p>WhatsApp: {{ $settings->whatsapp }}</p>@endif
      @if($settings->receipt_header)<p>{{ $settings->receipt_header }}</p>@endif
    </header>
    <div class="receipt-meta">
      <div><span>No. Transaksi</span><strong>{{ $sale->transaction_number }}</strong></div>
      <div><span>Tanggal</span><strong>{{ $sale->created_at->format('d M Y H:i') }}</strong></div>
      <div><span>Pelanggan</span><strong>{{ $sale->customer_name ?: 'Umum' }}</strong></div>
      <div><span>Kasir</span><strong>{{ $sale->user?->name ?? 'Akun dihapus' }}</strong></div>
    </div>
    @if($sale->canceled_at)
      <p class="notice error" role="status">
        TRANSAKSI DIBATALKAN — {{ $sale->cancellation_reason }}
      </p>
    @endif
    @if($sale->payment_method !== 'cash' && $settings->payment_information)
      <p class="receipt-payment-info">{{ $settings->payment_information }}</p>
    @endif
    @if($settings->printer_paper_size === '58mm')
      <table class="receipt-items receipt-items-compact">
        <thead><tr><th>Barang / Jasa</th><th class="r">Subtotal</th></tr></thead>
        <tbody>
          @foreach($sale->items as $item)
            <tr>
              <td>
                <strong class="receipt-item-name">{{ $item->product_name }}</strong>
                <span class="receipt-item-details">
                  {{ number_format($item->quantity, 0, ',', '.') }} {{ $item->unit }}
                  × Rp {{ number_format($item->unit_price, 0, ',', '.') }}
                </span>
              </td>
              <td class="r receipt-item-subtotal">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <table class="receipt-items">
        <thead><tr><th>Barang / Jasa</th><th class="r">Qty</th><th class="r">Harga</th><th class="r">Subtotal</th></tr></thead>
        <tbody>
          @foreach($sale->items as $item)
            <tr>
              <td>{{ $item->product_name }}</td>
              <td class="r">{{ number_format($item->quantity, 0, ',', '.') }} {{ $item->unit }}</td>
              <td class="r">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
              <td class="r">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
    <div class="receipt-totals">
      <div><span>Subtotal</span><strong>Rp {{ number_format($sale->subtotal, 0, ',', '.') }}</strong></div>
      <div><span>Diskon</span><strong>− Rp {{ number_format($sale->discount_amount, 0, ',', '.') }}</strong></div>
      <div class="receipt-grand-total"><span>Total</span><strong>Rp {{ number_format($sale->total, 0, ',', '.') }}</strong></div>
      @if($sale->tip_amount > 0)
        <div><span>Tip</span><strong>Rp {{ number_format($sale->tip_amount, 0, ',', '.') }}</strong></div>
      @endif
      <div><span>{{ strtoupper($sale->payment_method) }}</span><strong>Rp {{ number_format($sale->amount_received, 0, ',', '.') }}</strong></div>
      @if($sale->payment_method === 'cash')
        <div><span>Kembalian</span><strong>Rp {{ number_format($sale->change_amount, 0, ',', '.') }}</strong></div>
      @endif
    </div>
    <footer class="receipt-footer">{{ $settings->receipt_footer ?: 'Simpan struk ini sebagai bukti transaksi.' }}</footer>
    <button class="btn primary receipt-print" type="button" onclick="window.print()">Cetak Struk</button>
  </main>
  @if($settings->receipt_auto_print)
    <script>window.addEventListener('load', () => window.print());</script>
  @endif
</body>
</html>
