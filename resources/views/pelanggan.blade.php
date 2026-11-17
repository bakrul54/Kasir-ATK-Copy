@extends('layouts.app')
@section('title', 'Pelanggan')
@section('subtitle', 'Data pelanggan, transaksi, dan total belanja')

@section('content')
@php
  $money = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
  $loyalThreshold = \App\Models\Customer::LOYAL_SALE_THRESHOLD;
@endphp

@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="notice error" role="alert">{{ $errors->first() }}</div>
@endif

<div class="grid g-main customer-layout">
  <section class="card">
    @if($selectedCustomer)
      @php
        $isLoyal = $selectedCustomer->sales_count >= $loyalThreshold;
        $customerWhatsappUrl = $selectedCustomer->whatsappUrl();
      @endphp
      <div class="card-head">
        <div>
          <a class="customer-back" href="{{ route('pelanggan') }}">← Kembali ke daftar</a>
          <h2>{{ $selectedCustomer->name }}</h2>
          <div class="sub">
            {{ $selectedCustomer->sales_count }} transaksi · {{ $money($selectedCustomer->sales_sum_total ?? 0) }} total belanja
          </div>
        </div>
        <span class="pill {{ $isLoyal ? 'c-yellow' : 'c-gray' }}">
          {{ $isLoyal ? 'Pelanggan Tetap' : 'Reguler' }}
        </span>
      </div>

      <div class="customer-contact-line">
        <span>WhatsApp</span>
        @if($selectedCustomer->phone)
          <b>{{ $selectedCustomer->phone }}</b>
          @if($customerWhatsappUrl)
            <a class="btn sm" href="{{ $customerWhatsappUrl }}" target="_blank" rel="noopener">Hubungi</a>
          @endif
        @else
          <span class="muted">Belum dicatat</span>
        @endif
      </div>

      <h3 class="customer-section-title">Riwayat Transaksi</h3>
      @if($sales->isEmpty())
        <div class="finance-empty">Belum ada transaksi yang terhubung dengan pelanggan ini.</div>
      @else
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>No. Transaksi</th><th>Tanggal</th><th>Item</th><th>Pembayaran</th><th class="r">Total</th></tr></thead>
            <tbody>
            @foreach($sales as $sale)
              <tr>
                <td>
                  <b>{{ $sale->transaction_number }}</b>
                  @if($sale->canceled_at)<span class="pill c-red">Dibatalkan</span>@endif
                </td>
                <td>{{ $sale->created_at->format('d M Y H:i') }}</td>
                <td>{{ $sale->items_count }} baris</td>
                <td><span class="pill c-blue">{{ strtoupper($sale->payment_method) }}</span></td>
                <td class="r"><b>{{ $money($sale->total) }}</b></td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
        <div class="finance-pagination">{{ $sales->links() }}</div>
      @endif

      <details class="customer-edit">
        <summary class="btn">Ubah data pelanggan</summary>
        <form method="POST" action="{{ route('pelanggan.update', $selectedCustomer) }}" class="customer-form">
          @csrf
          @method('PUT')
          <div class="field">
            <label for="edit-customer-name">Nama</label>
            <input class="input" id="edit-customer-name" name="name" value="{{ old('name', $selectedCustomer->name) }}" maxlength="160" required>
          </div>
          <div class="field">
            <label for="edit-customer-phone">Nomor WhatsApp <span class="hint">(opsional)</span></label>
            <input class="input" id="edit-customer-phone" name="phone" type="tel" value="{{ old('phone', $selectedCustomer->phone) }}" maxlength="30" placeholder="Contoh: 0812-3456-7890">
          </div>
          <button class="btn primary" type="submit">Simpan Perubahan</button>
        </form>
      </details>
    @else
      <div class="card-head">
        <div><h2>Daftar Pelanggan</h2><div class="sub">Pelanggan tetap otomatis setelah {{ $loyalThreshold }} transaksi tercatat</div></div>
      </div>
      <form class="toolbar customer-filters" method="GET" action="{{ route('pelanggan') }}">
        <div class="field customer-search-field">
          <label for="customer-search">Nama atau WhatsApp</label>
          <input class="input" type="search" id="customer-search" name="q" value="{{ $filters['q'] }}" placeholder="Cari nama atau nomor WhatsApp">
        </div>
        <div class="field">
          <label for="customer-status">Status</label>
          <select class="input" id="customer-status" name="status">
            <option value="semua" @selected($filters['status'] === 'semua')>Semua pelanggan</option>
            <option value="tetap" @selected($filters['status'] === 'tetap')>Pelanggan tetap</option>
          </select>
        </div>
        <button class="btn primary" type="submit">Cari</button>
        <a class="btn" href="{{ route('pelanggan') }}">Reset</a>
      </form>

      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Nama</th><th>WhatsApp</th><th>Transaksi</th><th class="r">Total Belanja</th><th>Status</th><th></th></tr></thead>
          <tbody>
          @forelse($customers as $customer)
            @php $isLoyal = $customer->sales_count >= $loyalThreshold; @endphp
            <tr>
              <td><b>{{ $customer->name }}</b></td>
              <td>{{ $customer->phone ?: '—' }}</td>
              <td>{{ $customer->sales_count }} transaksi</td>
              <td class="r"><b>{{ $money($customer->sales_sum_total ?? 0) }}</b></td>
              <td><span class="pill {{ $isLoyal ? 'c-yellow' : 'c-gray' }}">{{ $isLoyal ? 'Pelanggan Tetap' : 'Reguler' }}</span></td>
              <td class="r"><a class="btn sm" href="{{ route('pelanggan.show', $customer) }}">Detail</a></td>
            </tr>
          @empty
            <tr><td colspan="6" class="finance-empty">Belum ada pelanggan yang cocok. Tambahkan pelanggan melalui formulir.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      <div class="finance-pagination">{{ $customers->links() }}</div>
    @endif
  </section>

  <aside class="card" id="form-pelanggan">
    <div class="card-head">
      <div><h2>{{ $selectedCustomer ? 'Perlu pelanggan lain?' : 'Tambah Pelanggan' }}</h2><div class="sub">Nama dan nomor WhatsApp untuk menghubungkan transaksi.</div></div>
    </div>
    <form method="POST" action="{{ route('pelanggan.store') }}" class="customer-form">
      @csrf
      <div class="field">
        <label for="new-customer-name">Nama pelanggan</label>
        <input class="input" id="new-customer-name" name="name" value="{{ old('name') }}" maxlength="160" required>
        @error('name')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="new-customer-phone">Nomor WhatsApp <span class="hint">(opsional)</span></label>
        <input class="input" id="new-customer-phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="30" placeholder="Contoh: 0812-3456-7890">
        @error('phone')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <button class="btn primary block" type="submit">Simpan Pelanggan</button>
    </form>
  </aside>
</div>
@endsection
