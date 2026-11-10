<?php

namespace App\Http\Controllers\Company\Events;

use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pull feed for consumers that cannot expose a URL. Cursor is the last event id seen.
 */
class EventsFeedController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isOwner() && $user->tokenCan('events:read'), 403);

        $limit = max(1, min((int) $request->query('limit', 100), 200));
        $after = (int) $request->query('after', 0);
        $types = array_filter(explode(',', (string) $request->query('types', '')));

        $query = OutboxEvent::where('company_id', (int) $request->header('company'))
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1);

        if ($types) {
            $query->whereIn('type', $types);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn (OutboxEvent $e) => ['id' => $e->event_id, 'cursor' => (string) $e->id] + $e->envelope())->values(),
            'next_cursor' => $rows->isEmpty() ? (string) $after : (string) $rows->last()->id,
            'has_more' => $hasMore,
        ]);
    }
}
