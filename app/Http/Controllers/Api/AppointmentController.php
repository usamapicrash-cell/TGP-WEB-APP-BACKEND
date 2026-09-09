<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Email;
use App\Models\SmsLog;
use App\Mail\ScheduleConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log; // Controller ke top par import zaroori hai
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;

class AppointmentController extends Controller
{
    public function glazier_appointments(Request $request)
    {
        try {
            $user = auth()->user();
            
            // Base query with exact relationships
            $query = Appointment::with(['lead.gjob']);

            // Agar user glazier hai (Role Level > 2), toh sirf uske jobs dikhayein
            if ($user && $user->role && $user->role->level > 2) {
                $query->whereHas('lead.gjob', function($q) use ($user) {
                    $q->where('glazier_id', $user->id);
                });
            }

            $appointments = $query->orderBy('date', 'asc')
                ->orderBy('time', 'asc')
                ->get();

            // Figma Timeline ke liye Date wise group karein
            $grouped = $appointments->groupBy('date');

            return response()->json([
                'success' => true,
                'data' => $grouped
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    public function index($leadId)
    {
        $appointments = Appointment::with(['lead.gjob.glazier']) 
            ->where('lead_id', $leadId)
            ->orderBy('date', 'asc')
            ->orderBy('time', 'asc')
            ->get();

        return response()->json($appointments);
    }

    public function all_site_visit_get(Request $request)
    {
        try {
            $query = Appointment::with(['lead.gjob.glazier', 'lead.payments']);
            
            // Default: Aaj ki date se start hoga
            $query->whereDate('date', '>=', now()->toDateString());

            // Status Filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Glazier Filter
            if ($request->filled('glazier_id')) {
                $query->whereHas('lead.gjob', function($q) use ($request) {
                    $q->where('glazier_id', $request->glazier_id);
                });
            }

            // Search Filter
            if ($request->filled('search')) {
                $searchTerm = $request->search;
                $query->where(function($q) use ($searchTerm) {
                    $q->where('title', 'LIKE', "%{$searchTerm}%")
                      ->orWhereHas('lead', function($sq) use ($searchTerm) {
                          $sq->where('client_name', 'LIKE', "%{$searchTerm}%")
                            ->orWhere('lead_number', 'LIKE', "%{$searchTerm}%");
                      });
                });
            }

            // Auth Filter
            $user = auth()->user();
            if ($user && $user->role && $user->role->level > 2) {
                $query->whereHas('lead', function($q) use ($user) {
                    $q->where('created_by', $user->id);
                });
            }

            $appointments = $query->orderBy('date', 'asc')
                ->orderBy('time', 'asc')
                ->get();

            return response()->json($appointments);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    public function site_visit_get($leadId)
    {
        $appointments = Appointment::with(['lead.gjob.glazier']) 
            ->where('lead_id', $leadId)
            ->orderBy('date', 'asc')
            ->orderBy('time', 'asc')
            ->get();

        return response()->json($appointments);
    }

    // Site Visit Store
    public function site_visit_store(Request $request, $leadId)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'date'        => 'required|date|after_or_equal:today',
            'time'        => 'required',
            'end_time'    => 'nullable',
            'status'      => 'required|string',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string',
        ]);

        // 1. Past Time Check (agar aaj ki date ho)
        if ($validated['date'] === date('Y-m-d')) {
            $currentTime = date('H:i:s');
            if ($validated['time'] < $currentTime) {
                return response()->json([
                    'message' => 'Invalid Time: You cannot schedule a site visit in the past.'
                ], 422);
            }
        }

        // 2. Fetch Lead & Glazier Conflict Check
        $lead = Lead::with(['gjob.glazier', 'gjob.activities'])->findOrFail($leadId);
        $glazierId = $lead->gjob->glazier_id ?? null;

        if ($glazierId) {
            $requestedTime = $validated['time'];
            $startTimeLimit = date('H:i:s', strtotime($requestedTime . ' -2 hours + 1 minute'));
            $endTimeLimit = date('H:i:s', strtotime($requestedTime . ' +2 hours - 1 minute'));

            $conflict = Appointment::where('date', $validated['date'])
                ->whereHas('lead.gjob', function ($query) use ($glazierId) {
                    $query->where('glazier_id', $glazierId);
                })
                ->whereBetween('time', [$startTimeLimit, $endTimeLimit])
                ->exists();

            if ($conflict) {
                $readableTime = date('h:i A', strtotime($requestedTime));
                return response()->json([
                    'message' => "Schedule Conflict: Glazier needs a 2-hour gap. $readableTime is too close to another booking."
                ], 422);
            }
        }

        // 3. Create Appointment Record
        $validated['lead_id'] = $leadId;
        $validated['type'] = 'site_visit';
        $appointment = Appointment::create($validated);

        // 4. Client Confirmation Page Link Generation
        $frontendUrl = 'https://theglasspeople.com';
        $approvalLink = "{$frontendUrl}/site-visit/confirm/{$appointment->id}";

        // 5. Send Email with Link & Log DB Record
        $this->sendScheduleEmail($appointment, 'Site Visit', $approvalLink);

        // 6. Send SMS to Client via Vonage
        $clientPhone = $lead->phone ?? $lead->client_phone ?? null;
        if ($clientPhone) {
            $this->sendScheduleSms($clientPhone, $appointment, $approvalLink);
        }

        // 7. Activity History Logging
        if ($lead->gjob) {
            $readableDate = date('M d, Y', strtotime($appointment->date));
            $readableTime = date('h:i A', strtotime($appointment->time));

            $lead->gjob->activities()->create([
                'user_id'     => Auth::id(),
                'action'      => 'Site Visit Scheduled',
                'description' => "Site visit '{$appointment->title}' scheduled for {$readableDate} at {$readableTime}. Confirmation request sent to client.",
            ]);
        }

        return response()->json([
            'message'       => 'Site visit logged successfully, SMS and Email sent to client.',
            'data'          => $appointment->load('lead.gjob.glazier'),
            'approval_link' => $approvalLink
        ], 201);
    }

    // Store new appointment
    public function store(Request $request, $leadId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type'  => 'required|in:site_visit,appointment',
            'date'  => 'required|date|after_or_equal:today',
            'time'  => 'required',
        ]);

        if ($validated['date'] == date('Y-m-d')) {
            $currentTime = date('H:i:s');
            if ($validated['time'] < $currentTime) {
                return response()->json([
                    'message' => 'Invalid Time: You cannot schedule an appointment in the past.'
                ], 422);
            }
        }

        $lead = Lead::with('gjob')->findOrFail($leadId);
        $glazierId = $lead->gjob->glazier_id ?? null; 

        if ($glazierId) {
            $requestedTime = $validated['time'];
            $startTimeLimit = date('H:i:s', strtotime($requestedTime . ' -2 hours + 1 minute'));
            $endTimeLimit = date('H:i:s', strtotime($requestedTime . ' +2 hours - 1 minute'));

            $conflict = Appointment::where('date', $validated['date'])
                ->whereHas('lead.gjob', function($query) use ($glazierId) {
                    $query->where('glazier_id', $glazierId);
                })
                ->whereBetween('time', [$startTimeLimit, $endTimeLimit])
                ->exists();

            if ($conflict) {
                $readableTime = date('h:i A', strtotime($requestedTime));
                return response()->json([
                    'message' => "Schedule Conflict: Glazier needs a 2-hour gap. $readableTime is too close to another booking."
                ], 422);
            }
        }

        $appointment = Appointment::create([
            'lead_id' => $leadId,
            'title'   => $validated['title'],
            'type'    => $validated['type'],
            'date'    => $validated['date'],
            'time'    => $validated['time'],
        ]);

        $typeLabel = ucfirst(str_replace('_', ' ', $validated['type']));
        $this->sendScheduleEmail($appointment, $typeLabel);

        return response()->json($appointment->load('lead.gjob.glazier'), 201);
    }

    public function site_visit_update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'date'        => 'required|date',
            'time'        => 'required',
            'end_time'    => 'nullable',
            'status'      => 'required|string',
            'description' => 'nullable|string',
            'type'        => 'required|string',
            'icon'        => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $appointment = Appointment::with('lead.gjob.activities')->find($id);

            if (!$appointment) {
                return response()->json(['message' => 'Appointment not found.'], 404);
            }

            $oldDate   = $appointment->date;
            $oldTime   = $appointment->time;
            $oldStatus = $appointment->status;

            $appointment->update([
                'title'       => $request->title,
                'date'        => $request->date,
                'time'        => $request->time,
                'end_time'    => $request->end_time,
                'status'      => $request->status,
                'description' => $request->description,
                'type'        => $request->type,
                'icon'        => $request->icon ?? 'bi-chat-dots',
            ]);

            $isDateTimeChanged = ($oldDate !== $request->date) || ($oldTime !== $request->time);
            $isStatusChanged   = ($oldStatus !== $request->status);

            $lead = $appointment->lead;
            $frontendUrl = 'https://theglasspeople.com';
            $approvalLink = "{$frontendUrl}/site-visit/confirm/{$appointment->id}";

            // Send notification if Date/Time changed
            if ($isDateTimeChanged && !in_array(strtolower($request->status), ['completed', 'cancelled'])) {
                $typeLabel = ucfirst(str_replace('_', ' ', $request->type)) . ' Rescheduled';
                
                // Email
                $this->sendScheduleEmail($appointment, $typeLabel, $approvalLink);
                
                // SMS
                $clientPhone = $lead->phone ?? $lead->client_phone ?? null;
                if ($clientPhone) {
                    $this->sendScheduleSms($clientPhone, $appointment, $approvalLink, 'Rescheduled');
                }
            }

            // History / Activity Log
            if ($lead && $lead->gjob) {
                $changes = [];
                if ($isDateTimeChanged) {
                    $newDate = date('M d, Y', strtotime($request->date));
                    $newTime = date('h:i A', strtotime($request->time));
                    $changes[] = "rescheduled to {$newDate} at {$newTime}";
                }
                if ($isStatusChanged) {
                    $changes[] = "status changed to '{$request->status}'";
                }
                if (empty($changes)) {
                    $changes[] = "details updated";
                }

                $lead->gjob->activities()->create([
                    'user_id'     => Auth::id(),
                    'action'      => 'Site Visit Updated',
                    'description' => "Site visit '{$appointment->title}' was " . implode(' and ', $changes) . ".",
                ]);
            }

            return response()->json([
                'message' => 'Site visit updated successfully!',
                'data'    => $appointment
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating site visit.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $appointment = Appointment::with('lead.gjob.activities')->find($id);

            if (!$appointment) {
                return response()->json(['message' => 'Appointment not found.'], 404);
            }

            $lead = $appointment->lead;
            $title = $appointment->title;
            $dateFormatted = date('M d, Y', strtotime($appointment->date));
            $timeFormatted = date('h:i A', strtotime($appointment->time));

            // Activity Log
            if ($lead && $lead->gjob) {
                $lead->gjob->activities()->create([
                    'user_id'     => Auth::id(),
                    'action'      => 'Site Visit Cancelled',
                    'description' => "Site visit '{$title}' scheduled for {$dateFormatted} at {$timeFormatted} was deleted/cancelled.",
                ]);
            }

            // SMS Alert on Delete
            $clientPhone = $lead->phone ?? $lead->client_phone ?? null;
            if ($clientPhone) {
                $messageText = "Hello {$lead->client_name}, your Site Visit '{$title}' on {$dateFormatted} at {$timeFormatted} has been cancelled. Contact us for re-scheduling.";
                $this->sendRawSms($clientPhone, $messageText);
            }

            $appointment->delete();

            return response()->json(['message' => 'Appointment deleted successfully.'], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete appointment.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,scheduled,completed,cancelled',
        ]);

        try {
            $appointment = Appointment::findOrFail($id);
            $appointment->status = $request->status;
            $appointment->save();

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
                'data'    => $appointment
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Private Helper function to trigger Mail and log in `emails` table.
     */
    private function sendScheduleEmail(Appointment $appointment, string $typeLabel, string $approvalLink)
    {
        try {
            $appointment->loadMissing(['lead.gjob.glazier']);
            $lead = $appointment->lead;

            if (!$lead) {
                Log::warning('Schedule Email Failed: No associated Lead.', ['appointment_id' => $appointment->id]);
                return;
            }

            $customerEmail = $lead->email ?? $lead->customer_email ?? null;
            if (empty($customerEmail)) {
                Log::warning('Schedule Email Skipped: Customer email missing.', ['lead_id' => $lead->id]);
                return;
            }

            $gjob = $lead->gjob;
            $refCode = $gjob->job_number 
                ?? $lead->order_no 
                ?? $lead->lead_number 
                ?? "LD-{$lead->id}";

            $mailData = [
                'customer_name'  => $lead->client_name ?? 'Valued Customer',
                'reference_code' => $refCode,
                'type'           => $typeLabel,
                'schedule_date'  => $appointment->date . ' ' . $appointment->time,
                'site_address'   => $lead->address ?? $lead->job_address ?? null,
                'glazier_name'   => $gjob->glazier->name ?? null,
                'notes'          => $appointment->description,
                'approval_link'  => $approvalLink, // Dynamic Link Pass Ki Gayi Hai
            ];

            $sender = env('SENDER_EMAIL', 'sales@theglasspeople.com');
            $subject = "Action Required: Confirm Your {$typeLabel} - Ref: {$refCode}";

            $mailable = new ScheduleConfirmationMail($mailData);
            Mail::to($customerEmail)->send($mailable);

            $htmlContent = $mailable->render();

            Email::create([
                'sender'    => $sender,
                'receiver'  => $customerEmail,
                'subject'   => $subject,
                'html_body' => $htmlContent,
                'type'      => 'sent',
                'is_read'   => true,
            ]);

            Log::info('Schedule Confirmation Email Sent & Saved to DB', [
                'appointment_id' => $appointment->id,
                'customer_email' => $customerEmail,
            ]);

        } catch (\Exception $e) {
            Log::error('Schedule Email Sending Error: ' . $e->getMessage());
        }
    }

    /**
     * Private Helper function to Send SMS via Vonage & Log
     */
    private function sendScheduleSms(string $phone, Appointment $appointment, string $approvalLink)
    {
        try {
            $lead = $appointment->lead;
            $clientName = $lead->client_name ?? 'Customer';
            $formattedDate = date('d M Y', strtotime($appointment->date));
            $formattedTime = date('h:i A', strtotime($appointment->time));

            $messageText = "Hello {$clientName}, your Site Visit has been scheduled for {$formattedDate} at {$formattedTime}. Please review and confirm or leave a note here: {$approvalLink}";

            $response = Http::withOptions([
                'verify' => false,
                'curl'   => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ]
            ])->post('https://rest.nexmo.com/sms/json', [
                'api_key'    => config('services.vonage.key'),
                'api_secret' => config('services.vonage.secret'),
                'to'         => $phone,
                'from'       => config('services.vonage.sms_from') ?? 'Glazier',
                'text'       => $messageText
            ]);

            if ($response->successful()) {
                $resData = $response->json();
                $currentMessage = $resData['messages'][0] ?? null;

                if ($currentMessage && $currentMessage['status'] == 0) {
                    SmsLog::create([
                        'phone_number'      => $phone,
                        'type'              => 'outgoing',
                        'text'              => $messageText,
                        'vonage_message_id' => $currentMessage['message-id'] ?? null,
                        'status'            => 'sent'
                    ]);
                } else {
                    Log::error('Vonage SMS Rejection: ' . ($currentMessage['error-text'] ?? 'Unknown Error'));
                }
            }
        } catch (\Exception $e) {
            Log::error('Schedule SMS Error: ' . $e->getMessage());
        }
    }
}