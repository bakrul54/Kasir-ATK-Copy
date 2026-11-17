@extends('layouts.app')
@section('title', 'Kasir / Transaksi')
@section('subtitle', 'Layani jasa dan produk ATK dalam satu transaksi')

@section('content')
@php
  $selectedCategory = $selectedCategory;
  $money = fn ($amount) => 'Rp '.number_format($amount, 0, ',', '.');
@endphp

@if(session('success'))
  <div class="notice success" role="status">
    {{ session('success') }}
    @if(session('receipt_url'))
      <a class="receipt-link" href="{{ session('receipt_url') }}" target="_blank" rel="noopener">Buka / Cetak Struk</a>
    @endif
  </div>
@endif
@if($errors->any())
  <div class="notice error" role="alert">
    <strong>Transaksi belum disimpan. Periksa kembali data berikut:</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

@if(auth()->user()->role === 'cashier')
  <section class="card cashier-shift-card">
    @if($currentShift)
      @php $expectedCash = $currentShift->opening_cash + $currentShiftCashSales; @endphp
      <div class="cashier-shift-summary">
        <div>
          <span class="pill c-green">Shift Berjalan</span>
          <h2>Shift Anda</h2>
          <p>Dibuka {{ $currentShift->opened_at->format('d M Y H:i') }}</p>
        </div>
        <div><small>Kas awal</small><b>Rp {{ number_format($currentShift->opening_cash, 0, ',', '.') }}</b></div>
        <div><small>Penjualan tunai + tip</small><b>Rp {{ number_format($currentShiftCashSales, 0, ',', '.') }}</b></div>
        <div><small>Kas yang diharapkan</small><b>Rp {{ number_format($expectedCash, 0, ',', '.') }}</b></div>
      </div>
      <form class="cashier-shift-close" method="POST" action="{{ route('kasir.shifts.close') }}">
        @csrf
        <div class="field">
          <label for="closing_cash">Kas fisik saat tutup shift (Rp)</label>
          <input class="input" id="closing_cash" type="number" name="closing_cash" min="0" step="1" required>
        </div>
        <button class="btn danger" type="submit">Tutup Shift &amp; Rekonsiliasi</button>
      </form>
      @error('closing_cash')<p class="field-error">{{ $message }}</p>@enderror
    @else
      <div class="cashier-shift-summary">
        <div><span class="pill c-orange">Shift Belum Dibuka</span><h2>Mulai Bertugas</h2><p>Masukkan jumlah kas awal sebelum melayani transaksi.</p></div>
      </div>
      <form class="cashier-shift-open" method="POST" action="{{ route('kasir.shifts.store') }}">
        @csrf
        <div class="field">
          <label for="opening_cash">Kas awal (Rp)</label>
          <input class="input" id="opening_cash" type="number" name="opening_cash" value="{{ old('opening_cash') }}" min="0" step="1" required>
        </div>
        <button class="btn primary" type="submit">Buka Shift</button>
      </form>
      @error('opening_cash')<p class="field-error">{{ $message }}</p>@enderror
    @endif
    @error('shift')<p class="notice error" role="alert">{{ $message }}</p>@enderror
  </section>
@endif

