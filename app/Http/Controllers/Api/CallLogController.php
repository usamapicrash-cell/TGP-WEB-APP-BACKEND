<?php
// app/Http/Controllers/Api/CallLogController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CallLogController extends Controller
{
    // Match a raw phone number against Leads.phone, ignoring formatting/country code.
    // ⚠️ ASSUMPTION: Lead.phone may or may not include +1 — we compare on last 10 digits.
    public static function resolveClientName(string $rawNumber): ?string
    {
        $digits = preg_replace('/\D/', '', $rawNumber);
        $last10 = substr($digits, -10);
        if (strlen($last10) < 7) return null;

        $lead = Lead::where('phone', 'like', "%{$last10}")->first();
        return $lead?->client_name;
    }

    public function index(Request $request): JsonResponse
    {
        $logs = CallLog::orderBy('created_at', 'desc')
            ->limit($request->query('limit', 25))
            ->get();

        return response()->json(['data' => $logs]);
    }

    // Used by the frontend the moment an incoming call rings, so the widget
    // can show a name instead of a bare number before the call is answered.
    public function lookupCaller(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required']);
        $name = self::resolveClientName($request->query('phone'));
        return response()->json(['client_name' => $name]);
    }

    public function markEnded(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required']);
        $digits = preg_replace('/\D/', '', $request->input('phone'));
        $last10 = substr($digits, -10);

        // Sabse recent, abhi tak "open" (ended_at null) outbound log dhoondo is number ke liye
        $log = \App\Models\CallLog::where('phone_number', 'like', "%{$last10}")
            ->whereNull('ended_at')
            ->where('direction', 'outbound')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($log) {
            $log->ended_at = now();
            if (in_array($log->status, ['ringing', 'started'])) {
                $log->status = 'no-answer'; // kabhi answer hi nahi hua
            } elseif ($log->answered_at) {
                $log->duration = now()->diffInSeconds($log->answered_at);
            }
            $log->save();
        }

        return response()->json(['success' => true]);
    }
}