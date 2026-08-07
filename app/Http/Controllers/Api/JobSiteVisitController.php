<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\GJob;
use App\Models\JobSiteVisit;
use App\Models\JobActivity;
use App\Models\Email;
use App\Mail\ScheduleConfirmationMail;
use Illuminate\Support\Facades\Mail;

class JobSiteVisitController extends Controller
{
    public function store(Request $request, GJob $job)
    {
        $request->validate([
            'visit_date' => 'required|date',
            'notes' => 'nullable|string|max:1000'
        ], [
            'visit_date.required' => 'Visit date is required',
            'visit_date.date' => 'Visit date must be a valid date',
            'notes.string' => 'Notes must be a text',
            'notes.max' => 'Notes cannot exceed 1000 characters'
        ]);

        // Eager load glazier relationship if exists
        $job->load('glazier');

        // 1. Create site visit
        $siteVisit = $job->siteVisits()->create([
            'visit_date' => $request->visit_date,
            'notes' => $request->notes,
            'created_by' => auth()->id(),
        ]);

        // 2. Log activity
        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action' => 'Site visited on ' . $request->visit_date
        ]);

        // 3. Email & DB Record Save
        if (!empty($job->customer_email)) {
            $sender = config('mail.from.address', env('SENDER_EMAIL', 'sales@theglasspeople.com'));
            $subject = "Confirmation: Site Visit Scheduled - Ref: {$lead->order_no}";

            // Mail instance create karein
            $mailable = new ScheduleConfirmationMail(
                $job,
                'Site Visit',
                $request->visit_date,
                $request->notes
            );

            // Render Markdown body to HTML for database logging
            $htmlContent = $mailable->render();

            // Direct Mail Trigger
            Mail::to($job->customer_email)->send($mailable);

            // Database log save karein (Email history panel ke liye)
            Email::create([
                'sender'    => $sender,
                'receiver'  => $job->customer_email,
                'subject'   => $subject,
                'html_body' => $htmlContent,
                'type'      => 'sent',
                'is_read'   => true,
            ]);
        }

        return response()->json([
            'message' => 'Site visit added and confirmation email sent & logged successfully.',
            'site_visit' => $siteVisit
        ], 201);
    }
}