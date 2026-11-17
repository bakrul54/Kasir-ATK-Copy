@extends('layouts.app')
@section('title', 'Keuangan')
@section('subtitle', 'Pantau arus kas, HPP, dan estimasi laba toko')

@section('content')
@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif

<div class="grid g4">
  <div class="card stat">
    <div class="lbl">Saldo Laci Toko</div>
    <div class="val">Rp {{ number_format($stats['accounts'][\App\Models\CashEntry::ACCOUNT_DRAWER], 0, ',', '.') }}</div>
    <div class="note muted">Uang tunai yang seharusnya ada di laci</div>
  </div>
  <div class="card stat">
    <div class="lbl">Saldo Dompet Toko</div>
    <div class="val t-green">Rp {{ number_format($stats['accounts'][\App\Models\CashEntry::ACCOUNT_WALLET], 0, ',', '.') }}</div>
    <div class="note muted">Dana yang disimpan di dompet toko</div>
  </div>
  <div class="card stat">
    <div class="lbl">Saldo QRIS / Transfer</div>
    <div class="val t-purple">Rp {{ number_format($stats['accounts'][\App\Models\CashEntry::ACCOUNT_NON_CASH], 0, ',', '.') }}</div>
    <div class="note muted">Settlement QRIS otomatis masuk ke rekening pribadi</div>
  </div>
  <div class="card stat">
    <div class="lbl">Estimasi Laba Bersih (Periode)</div>
    <div class="val t-purple">Rp {{ number_format($stats['net_profit'], 0, ',', '.') }}</div>
    <div class="note muted">Pendapatan − HPP − biaya operasional</div>
  </div>
</div>

<div class="finance-period-note mt">
  Periode ini: <b>Kas masuk</b> Rp {{ number_format($stats['cash_in'], 0, ',', '.') }} · <b>Kas keluar</b> Rp {{ number_format($stats['cash_out'], 0, ',', '.') }}. Saldo awal belum dimasukkan otomatis; catat sebagai <b>Kas Masuk</b> dan pilih saldo yang sesuai. Penjualan QRIS/transfer masuk ke saldo non-tunai, bukan saldo laci. Pemindahan antar saldo dicatat sebagai <b>Transfer</b>, bukan biaya.
</div>

<div class="grid g-main mt">
  <section class="card">
    <div class="card-head">
      <div>
        <h2>Buku Kas</h2>
        <div class="sub">Penjualan, refund, pembelian lunas, transaksi manual, dan transfer antar saldo</div>
      </div>
      <a class="btn primary" href="#form-kas">Catat Arus Kas</a>
    </div>
    <form class="toolbar finance-filters" method="GET" action="{{ route('keuangan') }}">
      <div class="field">
        <label for="finance-from">Dari tanggal</label>
        <input class="input" id="finance-from" type="date" name="from" value="{{ $filters['from'] }}">
      </div>
      <div class="field">
        <label for="finance-to">Sampai tanggal</label>
        <input class="input" id="finance-to" type="date" name="to" value="{{ $filters['to'] }}">
      </div>
      <div class="field finance-search-field">
        <label for="finance-q">Pencarian</label>
        <input class="input" id="finance-q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Keterangan, nomor, kategori">
      </div>
      <button class="btn primary" type="submit">Terapkan</button>
      <a class="btn" href="{{ route('keuangan') }}">Reset</a>
    </form>
    @if($errors->any())
      <div class="notice error" role="alert">{{ $errors->first() }}</div>
    @endif
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Tanggal</th><th>Keterangan</th><th>Kategori</th><th>Saldo</th><th>Jenis</th><th class="r">Jumlah</th></tr>
        </thead>
        <tbody>
        @forelse($entries as $entry)
          @php
            $typeLabels = ['masuk' => 'Kas Masuk', 'keluar' => 'Kas Keluar', 'pengeluaran' => 'Operasional', 'transfer' => 'Transfer'];
            $typeClasses = ['masuk' => 'c-green', 'keluar' => 'c-red', 'pengeluaran' => 'c-orange', 'transfer' => 'c-orange'];
            $accountLabel = $accounts[$entry->account] ?? $entry->account;
            if ($entry->type === 'transfer') {
              $accountLabel .= ' → '.($accounts[$entry->to_account] ?? $entry->to_account);
            }
          @endphp
          <tr>
            <td>{{ \Illuminate\Support\Carbon::parse($entry->entry_date)->format('d M Y') }}</td>
            <td>
              <b>{{ $entry->description ?: $entry->category }}</b>
              @if($entry->reference)
                <small class="finance-reference">{{ $entry->reference }}</small>
              @elseif($entry->source === 'manual')
                <small class="finance-reference">Kas #{{ str_pad((string) $entry->source_id, 6, '0', STR_PAD_LEFT) }}</small>
              @endif
            </td>
            <td>{{ $entry->category }}</td>
            <td>{{ $accountLabel }}</td>
            <td><span class="pill {{ $typeClasses[$entry->type] }}">{{ $typeLabels[$entry->type] }}</span></td>
            <td class="r {{ $entry->type === 'masuk' ? 't-green' : ($entry->type === 'transfer' ? '' : 't-red') }}">
              <b>{{ $entry->type === 'masuk' ? '+' : ($entry->type === 'transfer' ? '' : '−') }} Rp {{ number_format((int) $entry->amount, 0, ',', '.') }}</b>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="finance-empty">Belum ada catatan kas pada periode ini.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <div class="finance-pagination">{{ $entries->links() }}</div>
  </section>

  <section class="card">
    <div class="card-head">
      <div><h2>Laba Rugi Periode</h2><div class="sub">{{ $filters['from'] }} s.d. {{ $filters['to'] }}</div></div>
    </div>
    <div class="kv"><span>Pendapatan penjualan</span><b>Rp {{ number_format($stats['revenue'], 0, ',', '.') }}</b></div>
    <div class="kv"><span>HPP barang terjual</span><b>− Rp {{ number_format($stats['cost_of_goods_sold'], 0, ',', '.') }}</b></div>
    <div class="kv"><span>Laba kotor</span><b class="t-green">Rp {{ number_format($stats['gross_profit'], 0, ',', '.') }}</b></div>
    <div class="kv"><span>Biaya operasional</span><b>− Rp {{ number_format($stats['operating_expenses'], 0, ',', '.') }}</b></div>
    <div class="kv"><strong>Estimasi laba bersih</strong><b class="t-purple">Rp {{ number_format($stats['net_profit'], 0, ',', '.') }}</b></div>
    <div class="finance-debt">
      <span>Pembelian belum lunas pada periode</span>
      <b>Rp {{ number_format($stats['unpaid_purchases'], 0, ',', '.') }}</b>
    </div>
  </section>
