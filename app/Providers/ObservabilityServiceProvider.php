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
            $tracer = $this->app->make(TracerInterface::class);
            Log::debug("ObservabilityServiceProvider - Prometheus ID: " . spl_object_id(app(Spatie\Prometheus\Prometheus::class)));

            // Register Prometheus DB Metric
            $dbHistogram = Prometheus::addHistogram('laravel_prometheus_db_query_duration_seconds')
                ->helpText('Duration of database queries in seconds')
                ->labels(['query_type'])
                ->buckets([0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]);

            // Listen for Database Queries
            DB::listen(function (QueryExecuted $query) use ($tracer, $dbHistogram) {
                try {
                    // Start OTel Span
                    $span = $tracer->spanBuilder('db.query '.$query->connectionName)
                        ->setAttribute('db.system', 'mysql')
                        ->setAttribute('db.statement', $query->sql)
                        ->setAttribute('db.duration_ms', $query->time)
                        ->startSpan();

                    $span->end();

                    // Record Prometheus Metrics
                    $queryType = strtoupper(strtok(trim($query->sql), ' '));
                    $durationSeconds = $query->time / 1000; // time is in ms

                    Log::debug("Recording DB Metric: {$queryType} - {$durationSeconds}s");
                    $dbHistogram->observe($durationSeconds, [$queryType]);

                } catch (\Throwable $e) {
                    // Fail silently for DB spans to avoid loop
                }
            });

            // Register HTTP Prometheus Metrics - Pulled from cache for persistence
            Prometheus::addCounter('laravel_prometheus_http_requests_total')
                ->helpText('Total HTTP requests')
                ->labels(['method', 'status'])
                ->setInitialValue(function () {
                    $metrics = [];
                    $keys = Cache::get('prometheus_metric_keys', []);
                    foreach ($keys as $key) {
                        if (str_starts_with($key, 'http_requests_total:')) {
                            $parts = explode(':', $key);
                            $labels = explode('|', $parts[1]);
                            $metrics[] = [(float) Cache::get($key, 0), $labels];
                        }
                    }
                    // Ensure at least one entry exists to always render the help/type headers
                    if (empty($metrics)) {
                        $metrics[] = [0, ['GET', '200']];
                    }
                    return $metrics;
                });

            // Register Custom Prometheus Metrics
            Prometheus::addGauge('test_metric')
                ->helpText('A test metric to verify Prometheus registration')
                ->value(42);

            Prometheus::addGauge('Active_Users')
                ->helpText('Number of active users in the system')
                ->value(function () {
                    Log::debug("Collecting Active_Users metric...");
                    try {
                        return User::count();
                    } catch (\Throwable $e) {
                        return 0;
                    }
                });

            Prometheus::addGauge('Login_Failures')
                ->helpText('Total cumulative login failures')
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
