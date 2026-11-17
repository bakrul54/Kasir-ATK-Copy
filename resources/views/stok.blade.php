@extends('layouts.app')
@section('title', 'Inventory / Stok')
@section('subtitle', 'Pantau stok produk, catat pergerakan, dan lakukan stok opname')

@section('content')
@php
  $status = request('status', 'semua');
  $statuses = [
    'semua' => 'Semua',
    'aman' => 'Aman',
    'menipis' => 'Menipis',
    'habis' => 'Habis',
    'nonaktif' => 'Nonaktif',
  ];
  $hasFilters = request()->filled('q') || request()->filled('category') || $status !== 'semua';
  $movementLabels = [
    'masuk' => ['Stok Masuk', 'c-green'],
    'keluar' => ['Stok Keluar', 'c-orange'],
    'opname' => ['Stok Opname', 'c-blue'],
  ];
@endphp

@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="notice error" role="alert">
    <strong>Pergerakan stok belum disimpan.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

<div class="grid g4">
  <div class="card stat"><div class="lbl">Total Produk Stok</div><div class="val">{{ number_format($stats['total'], 0, ',', '.') }}</div><div class="note muted">Produk aktif yang mencatat stok</div></div>
  <div class="card stat"><div class="lbl">Stok Menipis</div><div class="val t-orange">{{ number_format($stats['low'], 0, ',', '.') }}</div><div class="note muted">Di atas nol dan pada/bawah minimum</div></div>
  <div class="card stat"><div class="lbl">Barang Habis</div><div class="val t-red">{{ number_format($stats['empty'], 0, ',', '.') }}</div><div class="note muted">Perlu segera direstok</div></div>
  <div class="card stat"><div class="lbl">Nilai Stok</div><div class="val">Rp {{ number_format($stats['value'], 0, ',', '.') }}</div><div class="note muted">Perkiraan berdasarkan harga modal</div></div>
</div>

