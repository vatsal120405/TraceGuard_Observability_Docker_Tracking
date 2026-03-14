<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Spatie\Prometheus\Facades\Prometheus;

class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        try {
            $this->app->singleton(TracerInterface::class, function ($app) {
                $resource = ResourceInfo::create(Attributes::create([
                    ResourceAttributes::SERVICE_NAME => 'traceguard-app',
                    ResourceAttributes::SERVICE_VERSION => '1.0.0',
                    'deployment.environment' => config('app.env', 'production'),
                ]));

                // Tempo OTLP HTTP endpoint (factory will use environmental variables or defaults)
                $exporter = (new SpanExporterFactory)->create();

                $spanProcessor = new SimpleSpanProcessor($exporter);

                $tracerProvider = new TracerProvider(
                    $spanProcessor,
                    null,
                    $resource
                );

                return $tracerProvider->getTracer('laravel-tracer');
            });
        } catch (\Throwable $e) {
            Log::error('OTel Registration Failed: '.$e->getMessage());
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        try {
            $tracer = $this->app->make(TracerInterface::class);

            // Listen for Database Queries
            DB::listen(function (QueryExecuted $query) use ($tracer) {
                try {
                    $span = $tracer->spanBuilder('db.query '.$query->connectionName)
                        ->setAttribute('db.system', 'mysql')
                        ->setAttribute('db.statement', $query->sql)
                        ->setAttribute('db.duration_ms', $query->time)
                        ->startSpan();

                    $span->end();
                } catch (\Throwable $e) {
                    // Fail silently for DB spans to avoid loop
                }
            });

            // Register HTTP Prometheus Metrics (initialized but values set by middleware)
            Prometheus::counter('laravel_prometheus_http_requests_total', 'Total HTTP requests');
            Prometheus::histogram('laravel_prometheus_http_request_duration_seconds', 'HTTP request duration in seconds');

            // Register Custom Prometheus Metrics
            Prometheus::gauge('Active_Users', 'Number of active users in the system')
                ->value(function () {
                    try {
                        return User::count();
                    } catch (\Throwable $e) {
                        return 0;
                    }
                });

            Prometheus::gauge('Login_Failures', 'Total cumulative login failures')
                ->value(function () {
                    try {
                        return Cache::get('login_failures_total', 0);
                    } catch (\Throwable $e) {
                        return 0;
                    }
                });
        } catch (\Throwable $e) {
            Log::error('Observability Boot Failed: '.$e->getMessage());
        }
    }
}
