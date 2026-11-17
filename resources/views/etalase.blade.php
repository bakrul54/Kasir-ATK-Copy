@extends('layouts.app')
@section('title', 'Etalase Produk')
@section('subtitle', 'Kelola produk, jasa, kategori, dan harga toko')

@section('content')
@php
  $status = request('status', 'semua');
  $statuses = [
    'semua' => 'Semua',
    'tersedia' => 'Tersedia',
    'menipis' => 'Menipis',
    'habis' => 'Habis',
    'promo' => 'Promo',
    'nonaktif' => 'Nonaktif',
  ];
  if (! $settings->promo_enabled) {
    unset($statuses['promo']);
  }
  $hasFilters = request()->filled('q') || request()->filled('category') || $status !== 'semua';
  $availabilityLabels = [
    'tersedia' => ['Tersedia', 'c-green'],
    'menipis' => ['Menipis', 'c-yellow'],
    'habis' => ['Habis', 'c-red'],
    'nonaktif' => ['Nonaktif', 'c-gray'],
  ];
@endphp

@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif

@if($errors->any())
  <div class="notice error" role="alert">
    <strong>Periksa kembali data yang dimasukkan.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

<div class="card">
  <form class="toolbar" method="GET" action="{{ route('etalase') }}">
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
    <div class="sp"></div>
    <a class="btn" href="{{ route('etalase.create') }}#product-form"><x-icon name="plus" /> Tambah Produk</a>
  </form>

  <div class="toolbar">
    <nav class="tabs" aria-label="Filter status produk">
      @foreach($statuses as $key => $label)
        <a class="{{ $status === $key ? 'on' : '' }}" href="{{ route('etalase', array_merge(request()->except('status', 'page'), ['status' => $key])) }}">{{ $label }}</a>
      @endforeach
    </nav>
    <div class="sp"></div>
    <details class="category-manager">
      <summary class="btn">Kelola Kategori ({{ $categories->count() }})</summary>
      <div class="category-panel">
        <form class="category-add" method="POST" action="{{ route('etalase.categories.store') }}">
          @csrf
          <input class="input" name="name" maxlength="80" placeholder="Nama kategori baru" required>
          <button class="btn primary" type="submit">Tambah</button>
        </form>
        @forelse($categories as $category)
          <div class="category-row">
            <span>{{ $category->name }} <small>({{ $category->products_count }} produk)</small></span>
            @if($category->products_count === 0)
              <form method="POST" action="{{ route('etalase.categories.destroy', $category) }}" onsubmit="return confirm('Hapus kategori {{ $category->name }}?')">
                @csrf
                @method('DELETE')
                <button class="btn sm danger" type="submit" aria-label="Hapus kategori {{ $category->name }}"><x-icon name="trash" /></button>
              </form>
            @endif
          </div>
        @empty
          <p class="hint">Belum ada kategori. Tambahkan kategori sebelum membuat produk.</p>
        @endforelse
      </div>
    </details>
  </div>

  @if($products->isEmpty())
    <div class="empty-state">
      <strong>{{ $hasFilters ? 'Produk tidak ditemukan' : 'Belum ada produk' }}</strong>
      <p>{{ $hasFilters ? 'Coba ubah kata kunci atau filter.' : 'Tambahkan produk atau jasa pertama untuk mulai mengisi etalase.' }}</p>
      @if($categories->isNotEmpty())
        <a class="btn primary" href="{{ route('etalase.create') }}#product-form"><x-icon name="plus" /> Tambah Produk</a>
      @endif
    </div>
  @else
    <div class="pgrid">
      @foreach($products as $item)
        @php
          [$availabilityLabel, $availabilityClass] = $availabilityLabels[$item->availability()];
        @endphp
        <article class="pcard">
          <div class="img">
            @if($item->photo_path)
              <img class="product-photo" src="{{ \Illuminate\Support\Facades\Storage::url($item->photo_path) }}" alt="{{ $item->name }}" loading="lazy">
            @else
              <span>Foto produk</span>
            @endif
            <span class="pill {{ $availabilityClass }}">{{ $availabilityLabel }}</span>
            @if($settings->promo_enabled && $item->promo_price)
              <span class="pill c-orange promo-label">Promo</span>
            @endif
          </div>
          <div class="body">
            <p class="product-category">{{ $item->category->name }}{{ $item->sku ? ' · '.$item->sku : '' }}</p>
            <h4>{{ $item->name }}</h4>
            <div class="price">Rp {{ number_format($settings->promo_enabled ? ($item->promo_price ?? $item->sale_price) : $item->sale_price, 0, ',', '.') }}</div>
            @if($settings->promo_enabled && $item->promo_price)
              <div class="meta"><span class="old-price">Rp {{ number_format($item->sale_price, 0, ',', '.') }}</span><span>Harga promo</span></div>
            @endif
            <div class="meta">
              <span>Modal Rp {{ number_format($item->cost_price, 0, ',', '.') }}</span>
              <span>{{ $item->tracks_stock ? 'Stok '.$item->stock_qty.' '.$item->unit : 'Jasa' }}</span>
            </div>
            @if($settings->wholesale_enabled && $item->wholesale_price)
              <div class="meta"><span>Grosir Rp {{ number_format($item->wholesale_price, 0, ',', '.') }}</span><span>Min. {{ $item->wholesale_min_qty }}</span></div>
            @endif
            <div class="foot">
              <a class="btn sm" href="{{ route('etalase.edit', $item) }}#product-form"><x-icon name="edit" /> Ubah</a>
              <form method="POST" action="{{ route('etalase.destroy', $item) }}" onsubmit="return confirm('Hapus produk {{ $item->name }}?')">
                @csrf
                @method('DELETE')
                <button class="btn sm danger" type="submit" aria-label="Hapus {{ $item->name }}"><x-icon name="trash" /></button>
              </form>
            </div>
          </div>
        </article>
      @endforeach
    </div>
    <div class="pagination-wrap">{{ $products->links() }}</div>
  @endif