<div class="pos">
  <section class="card pos-products">
    <form class="toolbar" id="product-search-form" method="GET" action="{{ route('kasir') }}">
      <div class="search" style="flex:1;width:auto">
        <x-icon name="search" />
        <input type="search" id="product-search" name="product_q" value="{{ request('product_q') }}" placeholder="Cari nama, SKU, kategori...">
      </div>
      <select class="input filter-select" name="category" aria-label="Filter kategori produk">
        <option value="">Semua kategori</option>
        @foreach($categories as $category)
          <option value="{{ $category->id }}" @selected($selectedCategory === $category->id)>{{ $category->name }}</option>
        @endforeach
      </select>
      <button class="btn" type="submit">Cari</button>
    </form>

    @if($products->isEmpty())
      <div class="empty-state">
        <strong>Produk tidak ditemukan</strong>
        <p>Tambahkan produk atau jasa melalui Etalase Produk, atau ubah pencarian.</p>
        <a class="btn" href="{{ route('etalase') }}">Buka Etalase</a>
      </div>
    @else
      <div class="pgrid">
        @foreach($products as $product)
          @include('partials.pos-product-card', ['product' => $product, 'settings' => $settings, 'money' => $money])
        @endforeach
      </div>
      <div class="pagination-wrap">{{ $products->links('pagination.pos') }}</div>
    @endif
  </section>

  <section class="card pos-print-services" id="print-and-service-products">
    <div class="card-head">
      <div><h2>Cetak dan Jasa</h2><div class="sub">Produk kategori Cetak dan Jasa, siap ditambahkan ke keranjang.</div></div>
    </div>
    @if($printAndServiceProducts->isEmpty())
      <div class="empty-state">
        <strong>Belum ada produk tersedia</strong>
        <p>Produk aktif dan tersedia dari kategori Cetak dan Jasa akan muncul di sini.</p>
      </div>
    @else
      <div class="pgrid">
        @foreach($printAndServiceProducts as $product)
          @include('partials.pos-product-card', ['product' => $product, 'settings' => $settings, 'money' => $money])
        @endforeach
      </div>
    @endif
  </section>

  <aside class="card pos-cart">
    <div class="card-head">
      <div><h2>Keranjang</h2><div class="sub"><span id="cart-count">0</span> baris produk</div></div>
      <button class="btn sm danger" id="clear-cart" type="button">Kosongkan</button>
    </div>
    <p class="notice success" id="print-quote-cart-notice" role="status" hidden></p>
    <div id="wholesale-progress" class="sub" aria-live="polite"></div>

    <div id="cart-items" class="cart-items">
      <div class="cart-empty">Pilih produk atau jasa untuk memulai transaksi.</div>
    </div>

    <form method="POST" action="{{ route('kasir.store') }}" id="sale-form">
      @csrf
      <div id="cart-inputs"></div>
      <input type="hidden" name="submission_token" value="{{ old('submission_token', $submissionToken) }}">
      <div class="field">
        <label for="customer_id">Pelanggan terdaftar <span class="hint">(opsional, riwayat transaksi akan terhubung)</span></label>
        <select class="input" name="customer_id" id="customer_id">
          <option value="">Pelanggan umum / belum terdaftar</option>
          @foreach($customers as $customer)
            <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>
              {{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}
            </option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="customer_name">Nama pelanggan umum <span class="hint">(jika belum terdaftar)</span></label>
        <input class="input" type="text" name="customer_name" id="customer_name" maxlength="160" value="{{ old('customer_name') }}" placeholder="Kosongkan untuk pelanggan umum">
      </div>
      <div class="field">
        <label for="discount_value">Diskon Transaksi</label>
        <div class="row">
          <input class="input" type="number" name="discount_value" id="discount_value" value="{{ old('discount_value', 0) }}" min="0" step="1">
          <select class="input" name="discount_type" id="discount_type">
            <option value="nominal" @selected(old('discount_type', 'nominal') === 'nominal')>Rupiah</option>
            <option value="persen" @selected(old('discount_type') === 'persen')>Persen (%)</option>
          </select>
        </div>
      </div>

      <div class="sum-row"><span>Subtotal</span><strong id="cart-subtotal">Rp 0</strong></div>
      <div class="sum-row"><span>Diskon</span><strong id="cart-discount">− Rp 0</strong></div>
      <div class="sum-row total"><span>Total</span><strong id="cart-total">Rp 0</strong></div>

      <div class="field">
        <label for="payment_method">Metode Pembayaran</label>
        <select class="input" name="payment_method" id="payment_method" required>
          @foreach(['cash' => 'Cash', 'transfer' => 'Transfer Bank', 'qris' => 'QRIS'] as $method => $label)
            @if(in_array($method, $settings->payment_methods, true))
              <option value="{{ $method }}" @selected(old('payment_method', $settings->payment_methods[0]) === $method)>{{ $label }}</option>
            @endif
          @endforeach
        </select>
      </div>
      @if($settings->payment_information)
        <p class="payment-information">{{ $settings->payment_information }}</p>
      @endif
      <div class="field" id="received-field">
        <label for="amount_received">Uang Diterima (Rp)</label>
        <div class="received-input-row">
          <input class="input" type="number" name="amount_received" id="amount_received" value="{{ old('amount_received') }}" min="0" step="1">
          <button class="btn sm" type="button" id="exact-payment" disabled>Uang pas</button>
        </div>
      </div>
      <div class="field" id="tip-field" hidden>
        <label for="tip_amount">Tip dari uang lebih (Rp) <span class="hint">(opsional, sesuai persetujuan pelanggan)</span></label>
        <div class="received-input-row">
          <input class="input" type="number" name="tip_amount" id="tip_amount" value="{{ old('tip_amount', 0) }}" min="0" step="1">
          <button class="btn sm" type="button" id="tip-change" disabled>Jadikan sisa tip</button>
        </div>
      </div>
      <div class="sum-row" id="tip-row" hidden><span>Tip</span><strong id="cart-tip">Rp 0</strong></div>
      <div class="sum-row"><span class="muted">Kembalian</span><strong id="cart-change">Rp 0</strong></div>
      @error('items')<p class="field-error">{{ $message }}</p>@enderror
      <button class="btn primary block mt" type="submit" id="pay-button" disabled>Bayar &amp; Simpan Transaksi</button>
    </form>
  </aside>
</div>

<div class="card mt">
  <div class="card-head purchase-history-heading">
    <div><h2>Riwayat Transaksi</h2><div class="sub">Transaksi sesuai tanggal yang dipilih</div></div>
  </div>
  <form class="toolbar" method="GET" action="{{ route('kasir') }}">
    <div class="search" style="width:280px">
      <x-icon name="search" />
      <input type="search" name="sales_q" value="{{ request('sales_q') }}" placeholder="Cari no. transaksi atau pelanggan...">
    </div>
    <label class="sales-date-label" for="sales_date">Tanggal</label>
    <input class="input sales-date" type="date" id="sales_date" name="sales_date" value="{{ request('sales_date', today()->toDateString()) }}">
    <button class="btn" type="submit">Cari</button>
  </form>
  @if($sales->isEmpty())
    <div class="empty-state">
      <strong>Belum ada transaksi pada tanggal ini</strong>
      <p>Transaksi yang berhasil dibayar akan muncul di sini.</p>
    </div>
  @else
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>No. Transaksi</th><th>Waktu</th><th>Pelanggan</th><th>Baris Item</th><th>Pembayaran</th><th>Status</th><th class="r">Total</th><th>Aksi</th></tr>
        </thead>
        <tbody>
          @foreach($sales as $sale)
            <tr>
              <td><strong>{{ $sale->transaction_number }}</strong></td>
              <td>{{ $sale->created_at->format('H:i') }}</td>
              <td>{{ $sale->customer_name ?: 'Umum' }}</td>
              <td>{{ $sale->items_count }}</td>
              <td><span class="pill c-blue">{{ strtoupper($sale->payment_method) }}</span></td>
              @if($sale->canceled_at)
                <td><span class="pill c-red">Dibatalkan</span></td>
              @else
                <td><span class="pill c-green">Selesai</span></td>
              @endif
              <td class="r"><strong>{{ $money($sale->total) }}</strong></td>
              <td class="r">
                <a class="btn sm" href="{{ route('kasir.detail', array_merge(['sale' => $sale], request()->only('sales_q', 'sales_date', 'sales_page'))) }}">Detail</a>
                <a class="btn sm" href="{{ route('kasir.receipt', $sale) }}" target="_blank" rel="noopener"><x-icon name="printer" /> Struk</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="pagination-wrap">{{ $sales->links('pagination.pos') }}</div>
  @endif
</div>

<script>
  const cart = new Map();
  const cashierShiftOpen = @json(auth()->user()->role !== 'cashier' || $currentShift !== null);
  const cartStorageKey = `spot-print:kasir-cart:v1:${@json(auth()->id())}`;
  const printQuoteStorageKey = `spot-print:print-quote:v2:${@json(auth()->id())}`;
  const printProducts = @json($printProducts);
  const printProductIds = new Set(Object.keys(printProducts));
  const pendingSaleStorageKey = `${cartStorageKey}:pending-sale`;
  const saleSaved = @json(session()->has('receipt_url'));
  const cartItems = document.getElementById('cart-items');
  const cartInputs = document.getElementById('cart-inputs');
  const cartQuantityInputs = new Map();
  const saleForm = document.getElementById('sale-form');
  const amountReceivedInput = document.getElementById('amount_received');
  const tipAmountInput = document.getElementById('tip_amount');
  let cartTotal = 0;
  const formatCurrency = (value) => `Rp ${new Intl.NumberFormat('id-ID').format(value)}`;
  const isValidCartItem = (item) => item
    && typeof item.id === 'string'
    && /^\d+$/.test(item.id)
    && typeof item.name === 'string'
    && typeof item.unit === 'string'
    && Number.isSafeInteger(item.salePrice)
    && item.salePrice >= 0
    && (item.promoPrice === null || (Number.isSafeInteger(item.promoPrice) && item.promoPrice >= 0))
    && (item.wholesalePrice === null || (Number.isSafeInteger(item.wholesalePrice) && item.wholesalePrice >= 0))
    && (item.wholesaleMinQty === null || (Number.isSafeInteger(item.wholesaleMinQty) && item.wholesaleMinQty >= 1))
    && (item.wholesaleGroupId === undefined || item.wholesaleGroupId === null || /^\d+$/.test(item.wholesaleGroupId))
    && (item.wholesaleGroupMinQty === undefined || item.wholesaleGroupMinQty === null || (Number.isSafeInteger(item.wholesaleGroupMinQty) && item.wholesaleGroupMinQty >= 1))
    && (item.stock === null || (Number.isSafeInteger(item.stock) && item.stock >= 0))
    && Number.isSafeInteger(item.quantity)
    && item.quantity >= 1
    && (item.stock === null || item.quantity <= item.stock);
  const loadCart = () => {
    try {
      const storedCart = window.localStorage.getItem(cartStorageKey);
      if (!storedCart) return;

      const storedItems = JSON.parse(storedCart);
      if (!Array.isArray(storedItems)) {
        throw new TypeError('Data keranjang tersimpan harus berupa daftar item.');
      }

      storedItems.forEach((item) => {
        if (isValidCartItem(item)) cart.set(item.id, item);
      });
    } catch (error) {
      console.error('Keranjang tersimpan tidak dapat dimuat.', error);
      cart.clear();
      try {
        window.localStorage.removeItem(cartStorageKey);
      } catch (storageError) {
        console.error('Data keranjang tersimpan tidak dapat dihapus.', storageError);
      }
    }
  };
  const loadPrintQuote = () => {
    try {
      const transferredQuote = window.sessionStorage.getItem(printQuoteStorageKey);
      if (!transferredQuote) return;

      const items = JSON.parse(transferredQuote);
      if (!Array.isArray(items) || items.length === 0 || items.length > printProductIds.size) {
        throw new TypeError('Hasil kalkulator print tidak valid.');
      }

      const transferredProducts = new Set();
      const printItems = items.map((transferredItem) => {
        const productId = String(transferredItem?.product_id ?? '');
        const quantity = transferredItem?.quantity;
        if (
          !printProductIds.has(productId)
          || transferredProducts.has(productId)
          || !Number.isSafeInteger(quantity)
          || quantity < 1
          || quantity > 4294967295
        ) {
          throw new TypeError('Produk atau jumlah lembar hasil kalkulator tidak valid. Hitung ulang dokumen untuk mendapatkan hasil terbaru.');
        }

        transferredProducts.add(productId);
        return { productId, quantity };
      });
      const newPrintLines = printItems.filter(({ productId }) => !cart.has(productId)).length;
      if (cart.size + newPrintLines > 50) {
        throw new TypeError('Keranjang sudah mencapai batas 50 baris. Hapus item terlebih dahulu sebelum menambahkan hasil kalkulasi.');
      }

      const nextPrintItems = printItems.map(({ productId, quantity }) => {
        const existingItem = cart.get(productId);
        const item = existingItem || printProducts[productId];
        const nextQuantity = (existingItem ? existingItem.quantity : 0) + quantity;
        const nextItem = { ...item, quantity: nextQuantity };
        if (
          !isValidCartItem(nextItem)
          || nextQuantity > 4294967295
        ) {
          throw new TypeError(`Jumlah ${item.name} melebihi stok tersedia atau batas kapasitas transaksi. Periksa pengaturan stok produk di Etalase.`);
        }

        return { id: productId, item: nextItem };
      });

      nextPrintItems.forEach(({ id, item }) => cart.set(id, item));
      window.sessionStorage.removeItem(printQuoteStorageKey);
      const notice = document.getElementById('print-quote-cart-notice');
      notice.textContent = 'Hasil hitung coverage print berhasil ditambahkan ke keranjang.';
      notice.hidden = false;
    } catch (error) {
      console.error('Hasil hitung coverage print tidak dapat dimasukkan ke keranjang.', error);
      try {
        window.sessionStorage.removeItem(printQuoteStorageKey);
      } catch (storageError) {
        console.error('Hasil hitung print yang gagal tidak dapat dihapus.', storageError);
      }
      const notice = document.getElementById('print-quote-cart-notice');
      notice.className = 'notice error';
      notice.textContent = error instanceof Error
        ? `Hasil hitung print tidak dapat dimasukkan: ${error.message}`
        : 'Hasil hitung print tidak dapat dimasukkan. Silakan hitung ulang dokumen.';
      notice.hidden = false;
    }
  };
  try {
    if (saleSaved && window.sessionStorage.getItem(pendingSaleStorageKey) === '1') {
      window.localStorage.removeItem(cartStorageKey);
    }
    window.sessionStorage.removeItem(pendingSaleStorageKey);
  } catch (error) {
    console.error('Status transaksi kasir tidak dapat diperiksa.', error);
  }
  const persistCart = () => {
    try {
      if (cart.size === 0) {
        window.localStorage.removeItem(cartStorageKey);
        return;
      }

      window.localStorage.setItem(cartStorageKey, JSON.stringify([...cart.values()]));
    } catch (error) {
      console.error('Keranjang kasir tidak dapat disimpan di browser.', error);
    }
  };
  const groupQuantities = () => {
    const quantities = new Map();
    cart.forEach((item) => {
      if (item.wholesaleGroupId !== null && item.wholesaleGroupId !== undefined) {
        quantities.set(item.wholesaleGroupId, (quantities.get(item.wholesaleGroupId) || 0) + item.quantity);
      }
    });
    return quantities;
  };
  const unitPrice = (item, quantities) => {
    const prices = [item.salePrice];
    if (item.promoPrice !== null) prices.push(item.promoPrice);
    const grouped = item.wholesaleGroupId !== null
      && item.wholesaleGroupId !== undefined
      && item.wholesaleGroupMinQty !== null
      && item.wholesaleGroupMinQty !== undefined;
    const minimumQuantity = grouped ? item.wholesaleGroupMinQty : item.wholesaleMinQty;
    const eligibleQuantity = grouped ? (quantities.get(item.wholesaleGroupId) || 0) : item.quantity;
    if (item.wholesalePrice !== null && minimumQuantity !== null && eligibleQuantity >= minimumQuantity) {
      prices.push(item.wholesalePrice);
    }
    return Math.min(...prices);
  };

  const updateCartTotals = () => {
    let subtotal = 0;
    let count = 0;
    const quantities = groupQuantities();

    [...cart.values()].forEach((item) => {
      const row = cartItems.querySelector(`[data-cart-id="${item.id}"]`);
      const price = unitPrice(item, quantities);
      const line = row.querySelector('.cart-line-total');
      const priceLabel = row.querySelector('.qty-unit-price');

      line.textContent = formatCurrency(price * item.quantity);
      priceLabel.textContent = `${formatCurrency(price)} / ${item.unit}`;
      const quantity = row.querySelector('.cart-quantity');
      quantity.textContent = item.quantity;
      quantity.setAttribute('aria-label', `Jumlah ${item.quantity} ${item.unit}`);
      row.querySelector('[data-action="plus"]').disabled = item.stock !== null && item.quantity >= item.stock;
      const quantityInput = cartQuantityInputs.get(item.id);
      if (quantityInput) quantityInput.value = item.quantity;
      subtotal += price * item.quantity;
      count += item.quantity;
    });

    const progress = document.getElementById('wholesale-progress');
    const groupedItems = [...cart.values()].filter((item) => item.wholesaleGroupId !== null && item.wholesaleGroupId !== undefined);
    const groups = new Map();
    groupedItems.forEach((item) => {
      if (!groups.has(item.wholesaleGroupId)) groups.set(item.wholesaleGroupId, item);
    });
    progress.replaceChildren();
    groups.forEach((item) => {
      const message = document.createElement('div');
      const quantity = quantities.get(item.wholesaleGroupId) || 0;
      const minimum = item.wholesaleGroupMinQty;
      message.textContent = `${item.wholesaleGroupName}: ${quantity}/${minimum} ${item.unit} menuju harga grosir`;
      progress.append(message);
    });

    document.getElementById('cart-count').textContent = count;
    document.getElementById('cart-subtotal').textContent = formatCurrency(subtotal);
    const discountValue = Math.max(0, Number(document.getElementById('discount_value').value || 0));
    const discountType = document.getElementById('discount_type').value;
    const discount = discountType === 'persen' ? Math.floor(subtotal * Math.min(100, discountValue) / 100) : Math.min(subtotal, discountValue);
    const total = subtotal - discount;
    const received = Number(amountReceivedInput.value || 0);
    const tipAmount = Number(tipAmountInput.value || 0);
    const availableTip = Math.max(0, received - total);
    cartTotal = total;

    document.getElementById('cart-discount').textContent = `− ${formatCurrency(discount)}`;
    document.getElementById('cart-total').textContent = formatCurrency(total);
    document.getElementById('cart-tip').textContent = formatCurrency(tipAmount);
    document.getElementById('cart-change').textContent = formatCurrency(Math.max(0, availableTip - tipAmount));
    document.getElementById('tip-row').hidden = document.getElementById('payment_method').value !== 'cash' || tipAmount === 0;
    document.getElementById('pay-button').disabled = cart.size === 0 || !cashierShiftOpen;
    document.getElementById('exact-payment').disabled = cart.size === 0;
    document.getElementById('tip-change').disabled = cart.size === 0 || availableTip === 0;
    persistCart();
  };

  const renderCart = () => {
    cartItems.replaceChildren();
    cartInputs.replaceChildren();
    cartQuantityInputs.clear();

    if (cart.size === 0) {
      const empty = document.createElement('div');
      empty.className = 'cart-empty';
      empty.textContent = 'Pilih produk atau jasa untuk memulai transaksi.';
      cartItems.append(empty);
    }

    const quantities = groupQuantities();
    [...cart.values()].forEach((item, index) => {
      const price = unitPrice(item, quantities);
      const row = document.createElement('div');
      row.className = 'cart-item';
      row.dataset.cartId = item.id;
      const detail = document.createElement('div');
      detail.className = 'grow';
      const name = document.createElement('b');
      name.textContent = item.name;
      const unitPriceLabel = document.createElement('small');
      unitPriceLabel.className = 'qty-unit-price';
      unitPriceLabel.textContent = `${formatCurrency(price)} / ${item.unit}`;
      detail.append(name, unitPriceLabel);

      const line = document.createElement('strong');
      line.className = 'cart-line-total';
      line.textContent = formatCurrency(price * item.quantity);

      const controls = document.createElement('div');
      controls.className = 'qty';
      const minus = document.createElement('button');
      minus.type = 'button';
      minus.textContent = '−';
      minus.setAttribute('aria-label', `Kurangi ${item.name}`);
      minus.dataset.action = 'minus';
      minus.dataset.id = item.id;
      const quantity = document.createElement('span');
      quantity.className = 'cart-quantity';
      quantity.textContent = item.quantity;
      quantity.setAttribute('aria-label', `Jumlah ${item.quantity} ${item.unit}`);
      const plus = document.createElement('button');
      plus.type = 'button';
      plus.textContent = '+';
      plus.setAttribute('aria-label', `Tambah ${item.name}`);
      plus.dataset.action = 'plus';
      plus.dataset.id = item.id;
      plus.disabled = item.stock !== null && item.quantity >= item.stock;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'cart-remove';
      remove.textContent = '×';
      remove.setAttribute('aria-label', `Hapus ${item.name}`);
      remove.dataset.action = 'remove';
      remove.dataset.id = item.id;
      controls.append(minus, quantity, plus, remove);
      row.append(detail, line, controls);
      cartItems.append(row);

      const productInput = document.createElement('input');
      productInput.type = 'hidden';
      productInput.name = `items[${index}][product_id]`;
      productInput.value = item.id;
      const quantityInput = document.createElement('input');
      quantityInput.type = 'hidden';
      quantityInput.name = `items[${index}][quantity]`;
      quantityInput.value = item.quantity;
      quantityInput.dataset.productId = item.id;
      cartQuantityInputs.set(item.id, quantityInput);
      cartInputs.append(productInput, quantityInput);
    });

    updateCartTotals();
  };

  loadCart();
  loadPrintQuote();

  document.querySelectorAll('.add-to-cart').forEach((button) => {
    button.addEventListener('click', () => {
      const card = button.closest('.pos-product');
      const id = card.dataset.id;
      const item = cart.get(id) || {
        id,
        name: card.dataset.name,
        unit: card.dataset.unit,
        salePrice: Number(card.dataset.salePrice),
        promoPrice: card.dataset.promoPrice === '' ? null : Number(card.dataset.promoPrice),
        wholesalePrice: card.dataset.wholesalePrice === '' ? null : Number(card.dataset.wholesalePrice),
        wholesaleMinQty: card.dataset.wholesaleMinQty === '' ? null : Number(card.dataset.wholesaleMinQty),
        wholesaleGroupId: card.dataset.wholesaleGroupId === '' ? null : card.dataset.wholesaleGroupId,
        wholesaleGroupName: card.dataset.wholesaleGroupName || null,
        wholesaleGroupMinQty: card.dataset.wholesaleGroupMinQty === '' ? null : Number(card.dataset.wholesaleGroupMinQty),
        stock: card.dataset.tracksStock === '1' ? Number(card.dataset.stock) : null,
        quantity: 0,
      };
      if (cart.has(id)) {
        if (item.stock === null || item.quantity < item.stock) item.quantity++;
      } else if (item.stock === null || item.quantity < item.stock) {
        item.quantity++;
        cart.set(id, item);
      }
      renderCart();
    });
  });

  let repeatTimer = null;
  let repeatInterval = null;
  let suppressPointerClick = null;
  let suppressPointerClickTimer = null;

  const stopQuantityRepeat = () => {
    clearTimeout(repeatTimer);
    clearInterval(repeatInterval);
    repeatTimer = null;
    repeatInterval = null;
  };

  const adjustQuantity = (button, isRepeat = false) => {
    const item = cart.get(button.dataset.id);
    if (!item) return false;

    if (button.dataset.action === 'plus') {
      if (item.stock !== null && item.quantity >= item.stock) return false;
      item.quantity++;
    } else if (item.quantity > 1) {
      item.quantity--;
    } else if (!isRepeat) {
      cart.delete(button.dataset.id);
      renderCart();
      return false;
    } else {
      return false;
    }

    updateCartTotals();
    return true;
  };

  cartItems.addEventListener('pointerdown', (event) => {
    const button = event.target.closest('[data-action="plus"], [data-action="minus"]');
    if (!button || button.disabled || (event.pointerType === 'mouse' && event.button !== 0)) return;

    event.preventDefault();
    stopQuantityRepeat();
    adjustQuantity(button);
    suppressPointerClick = button;
    clearTimeout(suppressPointerClickTimer);
    suppressPointerClickTimer = setTimeout(() => { suppressPointerClick = null; }, 1000);

    repeatTimer = setTimeout(() => {
      repeatInterval = setInterval(() => {
        if (!adjustQuantity(button, true)) stopQuantityRepeat();
      }, 80);
    }, 350);
  });

  document.addEventListener('pointerup', stopQuantityRepeat);
  document.addEventListener('pointercancel', stopQuantityRepeat);
  window.addEventListener('blur', stopQuantityRepeat);

  cartItems.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    if (button === suppressPointerClick) {
      suppressPointerClick = null;
      clearTimeout(suppressPointerClickTimer);
      return;
    }
    const item = cart.get(button.dataset.id);
    if (!item) return;
    if (button.dataset.action === 'remove') {
      cart.delete(button.dataset.id);
      renderCart();
      return;
    }
    if (button.dataset.action === 'plus' || button.dataset.action === 'minus') adjustQuantity(button);
  });

  document.getElementById('clear-cart').addEventListener('click', () => {
    cart.clear();
    renderCart();
  });
  saleForm.addEventListener('submit', (event) => {
    if (saleForm.dataset.submitting === 'true') {
      event.preventDefault();
      return;
    }

    saleForm.dataset.submitting = 'true';
    const payButton = document.getElementById('pay-button');
    payButton.disabled = true;
    payButton.textContent = 'Menyimpan Transaksi…';

    try {
      window.sessionStorage.setItem(pendingSaleStorageKey, '1');
    } catch (error) {
      console.error('Status transaksi kasir tidak dapat disimpan.', error);
    }
  });
  document.getElementById('discount_value').addEventListener('input', renderCart);
  document.getElementById('discount_type').addEventListener('change', renderCart);
  amountReceivedInput.addEventListener('input', renderCart);
  tipAmountInput.addEventListener('input', renderCart);
  document.getElementById('exact-payment').addEventListener('click', () => {
    amountReceivedInput.value = cartTotal;
    tipAmountInput.value = 0;
    renderCart();
  });
  document.getElementById('tip-change').addEventListener('click', () => {
    tipAmountInput.value = Math.max(0, Number(amountReceivedInput.value || 0) - cartTotal);
    renderCart();
  });
  document.getElementById('payment_method').addEventListener('change', (event) => {
    const cash = event.target.value === 'cash';
    document.getElementById('received-field').hidden = !cash;
    document.getElementById('tip-field').hidden = !cash;
    document.getElementById('tip-row').hidden = !cash || Number(tipAmountInput.value || 0) === 0;
    document.getElementById('amount_received').required = cash;
    if (!cash) {
      document.getElementById('amount_received').value = '';
      tipAmountInput.value = 0;
    }
    renderCart();
  });
  document.getElementById('payment_method').dispatchEvent(new Event('change'));
  const customerSelect = document.getElementById('customer_id');
  const customerName = document.getElementById('customer_name');
  const syncCustomerFields = () => {
    const registeredCustomerSelected = customerSelect.value !== '';
    customerName.disabled = registeredCustomerSelected;
    if (registeredCustomerSelected) customerName.value = '';
  };
  customerSelect.addEventListener('change', syncCustomerFields);
  syncCustomerFields();
  renderCart();

  const productSearchForm = document.getElementById('product-search-form');
  const productSearchInput = document.getElementById('product-search');
  let productSearchTimer;
  const scheduleProductSearch = () => {
    clearTimeout(productSearchTimer);
    productSearchTimer = setTimeout(() => productSearchForm.requestSubmit(), 400);
  };
  productSearchForm.addEventListener('submit', () => clearTimeout(productSearchTimer));
  productSearchInput.addEventListener('input', (event) => {
    if (!event.isComposing) scheduleProductSearch();
  });
  productSearchInput.addEventListener('compositionstart', () => clearTimeout(productSearchTimer));
  productSearchInput.addEventListener('compositionend', scheduleProductSearch);
</script>
@endsection
