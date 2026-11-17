<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') — {{ $storeSetting->store_name }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
</head>
<body>
@php
$menu = [
  ['dashboard',  'Dashboard',          'dashboard'],
  ['kasir',      'Kasir / Transaksi',  'cart'],
  ['print-pricing', 'Hitung Harga Print', 'printer'],
  ['etalase',    'Etalase Produk',     'store'],
  ['harga',      'Manajemen Harga',    'tag'],
  ['stok',       'Inventory / Stok',   'box'],
  ['pembelian',  'Pembelian / Belanja','bag'],
  ['keuangan',   'Keuangan',           'card'],
  ['pelanggan',  'Pelanggan',          'users'],
  ['laporan',    'Laporan',            'chart'],
  ['pengguna',   'User & Kasir',       'check'],
  ['pengaturan', 'Pengaturan',         'settings'],
];
@endphp
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="logo">
        @if($storeSetting->logo_path)
          <img src="{{ \Illuminate\Support\Facades\Storage::url($storeSetting->logo_path) }}" alt="Logo {{ $storeSetting->store_name }}">
        @else
          <x-icon name="printer" />
        @endif
      </div>
      <div>
        <b>{{ $storeSetting->store_name }}</b>
        <small>{{ $storeSetting->address ?: 'Fotocopy • Cetak Foto • ATK' }}</small>
      </div>
    </div>
    <div class="menu-label">MENU UTAMA</div>
    <nav class="nav">
      @foreach($menu as $m)
        @if(auth()->user()->role === 'cashier' && !in_array($m[0], ['kasir', 'print-pricing'], true))
          @continue
        @endif
        @if(auth()->user()->role === 'admin' && in_array($m[0], ['pengguna', 'pengaturan'], true))
          @continue
        @endif
        <a href="{{ route($m[0]) }}" class="{{ request()->routeIs($m[0], $m[0].'.*') ? 'active' : '' }}">
          <x-icon :name="$m[2]" /> {{ $m[1] }}
        </a>
      @endforeach
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <div>
        <h1>@yield('title')</h1>
        <p>@yield('subtitle')</p>
      </div>
      <div class="sp"></div>
      <div class="search"><x-icon name="search" /><input type="text" placeholder="Cari transaksi, produk, atau pelanggan..."></div>
      <a class="icon-btn" href="#"><x-icon name="bell" /><span class="dot"></span></a>
      <div class="me">
        <div class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
        <div><b>{{ auth()->user()->name }}</b><small>{{ ['owner' => 'Owner', 'admin' => 'Admin', 'cashier' => 'Kasir'][auth()->user()->role] }}</small></div>
        <form method="POST" action="{{ route('logout') }}">
          @csrf
          <button class="btn sm" type="submit">Keluar</button>
        </form>
      </div>
    </header>

    <main class="content">
      @yield('content')
    </main>
  </div>
</div>
@stack('scripts')
</body>
</html>