<div class="card mt" id="movement-form">
  <div class="card-head">
    <div>
      <h2>Catat Pergerakan Stok</h2>
      <div class="sub">Stok masuk dan keluar memakai jumlah perubahan; opname memakai jumlah fisik hasil hitung.</div>
    </div>
  </div>
  @if($stats['total'] === 0)
    <p class="hint">Belum ada produk stok aktif. Tambahkan produk melalui Etalase Produk terlebih dahulu.</p>
    <a class="btn primary mt" href="{{ route('etalase') }}">Buka Etalase Produk</a>
  @else
    <form class="stock-movement-form" method="POST" action="{{ route('stok.movements.store') }}">
      @csrf
      <div class="stock-form-grid">
        <div class="field">
          <label for="product_id">Produk</label>
          <select class="input" name="product_id" id="product_id" required>
            <option value="">Pilih produk</option>
            @foreach($stockOptions as $product)
              <option value="{{ $product->id }}" @selected((string) old('product_id', $selectedProductId) === (string) $product->id)>
                {{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}{{ $product->is_active ? '' : ' (Nonaktif)' }} (stok {{ $product->stock_qty }} {{ $product->unit }})
              </option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="type">Jenis pergerakan</label>
          <select class="input" name="type" id="type" required>
            <option value="masuk" @selected(old('type') === 'masuk')>Barang masuk</option>
            <option value="keluar" @selected(old('type') === 'keluar')>Barang keluar</option>
            <option value="opname" @selected(old('type') === 'opname')>Stok opname</option>
          </select>
        </div>
        <div class="field">
          <label for="quantity" id="quantity-label">Jumlah masuk</label>
          <input class="input" type="number" name="quantity" id="quantity" value="{{ old('quantity') }}" min="1" step="1" required>
          <small class="hint" id="quantity-hint">Jumlah barang yang ditambahkan ke stok.</small>
        </div>
        <div class="field">
          <label for="notes">Keterangan <span class="hint">(opsional)</span></label>
          <input class="input" type="text" name="notes" id="notes" value="{{ old('notes') }}" maxlength="500" placeholder="Mis. belanja dari supplier, barang rusak">
        </div>
      </div>
      <button class="btn primary" type="submit">Simpan Pergerakan</button>
    </form>
  @endif
</div>

<div class="card mt">
  <div class="toolbar">
    <form class="toolbar stock-filter-form" method="GET" action="{{ route('stok') }}">
      <div class="search" style="width:260px">
        <x-icon name="search" />
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari produk atau SKU...">
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
    <div class="sp"></div>
    <a class="btn" href="#movement-form">Stok Opname / Pergerakan</a>
  </div>

  <nav class="toolbar tabs" aria-label="Filter status stok">
    @foreach($statuses as $key => $label)
      <a class="{{ $status === $key ? 'on' : '' }}" href="{{ route('stok', array_merge(request()->except('status', 'products_page', 'movements_page'), ['status' => $key])) }}">{{ $label }}</a>
    @endforeach
  </nav>

  @if($products->isEmpty())
    <div class="empty-state">
      <strong>{{ $hasFilters ? 'Produk tidak ditemukan' : 'Belum ada produk stok' }}</strong>
      <p>{{ $hasFilters ? 'Ubah kata kunci atau filter stok.' : 'Produk jasa tanpa pencatatan stok tidak ditampilkan di inventory.' }}</p>
      <a class="btn" href="{{ route('etalase') }}">Buka Etalase Produk</a>
    </div>
  @else
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Barang</th>
            <th>Kategori</th>
            <th class="r">Stok Saat Ini</th>
            <th class="r">Stok Minimum</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($products as $product)
            @php
              $availability = $product->availability();
              $stockStatus = match ($availability) {
                'habis' => ['Habis', 'c-red'],
                'menipis' => ['Menipis', 'c-yellow'],
                'nonaktif' => ['Nonaktif', 'c-gray'],
                default => ['Aman', 'c-green'],
              };
            @endphp
            <tr>
              <td><strong>{{ $product->name }}</strong>@if($product->sku)<small class="stock-sku">{{ $product->sku }}</small>@endif</td>
              <td>{{ $product->category->name }}</td>
              <td class="r"><strong>{{ number_format($product->stock_qty, 0, ',', '.') }} {{ $product->unit }}</strong></td>
              <td class="r muted">{{ number_format($product->minimum_stock, 0, ',', '.') }} {{ $product->unit }}</td>
              <td><span class="pill {{ $stockStatus[1] }}">{{ $stockStatus[0] }}</span></td>
              <td class="r"><a class="btn sm" href="{{ route('stok', array_merge(request()->query(), ['product_id' => $product->id])) }}#movement-form">Atur Stok</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="pagination-wrap">{{ $products->links() }}</div>
  @endif
</div>

<div class="card mt">
  <div class="card-head">
    <div>
      <h2>Riwayat Pergerakan Stok</h2>
      <div class="sub">Barang masuk, barang keluar, dan hasil opname terbaru</div>
    </div>
  </div>
  @if($movements->isEmpty())
    <div class="empty-state">
      <strong>Belum ada riwayat pergerakan</strong>
      <p>Setiap stok masuk, keluar, atau opname akan tercatat di sini.</p>
    </div>
  @else
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Barang</th>
            <th>Jenis</th>
            <th class="r">Perubahan / Jumlah Fisik</th>
            <th class="r">Stok Sebelum → Sesudah</th>
            <th>Dicatat Oleh</th>
            <th>Keterangan</th>
          </tr>
        </thead>
        <tbody>
          @foreach($movements as $movement)
            @php
              [$movementLabel, $movementClass] = $movementLabels[$movement->type];
            @endphp
            <tr>
              <td>{{ $movement->created_at->format('d M Y H:i') }}</td>
              <td><strong>{{ $movement->product->name }}</strong></td>
              <td><span class="pill {{ $movementClass }}">{{ $movementLabel }}</span></td>
              <td class="r">
                @if($movement->type === 'masuk')+@elseif($movement->type === 'keluar')−@endif
                {{ number_format($movement->quantity, 0, ',', '.') }} {{ $movement->product->unit }}
              </td>
              <td class="r">{{ number_format($movement->stock_before, 0, ',', '.') }} → {{ number_format($movement->stock_after, 0, ',', '.') }}</td>
              <td>{{ $movement->user?->name ?? 'Akun dihapus' }}</td>
              <td class="muted">{{ $movement->notes ?: '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="pagination-wrap">{{ $movements->links() }}</div>
  @endif
</div>

<script>
  const movementType = document.getElementById('type');
  const quantityLabel = document.getElementById('quantity-label');
  const quantityHint = document.getElementById('quantity-hint');
  const quantityInput = document.getElementById('quantity');
  const updateQuantityHelp = () => {
    const opname = movementType.value === 'opname';
    const keluar = movementType.value === 'keluar';
    quantityLabel.textContent = opname ? 'Jumlah fisik hasil hitung' : keluar ? 'Jumlah keluar' : 'Jumlah masuk';
    quantityHint.textContent = opname
      ? 'Isi jumlah stok yang benar-benar ada setelah dihitung.'
      : keluar
        ? 'Jumlah ini akan dikurangi dari stok saat ini.'
        : 'Jumlah ini akan ditambahkan ke stok saat ini.';
    quantityInput.min = opname ? '0' : '1';
  };
  movementType?.addEventListener('change', updateQuantityHelp);
  updateQuantityHelp();
</script>
@endsection
