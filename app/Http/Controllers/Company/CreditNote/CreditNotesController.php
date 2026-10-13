<?php

namespace App\Http\Controllers\Company\CreditNote;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCreditNoteRequest;
use App\Http\Resources\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Document\CreditNoteService;
use Illuminate\Http\Request;

class CreditNotesController extends Controller
{
    public function __construct(
        private readonly CreditNoteService $creditNoteService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $query = CreditNote::whereCompany()->with('customer', 'invoice')->latest('id');

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->input('invoice_id'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('credit_note_date', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('credit_note_date', '<=', $request->input('to_date'));
        }

        return CreditNoteResource::collection($query->paginate((int) $request->input('limit', 10)));
    }

    public function show(CreditNote $creditNote)
    {
        $this->authorize('view', $creditNote->invoice);

        return new CreditNoteResource($creditNote->load('items', 'customer', 'invoice'));
    }

    public function store(CreateCreditNoteRequest $request, Invoice $invoice)
    {
        $this->authorize('create', Invoice::class);
        $this->authorize('view', $invoice);

        $lines = null;
        if ($request->filled('items')) {
            $lines = [];
            foreach ($request->input('items') as $row) {
                $lines[(int) $row['invoice_item_id']] = ($lines[(int) $row['invoice_item_id']] ?? 0) + (float) $row['quantity'];
            }
        }

        $creditNote = $this->creditNoteService->create($invoice, $request->validated(), $lines);

        return (new CreditNoteResource($creditNote))->response()->setStatusCode(201);
    }

    public function pdf(CreditNote $creditNote)
    {
        $this->authorize('view', $creditNote->invoice);

        $pdf = $this->creditNoteService->getPdfData($creditNote);

        return response()->make($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$creditNote->credit_note_number.'.pdf"',
        ]);
    }
}
