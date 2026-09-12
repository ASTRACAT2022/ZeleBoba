@extends('auth.layout')
@section('title', 'Регистрация')
@section('content')
<h1>Регистрация</h1><p>Пароль должен содержать не менее 12 символов.</p>
<form method="post" action="{{ url('/register') }}">@csrf<label>Email</label><input name="email" type="email" value="{{ old('email') }}" required autofocus>@error('email')<p class="error">{{ $message }}</p>@enderror<label>Пароль</label><input name="password" type="password" required>@error('password')<p class="error">{{ $message }}</p>@enderror<button>Создать аккаунт</button></form>
<p>Уже есть аккаунт? <a href="{{ url('/login') }}">Войти</a></p>
@endsection
