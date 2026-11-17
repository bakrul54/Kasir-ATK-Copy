@extends('layouts.app')
@section('title', 'Pembelian / Belanja')
@section('subtitle', 'Catat pembelian barang dan perbarui stok secara otomatis')

@section('content')
@php
  $periods = [
    'month' => 'Bulan Ini',
    'last_month' => 'Bulan Lalu',
    'all' => 'Semua',
  ];
@endphp

@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="notice error" role="alert">
    <strong>Pembelian belum disimpan. Periksa kembali data berikut:</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

<div class="grid g3">
  <div class="card stat"><div class="lbl">Pembelian Bulan Ini</div><div class="val">Rp {{ number_format($stats['this_month'], 0, ',', '.') }}</div><div class="note muted">{{ number_format($stats['count'], 0, ',', '.') }} nota tercatat</div></div>
  <div class="card stat"><div class="lbl">Total Nota</div><div class="val">{{ number_format($purchases->total(), 0, ',', '.') }}</div><div class="note muted">Sesuai periode dan filter</div></div>
  <div class="card stat"><div class="lbl">Total Belum Lunas</div><div class="val t-orange">Rp {{ number_format($stats['unpaid'], 0, ',', '.') }}</div><div class="note muted">Nota berstatus hutang</div></div>
</div>

