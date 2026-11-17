@extends('layouts.app')
@section('title', 'Detail Transaksi')
@section('subtitle', $sale->transaction_number)

@section('content')
@php $refundAmount = $sale->total + $sale->tip_amount; @endphp
<div class="card">
  @if(session('success'))
    <div class="notice success" role="status">{{ session('success') }}</div>
  @endif
  @if($errors->any())
    <div class="notice error" role="alert">
      <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif
  <div class="card-head">
    <div>
      <h2>{{ $sale->transaction_number }}</h2>
      <div class="sub">Transaksi {{ $sale->created_at->format('d M Y H:i') }}</div>
    </div>
    <div class="row">
      <a class="btn" href="{{ route('kasir', $backQuery) }}">Kembali ke Riwayat</a>
      <a class="btn primary" href="{{ route('kasir.receipt', $sale) }}" target="_blank" rel="noopener"><x-icon name="printer" /> Struk</a>
    </div>
  </div>

  @if($sale->canceled_at)
    <div class="notice error" role="status">
      <strong>Transaksi dibatalkan</strong>
      <p>{{ $sale->cancellation_reason }}</p>
      <small>
        {{ $sale->canceled_at->format('d M Y H:i') }}
        oleh {{ $sale->canceledBy?->name ?? 'Akun dihapus' }}
      </small>
    </div>
    @if($sale->refundEntry)
      <div class="notice success mt" role="status">
        Refund Rp {{ number_format($sale->refundEntry->amount, 0, ',', '.') }} sudah dicatat sebagai kas keluar
        pada {{ $sale->refundEntry->transaction_date->format('d M Y') }}.
      </div>
    @elseif(in_array(auth()->user()->role, ['owner', 'admin'], true))
      <form class="notice error mt" method="POST" action="{{ route('kasir.refund', $sale) }}" onsubmit="return confirm('Pastikan dana Rp {{ number_format($refundAmount, 0, ',', '.') }} sudah benar-benar dikembalikan sebelum mencatat refund.');">
        @csrf
        <strong>Refund belum dicatat</strong>
        <p>Kembalikan dana sebesar total transaksi{{ $sale->tip_amount > 0 ? ' dan tip' : '' }} (Rp {{ number_format($refundAmount, 0, ',', '.') }}) secara manual, lalu konfirmasi untuk mencatatnya sebagai kas keluar.</p>
        <label class="row mt">
          <input type="checkbox" name="refund_confirmed" value="1" required>
          Dana sudah benar-benar dikembalikan
        </label>
        <button class="btn danger mt" type="submit">Catat Refund</button>
      </form>
    @endif
  @elseif(in_array(auth()->user()->role, ['owner', 'admin'], true))
    <form class="notice error mt" method="POST" action="{{ route('kasir.cancel', $sale) }}" onsubmit="return confirm('Pastikan dana Rp {{ number_format($refundAmount, 0, ',', '.') }} sudah dikembalikan. Transaksi akan dibatalkan dan refund dicatat sebagai kas keluar.');">
      @csrf
      <strong>Batalkan transaksi</strong>
      <p>Pengembalian uang dilakukan manual. Setelah konfirmasi, stok dikembalikan dan refund sebesar total transaksi{{ $sale->tip_amount > 0 ? ' dan tip' : '' }} dicatat sebagai kas keluar.</p>
      @error('reason')<small class="field-error">{{ $message }}</small>@enderror
      <div class="field">
        <label for="cancellation-reason">Alasan pembatalan</label>
        <input class="input" id="cancellation-reason" name="reason" value="{{ old('reason') }}" maxlength="500" required>
      </div>
      @error('refund_confirmed')<small class="field-error">{{ $message }}</small>@enderror
      <label class="row mt">
        <input type="checkbox" name="refund_confirmed" value="1" required>
        Dana sudah benar-benar dikembalikan kepada pelanggan
      </label>
      <button class="btn danger mt" type="submit">Batalkan Transaksi</button>
    </form>
  @endif

  <div class="purchase-detail-grid">
    <div><small>Pelanggan</small><strong>{{ $sale->customer_name ?: 'Umum' }}</strong></div>
    <div><small>Kasir</small><strong>{{ $sale->user?->name ?? 'Akun dihapus' }}</strong></div>
    <div><small>Metode Pembayaran</small><strong>{{ strtoupper($sale->payment_method) }}</strong></div>
    <div><small>Subtotal</small><strong>Rp {{ number_format($sale->subtotal, 0, ',', '.') }}</strong></div>
    <div>
      <small>Diskon Transaksi</small>
      <strong>
        {{ $sale->discount_type === 'persen' ? $sale->discount_value.'%' : 'Rp '.number_format($sale->discount_value, 0, ',', '.') }}
        (Rp {{ number_format($sale->discount_amount, 0, ',', '.') }})
      </strong>
    </div>
    <div><small>Total</small><strong>Rp {{ number_format($sale->total, 0, ',', '.') }}</strong></div>
    @if($sale->tip_amount > 0)
      <div><small>Tip</small><strong>Rp {{ number_format($sale->tip_amount, 0, ',', '.') }}</strong></div>
    @endif
    <div><small>Uang Diterima</small><strong>Rp {{ number_format($sale->amount_received, 0, ',', '.') }}</strong></div>
    @if($sale->payment_method === 'cash')
      <div><small>Kembalian</small><strong>Rp {{ number_format($sale->change_amount, 0, ',', '.') }}</strong></div>
    @endif
  </div>

  <div class="tbl-wrap mt">
    <table>
      <thead>
        <tr><th>Produk / Jasa</th><th class="r">Jumlah</th><th class="r">Harga Satuan</th><th class="r">Subtotal</th></tr>
      </thead>
      <tbody>
        @foreach($sale->items as $item)
          <tr>
            <td>
              <strong>{{ $item->product_name }}</strong>
              @if($item->sku)<small class="sub">{{ $item->sku }}</small>@endif
            </td>
            <td class="r">{{ number_format($item->quantity, 0, ',', '.') }} {{ $item->unit }}</td>
            <td class="r">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
            <td class="r"><strong>Rp {{ number_format($item->subtotal, 0, ',', '.') }}</strong></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection
