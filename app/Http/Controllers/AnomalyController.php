<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\TracerInterface;
use Illuminate\Support\Facades\Cache;

class AnomalyController extends Controller
{
    private $tracer;

    public function __construct(TracerInterface $tracer)
    {
        Log::info('AnomalyController instantiated');
        $this->tracer = $tracer;
    }

    /**
     * Simulate a slow endpoint with artificial delay.
     */
    public function delay(Request $request)
    {
        $seconds = $request->get('seconds', 2);
        
        $span = $this->tracer->spanBuilder('anomaly.delay')->startSpan();
        sleep($seconds);
        $span->end();

        return response()->json([
            'message' => "Injected $seconds seconds of delay",
            'status' => 'success'
        ]);
    }

    /**
     * Simulate a DB bottleneck with an inefficient query.
     */
    public function dbBottleneck()
    {
        $span = $this->tracer->spanBuilder('anomaly.db_bottleneck')->startSpan();
        
        // Running a cross join or something slow on users/products if they exist
        // This is a "heavy" query for demonstration targets
        DB::statement("SELECT COUNT(*) FROM users CROSS JOIN products");
        
        $span->end();

        return response()->json([
            'message' => 'DB bottleneck simulated with an inefficient CROSS JOIN',
            'status' => 'success'
        ]);
    }

    /**
     * Simulate random 500 errors.
     */
    public function error()
    {
        Log::error('Injected artificial exception for observability testing', [
            'type' => 'anomaly_injection',
            'trace_id' => \OpenTelemetry\API\Trace\Span::getCurrent()->getContext()->getTraceId()
        ]);

        abort(500, 'Artificial Anomaly Error');
    }

    /**
     * Simulate login failures to track metrics.
     */
    public function loginFailure()
    {
        Cache::increment('login_failures_total');
        
        Log::warning('Failed login attempt detected (Simulated)', [
            'ip' => request()->ip(),
            'user' => 'admin@example.com'
        ]);

        return response()->json([
            'message' => 'Simulated login failure recorded',
            'status' => 'warning'
        ]);
    }

    /**
     * Simulate large payload to test bandwidth/memory.
     */
    public function heavyPayload()
    {
        $size = 5 * 1024 * 1024; // 5MB
        $data = str_repeat('A', $size);

        return response()->json([
            'message' => 'Large payload generated',
            'size_mb' => 5,
            'data_preview' => substr($data, 0, 100)
        ]);
    }
}
