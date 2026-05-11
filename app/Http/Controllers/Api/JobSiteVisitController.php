<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\GJob;
use App\Models\JobSiteVisit;
use App\Models\JobActivity;

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

        // Create site visit
        $siteVisit = $job->siteVisits()->create([
            'visit_date' => $request->visit_date,
            'notes' => $request->notes,
            'created_by' => auth()->id(),
        ]);

        // Log activity
        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action' => 'Site visited on ' . $request->visit_date
        ]);

        return response()->json([
            'message' => 'Site visit added successfully',
            'site_visit' => $siteVisit
        ], 201);
    }
}
