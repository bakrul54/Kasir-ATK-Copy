@extends('layouts.app')
@section('title', 'Hitung Harga Print')
@section('subtitle', 'Estimasi biaya berdasarkan coverage warna per lembar')

@section('content')
<div class="grid g-main2 print-coverage-layout">
  <section class="card">
    <div class="card-head">
      <div>
        <h2>Analisis dokumen</h2>
        <p class="sub">Unggah satu file PDF atau gambar untuk menghitung coverage setiap lembar.</p>
      </div>
    </div>

    <div class="notice print-coverage-privacy" role="note">
      File dianalisis langsung di browser dan tidak diunggah ke server.
    </div>

    <form id="print-coverage-form">
      <div class="field">
        <label for="print-coverage-file">File PDF atau gambar</label>
        <input
          class="input"
          id="print-coverage-file"
          type="file"
          accept=".pdf,.jpg,.jpeg,.png,.bmp,.webp,application/pdf,image/jpeg,image/png,image/bmp,image/webp"
          required
        >
        <p class="hint">Format: PDF, JPG, JPEG, PNG, BMP, atau WebP. Maksimal 25 MB; halaman dirender pada 100 DPI dan maksimal 12 juta piksel.</p>
      </div>

      <button class="btn primary" id="print-coverage-submit" type="submit">Hitung Coverage &amp; Harga</button>
    </form>

    <p class="notice error print-coverage-error" id="print-coverage-error" role="alert" hidden></p>
    <p class="hint print-coverage-status" id="print-coverage-status" role="status" aria-live="polite"></p>
  </section>

  <section class="card print-coverage-guide">
    <h2>Aturan perhitungan</h2>
    <div class="kv"><span>Warna 10%</span><span>Coverage total warna dan hitam 2% sampai 10%</span></div>
    <div class="kv"><span>Warna 50%</span><span>Coverage total warna dan hitam di atas 10% sampai 50%</span></div>
    <div class="kv"><span>Full Warna</span><span>Coverage total warna dan hitam di atas 50%</span></div>
    <div class="kv"><span>Hitam Putih</span><span>Coverage hitam sampai 15%</span></div>
    <div class="kv"><span>Hitam 50%</span><span>Coverage hitam di atas 15% sampai 50%</span></div>
    <div class="kv"><span>Full Hitam</span><span>Coverage hitam di atas 50%</span></div>
    <p class="hint mt">Coverage warna dihitung dari perbedaan nilai RGB lebih dari 15. Piksel hitam memiliki nilai RGB di bawah 50.</p>
    <p class="hint mt">Estimasi harga memakai harga produk dan aturan promo/grosir dari sistem Kasir. Harga final selalu dihitung ulang oleh Kasir.</p>
  </section>
</div>

<div
  id="print-coverage-options"
  data-cashier-url="{{ route('kasir') }}"
  data-transfer-key="spot-print:print-quote:v2:{{ auth()->id() }}"
  data-categories="{{ json_encode($printCategories, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}"
  hidden
></div>

<section class="card mt print-coverage-results" id="print-coverage-results" hidden aria-live="polite">
  <div class="card-head print-coverage-result-heading">
    <div>
      <h2>Estimasi biaya</h2>
      <p class="sub" id="print-coverage-filename"></p>
    </div>
    <span class="pill c-blue" id="print-coverage-tier"></span>
  </div>

  <div class="print-coverage-total">
    <span id="print-coverage-page-count"></span>
    <strong id="print-coverage-grand-total"></strong>
  </div>

  <div class="tbl-wrap">
    <table>
      <thead>
        <tr><th>Kategori</th><th class="r">Jumlah lembar</th><th class="r">Tarif per lembar</th><th class="r">Subtotal</th></tr>
      </thead>
      <tbody id="print-coverage-summary"></tbody>
      <tfoot>
        <tr><th colspan="3">Total biaya</th><th class="r" id="print-coverage-table-total"></th></tr>
      </tfoot>
    </table>
  </div>

  <p class="notice error print-coverage-error" id="print-coverage-cart-warning" role="alert" hidden></p>
  <h3 class="print-coverage-detail-title">Rincian coverage per lembar</h3>
  <div class="tbl-wrap">
    <table>
      <thead>
        <tr><th>Lembar</th><th>Kategori</th><th class="r">Coverage warna</th><th class="r">Coverage hitam</th></tr>
      </thead>
      <tbody id="print-coverage-pages"></tbody>
    </table>
  </div>
  <button class="btn primary mt" id="print-coverage-add-to-cart" type="button" hidden>Masukkan hasil ke keranjang kasir</button>
</section>

<dialog class="print-coverage-confirm-dialog" id="print-coverage-confirm-dialog" aria-labelledby="print-coverage-confirm-title" aria-describedby="print-coverage-confirm-description">
  <form id="print-coverage-confirm-form">
    <h2 id="print-coverage-confirm-title">Konfirmasi jumlah cetak</h2>
    <p class="hint print-coverage-confirm-description" id="print-coverage-confirm-description" aria-live="polite"></p>

    <div class="field">
      <label for="print-coverage-copy-count">Berapa kali file ini akan dicetak?</label>
      <div class="print-coverage-copy-count">
        <input class="input" id="print-coverage-copy-count" type="number" min="1" step="1" value="1" required inputmode="numeric">
        <span>kali</span>
      </div>
    </div>

    <p class="notice error print-coverage-error" id="print-coverage-confirm-error" role="alert" hidden></p>

    <div class="print-coverage-confirm-actions">
      <button class="btn" id="print-coverage-cancel" type="button">Batal</button>
      <button class="btn primary" type="submit">Konfirmasi &amp; masukkan ke keranjang</button>
    </div>
  </form>
</dialog>
@endsection

@push('scripts')
  @vite('resources/js/print-coverage.js')
@endpush
