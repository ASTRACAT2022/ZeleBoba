@extends('layouts.admin')
@section('title', 'Тарифы')
@section('content')
<a href="{{ url('/') }}">← В кабинет</a><h1>Тарифы</h1><p class="muted">Выберите период и объём доступа.</p>
@forelse ($plans as $plan)
<section class="panel"><h2>{{ $plan->name }}</h2><p><strong>{{ number_format($plan->effective_price_minor / 100, 0, ',', ' ') }} ₽</strong> · {{ $plan->duration_days }} дней</p><p class="muted">{{ $plan->traffic_bytes == 0 ? 'Безлимитный трафик' : number_format($plan->traffic_bytes / 1073741824, 0, ',', ' ') . ' ГБ' }} · до {{ $plan->devices }} устройств</p><form method="post" action="{{ url('/orders') }}">@csrf<input type="hidden" name="plan_id" value="{{ $plan->id }}"><input type="hidden" name="idempotency_key" value="{{ bin2hex(random_bytes(16)) }}"><button>Выбрать тариф</button></form></section>
@empty <section class="panel"><p class="muted">Нет доступных тарифов.</p></section>@endforelse
@endsection
