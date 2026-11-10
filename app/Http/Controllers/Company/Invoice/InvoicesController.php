<?php

namespace App\Http\Controllers\Company\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Requests;
use App\Http\Requests\DeleteInvoiceRequest;
use App\Http\Requests\SendInvoiceRequest;
use App\Http\Resources\EstimateResource;
use App\Http\Resources\InvoiceResource;
use App\Jobs\GenerateInvoicePdfJob;
use App\Models\DocumentRevision;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Services\Document\InvoiceService;
use App\Services\Document\RevisionService;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;

class InvoicesController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $limit = $request->input('limit', 10);

        $invoices = Invoice::whereCompany()
            ->applyFilters($request->all())
            ->with('customer')
            ->latest()
            ->paginateData($limit);

        return InvoiceResource::collection($invoices)
            ->additional(['meta' => [
                'invoice_total_count' => Invoice::whereCompany()->count(),
            ]]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function store(Requests\InvoicesRequest $request)
    {
        $this->authorize('create', Invoice::class);

        $invoice = $this->invoiceService->create($request);

        if ($request->has('invoiceSend')) {
            $this->invoiceService->send($invoice, $request->only(['subject', 'body']));
        }

        GenerateInvoicePdfJob::dispatch($invoice);

        return new InvoiceResource($invoice);
    }

    /**
     * Display the specified resource.
     *
     * @return JsonResponse
     */
    public function show(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load('estimate'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function update(Requests\InvoicesRequest $request, Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        $invoice = $this->invoiceService->update($invoice, $request);

        GenerateInvoicePdfJob::dispatch($invoice, true);

        return new InvoiceResource($invoice);
    }

    /**
     * delete the specified resources in storage.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function delete(DeleteInvoiceRequest $request)
    {
        $this->authorize('delete multiple invoices');

        $ids = Invoice::whereCompany()
            ->whereIn('id', $request->ids)
            ->pluck('id');

        $this->invoiceService->delete($ids, $request->reason_code, $request->reasonText());

        return response()->json([
            'success' => true,
        ]);
    }

    public function send(SendInvoiceRequest $request, Invoice $invoice)
    {
        $this->authorize('send invoice', $invoice);

        $this->invoiceService->send($invoice, $request->all());

        return response()->json([
            'success' => true,
        ]);
    }

    public function sendPreview(SendInvoiceRequest $request, Invoice $invoice)
    {
        $this->authorize('send invoice', $invoice);

        $markdown = new Markdown(view(), config('mail.markdown'));

        $data = $this->invoiceService->sendInvoiceData($invoice, $request->all());
        $data['url'] = $invoice->invoicePdfUrl;

        return $markdown->render('emails.send.invoice', ['data' => $data]);
    }

    public function issue(Request $request, Invoice $invoice)
    {
        $this->authorize('send invoice', $invoice);

        $invoice = $this->invoiceService->issue($invoice);

        GenerateInvoicePdfJob::dispatch($invoice, true);

        return new InvoiceResource($invoice->load('estimate'));
    }

    /**
     * The PDF exactly as it was rendered when the invoice was issued, with its SHA-256 in `Digest`
     * (RFC 3230, base64). A draft has no final PDF.
     */
    public function pdf(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        if (! $invoice->isIssued()) {
            return Problem::response(409, 'document_immutable', 'Draft has no final PDF', 'Issue the invoice first.');
        }

        $revision = DocumentRevision::where('document_type', 'invoice')->where('document_id', $invoice->id)
            ->whereNotNull('pdf_sha256')->orderByDesc('revision_no')->first();
        $bytes = $revision ? RevisionService::storedPdf($revision) : null;

        // invoices issued before PDFs were stored: render now (the hash is then not stable across renders)
        $bytes ??= $invoice->getPDFData()->output();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"',
            'Digest' => 'sha-256='.base64_encode(hash('sha256', $bytes, true)),
        ]);
    }

    public function clone(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);
        $this->authorize('create', Invoice::class);

        $newInvoice = $this->invoiceService->clone($invoice);

        return new InvoiceResource($newInvoice);
    }

    public function convertToEstimate(Request $request, Invoice $invoice)
    {
        // Authorize access to the source invoice (tenant isolation) in addition
        // to the ability to create an estimate.
        $this->authorize('view', $invoice);
        $this->authorize('create', Estimate::class);

        $estimate = $this->invoiceService->convertToEstimate($invoice);

        return new EstimateResource($estimate);
    }

    public function changeStatus(Request $request, Invoice $invoice)
    {
        $this->authorize('send invoice', $invoice);

        $this->invoiceService->changeStatus($invoice, $request->status);

        return response()->json([
            'success' => true,
        ]);
    }
}
