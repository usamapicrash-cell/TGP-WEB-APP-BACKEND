<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\GJob;
use App\Models\Invoice;
use App\Models\GlazierAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/*
  ============================================================================
  REPORTS & ANALYTICS — dedicated endpoint, separate from DashboardController
  ============================================================================
  GET /reports/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD
  (from/to optional — defaults to "this month")

  KEY DECISIONS (please read):

  1. A Job's "date" for filtering = its Lead's `date` column.
     GJob has no date/created_at-based report field of its own — it's always
     tied to a Lead that already carries the real intake date.

  2. Only GJob::where('status','job') counts as a real Job in this report.
     Every Lead auto-creates a placeholder GJob (status='lead') the moment
     it's created (see Lead::boot()) — that's not a converted job yet, so we
     exclude it, matching what DashboardController already does for the
     "Work Order Stages" widget.

  3. Invoiced / Received / Pending / Collection Rate — for BOTH the Live
     All-Time snapshot (DashboardController) AND this date-range report —
     are computed from the exact same source: Invoice.total_amount and
     Invoice.paid_amount. Only the date scope differs. This keeps the two
     sections of the Reports page always in agreement (no more "top card
     says $28, range card says $203" mismatches).

  4. "Job Value" (used only in the Job Value Trend chart) is a SEPARATE,
     intentionally different figure — the sum of each job's Lead.value
     (the quoted amount), which can include work that hasn't been invoiced
     yet. It will not equal "Invoiced" and isn't meant to.
  ============================================================================
*/

