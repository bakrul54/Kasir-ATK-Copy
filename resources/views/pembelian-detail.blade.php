@extends('layouts.app')
@section('title', 'Detail Pembelian')
@section('subtitle', $purchase->purchase_number)

@section('content')
<div class="card">
  <div class="card-head">
    <div>
      <h2>{{ $purchase->purchase_number }}</h2>
      <div class="sub">Disimpan oleh {{ $purchase->user?->name ?? 'Akun dihapus' }}</div>
    </div>
    <a class="btn" href="{{ route('pembelian') }}">Kembali ke Riwayat</a>
  </div>

  <div class="purchase-detail-grid">
    <div><small>Tanggal Pembelian</small><strong>{{ $purchase->purchase_date->format('d M Y') }}</strong></div>
    <div><small>Supplier</small><strong>{{ $purchase->supplier->name }}</strong></div>
    <div><small>Telepon Supplier</small><strong>{{ $purchase->supplier->phone ?: '—' }}</strong></div>
    <div><small>Alamat Supplier</small><strong>{{ $purchase->supplier->address ?: '—' }}</strong></div>
    <div><small>Nomor Nota Supplier</small><strong>{{ $purchase->invoice_number ?: '—' }}</strong></div>
    <div><small>Status Pembayaran</small><span class="pill {{ $purchase->payment_status === 'lunas' ? 'c-green' : 'c-orange' }}">{{ $purchase->payment_status === 'lunas' ? 'Lunas' : 'Hutang' }}</span></div>
    <div><small>Dibayar dari saldo</small><strong>{{ \App\Models\CashEntry::accountLabels()[$purchase->payment_account] }}</strong></div>
  </div>

  <div class="tbl-wrap mt">
    <table>
      <thead>
        <tr><th>Produk</th><th class="r">Jumlah</th><th class="r">Harga Beli / Satuan</th><th class="r">Subtotal</th></tr>
      </thead>
      <tbody>
        @foreach($purchase->items as $item)
          <tr>
            <td><strong>{{ $item->product->name }}</strong>{{ $item->product->sku ? ' · '.$item->product->sku : '' }}</td>
            <td class="r">{{ number_format($item->quantity, 0, ',', '.') }} {{ $item->product->unit }}</td>
            <td class="r">Rp {{ number_format($item->unit_cost, 0, ',', '.') }}</td>
            <td class="r"><strong>Rp {{ number_format($item->subtotal, 0, ',', '.') }}</strong></td>
          </tr>
        @endforeach
        <tr><td colspan="3" class="r"><strong>Total Pembelian</strong></td><td class="r"><strong>Rp {{ number_format($purchase->total, 0, ',', '.') }}</strong></td></tr>
      </tbody>
    </table>
  </div>

  @if($purchase->notes)
    <div class="purchase-note"><strong>Catatan</strong><p>{{ $purchase->notes }}</p></div>
  @endif

  @if($purchase->receipt_path)
    <div class="purchase-note">
      <strong>Foto Nota</strong>
      <a class="receipt-link" href="{{ \Illuminate\Support\Facades\Storage::url($purchase->receipt_path) }}" target="_blank" rel="noopener">Lihat foto nota</a>
    </div>
  @endif
</div>
@endsection
