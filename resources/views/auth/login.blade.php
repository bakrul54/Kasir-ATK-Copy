<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Masuk — {{ $storeSetting->store_name }}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="auth-page">
  <main class="auth-card">
    <div class="auth-brand">
      @if($storeSetting->logo_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($storeSetting->logo_path) }}" alt="Logo {{ $storeSetting->store_name }}">
      @else
        <x-icon name="printer" />
      @endif
      <div><strong>{{ $storeSetting->store_name }}</strong><small>{{ $storeSetting->address ?: 'Fotocopy • Cetak Foto • ATK' }}</small></div>
    </div>
    <h1>Selamat datang</h1>
    <p class="auth-subtitle">Masuk untuk mengelola toko Anda.</p>
    @if(session('success'))
      <div class="notice success" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
      <div class="notice error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('login.store') }}">
      @csrf
      <div class="field">
        <label for="email">Email</label>
        <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Kata Sandi</label>
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <label class="remember-field"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
      <button class="btn primary block" type="submit">Masuk</button>
    </form>
  </main>
</body>
</html>
