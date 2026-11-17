@extends('layouts.app')
@section('title', 'Riwayat Harga')
@section('subtitle', 'Perubahan harga {{ $product->name }}')

@section('content')
@php
  $priceLabels = [
    'sale_price' => 'Harga normal',
    'cost_price' => 'Harga modal',
    'wholesale_price' => 'Harga grosir',
    'wholesale_min_qty' => 'Jumlah minimum grosir',
    'promo_price' => 'Harga promo',
  ];
@endphp

<div class="card">
  <div class="card-head">
    <div>
      <h2>Riwayat Harga {{ $product->name }}</h2>
      <div class="sub">{{ $product->category->name }}{{ $product->sku ? ' · '.$product->sku : '' }}</div>
    </div>
    <a class="btn" href="{{ route('harga') }}">Kembali ke Manajemen Harga</a>
  </div>

  @if($histories->isEmpty())
    <div class="empty-state">
      <strong>Belum ada perubahan harga</strong>
      <p>Riwayat perubahan harga produk ini akan muncul di sini.</p>
    </div>
  @else
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Diubah oleh</th>
            <th>Perubahan</th>
          </tr>
        </thead>
        <tbody>
          @foreach($histories as $history)
            <tr>
              <td>{{ $history->created_at->format('d M Y H:i') }}</td>
              <td>{{ $history->user?->name ?? 'Akun dihapus' }}</td>
              <td>
                <div class="history-changes">
                  @foreach($history->new_prices as $field => $newValue)
                    <div>
                      <strong>{{ $priceLabels[$field] ?? $field }}:</strong>
                      <span>{{ $field === 'wholesale_min_qty' ? ($history->old_prices[$field] ?? '—') : (isset($history->old_prices[$field]) ? 'Rp '.number_format($history->old_prices[$field], 0, ',', '.') : '—') }}</span>
                      <span aria-hidden="true">→</span>
                      <b>{{ $field === 'wholesale_min_qty' ? ($newValue ?? '—') : ($newValue === null ? '—' : 'Rp '.number_format($newValue, 0, ',', '.')) }}</b>
                    </div>
                  @endforeach
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="pagination-wrap">{{ $histories->links() }}</div>
  @endif
</div>
@endsection
