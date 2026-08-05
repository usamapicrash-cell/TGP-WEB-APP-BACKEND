<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\GJob;
use App\Models\JobMedia;
use App\Models\JobActivity;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobMediaController extends Controller
{

    public function download($jobId, $mediaId)
    {
        $media = JobMedia::where('gjob_id', $jobId)->findOrFail($mediaId);

        // Verify storage file existence
        if (!Storage::disk('public')->exists($media->file_path)) {
            return response()->json(['message' => 'File not found on server'], 404);
        }

        $fileName = basename($media->file_path);
        
        // Return streamed response using Laravel Storage API
        return Storage::disk('public')->download($media->file_path, $fileName, [
            'Access-Control-Expose-Headers' => 'Content-Disposition'
        ]);
    }

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
        $extension = strtolower($file->getClientOriginalExtension());
        
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
            'action'  => "Uploaded " . strtoupper($request->type) . " media"
        ]);

        return response()->json(['message' => 'Uploaded', 'media' => $media], 201);
    }

    public function destroy(GJob $job, JobMedia $media)
    {
        // Check if media belongs to the job
        if ($media->gjob_id !== $job->id) {
            return response()->json(['message' => 'Media does not belong to this job'], 403);
        }

        $stage = strtoupper($media->work_stage ?? 'MEDIA');

        // Delete physical file from storage
        if ($media->file_path && Storage::disk('public')->exists($media->file_path)) {
            Storage::disk('public')->delete($media->file_path);
        }

        // Delete DB Record
        $media->delete();

        // Log Activity
        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action'  => "Deleted {$stage} media"
        ]);

        return response()->json(['message' => 'Media deleted successfully']);
    }
}