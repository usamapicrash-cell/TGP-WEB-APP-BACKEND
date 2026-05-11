<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadType;
use Illuminate\Http\Request;

class LeadTypeController extends Controller
{
    public function index()
    {
        return LeadType::with('creator:id,name')->latest()->get();
    }

    public function store(Request $request)
    {
       $request->validate([
            'name' => 'required|string|max:255|unique:lead_types'
        ]);

        $type = LeadType::create([
            'name' => $request->name,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Lead type created',
            'data' => $type
        ], 201);
    }

    public function delete($id)
    {
        LeadType::findOrFail($id)->delete();

        return response()->json(['message' => 'Lead type deleted']);
    }
}
