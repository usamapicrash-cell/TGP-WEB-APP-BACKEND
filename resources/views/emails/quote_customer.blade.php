<h3>Hello {{ $quote->lead->client_name }},</h3>
<p>Thank you for choosing <strong>The Glass People</strong>. Please find the attached quote for your requested service.</p>

<p><strong>Quote Details:</strong></p>
<ul>
    <li>Quote Number: {{ $quote->quote_number }}</li>
    <li>Total Amount: ${{ number_format($quote->total_amount, 2) }}</li>
</ul>

<p>If you have any questions, feel free to reply to this email.</p>
<p>Best Regards,<br>The Glass People Team</p>