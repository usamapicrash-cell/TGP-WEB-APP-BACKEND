<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ScheduleConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $customerName;
    public $referenceCode;
    public $type;
    public $scheduleDate;
    public $siteAddress;
    public $glazierName;
    public $notes;

    public function __construct(array $data)
    {
        $this->customerName  = $data['customer_name'];
        $this->referenceCode = $data['reference_code'];
        $this->type          = $data['type'];
        $this->scheduleDate  = $data['schedule_date'];
        $this->siteAddress   = $data['site_address'] ?? null;
        $this->glazierName   = $data['glazier_name'] ?? null;
        $this->notes         = $data['notes'] ?? null;
    }

    public function build()
    {
        return $this->subject("Confirmation: {$this->type} Scheduled - Ref: {$this->referenceCode}")
                    ->view('emails.schedule_confirmation');
    }
}