<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $creditNote->credit_note_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; margin: 30px; }
        h1 { font-size: 22px; margin: 0 0 4px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th { text-align: left; border-bottom: 2px solid #222; padding: 6px 4px; }
        td { border-bottom: 1px solid #ccc; padding: 6px 4px; vertical-align: top; }
        .r { text-align: right; }
        .meta td { border: 0; padding: 2px 0; }
        .total td { font-weight: bold; border-top: 2px solid #222; border-bottom: 0; }
    </style>
</head>
<body>
    @if ($logo)<img src="{{ $logo }}" style="max-height: 50px;"><br>@endif
    <h1>CREDIT NOTE</h1>
    <table class="meta">
        <tr><td>Credit note number</td><td class="r">{{ $creditNote->credit_note_number }}</td></tr>
        <tr><td>Date</td><td class="r">{{ $creditNote->credit_note_date }}</td></tr>
        <tr><td>Against invoice</td><td class="r">{{ $creditNote->invoice->invoice_number }}</td></tr>
        <tr><td>Customer</td><td class="r">{{ $creditNote->customer->name }}</td></tr>
        <tr><td>Reason</td><td class="r">{{ $creditNote->reason }}</td></tr>
    </table>
    <table>
        <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr></thead>
        <tbody>
        @foreach ($creditNote->items as $item)
            <tr>
                <td>{{ $item->name }}@if ($item->description)<br><small>{{ $item->description }}</small>@endif</td>
                <td class="r">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') }}</td>
                <td class="r">{{ number_format($item->price / 100, 2, '.', ' ') }}</td>
                <td class="r">{{ number_format($item->total / 100, 2, '.', ' ') }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="r">Sub total</td><td class="r">{{ number_format($creditNote->sub_total / 100, 2, '.', ' ') }}</td></tr>
            @if ($creditNote->discount_val > 0)
            <tr><td colspan="3" class="r">Discount</td><td class="r">-{{ number_format($creditNote->discount_val / 100, 2, '.', ' ') }}</td></tr>
            @endif
            @if ($creditNote->tax > 0)
            <tr><td colspan="3" class="r">Tax</td><td class="r">{{ number_format($creditNote->tax / 100, 2, '.', ' ') }}</td></tr>
            @endif
            <tr class="total"><td colspan="3" class="r">Total credited</td><td class="r">{{ number_format($creditNote->total / 100, 2, '.', ' ') }}</td></tr>
        </tfoot>
    </table>
    @if ($creditNote->unapplied_amount > 0)
        <p>Of this credit, {{ number_format($creditNote->unapplied_amount / 100, 2, '.', ' ') }} is more than the invoice balance and is held as customer credit or refunded.</p>
    @endif
</body>
</html>
