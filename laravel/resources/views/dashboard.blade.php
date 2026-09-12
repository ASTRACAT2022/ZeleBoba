@extends('layouts.admin')
@section('title', 'Личный кабинет')
@section('content')
<header><h1>{{ $user->email ?: 'Telegram-аккаунт' }}</h1><form method="post" action="{{ url('/logout') }}">@csrf<button type="submit">Выйти</button></form></header>
<section class="panel"><h2>Подписки</h2>
@forelse ($subscriptions as $subscription)
    <article><strong>{{ $subscription->plan_name ?: 'Подписка' }}</strong> · <span>{{ strtoupper($subscription->status) }}</span><br><span class="muted">Действует до {{ \Carbon\Carbon::createFromTimestamp($subscription->expires_at)->format('d.m.Y H:i') }}</span></article>
@empty <p class="muted">Активных подписок пока нет.</p>@endforelse
</section>
<section class="panel"><h2>Последние платежи</h2>
@forelse ($orders as $order)<article><strong>{{ $order->plan_name }}</strong> · {{ number_format($order->price_minor / 100, 0, ',', ' ') }} ₽ · {{ $order->status }}</article>
@empty <p class="muted">Платежей пока нет.</p>@endforelse
</section>
@endsection
