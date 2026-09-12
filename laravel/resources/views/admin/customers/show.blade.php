@extends('layouts.admin')

@section('title', 'Клиент')

@section('content')
    <a href="{{ url('/admin') }}">← К управлению</a>
    <h1>{{ $customer->email ?: 'Telegram-аккаунт' }}</h1>
    <p class="muted">ID: {{ \Illuminate\Support\Str::limit($customer->id, 8, '') }} · Регистрация: {{ \Carbon\Carbon::createFromTimestamp($customer->created_at)->format('d.m.Y') }}</p>

    <section class="panel">
        <h2>Customer Timeline</h2>
        @if ($timeline->isEmpty())
            <p class="muted">Событий пока нет.</p>
        @else
            <ol class="timeline">
                @foreach ($timeline as $event)
                    <li>
                        <time>{{ \Carbon\Carbon::createFromTimestamp($event->occurred_at)->format('H:i') }}</time>
                        <span>
                            @switch($event->event_type)
                                @case('payment.created') Payment created — {{ number_format($event->payload['amount_kopeks'] / 100, 0, ',', ' ') }} ₽ @break
                                @case('payment.paid') Payment paid @break
                                @case('subscription.renewed') Subscription renewed → {{ \Carbon\Carbon::createFromTimestamp($event->payload['expires_at'])->format('d M') }} @break
                                @case('vpn.provisioning_started') VPN provisioning started @break
                                @case('vpn.resource_updated') VPN resource updated @break
                                @case('subscription.active') Subscription ACTIVE @break
                                @case('telegram.notification_delivered') Telegram notification delivered @break
                                @default {{ $event->event_type }}
                            @endswitch
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endsection
