<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Barryvdh\DomPDF\Facade\Pdf;

class SendQuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public $quote;

    public function __construct(Quote $quote)
    {
        $this->quote = $quote->load(['items', 'lead']);
    }

    public function build()
    {
        // PDF generate karein attachment ke liye
        $pdf = Pdf::loadView('pdfs.quote', [
            'quote' => $this->quote,
            'items' => $this->quote->items,
            'lead'  => $this->quote->lead
        ]);

        return $this->view('emails.quote_customer')
                    ->subject("New Quote Received: #{$this->quote->quote_number}")
                    ->attachData($pdf->output(), "Quote_{$this->quote->quote_number}.pdf", [
                        'mime' => 'application/pdf',
                    ]);
    }
}