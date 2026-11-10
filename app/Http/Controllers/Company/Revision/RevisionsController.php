<?php

namespace App\Http\Controllers\Company\Revision;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentRevisionResource;
use App\Models\CreditNote;
use App\Models\DocumentRevision;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Services\Document\RevisionDiffService;
use App\Services\Document\RevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only history of quotes, invoices and credit notes (spec 07 section 6).
 */
class RevisionsController extends Controller
{
    public function invoice(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return $this->list($request, 'invoice', $invoice->id);
    }

    public function estimate(Request $request, Estimate $estimate)
    {
        $this->authorize('view', $estimate);

        return $this->list($request, 'estimate', $estimate->id);
    }

    public function creditNote(Request $request, CreditNote $creditNote)
    {
        $this->authorize('view', $creditNote->invoice);

        return $this->list($request, 'credit_note', $creditNote->id);
    }

    public function show(DocumentRevision $revision)
    {
        $this->authorizeRevision($revision);

        return new DocumentRevisionResource($revision->load('actor')->setAttribute('detail', true));
    }

    public function diff(Request $request, DocumentRevision $revision)
    {
        $this->authorizeRevision($revision);

        $against = $request->input('against', 'previous');

        if ($against === 'previous') {
            $base = DocumentRevision::where('document_type', $revision->document_type)
                ->where('document_id', $revision->document_id)
                ->where('revision_no', '<', $revision->revision_no)
                ->orderByDesc('revision_no')->first();
        } else {
            $base = DocumentRevision::find((int) $against);

            if (! $base || $base->document_type !== $revision->document_type || $base->document_id !== $revision->document_id) {
                return $this->problem(422, 'validation_failed', 'The revision to compare against must belong to the same document.');
            }
        }

        $changes = $base ? RevisionDiffService::diff($base->snapshot, $revision->snapshot) : [];

        return response()->json(['data' => [
            'revision_id' => $revision->id,
            'against_id' => $base?->id,
            'changes' => $changes,
            'summary' => RevisionDiffService::summarise($changes),
        ]]);
    }

    public function pdf(DocumentRevision $revision)
    {
        $this->authorizeRevision($revision);

        $bytes = RevisionService::storedPdf($revision);

        if ($bytes === null) {
            return $this->problem(404, 'pdf_not_stored', 'No PDF was stored with this revision. Only issued and sent revisions keep one.');
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$revision->document_type.'-'.$revision->document_id.'-r'.$revision->revision_no.'.pdf"',
            'Digest' => 'sha-256='.base64_encode(hex2bin($revision->pdf_sha256)),
        ]);
    }

    private function list(Request $request, string $type, int $id)
    {
        $limit = min(max((int) $request->input('limit', 25), 1), 200);

        $revisions = DocumentRevision::with('actor')
            ->where('document_type', $type)->where('document_id', $id)
            ->orderByDesc('revision_no')->paginate($limit);

        return DocumentRevisionResource::collection($revisions);
    }

    /**
     * Authorise through the live document; when a deleted draft's history is read, fall back to
     * company membership plus the type's list permission.
     */
    private function authorizeRevision(DocumentRevision $revision): void
    {
        $parent = match ($revision->document_type) {
            'invoice' => Invoice::find($revision->document_id),
            'estimate' => Estimate::find($revision->document_id),
            'credit_note' => CreditNote::find($revision->document_id)?->invoice,
        };

        if ($parent) {
            $this->authorize('view', $parent);

            return;
        }

        $this->authorize('viewAny', $revision->document_type === 'estimate' ? Estimate::class : Invoice::class);
        abort_unless(request()->user()->hasCompany($revision->company_id), 403);
    }

    private function problem(int $status, string $code, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'https://docs.tarcza-electronics.com/problems/'.$code,
            'title' => $detail,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
