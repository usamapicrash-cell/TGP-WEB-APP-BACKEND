<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\GJob;
use App\Models\Lead;
use App\Models\User;
use App\Models\JobActivity;
use App\Models\GlazierAttendance;
use Illuminate\Support\Facades\Log; // 👈 Add this at the top
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JobController extends Controller
{

    public function recordAttendance(Request $request)
    {
        $request->validate([
            'job_id' => 'required|exists:gjobs,id',
            'action' => 'required|in:CLOCK_IN,CLOCK_OUT',
            'lat'    => 'nullable',
            'lng'    => 'nullable',
        ]);

        $userId = auth()->id();
        $job = GJob::with('lead')->findOrFail($request->job_id);
        $action = $request->action;

        if ($action === 'CLOCK_IN') {
            // 1. Double entry prevention
            $alreadyIn = GlazierAttendance::where('user_id', $userId)
                ->where('job_id', $job->id)
                ->where('action', 'CLOCK_IN')
                ->latest()
                ->first();

            $alreadyOut = GlazierAttendance::where('user_id', $userId)
                ->where('job_id', $job->id)
                ->where('action', 'CLOCK_OUT')
                ->latest()
                ->first();

            if ($alreadyIn && (!$alreadyOut || $alreadyOut->id < $alreadyIn->id)) {
                return response()->json(['message' => 'Already Clocked In'], 200);
            }

            // 2. Auto-Clockout from other jobs
            $activeOtherJob = GlazierAttendance::where('user_id', $userId)
                ->where('action', 'CLOCK_IN')
                ->where('job_id', '!=', $job->id)
                ->latest()
                ->first();

            if ($activeOtherJob) {
                GlazierAttendance::create([
                    'job_id'      => $activeOtherJob->job_id,
                    'user_id'     => $userId,
                    'action'      => 'CLOCK_OUT',
                    'lat'         => $request->lat,
                    'lng'         => $request->lng,
                    'recorded_at' => now(),
                ]);
                
                GJob::where('id', $activeOtherJob->job_id)->update(['work_status' => 'pending']);
                
                // Log activity for auto clock-out
                $otherJob = GJob::find($activeOtherJob->job_id);
                if ($otherJob) {
                    $otherJob->activities()->create([
                        'user_id'     => $userId,
                        'action'      => 'Auto Clock-Out',
                        'description' => "User auto clocked-out from Job #{$otherJob->job_number} to start Job #{$job->job_number}.",
                    ]);
                }
            }

            // Status update for current job
            // $job->update(['work_status' => 'in_progress']);

            // Log Activity for CLOCK_IN
            $job->activities()->create([
                'user_id'     => $userId,
                'action'      => 'Job Started',
                'description' => "Glazier started work on Job #{$job->job_number}.",
            ]);

        } else {
            // CLOCK_OUT case
            // $job->update(['work_status' => 'pending']); // Change to 'completed' if this finishes the job

            // Log Activity for CLOCK_OUT
            $job->activities()->create([
                'user_id'     => $userId,
                'action'      => 'Job Stopped',
                'description' => "Glazier stopped work on Job #{$job->job_number}.",
            ]);
        }

        // Attendance record create karein
        $attendance = GlazierAttendance::create([
            'job_id'      => $job->id,
            'user_id'     => $userId,
            'action'      => $action,
            'lat'         => $request->lat,
            'lng'         => $request->lng,
            'recorded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Attendance recorded successfully',
            'job'     => $job->refresh()
        ]);
    }
    public function Markedcomplete(Request $request, GJob $job)
    {
        $userId = auth()->id();

        // 1. Check karein agar user is waqt isi job par Clocked In hai
        $activeAttendance = GlazierAttendance::where('user_id', $userId)
            ->where('job_id', $job->id)
            ->where('action', 'CLOCK_IN')
            ->latest()
            ->first();

        // Check karein ke kya iske baad koi CLOCK_OUT record hai?
        $hasClockedOut = GlazierAttendance::where('user_id', $userId)
            ->where('job_id', $job->id)
            ->where('action', 'CLOCK_OUT')
            ->where('id', '>', ($activeAttendance->id ?? 0))
            ->exists();

        // Agar user Clocked In hai aur abhi tak Clock Out nahi kiya, toh auto Clock Out karein
        if ($activeAttendance && !$hasClockedOut) {
            GlazierAttendance::create([
                'job_id'      => $job->id,
                'user_id'     => $userId,
                'action'      => 'CLOCK_OUT',
                'lat'         => $request->lat, // Dashboard se agar lat/lng aayein
                'lng'         => $request->lng,
                'recorded_at' => now(),
            ]);

            // Activity log for Auto Clock-Out
            $job->activities()->create([
                'user_id'     => $userId,
                'action'      => 'Auto Clock-Out',
                'description' => "System auto clocked-out user because job was marked as completed.",
            ]);
        }

        // 2. Job Status aur Progress Update karein
        $job->update([
            'work_status' => 'completed',
            'progress'    => 100
        ]);

        // 3. Final Activity Log
        $job->activities()->create([
            'user_id'     => $userId,
            'action'      => 'Job Completed',
            'description' => "Job #{$job->job_number} marked as completed with 100% progress.",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Job completed and clocked out successfully.',
            'job'     => $job->load('activities.user')
        ]);
    }
    public function glazierJob(Request $request)
    {
        $glazierId = auth()->id(); 
        
        $jobs = GJob::where('glazier_id', $glazierId)
                    ->where('work_status', '!=', 'completed')
                    // ->where('status', 'job')
                    ->with(['lead', 'attendances' => function($query) use ($glazierId) {
                        $query->where('user_id', $glazierId)->latest();
                    }])
                    ->latest()->get();

        // Check karein ke kya koi job currently active hai (CLOCK_IN baghair CLOCK_OUT ke)
        foreach ($jobs as $job) {
            $latestAttendance = $job->attendances->first();
            if ($latestAttendance && $latestAttendance->action === 'CLOCK_IN') {
                $job->work_status = 'in_progress';
                // Hum actual clock-in time bhej rahe hain taake timer sahi chale
                $job->clock_in_time = $latestAttendance->recorded_at; 
            }
        }

        return response()->json([
            'status' => 'success',
            'jobs' => $jobs
        ]);
    }

    public function glazierJobAll(Request $request)
    {
        $glazierId = auth()->id(); 
        
        $jobs = GJob::where('glazier_id', $glazierId)
                    ->where('status', 'job')
                    ->with(['lead', 'attendances' => function($query) use ($glazierId) {
                        $query->where('user_id', $glazierId)->latest();
                    }])
                    ->latest()->get();

        return response()->json([
            'status' => 'success',
            'jobs' => $jobs
        ]);
    }
    // List all jobs
    public function index()
    {
        $user = auth()->user();
        $query = GJob::with(['lead','glazier'])->where('status', 'job')->latest();

        if ($user->role->level <= 2) {
            // Super Admin / Executive → all jobs
        } elseif ($user->role->level == 3) {
            // Admin → jobs created via leads OR assigned to them as glazier
            $query->where(function($q) use ($user) {
                $q->whereHas('lead', function($q2) use ($user) {
                    $q2->where('created_by', $user->id);
                })->orWhere('glazier_id', $user->id);
            });
        } else {
            // Glazier → only assigned jobs
            $query->where('glazier_id', $user->id);
        }

        return $query->get();
    }


    // Show single job with all details
    public function show(GJob $job)
    {
        $user = auth()->user();

        // Check if lead relationship exists before accessing created_by
        if ($user->role->level > 2) {
            $isCreator = $job->lead && $job->lead->created_by == $user->id;
            $isAssigned = $job->glazier_id == $user->id;
            
            if (!$isCreator && !$isAssigned) {
                abort(403, 'Unauthorized');
            }
        }

        // Relationships ke naam check karein (media, payments, etc.)
        return $job->load(['lead.creator', 'lead.gjob', 'glazier', 'activities' => fn($q) => $q->latest()]);
    }

    // Convert Lead → Job
    // public function store(Request $request, Lead $lead)
    // {
    //     $request->validate([
    //         'glazier_id' => 'required|exists:users,id'
    //     ]);

    //     // ❌ Stop duplicate job creation
    //     $existingJob = GJob::where('lead_id', $lead->id)
    //         ->whereIn('status', ['in_progress', 'pending'])
    //         ->first();

    //     if ($existingJob) {
    //         return response()->json([
    //             'message' => 'Job already exists for this lead',
    //             'job' => $existingJob->load('lead', 'glazier')
    //         ], 409);
    //     }

    //     $job = GJob::create([
    //         'lead_id' => $lead->id,
    //         'glazier_id' => $request->glazier_id,
    //         'status' => 'in_progress'
    //     ]);

    //     JobActivity::create([
    //         'gjob_id' => $job->id,
    //         'user_id' => auth()->id(),
    //         'action' => 'Job Created from Lead'
    //     ]);

    //     return response()->json([
    //         'message' => 'Lead converted to Job successfully',
    //         'job' => $job->load('lead','glazier')
    //     ], 201);
    // }

    public function store(Request $request, Lead $lead)
    {
        $request->validate([
            'title'      => 'required|string|max:255',
            'start_date' => 'required|date',
        ]);

        // 1. Duplicate Check: Check if already converted
        if ($lead->gjob->status === 'job') {
            return response()->json(['message' => 'This lead has already been converted to a job.'], 409);
        }

        return DB::transaction(function () use ($request, $lead) {
            
            // 2. Generate Job Number (LD-1012 -> GB-1012)
            // Agar lead number mein "LD-" nahi bhi hai, toh safe replacement ke liye prefix check kar sakte hain
            $jobNumber = str_replace('LD-', 'JB-', $lead->lead_number);
            if ($jobNumber === $lead->lead_number) {
                $jobNumber = 'JB-' . $lead->lead_number; // Fallback agar LD prefix na ho
            }

            // 3. Update Existing GJob (Jo lead ke saath create hui thi)
            // Hum new record create karne ke bajaye existing gjob record ko "Job" mein convert kar rahe hain
            $job = $lead->gjob;
            
            if (!$job) {
                return response()->json(['message' => 'Linked job record not found.'], 404);
            }

            $job->update([
                'status'      => 'job',      // Lead status becomes "Job"
                'work_status' => 'pending',  // Migration default
                'job_number'  => $jobNumber,
                'title'       => $request->title,
                'description' => $request->description,
                'start_date'  => $request->start_date,
                'end_date'    => $request->end_date,
            ]);
            $creatorName = $lead->creator->name ?? 'System';
            \App\Models\UserNotification::create([
                    'title'        => 'New Job Alert',
                    'msg'          => "You have received a new job from {$creatorName} customer " . ($lead->client_name),
                    'type'         => 'job_assign',
                    'user_id'      => $job->glazier_id, // Kis ko dikhegi
                    'from_user_id' => $lead->created_by,           // 0 = System / Website
                    'read_at'      => null,        // Default unread
                ]);

            
            // 5. Add to Job Activities (History)
            $job->activities()->create([
                'user_id'     => auth()->id(),
                'action'      => 'Lead Converted',
                'description' => "Lead #{$lead->lead_number} converted to Job #{$jobNumber}. Work Status: PENDING.",
            ]);

            return response()->json([
                'message' => 'Lead converted to Job successfully',
                'job'     => $job->load('lead')
            ], 201);
        });
    }

    // Update job status/description
    public function update(Request $request, GJob $job)
    {
        $data = $request->only(['title', 'work_status', 'description', 'progress', 'checklist_data']);
        
        // Checklist Log Logic: Pata lagana ke kya change hua
        $logDescription = 'Job status: ' . ($request->work_status ?? $job->work_status);
        
        if ($request->has('checklist_data')) {
            $oldChecklist = $job->checklist_data ?? [];
            $newChecklist = $request->checklist_data; // React se array hi aayega

            // Find which item was toggled
            foreach ($newChecklist as $newItem) {
                foreach ($oldChecklist as $oldItem) {
                    if ($newItem['id'] == $oldItem['id'] && $newItem['completed'] !== $oldItem['completed']) {
                        $statusText = $newItem['completed'] ? 'Checked' : 'Unchecked';
                        $logDescription .= " | Item: {$newItem['label']} ({$newItem['category']}) set to {$statusText}";
                    }
                }
            }
        }

        // Status completion logic
        if ($request->work_status === 'completed') {
            $data['progress'] = 100;
        } else {
            $data['progress'] = $request->progress ?? $job->progress;
        }

        $job->update($data);

        // Log Activity with detailed description
        $job->activities()->create([
            'user_id'     => auth()->id(),
            'action'      => 'Job Updated',
            'description' => $logDescription . ' (Progress: ' . $data['progress'] . '%)',
        ]);

        return response()->json($job->load('activities.user'));
    }

    // Update job progress (percentage)
    public function updateProgress(Request $request, GJob $job)
    {
        $request->validate([
            'progress' => 'required|numeric|min:0|max:100'
        ]);

        $job->update(['progress' => $request->progress]);

        $job->activities()->create([
                'user_id'     => auth()->id(),
                'action'      => 'Progress Updated',
                'description' => 'Progress updated to ' . $request->progress . '%',
            ]);

        return response()->json([
            'message' => 'Progress updated successfully',
            'progress' => $job->progress
        ]);
    }

    public function updateSchedule(Request $request, GJob $job)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ], [
            'start_date.date' => 'Start date must be a valid date',
            'end_date.date' => 'End date must be a valid date',
            'end_date.after_or_equal' => 'End date cannot be before start date',
        ]);

        // Default logic: if start_date null, use now; if end_date null, use start_date
        $startDate = $request->start_date ?? now();
        $endDate = $request->end_date ?? $startDate;

        $job->update([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        // Log activity
        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action' => "Job schedule updated: {$startDate} to {$endDate}"
        ]);

        return response()->json([
            'message' => 'Job schedule updated successfully',
            'job' => $job
        ]);
    }

    public function assign(Request $request, $id)
    {
        // 1. Find the lead
        $lead = Lead::with('gjob')->findOrFail($id);

        // 2. Validate input
        $request->validate([
            'glazier_id' => 'nullable|exists:users,id',
        ]);

        try {
            if ($lead->gjob) {
                // Update the glazier on the job
                $lead->gjob->update([
                    'glazier_id' => $request->glazier_id
                ]);

                // Get the name for the description
                $assignedUser = User::find($request->glazier_id);
                $userName = $assignedUser ? $assignedUser->name : 'Unassigned';
                
                // Create Activity Log in Database
                $lead->gjob->activities()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'Lead Assigned',
                    'description' => "Lead assigned to: {$userName}",
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Lead assigned successfully',
                'data' => $lead->load(['gjob.glazier', 'gjob']) 
            ]);

        } catch (\Exception $e) {
            // 🚨 THIS WRITES TO storage/logs/laravel.log
            Log::error("Assignment Error for Lead ID {$id}: " . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal Server Error. Check logs for details.',
                'debug_message' => $e->getMessage() // You can remove this in production
            ], 500);
        }
    }
}
