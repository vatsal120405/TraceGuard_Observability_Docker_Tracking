<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Spatie\Prometheus\Facades\Prometheus;
use Symfony\Component\HttpFoundation\Response;

class OpenTelemetryMiddleware
{
    private $tracer;

    public function __construct(TracerInterface $tracer)
    {
        $this->tracer = $tracer;
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $span = $this->tracer->spanBuilder($request->method().' '.$request->path())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.url', $request->fullUrl());
        $span->setAttribute('http.target', $request->path());
        $span->setAttribute('http.host', $request->getHost());
        $span->setAttribute('http.scheme', $request->getScheme());
        $span->setAttribute('http.user_agent', $request->userAgent());

        try {
            $response = $next($request);

            $span->setAttribute('http.status_code', $response->getStatusCode());

            if ($response->isServerError()) {
                $span->setStatus(StatusCode::STATUS_ERROR, 'Server Error');
            } else {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            // Record Prometheus metrics
            $this->recordMetrics($request, $response, $startTime);

            return $response;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    private function recordMetrics(Request $request, Response $response, float $startTime): void
    {
        try {
            $duration = microtime(true) - $startTime;
            $statusCode = $response->getStatusCode();

            // Record request counter
            Prometheus::counter('laravel_prometheus_http_requests_total', 'Total HTTP requests')
                ->labels($request->method(), (string) $statusCode)
                ->inc();

            // Record request duration histogram
            Prometheus::histogram('laravel_prometheus_http_request_duration_seconds', 'HTTP request duration in seconds')
                ->labels($request->method(), (string) $statusCode)
                ->observe($duration);
        } catch (\Throwable $e) {
            // Fail silently to avoid breaking the request
        }
    }
}
