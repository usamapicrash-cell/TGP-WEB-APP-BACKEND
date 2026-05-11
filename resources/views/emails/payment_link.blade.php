<div style="font-family: Arial, sans-serif; line-height: 1.6;">
    <h2>Hello {{ $clientName }},</h2>
    <p>We hope you are doing well. Please find the payment link for your invoice <strong>#{{ $invoice->invoice_number }}</strong> below.</p>
    
    <div style="margin: 20px 0;">
        <p><strong>Amount Due:</strong> ${{ number_format($amount, 2) }}</p>
        <p><strong>Description:</strong> {{ $invoice->notes }}</p>
    </div>

    <p>Click the button below to complete your payment securely via Helcim:</p>
    
    <a href="{{ $url }}" 
       style="background-color: #6c5ce7; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block;">
       Pay Now
    </a>

    <p style="margin-top: 30px;">If the button doesn't work, copy and paste this link in your browser:<br>
    {{ $url }}</p>

    <p>Thank you,<br><strong>The Glass People</strong></p>
</div>