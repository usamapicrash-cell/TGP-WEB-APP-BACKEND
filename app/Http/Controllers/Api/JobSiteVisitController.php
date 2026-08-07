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
use Illuminate\Support\Facades\Log;

class JobSiteVisitController extends Controller
{
    public function store(Request $request, GJob $job)
    {
        $request->validate([
            'visit_date' => 'required|date',
            'notes'      => 'nullable|string|max:1000'
        ], [
            'visit_date.required' => 'Visit date is required',
            'visit_date.date'     => 'Visit date must be a valid date',
            'notes.string'        => 'Notes must be a text',
            'notes.max'           => 'Notes cannot exceed 1000 characters'
        ]);

        // Eager load glazier aur lead relationships
        $job->loadMissing(['glazier', 'lead']);

        // 1. Create site visit
        $siteVisit = $job->siteVisits()->create([
            'visit_date' => $request->visit_date,
            'notes'      => $request->notes,
            'created_by' => auth()->id(),
        ]);

        // 2. Log activity
        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action'  => 'Site visited on ' . $request->visit_date
        ]);

        // 3. Send Email & Save DB Record
        try {
            $lead = $job->lead;
            $customerEmail = $job->customer_email ?? $lead->email ?? $lead->customer_email ?? null;

            if (!empty($customerEmail)) {
                // Dynamic Reference Code Extraction
                $refCode = $job->job_number 
                    ?? $job->reference_code 
                    ?? $lead->order_no 
                    ?? $lead->lead_number 
                    ?? "JOB-{$job->id}";

                $mailData = [
                    'customer_name'  => $job->customer_name ?? $lead->client_name ?? 'Valued Customer',
                    'reference_code' => $refCode,
                    'type'           => 'Site Visit',
                    'schedule_date'  => $request->visit_date,
                    'site_address'   => $job->site_address ?? $lead->address ?? null,
                    'glazier_name'   => $job->glazier->name ?? null,
                    'notes'          => $request->notes,
                ];

                $sender = env('SENDER_EMAIL', 'sales@theglasspeople.com');
                $subject = "Confirmation: Site Visit Scheduled - Ref: {$refCode}";

                // Mailable Instance
                $mailable = new ScheduleConfirmationMail($mailData);

                // Direct Mail Send
                Mail::to($customerEmail)->send($mailable);

                // Render HTML for DB Log
                $htmlContent = $mailable->render();

                // Save in `emails` table history
                Email::create([
                    'sender'    => $sender,
                    'receiver'  => $customerEmail,
                    'subject'   => $subject,
                    'html_body' => $htmlContent,
                    'type'      => 'sent',
                    'is_read'   => true,
                ]);

                Log::info('Site Visit Confirmation Email Sent & Saved to DB', [
                    'gjob_id'        => $job->id,
                    'reference_code' => $refCode,
                    'customer_email' => $customerEmail,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Site Visit Email Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return response()->json([
            'message'    => 'Site visit added and confirmation email sent & logged successfully.',
            'site_visit' => $siteVisit
        ], 201);
    }
}