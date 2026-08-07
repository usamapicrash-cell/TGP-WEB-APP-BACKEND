<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\GJob;

class ScheduleConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $job;
    public $type; // 'Site Visit' or 'Appointment'
    public $scheduleDate;
    public $notes;

    public function __construct(GJob $job, string $type, string $scheduleDate, ?string $notes = null)
    {
        $this->job = $job;
        $this->type = $type;
        $this->scheduleDate = $scheduleDate;
        $this->notes = $notes;
    }

    public function build()
    {
        return $this->subject("Confirmation: {$this->type} Scheduled - Ref: {$this->job->reference_code}")
                    ->markdown('emails.schedule_confirmation');
    }
}