<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Company\Export;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\DocumentRevision;
use App\Models\Invoice;
use App\Services\Events\EventData;
use App\Support\Problem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Issued invoices and credit notes in a date range for the bookkeeper, with totals and a signed manifest
 * of the stored PDFs (SHA-256) that the nightly verify job compares against the files.
 *
 * Signature: `v1,` + base64 HMAC-SHA256 of the canonical manifest JSON, key = EXPORT_SIGNING_KEY
 * (falls back to a key derived from APP_KEY). Same scheme as the webhook signatures.
 */
class DocumentExportController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->isOwner(), 403);

        $v = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['sometimes', 'in:csv,json'],
        ]);
        if ($v->fails()) {
            return Problem::response(422, 'validation_failed', 'Validation failed', implode(' ', $v->errors()->all()), ['errors' => $v->errors()->toArray()]);
        }

        $company = (int) $request->header('company');
        $from = Carbon::parse($request->query('from'))->startOfDay();
        $to = Carbon::parse($request->query('to'))->endOfDay();

        $rows = collect();

        Invoice::where('company_id', $company)->whereNotNull('issued_at')->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->with('customer', 'currency')->orderBy('invoice_date')->orderBy('id')->get()
            ->each(function (Invoice $i) use ($rows) {
                $rows->push([
                    'type' => 'invoice', 'number' => $i->invoice_number, 'date' => Carbon::parse($i->invoice_date)->format('Y-m-d'),
                    'customer_ref' => EventData::customerRef($i->customer), 'currency' => EventData::money($i, 0)['currency'],
                    'sub_total_minor' => (int) $i->sub_total, 'tax_minor' => (int) $i->tax, 'total_minor' => (int) $i->total,
                    'credited_minor' => (int) $i->credited_total, 'status' => $i->status, 'paid_status' => $i->paid_status,
                    'pdf_sha256' => $this->pdfHash('invoice', $i->id),
                ]);
            });

        CreditNote::where('company_id', $company)->whereBetween('credit_note_date', [$from->toDateString(), $to->toDateString()])
            ->with('invoice.customer', 'invoice.currency')->orderBy('credit_note_date')->orderBy('id')->get()
            ->each(function (CreditNote $c) use ($rows) {
                $rows->push([
                    'type' => 'credit_note', 'number' => $c->credit_note_number, 'date' => Carbon::parse($c->credit_note_date)->format('Y-m-d'),
                    'customer_ref' => EventData::customerRef($c->invoice->customer), 'currency' => EventData::money($c->invoice, 0)['currency'],
                    'sub_total_minor' => -(int) $c->sub_total, 'tax_minor' => -(int) $c->tax, 'total_minor' => -(int) $c->total,
                    'credited_minor' => 0, 'status' => 'ISSUED', 'paid_status' => '',
                    'pdf_sha256' => $this->pdfHash('credit_note', $c->id),
                ]);
            });

        $rows = $rows->sortBy([['date', 'asc'], ['number', 'asc']])->values();

        $totals = $rows->groupBy('currency')->map(fn ($group) => [
            'invoices' => $group->where('type', 'invoice')->count(),
            'credit_notes' => $group->where('type', 'credit_note')->count(),
            'invoiced_minor' => (int) $group->where('type', 'invoice')->sum('total_minor'),
            'credited_minor' => (int) -$group->where('type', 'credit_note')->sum('total_minor'),
            'net_minor' => (int) $group->sum('total_minor'),
        ])->all();

        $manifest = [
            'company_id' => $company,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'items' => $rows->map(fn ($r) => ['type' => $r['type'], 'number' => $r['number'], 'sha256' => $r['pdf_sha256']])->all(),
        ];
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES), $this->signingKey(), true));

        if ($request->query('format', 'json') === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, array_keys($rows->first() ?? ['type' => '']));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            rewind($out);
            $body = stream_get_contents($out);

            return response($body, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="documents-'.$from->toDateString().'-'.$to->toDateString().'.csv"',
                'Digest' => 'sha-256='.base64_encode(hash('sha256', $body, true)),
                'X-Manifest-Signature' => $signature,
                'X-Total-Count' => (string) $rows->count(),
            ]);
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => $rows->count(),
            'totals' => $totals,
            'documents' => $rows,
            'manifest' => $manifest + ['signature' => $signature],
        ]);
    }

    private function pdfHash(string $type, int $id): ?string
    {
        return DocumentRevision::where('document_type', $type)->where('document_id', $id)->whereNotNull('pdf_sha256')
            ->orderByDesc('revision_no')->value('pdf_sha256');
    }

    private function signingKey(): string
    {
        return config('invoiceshelf.export_signing_key') ?: hash_hmac('sha256', 'documents-export', (string) config('app.key'), true);
    }
}
