@extends('layouts.admin')
@section('title','Пополнение')
@section('content')
<a href="{{ url('/balance') }}">← К балансу</a><h1>Пополнение на {{ number_format($topup->amount_kopeks/100,0,',',' ') }} ₽</h1><section class="panel">@if($topup->status==='pending' && $topup->provider==='demo')<form method="post" action="{{ url('/balance/topup/'.$topup->id.'/demo-pay') }}">@csrf<button>Оплатить в деморежиме</button></form>@else<p>{{ strtoupper($topup->status) }}</p>@endif</section>
@endsection