</div>

@if($openForm)
  <div class="card mt" id="product-form">
    <div class="card-head">
      <div>
        <h2>{{ $product->exists ? 'Ubah Produk' : 'Tambah Produk' }}</h2>
        <div class="sub">Harga dan stok disimpan sebagai data dasar untuk modul selanjutnya.</div>
      </div>
      <a class="btn" href="{{ route('etalase') }}">Tutup</a>
    </div>

    @if($categories->isEmpty())
      <div class="notice error">Buat kategori terlebih dahulu melalui menu Kelola Kategori.</div>
    @else
      <form method="POST" action="{{ $product->exists ? route('etalase.update', $product) : route('etalase.store') }}" enctype="multipart/form-data">
        @csrf
        @if($product->exists) @method('PUT') @endif
        <div class="grid g3">
          <div>
            <div class="field">
              <label for="name">Nama Produk / Jasa</label>
              <input class="input" id="name" name="name" value="{{ old('name', $product->name) }}" maxlength="160" required>
            </div>
            <div class="field">
              <label for="category_id">Kategori</label>
              <select class="input" id="category_id" name="category_id" required>
                <option value="">Pilih kategori</option>
                @foreach($categories as $category)
                  <option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id) === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="sku">SKU / Kode Produk <span class="hint">(opsional)</span></label>
              <input class="input" id="sku" name="sku" value="{{ old('sku', $product->sku) }}" maxlength="80">
            </div>
            <div class="field">
              <label for="unit">Satuan</label>
              <select class="input" id="unit" name="unit" required>
                @foreach(array_values(array_unique([...$settings->units, ...($product->unit ? [$product->unit] : [])])) as $unit)
                  <option value="{{ $unit }}" @selected(old('unit', $product->unit ?: $settings->units[0]) === $unit)>{{ $unit }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="tracks_stock">Jenis pencatatan</label>
              <select class="input" id="tracks_stock" name="tracks_stock" required>
                <option value="1" @selected((string) old('tracks_stock', $product->exists ? (int) $product->tracks_stock : '1') === '1')>Produk dengan stok</option>
                <option value="0" @selected((string) old('tracks_stock', $product->exists ? (int) $product->tracks_stock : '1') === '0')>Jasa (tanpa stok)</option>
              </select>
            </div>
          </div>
          <div>
            <div class="field">
              <label for="sale_price">Harga Jual (Rp)</label>
              <input class="input" type="number" id="sale_price" name="sale_price" value="{{ old('sale_price', $product->sale_price) }}" min="0" step="1" required>
            </div>
            <div class="field">
              <label for="cost_price">Harga Modal (Rp)</label>
              <input class="input" type="number" id="cost_price" name="cost_price" value="{{ old('cost_price', $product->cost_price ?? 0) }}" min="0" step="1" required>
            </div>
            <div class="field">
              <label for="stock_qty">Jumlah Stok</label>
              @if($product->exists)
                <input class="input" type="number" id="stock_qty" value="{{ $product->stock_qty }}" readonly>
                <small class="hint">Perubahan jumlah stok dicatat melalui modul Inventory / Stok.</small>
              @else
                <input class="input stock-input" type="number" id="stock_qty" name="stock_qty" value="{{ old('stock_qty', 0) }}" min="0" step="1">
              @endif
            </div>
            <div class="field">
              <label for="minimum_stock">Batas Stok Menipis</label>
              <input class="input stock-input" type="number" id="minimum_stock" name="minimum_stock" value="{{ old('minimum_stock', $product->minimum_stock ?? $settings->default_minimum_stock) }}" min="0" step="1">
            </div>
            <div class="field">
              <label for="is_active">Status etalase</label>
              <select class="input" id="is_active" name="is_active" required>
                <option value="1" @selected((string) old('is_active', $product->exists ? (int) $product->is_active : '1') === '1')>Aktif</option>
                <option value="0" @selected((string) old('is_active', $product->exists ? (int) $product->is_active : '1') === '0')>Nonaktif</option>
              </select>
            </div>
          </div>
          <div>
            @if($settings->wholesale_enabled)
              <div class="field">
                <label for="wholesale_price">Harga Grosir (Rp)</label>
                <input class="input" type="number" id="wholesale_price" name="wholesale_price" value="{{ old('wholesale_price', $product->wholesale_price) }}" min="0" step="1">
              </div>
              <div class="field">
                <label for="wholesale_min_qty">Jumlah Minimum Grosir</label>
                <input class="input" type="number" id="wholesale_min_qty" name="wholesale_min_qty" value="{{ old('wholesale_min_qty', $product->wholesale_min_qty) }}" min="1" step="1">
              </div>
            @endif
            @if($settings->promo_enabled)
              <div class="field">
                <label for="promo_price">Harga Promo (Rp)</label>
                <input class="input" type="number" id="promo_price" name="promo_price" value="{{ old('promo_price', $product->promo_price) }}" min="0" step="1">
              </div>
            @endif
            <div class="field">
              <label for="photo">Foto Produk <span class="hint">(JPG, PNG, WebP; maks. 2 MB)</span></label>
              <input class="input" type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
              @if($product->photo_path)
                <img class="form-photo" src="{{ \Illuminate\Support\Facades\Storage::url($product->photo_path) }}" alt="Foto {{ $product->name }}">
              @endif
            </div>
          </div>
        </div>
        <div class="toolbar form-actions">
          <button class="btn primary" type="submit">{{ $product->exists ? 'Simpan Perubahan' : 'Simpan Produk' }}</button>
          <a class="btn" href="{{ route('etalase') }}">Batal</a>
        </div>
      </form>
    @endif
  </div>
@endif

<script>
  const stockType = document.getElementById('tracks_stock');
  const stockInputs = document.querySelectorAll('.stock-input');
  const updateStockInputs = () => {
    stockInputs.forEach((input) => {
      input.disabled = stockType.value === '0';
      input.required = stockType.value === '1';
    });
  };
  stockType?.addEventListener('change', updateStockInputs);
  updateStockInputs();
</script>
@endsection
