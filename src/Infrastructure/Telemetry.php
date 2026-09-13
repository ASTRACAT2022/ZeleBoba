<?php
declare(strict_types=1);
namespace App\Infrastructure;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;

/** Minimal, vendor-neutral tracing for request and durable-job lifecycles. */
final class Telemetry
{
    /** @param array<string,bool|int|float|string|array|null> $attributes */
    public static function start(string $name, array $attributes=[]): ?SpanInterface
    {
        try {
            $tracer=Globals::tracerProvider()->getTracer('zeleboba.billing','0.2.0');
            if (!$tracer->isEnabled()) return null;
            return $tracer->spanBuilder($name)->setAttributes($attributes)->startSpan();
        } catch (\Throwable) {
            // Observability must never make checkout, webhook, or worker paths fail.
            return null;
        }
    }

    /** @param array<string,bool|int|float|string|array|null> $attributes */
    public static function complete(?SpanInterface $span, array $attributes=[]): void
    {
        if (!$span) return;
        try {
            $span->setAttributes($attributes)->setStatus(StatusCode::STATUS_OK)->end();
        } catch (\Throwable) {}
    }

    public static function fail(?SpanInterface $span, \Throwable $error): void
    {
        if (!$span) return;
        try {
            $span->recordException($error)->setStatus(StatusCode::STATUS_ERROR, get_class($error))->end();
        } catch (\Throwable) {}
    }
}