<div class="grid g-main mt">
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Pembelian Baru</h2>
        <div class="sub">Pembelian langsung menambah stok dan mencatat harga modal terbaru.</div>
      </div>
    </div>

    @if($suppliers->isEmpty())
      <p class="hint">Tambahkan supplier terlebih dahulu sebelum mencatat pembelian.</p>
    @endif
    @if($products->isEmpty())
      <p class="hint">Belum ada produk aktif yang menggunakan stok.</p>
    @endif

    @if($suppliers->isNotEmpty() && $products->isNotEmpty())
      <form method="POST" action="{{ route('pembelian.store') }}" enctype="multipart/form-data" id="purchase-form">
        @csrf
        <div class="purchase-header-grid">
          <div class="field">
            <label for="supplier_id">Supplier</label>
            <select class="input" name="supplier_id" id="supplier_id" required>
              <option value="">Pilih supplier</option>
              @foreach($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="invoice_number">Nomor Nota Supplier <span class="hint">(opsional)</span></label>
            <input class="input" type="text" name="invoice_number" id="invoice_number" value="{{ old('invoice_number') }}" maxlength="120" placeholder="Mis. NB-0001">
          </div>
          <div class="field">
            <label for="purchase_date">Tanggal Pembelian</label>
            <input class="input" type="date" name="purchase_date" id="purchase_date" value="{{ old('purchase_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
          </div>
          <div class="field">
            <label for="payment_status">Status Pembayaran</label>
            <select class="input" name="payment_status" id="payment_status" required>
              <option value="lunas" @selected(old('payment_status', 'lunas') === 'lunas')>Lunas</option>
              <option value="hutang" @selected(old('payment_status') === 'hutang')>Hutang</option>
            </select>
          </div>
          <div class="field">
            <label for="payment_account">Dibayar dari saldo</label>
            <select class="input" name="payment_account" id="payment_account" required>
              @foreach(\App\Models\CashEntry::accountLabels() as $account => $label)
                <option value="{{ $account }}" @selected(old('payment_account', \App\Models\CashEntry::ACCOUNT_DRAWER) === $account)>{{ $label }}</option>
              @endforeach
            </select>
            @error('payment_account')<small class="field-error">{{ $message }}</small>@enderror
          </div>
        </div>

        <div class="purchase-items-heading">
          <div>
            <h3>Barang yang Dibeli</h3>
            <p class="hint">Harga beli per satuan dicatat sebagai harga modal terbaru produk.</p>
          </div>
          <button class="btn" type="button" id="add-purchase-item"><x-icon name="plus" /> Tambah Barang</button>
        </div>

        <div class="purchase-item-list" id="purchase-item-list">
          @foreach(old('items', [['product_id' => '', 'quantity' => 1, 'unit_cost' => '']]) as $index => $item)
            <div class="purchase-item-row">
              <div class="field">
                <label for="product-{{ $index }}">Produk</label>
                <select class="input purchase-product" id="product-{{ $index }}" name="items[{{ $index }}][product_id]" required>
                  <option value="">Pilih produk</option>
                  @foreach($products as $product)
                    <option value="{{ $product->id }}" data-unit="{{ $product->unit }}" data-cost="{{ $product->cost_price }}" @selected((string) ($item['product_id'] ?? '') === (string) $product->id)>
                      {{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div class="field">
                <label for="quantity-{{ $index }}">Jumlah</label>
                <div class="purchase-quantity-field">
                  <input class="input purchase-quantity" id="quantity-{{ $index }}" type="number" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? 1 }}" min="1" step="1" required>
                  <span class="purchase-unit">{{ $products->firstWhere('id', (int) ($item['product_id'] ?? 0))?->unit ?? 'unit' }}</span>
                </div>
              </div>
              <div class="field">
                <label for="unit-cost-{{ $index }}">Harga Beli / Satuan (Rp)</label>
                <input class="input purchase-unit-cost" id="unit-cost-{{ $index }}" type="number" name="items[{{ $index }}][unit_cost]" value="{{ $item['unit_cost'] ?? '' }}" min="0" step="1" required>
              </div>
              <div class="purchase-subtotal">
                <small>Subtotal</small>
                <strong>Rp <span class="purchase-row-total">0</span></strong>
              </div>
              <button class="btn sm danger remove-purchase-item" type="button" aria-label="Hapus baris produk"><x-icon name="trash" /></button>
            </div>
          @endforeach
        </div>
        @error('items')<p class="field-error">{{ $message }}</p>@enderror
        @error('items.*.product_id')<p class="field-error">{{ $message }}</p>@enderror
        @error('items.*.quantity')<p class="field-error">{{ $message }}</p>@enderror
        @error('items.*.unit_cost')<p class="field-error">{{ $message }}</p>@enderror

        <div class="purchase-footer-grid">
          <div class="field">
            <label for="notes">Catatan <span class="hint">(opsional)</span></label>
            <textarea class="input" name="notes" id="notes" maxlength="1000" placeholder="Catatan pembelian">{{ old('notes') }}</textarea>
          </div>
          <div class="field">
            <label for="receipt_photo">Foto Nota <span class="hint">(JPG/PNG/WebP maks. 4 MB)</span></label>
            <input class="input" type="file" name="receipt_photo" id="receipt_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
          </div>
          <div class="purchase-total-box">
            <span>Total Belanja</span>
            <strong>Rp <span id="purchase-total">0</span></strong>
            <small>Dihitung ulang oleh server saat disimpan.</small>
          </div>
        </div>
        <button class="btn primary block" type="submit">Simpan Pembelian &amp; Tambah Stok</button>
      </form>
    @endif

    <details class="supplier-manager">
      <summary class="btn">Tambah Supplier</summary>
      <form class="supplier-form" method="POST" action="{{ route('pembelian.suppliers.store') }}">
        @csrf
        <div class="field">
          <label for="supplier-name">Nama Supplier</label>
          <input class="input" name="name" id="supplier-name" maxlength="160" required>
        </div>
        <div class="field">
          <label for="supplier-phone">Nomor Telepon</label>
          <input class="input" name="phone" id="supplier-phone" maxlength="30">
        </div>
        <div class="field">
          <label for="supplier-address">Alamat</label>
          <input class="input" name="address" id="supplier-address" maxlength="255">
        </div>
        <button class="btn primary" type="submit">Simpan Supplier</button>
      </form>
    </details>
  </div>

  <aside class="card purchase-guidance">
    <div class="card-head"><h2>Alur Pembelian</h2></div>
    <ol>
      <li>Pilih supplier dan masukkan nomor nota bila tersedia.</li>
      <li>Tambahkan tiap produk satu kali, lalu isi jumlah dan harga belinya.</li>
      <li>Simpan pembelian untuk menambah stok dan mencatat riwayat.</li>
      <li>Harga modal produk diperbarui sesuai harga beli terbaru.</li>
    </ol>
    <p class="hint">Status hutang hanya dicatat sebagai status pembelian. Modul pembayaran hutang belum tersedia.</p>
  </aside>
</div>

<div class="card mt">
  <div class="card-head purchase-history-heading">
    <div>
      <h2>Riwayat Pembelian</h2>
      <div class="sub">Nota yang sudah tersimpan dan menambah stok</div>
    </div>
    <nav class="tabs" aria-label="Filter periode pembelian">
      @foreach($periods as $key => $label)
        <a class="{{ $period === $key ? 'on' : '' }}" href="{{ route('pembelian', array_merge(request()->except('period', 'page'), ['period' => $key])) }}">{{ $label }}</a>
      @endforeach
    </nav>
  </div>
  <form class="toolbar purchase-filters" method="GET" action="{{ route('pembelian') }}">
    <div class="search" style="width:280px">
      <x-icon name="search" />
      <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari no. pembelian, nota, supplier...">
    </div>
    <input type="hidden" name="period" value="{{ $period }}">
    <select class="input filter-select" name="payment_status" aria-label="Filter status pembayaran">
      <option value="">Semua pembayaran</option>
      <option value="lunas" @selected(request('payment_status') === 'lunas')>Lunas</option>
      <option value="hutang" @selected(request('payment_status') === 'hutang')>Hutang</option>
    </select>
    <button class="btn" type="submit">Cari / Filter</button>
  </form>

  @if($purchases->isEmpty())
    <div class="empty-state">
      <strong>Belum ada pembelian pada periode ini</strong>
      <p>Pembelian yang disimpan akan muncul di tabel riwayat ini.</p>
    </div>
  @else
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>No. Pembelian</th>
            <th>Tanggal</th>
            <th>Supplier</th>
            <th>Nota Supplier</th>
            <th class="r">Total</th>
            <th>Status Pembayaran</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($purchases as $purchase)
            <tr>
              <td><strong>{{ $purchase->purchase_number }}</strong></td>
              <td>{{ $purchase->purchase_date->format('d M Y') }}</td>
              <td>{{ $purchase->supplier->name }}</td>
              <td>{{ $purchase->invoice_number ?: '—' }}</td>
              <td class="r"><strong>Rp {{ number_format($purchase->total, 0, ',', '.') }}</strong></td>
              <td><span class="pill {{ $purchase->payment_status === 'lunas' ? 'c-green' : 'c-orange' }}">{{ $purchase->payment_status === 'lunas' ? 'Lunas' : 'Hutang' }}</span></td>
              <td class="r"><a class="btn sm" href="{{ route('pembelian.show', $purchase) }}">Detail</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="pagination-wrap">{{ $purchases->links() }}</div>
  @endif
</div>

<template id="purchase-item-template">
  <div class="purchase-item-row">
    <div class="field">
      <label>Produk</label>
      <select class="input purchase-product" required>
        <option value="">Pilih produk</option>
        @foreach($products as $product)
          <option value="{{ $product->id }}" data-unit="{{ $product->unit }}" data-cost="{{ $product->cost_price }}">
            {{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}
          </option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label>Jumlah</label>
      <div class="purchase-quantity-field">
        <input class="input purchase-quantity" type="number" min="1" step="1" value="1" required>
        <span class="purchase-unit">unit</span>
      </div>
    </div>
    <div class="field">
      <label>Harga Beli / Satuan (Rp)</label>
      <input class="input purchase-unit-cost" type="number" min="0" step="1" required>
    </div>
    <div class="purchase-subtotal"><small>Subtotal</small><strong>Rp <span class="purchase-row-total">0</span></strong></div>
    <button class="btn sm danger remove-purchase-item" type="button" aria-label="Hapus baris produk"><x-icon name="trash" /></button>
  </div>
</template>

<script>
  const itemList = document.getElementById('purchase-item-list');
  const itemTemplate = document.getElementById('purchase-item-template');
  const totalDisplay = document.getElementById('purchase-total');
  const formatRupiah = (value) => new Intl.NumberFormat('id-ID').format(value);

  const refreshPurchaseTotals = () => {
    let grandTotal = 0;
    itemList.querySelectorAll('.purchase-item-row').forEach((row) => {
      const quantity = Number(row.querySelector('.purchase-quantity').value || 0);
      const unitCost = Number(row.querySelector('.purchase-unit-cost').value || 0);
      const subtotal = quantity * unitCost;
      grandTotal += subtotal;
      row.querySelector('.purchase-row-total').textContent = formatRupiah(subtotal);
    });
    totalDisplay.textContent = formatRupiah(grandTotal);
  };

  const refreshPurchaseNames = () => {
    itemList.querySelectorAll('.purchase-item-row').forEach((row, index) => {
      const product = row.querySelector('.purchase-product');
      const quantity = row.querySelector('.purchase-quantity');
      const unitCost = row.querySelector('.purchase-unit-cost');
      product.name = `items[${index}][product_id]`;
      quantity.name = `items[${index}][quantity]`;
      unitCost.name = `items[${index}][unit_cost]`;
      product.id = `product-${index}`;
      quantity.id = `quantity-${index}`;
      unitCost.id = `unit-cost-${index}`;
      row.querySelectorAll('label').forEach((label, labelIndex) => {
        label.htmlFor = [product.id, quantity.id, unitCost.id][labelIndex];
      });
    });
    refreshPurchaseTotals();
  };

  document.getElementById('add-purchase-item')?.addEventListener('click', () => {
    itemList.append(itemTemplate.content.cloneNode(true));
    refreshPurchaseNames();
  });

  itemList?.addEventListener('input', refreshPurchaseTotals);
  itemList?.addEventListener('change', (event) => {
    if (event.target.matches('.purchase-product')) {
      const option = event.target.selectedOptions[0];
      const row = event.target.closest('.purchase-item-row');
      row.querySelector('.purchase-unit').textContent = option.dataset.unit || 'unit';
      row.querySelector('.purchase-unit-cost').value = option.dataset.cost || '';
    }
    refreshPurchaseTotals();
  });
  itemList?.addEventListener('click', (event) => {
    const removeButton = event.target.closest('.remove-purchase-item');
    if (removeButton && itemList.querySelectorAll('.purchase-item-row').length > 1) {
      removeButton.closest('.purchase-item-row').remove();
      refreshPurchaseNames();
    }
  });
  refreshPurchaseTotals();
  refreshPurchaseNames();
</script>
@endsection
