<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pengaturan Awal — Spot Print</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="auth-page">
  <main class="auth-card">
    <div class="auth-brand"><x-icon name="printer" /><div><strong>Spot Print</strong><small>Fotocopy • Cetak Foto • ATK</small></div></div>
    <h1>Buat akun pemilik</h1>
    <p class="auth-subtitle">Akun ini dibuat satu kali untuk mengamankan akses ke sistem toko.</p>
    @if($errors->any())
      <div class="notice error" role="alert">
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      </div>
    @endif
    <form method="POST" action="{{ route('setup.store') }}">
      @csrf
      <div class="field">
        <label for="name">Nama</label>
        <input class="input" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
      </div>
      <div class="field">
        <label for="password">Kata Sandi (minimal 8 karakter)</label>
        <input class="input" id="password" name="password" type="password" autocomplete="new-password" required>
      </div>
      <div class="field">
        <label for="password_confirmation">Ulangi Kata Sandi</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
      </div>
      <button class="btn primary block" type="submit">Buat Akun & Lanjutkan</button>
    </form>
  </main>
</body>
</html>
