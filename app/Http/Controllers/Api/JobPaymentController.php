<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\GJob;
use App\Models\JobPayment;
use App\Models\JobActivity;

class JobPaymentController extends Controller
{
    // Store a new payment
    public function store(Request $request, GJob $job)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cash,bank,stripe',
        ], [
            'amount.required' => 'Payment amount is required',
            'amount.numeric' => 'Payment amount must be a number',
            'amount.min' => 'Payment must be at least 1',
            'method.required' => 'Payment method is required',
            'method.in' => 'Payment method must be cash, bank, or stripe'
        ]);

        $payment = $job->payments()->create([
            'created_by' => auth()->id(),
            'amount' => $request->amount,
            'method' => $request->method,
            'status' => 'paid'
        ]);

        // Log activity
        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action' => 'Payment added: ' . $request->amount . ' via ' . $request->method
        ]);

        return response()->json([
            'message' => 'Payment added successfully',
            'payment' => $payment
        ], 201);
    }

    // Get Job Payment Summary
    public function summary(GJob $job)
    {
        $totalJobValue = $job->lead->value ?? 0; // Assuming lead has value
        $totalPaid = $job->payments()->sum('amount');
        $remaining = $totalJobValue - $totalPaid;

        return response()->json([
            'job_id' => $job->id,
            'total_value' => $totalJobValue,
            'total_paid' => $totalPaid,
            'remaining' => max($remaining, 0)
        ]);
    }
}
