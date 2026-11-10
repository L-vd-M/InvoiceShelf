<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Company\Audit;

use App\Http\Controllers\Controller;
use App\Models\DocumentDeletion;
use App\Models\DocumentRevision;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Read-only audit trail for the owner and the bookkeeper's tooling: deletions with their reason,
 * document revisions and integration-token creations, newest first.
 */
class AuditLogController extends Controller
{
    private const TYPES = ['deletion', 'revision', 'token'];

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403);

        $v = Validator::make($request->query(), [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'type' => ['sometimes', 'in:'.implode(',', self::TYPES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'integer', 'min:0'],
        ]);
        if ($v->fails()) {
            return Problem::response(422, 'validation_failed', 'Validation failed', implode(' ', $v->errors()->all()), ['errors' => $v->errors()->toArray()]);
        }

        $company = (int) $request->header('company');
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;
        $type = $request->query('type');
        $limit = (int) $request->query('limit', 50);
        $offset = (int) $request->query('cursor', 0);

        $rows = collect();

        if (! $type || $type === 'deletion') {
            DocumentDeletion::where('company_id', $company)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->get()->each(fn ($d) => $rows->push([
                    'type' => 'deletion',
                    'at' => $d->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'actor_user_id' => $d->deleted_by,
                    'subject' => ['kind' => $d->document_type, 'id' => $d->document_number, 'ref' => (string) $d->document_id],
                    'detail' => ['reason_code' => $d->reason_code, 'reason' => $d->reason, 'status_at_deletion' => $d->status_at_deletion, 'total' => $d->total],
                ]));
        }

        if (! $type || $type === 'revision') {
            DocumentRevision::where('company_id', $company)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->get(['id', 'document_type', 'document_id', 'revision_no', 'reason_code', 'actor_user_id', 'totals_hash', 'pdf_sha256', 'created_at'])
                ->each(fn ($r) => $rows->push([
                    'type' => 'revision',
                    'at' => $r->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'actor_user_id' => $r->actor_user_id,
                    'subject' => ['kind' => $r->document_type, 'id' => null, 'ref' => (string) $r->document_id],
                    'detail' => ['revision_no' => $r->revision_no, 'reason' => $r->reason_code, 'totals_hash' => $r->totals_hash, 'pdf_sha256' => $r->pdf_sha256],
                ]));
        }

        if (! $type || $type === 'token') {
            DB::table('personal_access_tokens')->where('name', 'like', 'integration:%')->where('abilities', 'like', '%"company:'.$company.'"%')
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->get()->each(fn ($t) => $rows->push([
                    'type' => 'token',
                    'at' => Carbon::parse($t->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                    'actor_user_id' => $t->tokenable_id,
                    'subject' => ['kind' => 'integration_token', 'id' => substr($t->name, 12), 'ref' => (string) $t->id],
                    'detail' => ['abilities' => array_values(array_filter(json_decode($t->abilities, true), fn ($a) => ! str_starts_with($a, 'company:'))), 'expires_at' => $t->expires_at],
                ]));
        }

        $sorted = $rows->sortByDesc('at')->values();
        $page = $sorted->slice($offset, $limit)->values();

        return response()->json([
            'data' => $page,
            'next_cursor' => $offset + $limit < $sorted->count() ? (string) ($offset + $limit) : null,
        ]);
    }
}
