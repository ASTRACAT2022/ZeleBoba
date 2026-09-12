@extends('layouts.admin')
@section('title','Баланс')
@section('content')
<a href="{{ url('/') }}">← В кабинет</a><h1>Баланс: {{ number_format($user->balance_kopeks / 100,0,',',' ') }} ₽</h1><section class="panel"><h2>Пополнить</h2><form method="post" action="{{ url('/balance/topup') }}">@csrf<label>Сумма, ₽</label><input name="amount" type="number" min="1" required><input name="idempotency_key" type="hidden" value="{{ bin2hex(random_bytes(16)) }}"><button>Перейти к оплате</button></form></section><section class="panel"><h2>Операции</h2>@forelse($history as $item)<article>{{ $item->description ?: $item->type }} · {{ $item->amount_kopeks > 0 ? '+' : '' }}{{ number_format($item->amount_kopeks/100,0,',',' ') }} ₽</article>@empty<p class="muted">Операций нет.</p>@endforelse</section>
@endsection
