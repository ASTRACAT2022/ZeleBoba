@extends('layouts.admin')
@section('title', 'Заказ')
@section('content')
<a href="{{ url('/') }}">← В кабинет</a><h1>{{ $order->plan_name }}</h1><section class="panel"><p><strong>{{ number_format($order->price_minor / 100, 0, ',', ' ') }} ₽</strong> · {{ strtoupper($order->status) }}</p>
@if ($order->status === 'pending' && $order->provider === 'demo')<form method="post" action="{{ url('/orders/'.$order->id.'/demo-pay') }}">@csrf<button>Оплатить в деморежиме</button></form>@elseif ($order->status === 'pending' && $order->checkout_url)<a href="{{ $order->checkout_url }}" rel="noreferrer">Перейти к оплате →</a>@endif
</section>
@endsection
