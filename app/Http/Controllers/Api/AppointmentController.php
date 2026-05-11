<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\Validator;
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
            // Return clear error for debugging
            return response()->json([
                'success' => false, 
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    // Optional: create company manually (only super admin)
    public function index($leadId)
    {
        // Yahan hum nested data mangwa rahe hain: Appointment -> Lead -> GJob
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
            // 1. Base Query with relations (Removing withSum to avoid 500 error)
            $query = Appointment::with(['lead.gjob.glazier', 'lead.payments']);
            
            // Default: Aaj ki date se start hoga
            $query->whereDate('date', '>=', now()->toDateString());

            // 2. Dynamic Filter (Type)
            // if ($request->has('q')) {
            //     $query->where('type', $request->q);
            // } else {
            //     $query->where('type', 'site_visit');
            // }

            // 3. Status Filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // 4. Glazier Filter
            if ($request->filled('glazier_id')) {
                $query->whereHas('lead.gjob', function($q) use ($request) {
                    $q->where('glazier_id', $request->glazier_id);
                });
            }

            // 5. Search Filter
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

            // 6. Auth Filter (Safe Check)
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
            // Taake aapko console mein asli error nazar aaye
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    public function site_visit_get($leadId)
    {
        // Yahan hum nested data mangwa rahe hain: Appointment -> Lead -> GJob
        $appointments = Appointment::with(['lead.gjob.glazier']) 
            ->where('lead_id', $leadId)
            // ->where('type', 'site_visit')
            ->orderBy('date', 'asc')
            ->orderBy('time', 'asc')
            ->get();

        return response()->json($appointments);
    }

    // Controller Method for Storing
    public function site_visit_store(Request $request, $leadId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'date' => 'required|date',
            'time' => 'required',
            'end_time' => 'nullable',
            'status' => 'required|string',
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);

        // Forcefully setting lead_id and type
        $validated['lead_id'] = $leadId;
        $validated['type'] = 'site_visit';

        $appointment = Appointment::create($validated);

        return response()->json([
            'message' => 'Site visit logged successfully',
            'data' => $appointment
        ], 201);
    }

    // Store new appointment
    public function store(Request $request, $leadId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type'  => 'required|in:site_visit,appointment',
            'date'  => 'required|date|after_or_equal:today', // Past date block ho jayegi
            'time'  => 'required',
        ]);

        // --- CHECK: Agar date AAJ ki hai, toh guzra hua TIME block karein ---
        if ($validated['date'] == date('Y-m-d')) {
            $currentTime = date('H:i:s');
            if ($validated['time'] < $currentTime) {
                return response()->json([
                    'message' => 'Invalid Time: You cannot schedule an appointment in the past.'
                ], 422);
            }
        }

        $lead = \App\Models\Lead::with('gjob')->findOrFail($leadId);
        $glazierId = $lead->gjob->glazier_id ?? null; 

        if ($glazierId) {
            $requestedTime = $validated['time'];
            
            // 2-hour gap logic (Same as before)
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

        // Appointment Create
        $appointment = Appointment::create([
            'lead_id' => $leadId,
            'title'   => $validated['title'],
            'type'    => $validated['type'],
            'date'    => $validated['date'],
            'time'    => $validated['time'],
        ]);

        return response()->json($appointment->load('lead.gjob.glazier'), 201);
    }


    public function site_visit_update(Request $request, $id)
    {
        // 1. Validation Rules
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
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // 2. Find the Appointment
            $appointment = Appointment::find($id);

            if (!$appointment) {
                return response()->json([
                    'message' => 'Appointment not found.'
                ], 404);
            }

            // 3. Update Data
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
            // Appointment find karein
            $appointment = Appointment::find($id);

            // Agar appointment nahi milta
            if (!$appointment) {
                return response()->json([
                    'message' => 'Appointment not found.'
                ], 404);
            }

            // Delete karein
            $appointment->delete();

            return response()->json([
                'message' => 'Appointment deleted successfully.'
            ], 200);

        } catch (\Exception $e) {
            // Kisi bhi error ki surat mein
            return response()->json([
                'message' => 'Failed to delete appointment.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        // 1. Validation
        $request->validate([
            'status' => 'required|in:pending,scheduled,completed,cancelled',
        ]);

        try {
            // 2. Find Appointment
            $appointment = Appointment::findOrFail($id);

            // 3. Update Status
            $appointment->status = $request->status;
            $appointment->save();

            // 4. Return Response
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
}
