@extends('layouts.app')
@section('title', 'Manajemen Harga')
@section('subtitle', 'Atur harga produk dan jasa Spot Print dari satu tempat')

@section('content')
@php
  $status = request('status', 'semua');
  $statuses = [
    'semua' => 'Semua',
    'aktif' => 'Aktif',
    'promo' => 'Promo',
    'nonaktif' => 'Nonaktif',
  ];
  $priceLabels = [
    'sale_price' => 'Harga normal',
    'cost_price' => 'Harga modal',
    'wholesale_price' => 'Harga grosir',
    'wholesale_min_qty' => 'Jumlah minimum grosir',
    'promo_price' => 'Harga promo',
  ];
  $hasFilters = request()->filled('q') || request()->filled('category') || $status !== 'semua';
@endphp

@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif

@if($errors->any())
  <div class="notice error" role="alert">
    <strong>Perubahan belum disimpan. Periksa harga dan pengaturan grup grosir.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

<div class="card">
  <div class="price-intro">
    <div>
      <h2>Harga Produk &amp; Jasa</h2>
      <p class="sub">Perbarui satu produk per penyimpanan. Harga yang sama akan langsung tampil di Etalase.</p>
    </div>
    <a class="btn" href="{{ route('etalase') }}">Kelola Produk &amp; Kategori</a>
  </div>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>Grup Grosir Gabungan</h2>
        <div class="sub">Jumlah produk dalam grup dijumlahkan. Harga grosir tetap mengikuti harga masing-masing produk.</div>
      </div>
    </div>

    <form class="toolbar" method="POST" action="{{ route('harga.wholesale-groups.store') }}">
      @csrf
      <input class="input" type="text" name="name" value="{{ old('name') }}" maxlength="100" placeholder="Nama grup, mis. Print" aria-label="Nama grup grosir" required>
      <input class="input" type="number" name="minimum_quantity" value="{{ old('minimum_quantity') }}" min="1" max="4294967295" step="1" placeholder="Minimal lembar" aria-label="Jumlah minimum grup grosir" required>
      <button class="btn primary" type="submit">Tambah Grup</button>
    </form>
    @error('name')<p class="field-error">{{ $message }}</p>@enderror
    @error('minimum_quantity')<p class="field-error">{{ $message }}</p>@enderror

    @if($wholesaleGroups->isNotEmpty())
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Grup</th><th>Jumlah minimum</th><th>Produk terhubung</th><th>Aksi</th></tr></thead>
          <tbody>
            @foreach($wholesaleGroups as $group)
              <tr>
                <td colspan="3">
                  <form id="wholesale-group-form-{{ $group->id }}" method="POST" action="{{ route('harga.wholesale-groups.update', $group) }}">
                    @csrf
                    @method('PATCH')
                  </form>
                  <div class="price-inputs wholesale-group-inputs">
                    <label class="sr-only" for="wholesale-group-name-{{ $group->id }}">Nama grup</label>
                    <input class="input" form="wholesale-group-form-{{ $group->id }}" id="wholesale-group-name-{{ $group->id }}" type="text" name="name" value="{{ $group->name }}" maxlength="100" required>
                    <label class="sr-only" for="wholesale-group-min-{{ $group->id }}">Jumlah minimum</label>
                    <input class="input" form="wholesale-group-form-{{ $group->id }}" id="wholesale-group-min-{{ $group->id }}" type="number" name="minimum_quantity" value="{{ $group->minimum_quantity }}" min="1" max="4294967295" step="1" required>
                    <span class="muted">{{ $group->products_count }} produk</span>
                  </div>
                </td>
                <td class="price-actions">
                  <button class="btn primary sm" type="submit" form="wholesale-group-form-{{ $group->id }}">Simpan</button>
                  <form method="POST" action="{{ route('harga.wholesale-groups.destroy', $group) }}" onsubmit="return confirm('Hapus grup ini? Produk akan kembali menggunakan minimum grosir masing-masing.')">
                    @csrf
                    @method('DELETE')
                    <button class="btn danger sm" type="submit">Hapus</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

  <form class="toolbar" method="GET" action="{{ route('harga') }}">
    <div class="search" style="width:280px">
      <x-icon name="search" />
      <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari produk, SKU, kategori...">
    </div>
    <select class="input filter-select" name="category" aria-label="Filter kategori">
      <option value="">Semua kategori</option>
      @foreach($categories as $category)
        <option value="{{ $category->id }}" @selected((string) request('category') === (string) $category->id)>{{ $category->name }}</option>
      @endforeach
    </select>
    <input type="hidden" name="status" value="{{ $status }}">
    <button class="btn" type="submit">Cari / Filter</button>
  </form>

  <nav class="toolbar tabs" aria-label="Filter status produk">
    @foreach($statuses as $key => $label)
      <a class="{{ $status === $key ? 'on' : '' }}" href="{{ route('harga', array_merge(request()->except('status', 'page'), ['status' => $key])) }}">{{ $label }}</a>
    @endforeach
  </nav>

  @if($products->isEmpty())
    <div class="empty-state">
      <strong>{{ $hasFilters ? 'Produk tidak ditemukan' : 'Belum ada produk untuk diatur' }}</strong>
      <p>{{ $hasFilters ? 'Ubah kata kunci atau filter, atau tambahkan produk melalui Etalase.' : 'Tambahkan produk dan jasa terlebih dahulu agar harga dapat dikelola di sini.' }}</p>
      <a class="btn primary" href="{{ route('etalase') }}">Buka Etalase Produk</a>
    </div>
  @else
    <div class="tbl-wrap">
      <table class="price-table">
        <thead>
          <tr>
            <th>Produk / Jasa</th>
            <th>Harga Normal (Rp)</th>
            <th>Harga Modal (Rp)</th>
            <th>Harga Grosir (Rp)</th>
            <th>Min. Grosir / Grup</th>
            <th>Harga Promo (Rp)</th>
            <th>Grup Gabungan</th>
            <th>Diubah</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          @foreach($products as $product)
            <tr>
              <td class="price-product">
                <strong>{{ $product->name }}</strong>
                <small>{{ $product->category->name }}{{ $product->sku ? ' · '.$product->sku : '' }}</small>
                <span class="pill {{ $product->is_active ? 'c-green' : 'c-gray' }}">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</span>
              </td>
              <td colspan="5">
                <form id="price-form-{{ $product->id }}" method="POST" action="{{ route('harga.update', $product) }}">
                  @csrf
                  @method('PATCH')
                </form>
                <div class="price-inputs">
                  <label class="sr-only" for="sale-{{ $product->id }}">Harga normal {{ $product->name }}</label>
                  <input class="input price-input" form="price-form-{{ $product->id }}" id="sale-{{ $product->id }}" type="number" name="prices[{{ $product->id }}][sale_price]" value="{{ old('prices.'.$product->id.'.sale_price', $product->sale_price) }}" min="0" step="1" required>

                  <label class="sr-only" for="cost-{{ $product->id }}">Harga modal {{ $product->name }}</label>
                  <input class="input price-input" form="price-form-{{ $product->id }}" id="cost-{{ $product->id }}" type="number" name="prices[{{ $product->id }}][cost_price]" value="{{ old('prices.'.$product->id.'.cost_price', $product->cost_price) }}" min="0" step="1" required>

                  <label class="sr-only" for="wholesale-{{ $product->id }}">Harga grosir {{ $product->name }}</label>
                  <input class="input price-input" form="price-form-{{ $product->id }}" id="wholesale-{{ $product->id }}" type="number" name="prices[{{ $product->id }}][wholesale_price]" value="{{ old('prices.'.$product->id.'.wholesale_price', $product->wholesale_price) }}" min="0" step="1" placeholder="—">

                  <label class="sr-only" for="wholesale-qty-{{ $product->id }}">Jumlah minimum grosir mandiri {{ $product->name }}</label>
                  <input class="input price-input" form="price-form-{{ $product->id }}" id="wholesale-qty-{{ $product->id }}" type="number" name="prices[{{ $product->id }}][wholesale_min_qty]" value="{{ old('prices.'.$product->id.'.wholesale_min_qty', $product->wholesale_min_qty) }}" min="1" step="1" placeholder="—">

                  <label class="sr-only" for="promo-{{ $product->id }}">Harga promo {{ $product->name }}</label>
                  <input class="input price-input" form="price-form-{{ $product->id }}" id="promo-{{ $product->id }}" type="number" name="prices[{{ $product->id }}][promo_price]" value="{{ old('prices.'.$product->id.'.promo_price', $product->promo_price) }}" min="0" step="1" placeholder="—">

                </div>
                @if($errors->any())
                  <div class="price-row-errors">
                    @foreach(['sale_price', 'cost_price', 'wholesale_price', 'wholesale_min_qty', 'promo_price'] as $field)
                      @error('prices.'.$product->id.'.'.$field)<span>{{ $priceLabels[$field] }}: {{ $message }}</span>@enderror
                    @endforeach
                  </div>
                @endif
              </td>
              <td>
                <label class="sr-only" for="wholesale-group-{{ $product->id }}">Grup grosir {{ $product->name }}</label>
                <select class="input price-input" form="price-form-{{ $product->id }}" id="wholesale-group-{{ $product->id }}" name="prices[{{ $product->id }}][wholesale_group_id]">
                  <option value="">Tanpa grup</option>
                  @foreach($wholesaleGroups as $group)
                    <option value="{{ $group->id }}" @selected((string) old('prices.'.$product->id.'.wholesale_group_id', $product->wholesale_group_id) === (string) $group->id)>
                      {{ $group->name }} · {{ $group->minimum_quantity }} {{ $product->unit }}
                    </option>
                  @endforeach
                </select>
                @if($product->wholesale_group_id)
                  <small class="muted">Min. mandiri diabaikan.</small>
                @endif
                @error('wholesale_group_id')<small class="field-error">{{ $message }}</small>@enderror
              </td>
              <td class="muted price-updated">
                {{ $product->latestPriceHistory?->created_at?->format('d M Y H:i') ?? 'Belum pernah' }}
              </td>
              <td class="price-actions">
                <button class="btn primary sm" type="submit" form="price-form-{{ $product->id }}">Simpan</button>
                <a class="btn sm" href="{{ route('harga.history', $product) }}">Riwayat</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="pagination-wrap">{{ $products->links() }}</div>
  @endif
</div>
@endsection
