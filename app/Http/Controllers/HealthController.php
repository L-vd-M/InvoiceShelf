<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Unauthenticated probe for the hub and load balancers: counts only, no document data.
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true];
        } catch (Throwable $e) {
            return Problem::response(503, 'database_down', 'Database unavailable', null, ['status' => 'down', 'database' => ['ok' => false]]);
        }

        $queueDriver = config('queue.default');
        $checks['queue'] = ['driver' => $queueDriver, 'ok' => true];
        if ($queueDriver === 'database') {
            $checks['queue'] += [
                'pending' => DB::table(config('queue.connections.database.table', 'jobs'))->count(),
                'failed' => DB::table(config('queue.failed.table', 'failed_jobs'))->count(),
            ];
            $checks['queue']['ok'] = $checks['queue']['failed'] === 0;
        }

        $pending = WebhookDelivery::where('state', WebhookDelivery::PENDING);
        $oldest = (clone $pending)->orderBy('next_attempt_at')->value('next_attempt_at');
        $lag = $oldest ? max(0, now()->diffInSeconds(Carbon::parse($oldest), false) * -1) : 0;
        $dead = WebhookDelivery::where('state', WebhookDelivery::DEAD)->count();
        $checks['outbox'] = [
            'events' => OutboxEvent::count(),
            'pending_deliveries' => $pending->count(),
            'oldest_pending_seconds' => (int) $lag,
            'dead_letters' => $dead,
            'ok' => $lag < 900 && $dead === 0,
        ];

        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        $freePct = $total ? round($free / $total * 100, 1) : null;
        $checks['disk'] = ['free_percent' => $freePct, 'ok' => $freePct === null || $freePct >= 10];

        $degraded = collect($checks)->contains(fn ($c) => ! ($c['ok'] ?? true));

        return response()->json(['status' => $degraded ? 'degraded' : 'ok'] + $checks);
    }

    public function version(): JsonResponse
    {
        $sha = trim((string) @file_get_contents(base_path('GIT_SHA'))) ?: 'unknown';

        return response()->json([
            'version' => trim((string) @file_get_contents(base_path('version.md'))),
            'git_sha' => $sha,
            'migration_head' => DB::table('migrations')->orderByDesc('id')->value('migration'),
        ]);
    }
}
