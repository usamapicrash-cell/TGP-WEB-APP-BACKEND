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
    public $approvalLink; // 👈 1. Public Property Add Ki

    public function __construct(array $data)
    {
        $this->customerName  = $data['customer_name'] ?? 'Valued Customer';
        $this->referenceCode = $data['reference_code'] ?? '';
        $this->type          = $data['type'] ?? 'Site Visit';
        $this->scheduleDate  = $data['schedule_date'] ?? now();
        $this->siteAddress   = $data['site_address'] ?? null;
        $this->glazierName   = $data['glazier_name'] ?? null;
        $this->notes         = $data['notes'] ?? null;
        $this->approvalLink  = $data['approval_link'] ?? null; // 👈 2. Value Assign Ki
    }

    public function build()
    {
        return $this->subject("Action Required: Confirm Your {$this->type} - Ref: {$this->referenceCode}")
                    ->view('emails.schedule_confirmation');
    }
}