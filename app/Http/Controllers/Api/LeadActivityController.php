<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\LeadActivity;
use Illuminate\Http\Request;

class LeadActivityController extends Controller
{
    public function index($leadId)
    {
        return LeadActivity::where('lead_id', $leadId)
            ->with('user:id,name')
            ->latest()
            ->get();
    }

    public function store(Request $request, $leadId)
    {
        $request->validate([
            'content' => 'required|string',
            'type' => 'required|string'
        ]);

        $activity = LeadActivity::create([
            'lead_id' => $leadId,
            'user_id' => auth()->id(),
            'type' => $request->type,
            'content' => $request->content
        ]);

        return response()->json($activity->load('user:id,name'), 201);
    }
}