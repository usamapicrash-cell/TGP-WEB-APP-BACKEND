<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HelcimWebhookController extends Controller
{
    public function handle(Request $request)
    {
        try {

            // Log complete payload
            Log::info('Helcim Webhook', [
                'headers' => $request->headers->all(),
                'body' => $request->all(),
                'raw' => $request->getContent(),
            ]);

            // IMPORTANT:
            // Always return 200 quickly
            return response()->json([
                'success' => true
            ], 200);

        } catch (\Exception $e) {

            Log::error('Webhook Error', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}