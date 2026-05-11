<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; font-size: 14px; color: #333; }
        .header { text-align: right; margin-bottom: 20px; }
        .invoice-title { font-size: 24px; font-weight: bold; color: #2b3a67; }
        .info-table { width: 100%; margin-bottom: 30px; }
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table th { background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; text-align: left; }
        .items-table td { padding: 10px; border: 1px solid #dee2e6; }
        .total-box { text-align: right; margin-top: 20px; }
        .status-paid { color: green; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div class="invoice-title">INVOICE</div>
        <div># {{ $invoice->invoice_number }}</div>
        <div>Date: {{ $invoice->created_at->format('M d, Y') }}</div>
    </div>

    <table class="info-table">
        <tr>
            <td width="50%">
                <strong>BILL TO:</strong><br>
                {{ $lead->first_name }} {{ $lead->last_name }}<br>
                {{ $lead->email }}<br>
                {{ $lead->phone }}
            </td>
            <td width="50%" style="text-align: right;">
                <strong>STATUS:</strong> {{ $invoice->status }}<br>
                <strong>DUE DATE:</strong> {{ date('M d, Y', strtotime($invoice->due_date)) }}
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th width="150px">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->notes ?? 'Service Charges' }}</td>
                <td>${{ number_format($invoice->total_amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="total-box">
        <p>Total Amount: ${{ number_format($invoice->total_amount, 2) }}</p>
        <p>Paid Amount: ${{ number_format($invoice->paid_amount, 2) }}</p>
        <h3 style="color: #dc3545;">Balance Due: ${{ number_format($invoice->total_amount - $invoice->paid_amount, 2) }}</h3>
    </div>
</body>
</html>