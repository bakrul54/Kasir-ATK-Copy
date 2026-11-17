@extends('layouts.app')
@section('title', 'User & Kasir')
@section('subtitle', 'Kelola akun, hak akses, shift kasir, dan aktivitas')

@section('content')
@if(session('success'))
  <div class="notice success" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="notice error" role="alert">{{ $errors->first() }}</div>
@endif

<div class="grid g-main user-management-grid">
  <section class="card">
    <div class="card-head">
      <div><h2>Daftar Pengguna</h2><div class="sub">Akun pemilik hanya dapat dikelola melalui akses pemilik langsung.</div></div>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Nama / Email</th><th>Peran</th><th>Status</th><th>Shift</th><th>Aksi</th></tr></thead>
        <tbody>
        @forelse($users as $staff)
          <tr>
            <td><b>{{ $staff->name }}</b><small class="user-email">{{ $staff->email }}</small></td>
            <td><span class="pill {{ ['owner' => 'c-purple', 'admin' => 'c-blue', 'cashier' => 'c-green'][$staff->role] }}">{{ ['owner' => 'Owner', 'admin' => 'Admin', 'cashier' => 'Kasir'][$staff->role] }}</span></td>
            <td><span class="pill {{ $staff->is_active ? 'c-green' : 'c-gray' }}">{{ $staff->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
            <td>{{ $staff->open_shifts_count ? 'Berjalan' : '—' }}</td>
            <td>
              @if($staff->role !== 'owner')
                <details class="user-edit">
                  <summary class="btn sm">Ubah</summary>
                  <form method="POST" action="{{ route('pengguna.update', $staff) }}" class="user-edit-form">
                    @csrf
                    @method('PUT')
                    <div class="field">
                      <label for="user-name-{{ $staff->id }}">Nama</label>
                      <input class="input" id="user-name-{{ $staff->id }}" name="name" value="{{ $staff->name }}" maxlength="255" required>
                    </div>
                    <div class="field">
                      <label for="user-email-{{ $staff->id }}">Email</label>
                      <input class="input" id="user-email-{{ $staff->id }}" name="email" type="email" value="{{ $staff->email }}" maxlength="255" required>
                    </div>
                    <div class="field">
                      <label for="user-role-{{ $staff->id }}">Peran</label>
                      <select class="input" id="user-role-{{ $staff->id }}" name="role" required>
                        <option value="admin" @selected($staff->role === 'admin')>Admin</option>
                        <option value="cashier" @selected($staff->role === 'cashier')>Kasir</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="user-password-{{ $staff->id }}">Kata sandi baru <span class="hint">(opsional)</span></label>
                      <input class="input" id="user-password-{{ $staff->id }}" name="password" type="password" autocomplete="new-password" minlength="8">
                    </div>
                    <div class="field">
                      <label for="user-password-confirmation-{{ $staff->id }}">Konfirmasi kata sandi</label>
                      <input class="input" id="user-password-confirmation-{{ $staff->id }}" name="password_confirmation" type="password" autocomplete="new-password">
                    </div>
                    <label class="user-active-toggle">
                      <input type="checkbox" name="is_active" value="1" @checked($staff->is_active)>
                      Akun aktif
                    </label>
                    <button class="btn primary sm" type="submit">Simpan Perubahan</button>
                  </form>
                </details>
              @else
                <span class="muted">Akun utama</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="finance-empty">Belum ada pengguna.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <div class="finance-pagination">{{ $users->links() }}</div>
  </section>

  <aside class="card">
    <div class="card-head"><div><h2>Tambah User</h2><div class="sub">Buat akun Admin atau Kasir. Peran Owner tidak dapat dibuat dari formulir ini.</div></div></div>
    <form method="POST" action="{{ route('pengguna.store') }}" class="staff-create-form">
      @csrf
      <div class="field">
        <label for="new-user-name">Nama</label>
        <input class="input" id="new-user-name" name="name" value="{{ old('name') }}" maxlength="255" required>
        @error('name')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="new-user-email">Email</label>
        <input class="input" id="new-user-email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required>
        @error('email')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="new-user-role">Peran</label>
        <select class="input" id="new-user-role" name="role" required>
          <option value="cashier" @selected(old('role', 'cashier') === 'cashier')>Kasir</option>
          <option value="admin" @selected(old('role') === 'admin')>Admin</option>
        </select>
        @error('role')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="new-user-password">Kata sandi sementara</label>
        <input class="input" id="new-user-password" name="password" type="password" autocomplete="new-password" minlength="8" required>
        @error('password')<small class="field-error">{{ $message }}</small>@enderror
      </div>
      <div class="field">
        <label for="new-user-password-confirmation">Konfirmasi kata sandi</label>
        <input class="input" id="new-user-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
      </div>
      <button class="btn primary block" type="submit">Buat Akun</button>
    </form>
  </aside>
</div>

<section class="grid g3 mt">
  <article class="card"><span class="pill c-purple">Owner</span><h3 class="mt-sm">Akses penuh</h3><p class="sub">Seluruh modul, akun pengguna, pengaturan toko, shift, dan riwayat aktivitas.</p></article>
  <article class="card"><span class="pill c-blue">Admin</span><h3 class="mt-sm">Operasional toko</h3><p class="sub">Produk, harga, stok, pembelian, kasir, keuangan, pelanggan, dan laporan. Tidak dapat mengelola akun atau pengaturan.</p></article>
  <article class="card"><span class="pill c-green">Kasir</span><h3 class="mt-sm">Transaksi &amp; shift sendiri</h3><p class="sub">Hanya dapat mengakses kasir, harus membuka shift sebelum transaksi, serta hanya dapat membuka atau menutup shift miliknya.</p></article>
</section>

<section class="card mt">
  <div class="card-head"><div><h2>Riwayat Shift Kasir</h2><div class="sub">Kas yang diharapkan = kas awal + penjualan tunai selama shift.</div></div></div>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Kasir</th><th>Mulai</th><th>Selesai</th><th class="r">Kas Awal</th><th class="r">Kas Harapan</th><th class="r">Kas Fisik</th><th class="r">Selisih</th><th>Status</th></tr></thead>
      <tbody>
      @forelse($shifts as $shift)
        <tr>
          <td><b>{{ $shift->user->name }}</b></td>
          <td>{{ $shift->opened_at->format('d M Y H:i') }}</td>
          <td>{{ $shift->closed_at?->format('d M Y H:i') ?? '—' }}</td>
          <td class="r">Rp {{ number_format($shift->opening_cash, 0, ',', '.') }}</td>
          <td class="r">{{ $shift->expected_cash === null ? '—' : 'Rp '.number_format($shift->expected_cash, 0, ',', '.') }}</td>
          <td class="r">{{ $shift->closing_cash === null ? '—' : 'Rp '.number_format($shift->closing_cash, 0, ',', '.') }}</td>
          <td class="r">{{ $shift->cash_difference === null ? '—' : 'Rp '.number_format($shift->cash_difference, 0, ',', '.') }}</td>
          <td><span class="pill {{ $shift->isOpen() ? 'c-green' : 'c-gray' }}">{{ $shift->isOpen() ? 'Berjalan' : 'Selesai' }}</span></td>
        </tr>
      @empty
        <tr><td colspan="8" class="finance-empty">Belum ada shift yang dicatat.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="finance-pagination">{{ $shifts->links() }}</div>
</section>

<section class="card mt">
  <div class="card-head"><div><h2>Riwayat Aktivitas</h2><div class="sub">Aktivitas transaksi, stok, harga, kas, akun, dan shift.</div></div></div>
  @forelse($activities as $activity)
    <div class="list-item activity-row">
      <span class="activity-time">{{ $activity->created_at->format('d M H:i') }}</span>
      <b class="activity-user">{{ $activity->user?->name ?? 'Sistem' }}</b>
      <span class="grow">{{ $activity->description }}</span>
      <span class="pill c-gray">{{ $activity->action }}</span>
    </div>
  @empty
    <div class="finance-empty">Belum ada aktivitas yang tercatat.</div>
  @endforelse
</section>
@endsection
