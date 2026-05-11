<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\GJob;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Invoice;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = auth()->user();
            $userId = $user->id;
            $userLevel = $user->role->level;

            // --- 1. Base Queries with Permissions ---
            $leadQuery = Lead::query();
            $jobQuery = GJob::query();
            $invoiceQuery = Invoice::query();
            $poQuery = PurchaseOrder::query();
            
            // Appointment Query: Hum 'lead.gjob.glazier' load karenge kyunki GJob lead se connected hai
            $appointmentQuery = Appointment::with(['lead.gjob.glazier']);

            // Permission Logic: Level > 2 (Non-Admin/Executive)
            if ($userLevel > 2) {
                $leadQuery->where('created_by', $userId);
                
                $invoiceQuery->whereHas('lead', function($q) use ($userId) {
                    $q->where('created_by', $userId);
                });

                $poQuery->whereHas('lead', function($q) use ($userId) {
                    $q->where('created_by', $userId);
                });

                $jobQuery->whereHas('lead', function($q) use ($userId) {
                    $q->where('created_by', $userId);
                });

                // Appointment Filter
                if ($userLevel > 3) {
                    // Sirf wahi appointments jahan ye user as a glazier assigned hai
                    $appointmentQuery->whereHas('lead.gjob', function($q) use ($userId) {
                        $q->where('glazier_id', $userId);
                    });
                } else {
                    // Ya phir wo appointments jinki lead is user ne banayi
                    $appointmentQuery->whereHas('lead', function($q) use ($userId) {
                        $q->where('created_by', $userId);
                    });
                }
            }

            // --- 2. Stats Calculation ---
            $totalInvoiced = (clone $invoiceQuery)->sum('total_amount') ?? 0;
            $totalPaid = (clone $invoiceQuery)->sum('paid_amount') ?? 0;
            
            $stats = [
                'contract_total' => number_format($totalInvoiced, 2, '.', ''),
                'total_collected' => number_format($totalPaid, 2, '.', ''),
                'remaining_balance' => number_format($totalInvoiced - $totalPaid, 2, '.', ''),
                'collected_percentage' => $totalInvoiced > 0 ? round(($totalPaid / $totalInvoiced) * 100) : 0,
            ];

            // --- 3. Charts Data ---
            $months = collect(range(4, 0))->map(fn($i) => Carbon::now()->subMonths($i)->format('M'));

            $invoiceHistory = (clone $invoiceQuery)
                ->select(DB::raw("DATE_FORMAT(created_at, '%b') as month"), DB::raw('SUM(total_amount) as invoices'))
                ->where('created_at', '>=', Carbon::now()->subMonths(5))
                ->groupBy('month')->get()->pluck('invoices', 'month');

            $poHistory = (clone $poQuery)
                ->select(DB::raw("DATE_FORMAT(created_at, '%b') as month"), DB::raw('SUM(total) as po'))
                ->where('created_at', '>=', Carbon::now()->subMonths(5))
                ->groupBy('month')->get()->pluck('po', 'month');

            $chartData = $months->map(fn($m) => [
                'month' => $m,
                'invoices' => $invoiceHistory[$m] ?? 0,
                'po' => $poHistory[$m] ?? 0
            ]);

            // --- 4. Region/Source Data ---
            $sourceColors = ['website' => '#0077c5', 'referral' => '#ed6a10', 'manual' => '#393a3d', 'social' => '#8d10ee'];
            $regionData = (clone $leadQuery)
                ->select('source as name', DB::raw('count(*) as value'))
                ->groupBy('source')->get()
                ->map(fn($item) => [
                    'name' => ucfirst(strtolower($item->name)),
                    'value' => (int)$item->value,
                    'color' => $sourceColors[strtolower($item->name)] ?? '#d1d1d1'
                ]);

            // --- 5. Work Order Stages ---
            $stagesConfig = [
                'Install Completed'   => 'Done',
                'Install In Progress' => 'Install',
                'Pre-Install'         => 'Pre-Install',
                'Pre-Approval'        => 'Pre-Approval',
            ];
            $counts = ['Done' => 0, 'Install' => 0, 'Pre-Install' => 0, 'Pre-Approval' => 0];
            
            (clone $jobQuery)->where('status', 'job')->chunk(100, function($jobs) use (&$counts, $stagesConfig) {
                foreach ($jobs as $job) {
                    $checklist = $job->checklist_data;
                    if (!is_array($checklist)) continue;
                    foreach ($stagesConfig as $catName => $displayName) {
                        if (collect($checklist)->where('category', $catName)->where('completed', true)->first()) {
                            $counts[$displayName]++;
                            break; 
                        }
                    }
                }
            });

            // --- 6. Appointments & Glazier Data Fix ---
            $today = Carbon::today()->toDateString();
            $nextWeek = Carbon::today()->addDays(7)->toDateString();

            $appointments = $appointmentQuery
                ->whereBetween('date', [$today, $nextWeek])
                ->orderBy('date', 'asc')
                ->get()
                ->map(function($app) use ($today) {
                    // Lead se GJob par jayenge, phir Glazier ka naam nikalenge
                    $glazierName = $app->lead->gjob->glazier->name ?? 'Not Set';
                    
                    return [
                        'id' => $app->id,
                        'client' => $app->lead->client_name ?? 'N/A',
                        'glazier' => $glazierName, 
                        'title' => $app->title,
                        'type' => $app->type,
                        'date' => $app->date,
                        'time' => Carbon::parse($app->time)->format('h:i A'),
                        'is_today' => $app->date == $today,
                        'status' => $app->status
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'appointments' => $appointments,
                    'stats' => $stats,
                     'topProducts' => PurchaseOrderItem::select('item_name as name', DB::raw('count(*) as sales'))
                                    ->groupBy('name')->orderBy('sales', 'desc')->take(5)->get(),
                    'chartData' => $chartData,
                    'regionData' => $regionData,
                    'woStages' => collect($counts)->map(fn($val, $key) => ['stage' => $key, 'count' => $val])->values(),
                    'recentInvoices' => (clone $invoiceQuery)->with('lead:id,client_name')->where('status','DUE')->latest()->take(5)->get(),
                    'latestPO' => (clone $poQuery)->with('supplier')->where('payment_status', '!=', 'paid')->latest()->take(5)->get()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Dashboard Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}