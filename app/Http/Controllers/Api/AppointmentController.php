<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Email;
use App\Mail\ScheduleConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log; // Controller ke top par import zaroori hai

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
            'date'        => 'required|date',
            'time'        => 'required',
            'end_time'    => 'nullable',
            'status'      => 'required|string',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string',
        ]);

        $validated['lead_id'] = $leadId;
        $validated['type'] = 'site_visit';

        $appointment = Appointment::create($validated);

        // Send Email and Log DB Record
        $this->sendScheduleEmail($appointment, 'Site Visit');

        return response()->json([
            'message' => 'Site visit logged successfully and email sent.',
            'data'    => $appointment
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
            $appointment = Appointment::find($id);

            if (!$appointment) {
                return response()->json(['message' => 'Appointment not found.'], 404);
            }

            // 1. Purani date/time aur status capture karein
            $oldDate   = $appointment->date;
            $oldTime   = $appointment->time;
            $oldStatus = $appointment->status;

            // 2. Update record
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

            // 3. Condition Checks
            $isDateTimeChanged = ($oldDate !== $request->date) || ($oldTime !== $request->time);
            $isStatusOnlyChange = ($oldStatus !== $request->status) && !$isDateTimeChanged;

            // Email tabhi bhejein jab date/time change ho aur status cancelled/completed NA ho
            if ($isDateTimeChanged && !in_array(strtolower($request->status), ['completed', 'cancelled'])) {
                $typeLabel = ucfirst(str_replace('_', ' ', $request->type)) . ' Rescheduled';
                $this->sendScheduleEmail($appointment, $typeLabel);
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
            $appointment = Appointment::find($id);

            if (!$appointment) {
                return response()->json(['message' => 'Appointment not found.'], 404);
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
   private function sendScheduleEmail(Appointment $appointment, string $typeLabel)
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

            // Dynamic Reference Code Extraction (Fallbacks: job_number -> order_no -> lead_number)
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
            ];

            $sender = env('SENDER_EMAIL', 'sales@theglasspeople.com');
            $subject = "Confirmation: {$typeLabel} Scheduled - Ref: {$refCode}";

            // 1. Mailable Instance
            $mailable = new ScheduleConfirmationMail($mailData);

            // 2. Send Mail
            Mail::to($customerEmail)->send($mailable);

            // 3. Render HTML Body for DB Log
            $htmlContent = $mailable->render();

            // 4. Record Save in Email History
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
                'reference_code' => $refCode,
                'customer_email' => $customerEmail,
            ]);

        } catch (\Exception $e) {
            Log::error('Schedule Email Sending Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}