<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\LeadType;
use App\Models\Email;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    /**
     * GET /leads
     */

    public function getDashboardStats() {
        $userId = auth()->id();
        $userLevel = auth()->user()->role->level;

        // Base queries for Lead and Job models
        $leadQuery = \App\Models\Lead::query();
        $jobQuery = \App\Models\GJob::query();

        // Agar user executive/admin nahi hai (level > 2), to sirf uska apna data filter karein
        if ($userLevel > 2) {
            $leadQuery->where('created_by', $userId);
            
            // Jobs ke liye hum un leads ko dekhenge jo is user ne banayi thi
            $jobQuery->whereHas('lead', function($q) use ($userId) {
                $q->where('created_by', $userId);
            });
        }

        return response()->json([
            'totalLeads'    => (clone $leadQuery)->count(),
            'wonLeads'      => (clone $leadQuery)->where('status', 'won')->count(),
            'completedJobs' => (clone $jobQuery)->where('work_status', 'completed')->count(),
        ]);
    }

    public function index(Request $request)
    {
        $query = Lead::with(['creator', 'leadType', 'gjob', 'gjob.media.user'])
                    ->whereHas('gjob', function($q) {
                        $q->where('status', 'lead');
                    });

        if (auth()->user()->role->level > 2) {
            $query->where('created_by', auth()->id());
        }
       // 2. Search Filter (Client Name, Company, or Project Type)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('lead_number', 'like', '%' . $search . '%')
                  ->orwhere('client_name', 'like', '%' . $search . '%')
                  ->orWhere('company', 'like', '%' . $search . '%')
                  ->orWhere('value', 'like', '%' . $search . '%')
                  ->orWhere('phone', 'like', '%' . $search . '%')
                  // Search within the related LeadType table
                  ->orWhereHas('leadType', function($typeQuery) use ($search) {
                      $typeQuery->where('name', 'like', '%' . $search . '%');
                  });
            });
        }

        // 3. Status & Source Filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        // 4. Get Counts for the Stats Cards (Total across all pages)
        $stats = [
            'website' => (clone $query)->where('source', 'website')->count(),
            'call'    => (clone $query)->where('source', 'call')->count(),
            'email'   => (clone $query)->where('source', 'email')->count(),
            'manual'  => (clone $query)->where('source', 'manual')->count(),
        ];

        // 5. Paginate
        $leads = $query->latest()->paginate(10);

        // Merge stats into the pagination response
        return response()->json(array_merge($leads->toArray(), ['stats' => $stats]));
    }
    

    /**
     * GET /leads/{id}
     * Show single lead
     */
    public function show($id)
    {
    $query = Lead::with(['creator', 'leadType', 'gjob.activities' => fn($q) => $q->latest(),
            'gjob.activities.user'])
            ->where('id', $id);

        // Permission Check
        if (auth()->user()->role->level > 2) {
            $query->where('created_by', auth()->id());
        }

        $lead = $query->firstOrFail();

        return response()->json($lead);
    }

    public function getlead_Glazier($id)
    {
        try {
            $query = Lead::with([
                'creator', 
                'leadType', 
                'gjob.activities' => fn($q) => $q->latest(),
                'gjob.activities.user'
            ])->where('id', $id);

            // Permission Check: Use whereHas for relationship columns
            if (auth()->user()->role->level > 3) {
                $query->whereHas('gjob', function ($q) {
                    $q->where('glazier_id', auth()->id()); 
                    // Note: Ensure the column name is 'glazier_id' or whatever you named it in 'jobs' table
                });
            }

            $lead = $query->firstOrFail();

            return response()->json($lead);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching lead',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * POST /leads
     */
    public function store(Request $request)
    {
        $request->validate([
            'client_name'  => 'required|string|max:255',
            'email'       => 'nullable|email|max:255', // Added validation
            'type'         => 'nullable|exists:lead_types,id', // ✅ type ID
            'source'       => 'nullable|string',
            'status'       => 'nullable|string',
            'value'        => 'nullable|numeric',
            'date'         => 'nullable|date',
            'company'      => 'nullable|string',
            'address'      => 'nullable|string',
            'job_address'  => 'nullable|string',
            'phone'        => 'required|string',
        ]);

        $lead = Lead::create([
            'created_by'   => auth()->id(),
            'type'         => $request->type, // ✅ lead_type_id
            'client_name'  => $request->client_name,
            'email'       => $request->email, // Added here
            'source'       => $request->source,
            'status'       => $request->status ?? 'lead',
            'value'        => $request->value ?: null,
            'date'         => $request->date ?? now(),
            'company'      => $request->company,
            'address'      => $request->address,
            'job_address'  => $request->job_address,
            'phone'        => $request->phone,
        ]);

        if ($lead->gjob) {
            $lead->gjob->activities()->create([
                'user_id'     => auth()->id(),
                'action'      => 'Lead Created',
                'description' => "Initial lead created for {$lead->client_name} with status: " . ($request->status ?? 'lead') . $lead->lead_number,
            ]);
        }

        return response()->json([
            'message' => 'Lead created successfully',
            'data' => $lead->load('leadType')
        ], 201);
    }

    /**
     * PUT /leads/{id}
     */
    public function update(Request $request, $id)
    {
        $query = Lead::where('id', $id);

        if (auth()->user()->role->level > 2) {
            $query->where('created_by', auth()->id());
        }

        $lead = $query->firstOrFail();

        $request->validate([
            'type' => 'nullable|exists:lead_types,id',
        ]);

        $lead->update($request->only([
            'client_name',
            'type',
            'source',
            'status',
            'value',
            'date',
            'company',
            'address',
            'job_address',
            'phone',
        ]));

        return response()->json([
            'message' => 'Lead updated successfully',
            'data' => $lead->load('leadType')
        ]);
    }
    /**
     * DELETE /leads/{id}
     */
    public function destroy($id)
    {
        $query = Lead::where('id', $id);

        if (auth()->user()->role->level > 2) {
            $query->where('created_by', auth()->id());
        }

        $query->firstOrFail()->delete();

        return response()->json([
            'message' => 'Lead deleted successfully'
        ]);
    }

    public function web_store(Request $request)
    {
        // 1. API Validation
        $request->validate([
            'client_name' => 'required|string|max:255',
            'phone'       => 'required|string',
            'email'       => 'required|email',
            'service'     => 'nullable|string', // Website se service ka naam aayega
            'address'     => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                
                // 2. Lead Type Match karwana (String to ID)
                // Agar website se 'Glass Repair' aaya hai, to hum LeadType table mein name search karenge
                $typeId = null;
                if ($request->filled('service')) {
                    $leadType = LeadType::where('name', 'LIKE', '%' . $request->service . '%')->first();
                    $typeId = $leadType ? $leadType->id : null;
                }

                // 3. Default Admin User (Assignment ke liye)
                // Aapke User model mein 'role' relation hai, level check kar ke admin uthayenge
                $adminUser = User::whereHas('role', function($q) {
                    $q->where('level', 3);
                })->inRandomOrder()->first();

                $assignedTo = $adminUser ? $adminUser->id : 1;

                // 4. Lead Create Karein
                $lead = Lead::create([
                    'created_by'  => $assignedTo,
                    'type'        => $typeId, // ID match ho kar yahan save hogi
                    'client_name' => $request->client_name,
                    'email'       => $request->email,
                    'phone'       => $request->phone,
                    'source'      => 'Website',
                    'status'      => 'lead',
                    'address'     => $request->address,
                    'notes'       => "Website Message: " . ($request->message ?? 'No message provided'),
                    'date'        => now(),
                ]);

                // 5. Activity Log
                // GJob create hona model events par depend karta hai, agar automatic hai to:
                if ($lead->gjob) {
                    $lead->gjob->activities()->create([
                        'user_id'     => $assignedTo,
                        'action'      => 'Website Lead Received',
                        'description' => "Lead created via website form. Service matched: " . ($request->service ?? 'N/A'),
                    ]);
                }


                \App\Models\UserNotification::create([
                    'title'        => 'New Lead Alert',
                    'msg'          => "You have received a new lead from {$request->client_name} for " . ($request->service ?? 'General Enquiry'),
                    'type'         => 'web_lead',
                    'user_id'      => $assignedTo, // Kis ko dikhegi
                    'from_user_id' => 0,           // 0 = System / Website
                    'read_at'      => null,        // Default unread
                ]);

                Email::create([
                    'sender'    => $request->email,
                    'receiver'  => 'sales@theglasspeople.com',
                    'subject'   => 'New Website Enquiry',
                    'html_body' => $request->message,
                    'type'      => 'received',
                    'is_read'   => true
                ]);

                if ($request->has('ack_html')) {
                    Email::create([
                        'lead_id'   => $lead->id,
                        'sender'    => 'sales@theglasspeople.com',
                        'receiver'  => $request->email,
                        'subject'   => 'We Received Your Request - The Glass People',
                        'html_body' => $request->ack_html, // Pura rendered HTML yahan aayega
                        'type'      => 'sent',
                        'is_read'   => true
                    ]);
                }
                return response()->json([
                    'success' => true,
                    'message' => 'Lead synced successfully',
                    'lead_id' => $lead->id,
                    'type_matched' => $typeId ? 'Yes' : 'No'
                ], 201);
            });

        } catch (\Exception $e) {
            \Log::error("Web Store Lead Error: " . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Internal Server Error'
            ], 500);
        }
    }

}
