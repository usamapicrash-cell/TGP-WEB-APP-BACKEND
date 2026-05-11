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

.quote-title {
    font-size: 26px;
    font-weight: bold;
}

/* Client Info */
.client-box {
    margin-top: 25px;
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

/* Totals */
.totals {
    width: 300px;
    float: right;
    margin-top: 20px;
}

.totals table td {
    border: none;
    padding: 5px;
}

.grand-total {
    border-top: 2px solid #000;
    font-size: 16px;
    font-weight: bold;
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
        <div>2110 NE Aloclek Dr., Suite 613, Hilsboro, Oregon 97124</div>
        <div>(503) 690-8481 | admin@theglasspeople.com | theglasspeople.com</div>
    </div>

    <div class="header-right">
        <div class="quote-title">QUOTATION</div>
        <div><strong>No:</strong> {{ $quote->quote_number }}</div>
        <div><strong>Date:</strong> {{ $quote->created_at->format('d M Y') }}</div>
    </div>
</div>

<!-- CLIENT -->
<div class="client-box">
    <div class="section-title">Bill To:</div>
    <div>{{ $quote->lead->name ?? 'Client Name' }}</div>
</div>

<!-- ITEMS -->
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
            <td>${{ number_format($item->unit_price,2) }}</td>
            <td class="text-right">
                ${{ number_format($item->qty * $item->unit_price,2) }}
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

<!-- TOTALS -->
<div class="totals">
    <table>
        <tr>
            <td>Subtotal:</td>
            <td class="text-right">
                ${{ number_format($quote->subtotal,2) }}
            </td>
        </tr>

        <!-- <tr>
            <td>Labour:</td>
            <td class="text-right">
                ${{ number_format($quote->labour_total,2) }}
            </td>
        </tr> -->

        <tr class="grand-total">
            <td>TOTAL:</td>
            <td class="text-right">
                ${{ number_format($quote->total_amount,2) }}
            </td>
        </tr>
    </table>
</div>

<!-- FOOTER -->
<div class="footer">
    Thank you for your business • This quotation is valid for 30 days
</div>

</body>
</html>