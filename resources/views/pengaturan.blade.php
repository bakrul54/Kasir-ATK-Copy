@extends('layouts.app')
@section('title', 'Pengaturan')
@section('subtitle', 'Sesuaikan identitas dan operasional toko')

@section('content')
@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="notice error" role="alert">
    <strong>Permintaan belum dapat diproses.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

<form method="POST" action="{{ route('pengaturan.update') }}" enctype="multipart/form-data" id="store-settings-form">
  @csrf
  @method('PUT')
  <div class="grid g2 store-settings-grid">
    <section class="card">
      <div class="card-head"><div><h2>Profil Toko</h2><div class="sub">Nama dan kontak ditampilkan pada aplikasi serta struk.</div></div></div>
      <div class="field">
        <label for="store_name">Nama toko</label>
        <input class="input" id="store_name" name="store_name" value="{{ old('store_name', $settings->store_name) }}" maxlength="160" required>
        @error('store_name')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="address">Alamat</label>
        <textarea class="input" id="address" name="address" rows="3" maxlength="255">{{ old('address', $settings->address) }}</textarea>
        @error('address')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="whatsapp">Nomor WhatsApp</label>
        <input class="input" id="whatsapp" name="whatsapp" type="tel" value="{{ old('whatsapp', $settings->whatsapp) }}" maxlength="30" placeholder="08xx atau +62xx">
        @error('whatsapp')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="logo">Logo toko <span class="hint">(JPG, PNG, WebP; maksimal 2 MB)</span></label>
        <input class="input" id="logo" name="logo" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
        @if($settings->logo_path)
          <div class="store-logo-preview">
            <img src="{{ \Illuminate\Support\Facades\Storage::url($settings->logo_path) }}" alt="Logo {{ $settings->store_name }}">
            <label><input type="checkbox" name="remove_logo" value="1"> Hapus logo saat ini</label>
          </div>
        @endif
        @error('logo')<small class="field-error">{{ $message }}</small>@enderror
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Printer &amp; Format Struk</h2><div class="sub">Atur lebar kertas dan pesan yang dicetak untuk setiap transaksi.</div></div></div>
      <div class="field">
        <label for="printer_paper_size">Lebar kertas printer</label>
        <select class="input" id="printer_paper_size" name="printer_paper_size" required>
          <option value="58mm" @selected(old('printer_paper_size', $settings->printer_paper_size) === '58mm')>Thermal 58 mm</option>
          <option value="80mm" @selected(old('printer_paper_size', $settings->printer_paper_size) === '80mm')>Thermal 80 mm</option>
        </select>
        @error('printer_paper_size')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="receipt_header">Header struk <span class="hint">(opsional)</span></label>
        <input class="input" id="receipt_header" name="receipt_header" value="{{ old('receipt_header', $settings->receipt_header) }}" maxlength="255" placeholder="Pesan di bawah nama toko">
      </div>
      <div class="field">
        <label for="receipt_footer">Footer struk <span class="hint">(opsional)</span></label>
        <input class="input" id="receipt_footer" name="receipt_footer" value="{{ old('receipt_footer', $settings->receipt_footer) }}" maxlength="255">
      </div>
      <label class="settings-toggle">
        <input type="checkbox" name="receipt_show_logo" value="1" @checked(old('receipt_show_logo', $settings->receipt_show_logo))>
        <span>Tampilkan logo di struk</span>
      </label>
      <label class="settings-toggle">
        <input type="checkbox" name="receipt_auto_print" value="1" @checked(old('receipt_auto_print', $settings->receipt_auto_print))>
        <span>Buka dialog cetak otomatis setelah membuka struk</span>
      </label>
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Metode Pembayaran</h2><div class="sub">Kasir hanya dapat memilih metode yang diaktifkan.</div></div></div>
      @php($enabledMethods = old('payment_methods', $settings->payment_methods))
      @foreach(['cash' => 'Cash', 'transfer' => 'Transfer Bank', 'qris' => 'QRIS'] as $method => $label)
        <label class="settings-toggle">
          <input type="checkbox" name="payment_methods[]" value="{{ $method }}" @checked(in_array($method, $enabledMethods, true))>
          <span>{{ $label }}</span>
        </label>
      @endforeach
      @error('payment_methods')<small class="field-error">{{ $message }}</small>@enderror
      <div class="field mt">
        <label for="payment_information">Informasi rekening / QRIS <span class="hint">(opsional)</span></label>
        <textarea class="input" id="payment_information" name="payment_information" rows="3" maxlength="255" placeholder="Nomor rekening atau keterangan pembayaran">{{ old('payment_information', $settings->payment_information) }}</textarea>
        @error('payment_information')<small class="field-error">{{ $message }}</small>@enderror
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Harga &amp; Stok</h2><div class="sub">Preferensi ini memengaruhi harga POS dan nilai default saat membuat produk.</div></div></div>
      <label class="settings-toggle">
        <input type="checkbox" name="wholesale_enabled" value="1" @checked(old('wholesale_enabled', $settings->wholesale_enabled))>
        <span>Gunakan harga grosir pada transaksi</span>
      </label>
      <label class="settings-toggle">
        <input type="checkbox" name="promo_enabled" value="1" @checked(old('promo_enabled', $settings->promo_enabled))>
        <span>Gunakan harga promo pada transaksi</span>
      </label>
      <div class="field mt">
        <label for="default_minimum_stock">Batas stok menipis default</label>
        <input class="input" id="default_minimum_stock" name="default_minimum_stock" type="number" value="{{ old('default_minimum_stock', $settings->default_minimum_stock) }}" min="0" max="4294967295" step="1" required>
        @error('default_minimum_stock')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label>Satuan barang</label>
        <div class="settings-units" id="settings-units">
          @foreach(old('units', $settings->units) as $unit)
            <div class="settings-unit-row">
              <input class="input" name="units[]" value="{{ $unit }}" maxlength="30" required aria-label="Nama satuan">
              <button class="btn sm danger remove-unit" type="button" aria-label="Hapus satuan">×</button>
            </div>
          @endforeach
        </div>
        <button class="btn sm mt" id="add-unit" type="button">+ Tambah satuan</button>
        @error('units')<small class="field-error">{{ $message }}</small>@enderror
        @error('units.*')<small class="field-error">{{ $message }}</small>@enderror
      </div>
    </section>
  </div>
  <div class="settings-save-row">
    <button class="btn primary" type="submit">Simpan Pengaturan</button>
  </div>