class ReportController extends Controller
{
    public function analytics(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $userId = $user->id;
            $userLevel = $user->role->level;

            // ---- Date range (defaults to "this month") ----
            $from = $request->query('from')
                ? Carbon::parse($request->query('from'))->startOfDay()
                : Carbon::now()->startOfMonth();
            $to = $request->query('to')
                ? Carbon::parse($request->query('to'))->endOfDay()
                : Carbon::now()->endOfDay();

            // ---- Base queries with same permission rules as DashboardController ----
            $leadQuery = Lead::query();
            $jobQuery = GJob::query()->where('status', 'job'); // only converted/real jobs
            $invoiceQuery = Invoice::query();

            if ($userLevel > 2) {
                $leadQuery->where('created_by', $userId);
                $invoiceQuery->whereHas('lead', fn($q) => $q->where('created_by', $userId));
                $jobQuery->whereHas('lead', fn($q) => $q->where('created_by', $userId));
            }

            // ================= LEADS in range =================
            $leadsInRange = (clone $leadQuery)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->with('leadType:id,name')
                ->get();

            $leadsTotal = $leadsInRange->count();
            $leadsWon = $leadsInRange->where('status', 'won')->count();
            $leadsConversion = $leadsTotal > 0 ? round(($leadsWon / $leadsTotal) * 100, 1) : 0;

            $sourceColors = [
                'website' => '#34497e', 'referral' => '#ed6a10', 'manual' => '#393a3d',
                'social' => '#8d10ee', 'call' => '#ed6a10', 'email' => '#8d10ee',
            ];
            $leadSources = $leadsInRange->groupBy(fn($l) => $l->source ?: 'Manual')
                ->map(fn($group, $key) => [
                    'name' => ucfirst(strtolower($key)),
                    'value' => $group->count(),
                    'color' => $sourceColors[strtolower($key)] ?? '#94a3b8',
                ])->values();

            // ================= JOBS in range (filtered via lead's date) =================
            $jobsInRange = (clone $jobQuery)
                ->whereHas('lead', fn($q) => $q->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
                ->with('lead:id,lead_number,client_name,value,source,job_address,date')
                ->get();

            $jobsTotal = $jobsInRange->count();
            $jobsCompleted = $jobsInRange->where('work_status', 'completed')->count();
            $jobsInProgress = $jobsInRange->where('work_status', 'in_progress')->count();
            $jobsPending = max($jobsTotal - $jobsCompleted - $jobsInProgress, 0);

            $jobStatusBreakdown = $jobsInRange->groupBy(fn($j) => $j->work_status ?: 'pending')
                ->map(fn($group, $key) => [
                    'name' => ucfirst(str_replace('_', ' ', $key)),
                    'value' => $group->count(),
                ])->values();

            // "Job Value" = sum of each job's Lead.value (the quoted/contract amount).
            // This is a PIPELINE figure — it can include jobs that haven't been
            // invoiced yet, so it will NOT equal "Invoiced" below. Used only for
            // the Job Value Trend chart, kept separate on purpose.
            $contractValue = (float) $jobsInRange->sum(fn($j) => (float) ($j->lead->value ?? 0));

            // ================= INVOICED / RECEIVED / PENDING in range =================
            // Uses the SAME source as the Live All-Time snapshot (Invoice.total_amount
            // and Invoice.paid_amount) — just scoped to this date range instead of
            // all-time — so the two sections of the page always agree with each other.
            $invoicesInRange = (clone $invoiceQuery)
                ->whereBetween('created_at', [$from, $to])
                ->get();

            $invoicedInRange = (float) $invoicesInRange->sum('total_amount');
            $amountReceived = (float) $invoicesInRange->sum('paid_amount');
            $amountPending = max($invoicedInRange - $amountReceived, 0);
            $collectionRate = $invoicedInRange > 0 ? round(($amountReceived / $invoicedInRange) * 100, 1) : 0;

            // Paid amount per lead (used to show Paid/Balance per row in the Jobs table)
            $paidByLead = $invoicesInRange->groupBy('lead_id')->map(fn($grp) => (float) $grp->sum('paid_amount'));

            // ================= TREND (contract value per day the lead came in) =================
            $trend = $jobsInRange->groupBy(fn($j) => optional($j->lead)->date)
                ->filter(fn($group, $date) => !empty($date))
                ->map(fn($group, $date) => [
                    'date' => $date,
                    'value' => (float) $group->sum(fn($j) => (float) ($j->lead->value ?? 0)),
                ])->values()->sortBy('date')->values();

            // ================= FULL DETAIL LISTS (for tables + Excel export) =================
            $leadsList = $leadsInRange->map(fn($l) => [
                'id' => $l->id,
                'lead_number' => $l->lead_number,
                'client_name' => $l->client_name,
                'company' => $l->company,
                'phone' => $l->phone,
                'status' => $l->status,
                'project_type' => $l->leadType->name ?? 'General',
                'value' => (float) $l->value,
                'source' => $l->source ?: 'Manual',
                'date' => $l->date,
            ])->values();

            $jobsList = $jobsInRange->map(function ($j) use ($paidByLead) {
                $contract = (float) ($j->lead->value ?? 0);
                $paid = (float) ($paidByLead[$j->lead_id] ?? 0);
                return [
                    'id' => $j->id,
                    'job_number' => $j->job_number,
                    'lead_number' => $j->lead->lead_number ?? null,
                    'client_name' => $j->lead->client_name ?? $j->title,
                    'source' => $j->lead->source ?? 'Manual',
                    'work_status' => $j->work_status ?: 'pending',
                    'progress' => $j->progress ?? 0,
                    'contract_value' => $contract,
                    'paid_amount' => $paid,
                    'balance' => max($contract - $paid, 0),
                    'address' => $j->lead->job_address ?? null,
                    'date' => $j->lead->date ?? null,
                ];
            })->values();

            // ================= GLAZIER ATTENDANCE in range =================
            // ⚠️ ASSUMPTION: `action` column holds values like 'check_in' / 'check_out'.
            // If your app uses different values (e.g. 'clock_in'/'clock_out'), the
            // frontend badge label just does str_replace('_',' ') + ucfirst, so any
            // value will still display fine — only the color-coding (green=in,
            // red=out) below assumes the 'check_in' / 'check_out' naming.
            $attendanceQuery = GlazierAttendance::with(['job:id,job_number,lead_id', 'job.lead:id,client_name', 'user:id,name']);
            if ($userLevel > 2) {
                $attendanceQuery->whereHas('job.lead', fn($q) => $q->where('created_by', $userId));
            }
            $attendanceInRange = $attendanceQuery
                ->whereBetween('recorded_at', [$from, $to])
                ->orderBy('recorded_at', 'desc')
                ->get();

            $attendanceList = $attendanceInRange->map(fn($a) => [
                'id' => $a->id,
                'job_number' => $a->job->job_number ?? null,
                'client_name' => $a->job->lead->client_name ?? null,
                'glazier' => $a->user->name ?? 'Unknown',
                'action' => $a->action,
                'recorded_at' => optional($a->recorded_at)->toDateTimeString(),
                'lat' => $a->lat,
                'lng' => $a->lng,
            ])->values();

            $attendanceStats = [
                'total_records' => $attendanceInRange->count(),
                'check_ins' => $attendanceInRange->where('action', 'check_in')->count(),
                'check_outs' => $attendanceInRange->where('action', 'check_out')->count(),
                'active_glaziers' => $attendanceInRange->pluck('user_id')->unique()->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                    'stats' => [
                        'leads_total' => $leadsTotal,
                        'leads_won' => $leadsWon,
                        'leads_conversion' => $leadsConversion,
                        'jobs_total' => $jobsTotal,
                        'jobs_completed' => $jobsCompleted,
                        'jobs_in_progress' => $jobsInProgress,
                        'jobs_pending' => $jobsPending,
                        'contract_value' => round($contractValue, 2),
                        'invoiced_in_range' => round($invoicedInRange, 2),
                        'amount_received' => round($amountReceived, 2),
                        'amount_pending' => round($amountPending, 2),
                        'collection_rate' => $collectionRate,
                    ],
                    'lead_sources' => $leadSources,
                    'job_status' => $jobStatusBreakdown,
                    'trend' => $trend,
                    'leads' => $leadsList,
                    'jobs' => $jobsList,
                    'attendance' => $attendanceList,
                    'attendance_stats' => $attendanceStats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Report Analytics Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}