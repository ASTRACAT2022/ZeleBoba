@extends('auth.layout')
@section('title', 'Вход')
@section('content')
<h1>Вход</h1><p>Войдите в личный кабинет.</p>
<form method="post" action="{{ url('/login') }}">@csrf<label>Email</label><input name="email" type="email" value="{{ old('email') }}" required autofocus>@error('email')<p class="error">{{ $message }}</p>@enderror<label>Пароль</label><input name="password" type="password" required><button>Войти</button></form>
<p>Нет аккаунта? <a href="{{ url('/register') }}">Зарегистрироваться</a></p>
@endsection