</form>

<section class="card mt">
  <div class="card-head"><div><h2>Backup &amp; Restore Data</h2><div class="sub">Cadangkan dan pulihkan data toko beserta file unggahan.</div></div></div>
  <p>Backup mencakup produk, transaksi, stok, akun, pengaturan, foto produk, logo, dan kuitansi pembelian. Ukuran file maksimal 50 MB.</p>
  <form method="GET" action="{{ route('pengaturan.backup.download') }}">
    <button class="btn primary" type="submit">Unduh Backup</button>
  </form>

  <hr>
  <div class="notice error" role="alert">
    <strong>Restore mengganti seluruh data toko saat ini.</strong>
    Pastikan backup yang dipilih benar. Setelah restore, Anda akan keluar dan harus masuk kembali menggunakan akun yang tersimpan di dalam backup.
  </div>
  <form method="POST" action="{{ route('pengaturan.backup.restore') }}" enctype="multipart/form-data">
    @csrf
    <div class="field">
      <label for="backup_file">File backup (.zip)</label>
      <input class="input" id="backup_file" name="backup_file" type="file" accept=".zip,application/zip" required>
      @error('backup_file')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="field">
      <label for="backup_confirmation">Ketik PULIHKAN untuk mengonfirmasi</label>
      <input class="input" id="backup_confirmation" name="confirmation" autocomplete="off" required>
      @error('confirmation')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <button class="btn danger" type="submit">Pulihkan Backup</button>
  </form>
</section>

<template id="settings-unit-template">
  <div class="settings-unit-row">
    <input class="input" name="units[]" maxlength="30" required aria-label="Nama satuan">
    <button class="btn sm danger remove-unit" type="button" aria-label="Hapus satuan">×</button>
  </div>
</template>
<script>
  const unitsContainer = document.getElementById('settings-units');
  document.getElementById('add-unit').addEventListener('click', () => {
    if (unitsContainer.querySelectorAll('.settings-unit-row').length >= 20) return;
    unitsContainer.append(document.getElementById('settings-unit-template').content.cloneNode(true));
    unitsContainer.lastElementChild.querySelector('input').focus();
  });
  unitsContainer.addEventListener('click', (event) => {
    const button = event.target.closest('.remove-unit');
    if (button && unitsContainer.querySelectorAll('.settings-unit-row').length > 1) {
      button.closest('.settings-unit-row').remove();
    }
  });
</script>
@endsection
