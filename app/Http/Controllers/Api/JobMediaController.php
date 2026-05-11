<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\GJob;
use App\Models\JobActivity;
use Illuminate\Support\Facades\Storage;

class JobMediaController extends Controller
{
    public function index(GJob $job)
    {
        // GJob se linked saari media fetch karein user details ke saath
        $media = $job->media()->with('user')->latest()->get();
        
        return response()->json($media);
    }

    public function store(Request $request, GJob $job)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'type' => 'required' // Ye frontend se 'before' ya 'during' aayega
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        
        // Database 'type' enum check: agar pdf hai toh 'document' warna 'image'
        $dbType = ($extension === 'pdf') ? 'document' : 'image';
        
        $path = $file->store('job-media', 'public');

        $media = $job->media()->create([
            'created_by' => auth()->id(),
            'type'       => $dbType,         // Enum: image or document
            'work_stage' => $request->type,  // before, during, after
            'file_path'  => $path,
        ]);

        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action'  => "Uploaded " . strtoupper($request->type) . " photo"
        ]);

        return response()->json(['message' => 'Uploaded', 'media' => $media], 201);
    }
}