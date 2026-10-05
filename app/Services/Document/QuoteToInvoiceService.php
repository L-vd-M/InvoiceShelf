<?php

namespace App\Services\Document;

use App\Facades\Hashids;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteToInvoiceService
{
    public function __construct(
        private EstimateService $estimateService,
        private InvoiceService $invoiceService,
    ) {}

    /**
     * Issue the invoice for an accepted quote once its payment is confirmed.
     *
     * One transaction: invoice (number assigned now) linked to the quote, payment recorded, invoice
     * marked paid. The quote stays ACCEPTED. When $sendEmail is set the paid invoice is emailed as
     * the last step; if that fails the transaction rolls back and nothing is recorded, so the
     * caller can retry. Idempotent per quote: a repeat call returns the existing invoice.
     *
     * @param  array{payment_date: string, amount: int, payment_method_id?: int|null, notes?: string|null}  $paymentData
     * @return array{invoice: Invoice, created: bool}
     */
    public function issueInvoiceForPaidQuote(Estimate $estimate, array $paymentData, ?int $creatorId = null, bool $sendEmail = true): array
    {
        return DB::transaction(function () use ($estimate, $paymentData, $creatorId, $sendEmail) {
            $estimate = Estimate::whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            $existing = Invoice::where('estimate_id', $estimate->id)->first();
            if ($existing) {
                return ['invoice' => $existing, 'created' => false];
            }

            if ($estimate->status !== Estimate::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'status' => ['Only an accepted quote can be invoiced.'],
                ]);
            }

            if ((int) $paymentData['amount'] !== (int) $estimate->total) {
                throw ValidationException::withMessages([
                    'amount' => ['The payment amount must equal the quote total.'],
                ]);
            }

            $invoice = $this->estimateService->convertToInvoice($estimate);

            $this->recordPayment($invoice, $estimate, $paymentData, $creatorId);

            $invoice = Invoice::find($invoice->id);

            if ($sendEmail) {
                $this->emailPaidInvoice($invoice, $estimate);
            }

            return ['invoice' => $invoice, 'created' => true];
        });
    }

    private function recordPayment(Invoice $invoice, Estimate $estimate, array $paymentData, ?int $creatorId): Payment
    {
        $serial = (new SerialNumberService)
            ->setModel(new Payment)
            ->setCompany($estimate->company_id)
            ->setCustomer($estimate->customer_id)
            ->setNextNumbers();

        $payment = Payment::create([
            'payment_number' => $serial->getNextNumber(),
            'sequence_number' => $serial->nextSequenceNumber,
            'customer_sequence_number' => $serial->nextCustomerSequenceNumber,
            'payment_date' => $paymentData['payment_date'],
            'amount' => $paymentData['amount'],
            'notes' => $paymentData['notes'] ?? null,
            'payment_method_id' => $paymentData['payment_method_id'] ?? null,
            'invoice_id' => $invoice->id,
            'customer_id' => $estimate->customer_id,
            'company_id' => $estimate->company_id,
            'creator_id' => $creatorId,
            'currency_id' => $estimate->currency_id,
            'exchange_rate' => $estimate->exchange_rate,
            'base_amount' => $paymentData['amount'] * $estimate->exchange_rate,
        ]);

        $payment->unique_hash = Hashids::connection(Payment::class)->encode($payment->id);
        $payment->save();

        $invoice->subtractInvoicePayment($paymentData['amount']);

        return $payment;
    }

    private function emailPaidInvoice(Invoice $invoice, Estimate $estimate): void
    {
        $company = Company::find($invoice->company_id);
        $to = $invoice->customer->email;

        if (! $to) {
            throw ValidationException::withMessages([
                'send_email' => ['The customer has no email address. Add one, or issue the invoice without emailing it.'],
            ]);
        }

        $registration = $company->registration_number ? '<br>Reg No: '.e($company->registration_number) : '';

        try {
            $this->invoiceService->send($invoice, [
                'from' => CompanySetting::getSetting('notification_email', $invoice->company_id) ?: $company->email,
                'to' => $to,
                'subject' => 'Invoice {INVOICE_NUMBER} - payment received',
                'body' => 'Thank you, we have received your payment for quote '.e($estimate->estimate_number)
                    .'.<br>Your invoice <b>{INVOICE_NUMBER}</b> (paid) is attached.<br><br><b>{COMPANY_NAME}</b>'.$registration,
            ]);
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'send_email' => ['The invoice email could not be sent, so nothing was recorded. Check the mail settings and retry, or issue the invoice without emailing it.'],
            ]);
        }
    }
}
