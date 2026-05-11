<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendPaymentLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;

    public function __construct(Invoice $invoice)
    {
        // Invoice aur Lead ka data load karein
        $this->invoice = $invoice->load('lead');
    }

    public function build()
    {
        return $this->view('emails.payment_link')
                    ->subject("Payment Request for Invoice #{$this->invoice->invoice_number}")
                    ->with([
                        'clientName' => $this->invoice->lead->client_name,
                        'amount' => $this->invoice->total_amount,
                        'url' => $this->invoice->checkout_url,
                    ]);
    }
}