</div>

<section class="card mt">
  <div class="card-head">
    <div><h2>Pengeluaran Operasional</h2><div class="sub">Rincian biaya berdasarkan kategori untuk periode terpilih</div></div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Kategori</th><th class="r">Total</th></tr></thead>
      <tbody>
      @forelse($expenseCategories as $expense)
        <tr><td><b>{{ $expense->category }}</b></td><td class="r">Rp {{ number_format((int) $expense->total, 0, ',', '.') }}</td></tr>
      @empty
        <tr><td colspan="2" class="finance-empty">Belum ada pengeluaran operasional pada periode ini.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>

<section class="card mt" id="form-kas">
  <div class="card-head">
    <div><h2>Catat Arus Kas</h2><div class="sub">Pilih saldo yang benar. Transfer antar saldo tidak dihitung sebagai pendapatan atau biaya.</div></div>
  </div>
  <form method="POST" action="{{ route('keuangan.store') }}" class="finance-form">
    @csrf
    <div class="field">
      <label for="cash-type">Jenis transaksi</label>
      <select class="input" id="cash-type" name="type" required>
        <option value="masuk" @selected(old('type') === 'masuk')>Kas masuk (bukan pendapatan penjualan)</option>
        <option value="keluar" @selected(old('type') === 'keluar')>Kas keluar (bukan biaya operasional)</option>
        <option value="pengeluaran" @selected(old('type', 'pengeluaran') === 'pengeluaran')>Pengeluaran operasional</option>
        <option value="transfer" @selected(old('type') === 'transfer')>Transfer antar saldo (bukan biaya)</option>
      </select>
      @error('type')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="field">
      <label for="cash-account">Saldo asal / tujuan</label>
      <select class="input" id="cash-account" name="account" required>
        @foreach($accounts as $account => $label)
          <option value="{{ $account }}" @selected(old('account', \App\Models\CashEntry::ACCOUNT_DRAWER) === $account)>{{ $label }}</option>
        @endforeach
      </select>
      @error('account')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="field">
      <label for="cash-to-account">Saldo tujuan (khusus transfer)</label>
      <select class="input" id="cash-to-account" name="to_account">
        <option value="">Tidak ada</option>
        @foreach($accounts as $account => $label)
          <option value="{{ $account }}" @selected(old('to_account') === $account)>{{ $label }}</option>
        @endforeach
      </select>
      @error('to_account')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="field">
      <label for="cash-category">Kategori</label>
      <input class="input" id="cash-category" name="category" value="{{ old('category') }}" maxlength="100" placeholder="Contoh: Listrik, Setoran modal, Prive">
      @error('category')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="field">
      <label for="cash-amount">Jumlah (Rp)</label>
      <input class="input" id="cash-amount" name="amount" type="number" min="1" step="1" value="{{ old('amount') }}" required>
      @error('amount')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="field">
      <label for="cash-date">Tanggal</label>
      <input class="input" id="cash-date" name="transaction_date" type="date" value="{{ old('transaction_date', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required>
      @error('transaction_date')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="field finance-description-field">
      <label for="cash-description">Keterangan (opsional)</label>
      <input class="input" id="cash-description" name="description" value="{{ old('description') }}" maxlength="255" placeholder="Contoh: Tagihan listrik bulan Oktober">
      @error('description')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <div class="finance-submit"><button class="btn primary" type="submit">Simpan Catatan Kas</button></div>
  </form>
</section>
@endsection
