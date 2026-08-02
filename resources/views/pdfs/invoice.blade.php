<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 12px;
    color: #000;
    margin: 40px;
}

/* Header */
.header {
    display: table;
    width: 100%;
    border-bottom: 2px solid #000;
    padding-bottom: 15px;
}

.header-left {
    display: table-cell;
}

.header-right {
    display: table-cell;
    text-align: right;
}

/* Company */
.company {
    font-size: 20px;
    font-weight: bold;
    letter-spacing: 1px;
}

.invoice-title {
    font-size: 26px;
    font-weight: bold;
    color: #000;
}

/* Client Info */
.client-box {
    display: table;
    width: 100%;
    margin-top: 25px;
}

.client-left {
    display: table-cell;
    width: 60%;
}

.client-right {
    display: table-cell;
    width: 40%;
    text-align: right;
}

.section-title {
    font-weight: bold;
    margin-bottom: 5px;
}

/* Table */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 25px;
}

th {
    border-bottom: 2px solid #000;
    padding: 10px 5px;
    text-align: left;
}

td {
    padding: 8px 5px;
    border-bottom: 1px solid #ddd;
}

.text-right {
    text-align: right;
}

/* Bottom Container (Notes + Totals side by side) */
.bottom-container {
    display: table;
    width: 100%;
    margin-top: 25px;
}

.notes-box {
    display: table-cell;
    width: 55%;
    vertical-align: top;
    padding-right: 20px;
}

.notes-content {
    background-color: #f9f9f9;
    border-left: 3px solid #000;
    padding: 8px 12px;
    font-size: 11px;
    color: #444;
    white-space: pre-line;
}

.totals-box {
    display: table-cell;
    width: 45%;
    vertical-align: top;
}

.totals-table {
    width: 100%;
    margin-top: 0;
}

.totals-table td {
    border: none;
    padding: 5px;
}

.grand-total {
    border-top: 2px solid #000 !important;
    font-size: 15px;
    font-weight: bold;
    color: #dc3545;
}

/* Footer */
.footer {
    position: fixed;
    bottom: 20px;
    width: 100%;
    text-align: center;
    font-size: 10px;
    border-top: 1px solid #ccc;
    padding-top: 5px;
}
</style>
</head>

<body>

<!-- HEADER -->
<div class="header">
    <div class="header-left">
        <div class="company">THE GLASS PEOPLE</div>
        <div>2110 NE Aloclek Dr., Suite 613, Hillsboro, Oregon 97124</div>
        <div>(503) 690-8481 | admin@theglasspeople.com | theglasspeople.com</div>
    </div>

    <div class="header-right">
        <div class="invoice-title">INVOICE</div>
        <div><strong>No:</strong> #{{ $invoice->invoice_number }}</div>
        <div><strong>Date:</strong> {{ $invoice->created_at->format('d M Y') }}</div>
    </div>
</div>

<!-- CLIENT & STATUS INFO -->
<div class="client-box">
    <div class="client-left">
        <div class="section-title">Bill To:</div>
        <div>{{ $lead->first_name }} {{ $lead->last_name }}</div>
        <div>{{ $lead->email }}</div>
        <div>{{ $lead->phone }}</div>
    </div>
    <div class="client-right">
        <div><strong>STATUS:</strong> {{ strtoupper($invoice->status) }}</div>
        <div><strong>DUE DATE:</strong> {{ date('d M Y', strtotime($invoice->due_date)) }}</div>
    </div>
</div>

<!-- ITEMS TABLE -->
<table>
    <thead>
        <tr>
            <th width="50%">Description</th>
            <th width="10%">Qty</th>
            <th width="20%">Unit Price</th>
            <th width="20%" class="text-right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
        <tr>
            <td>{{ $item->description }}</td>
            <td>{{ $item->qty }}</td>
            <td>${{ number_format($item->unit_price, 2) }}</td>
            <td class="text-right">
                ${{ number_format($item->qty * $item->unit_price, 2) }}
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

<!-- NOTES & TOTALS SECTION -->
<div class="bottom-container">
    <!-- LEFT: NOTES -->
    <div class="notes-box">
        @if(!empty($invoice->notes))
            <div class="section-title">Notes / Terms:</div>
            <div class="notes-content">
                {{ $invoice->notes }}
            </div>
        @endif
    </div>

    <!-- RIGHT: TOTALS -->
    <div class="totals-box">
        <table class="totals-table">
            <tr>
                <td>Subtotal:</td>
                <td class="text-right">${{ number_format($invoice->total_amount, 2) }}</td>
            </tr>
            <tr>
                <td>Paid Amount:</td>
                <td class="text-right">${{ number_format($invoice->paid_amount, 2) }}</td>
            </tr>
            <tr class="grand-total">
                <td>Balance Due:</td>
                <td class="text-right">${{ number_format($invoice->total_amount - $invoice->paid_amount, 2) }}</td>
            </tr>
        </table>
    </div>
</div>

<!-- FOOTER -->
<div class="footer">
    Thank you for your business • The Glass People
</div>

</body>
</html>