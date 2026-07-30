<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use App\Events\SmsReceivedEvent;

class SmsCommunicationController extends Controller
{
    // Constructor ki ab koi zaroorat nahi kyunki hum direct HTTP call use kar rahe hain

    // --- Fetch History Endpoint ---
    public function getHistory(Request $request) {
        $request->validate(['phone' => 'required']);
        
        $logs = SmsLog::where('phone_number', $request->phone)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function($log) {
                return [
                    'id' => $log->id,
                    'type' => $log->type,
                    'text' => $log->text,
                    'status' => $log->status,
                    'time' => $log->created_at->format('h:i A')
                ];
            });

        return response()->json(['messages' => $logs]);
    }

    // --- Send Outbound SMS Endpoint (Direct HTTP) ---
    public function sendSms(Request $request) {
        $request->validate([
            'to' => 'required',
            'message' => 'required'
        ]);

        try {
            // 1. Configuration Validation Check
            if (!config('services.vonage.key') || !config('services.vonage.secret')) {
                throw new \Exception('Vonage API Key ya Secret config/services.php ya .env me missing hai!');
            }

            // 2. HTTP Post with absolute verification bypass arrays
            $response = Http::withOptions([
                'verify' => false, // SSL full bypass
                'curl'   => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ]
            ])->post('https://rest.nexmo.com/sms/json', [
                'api_key'    => config('services.vonage.key'),
                'api_secret' => config('services.vonage.secret'),
                'to'         => $request->to,
                'from'       => config('services.vonage.sms_from') ?? 'Glazier',
                'text'       => $request->message
            ]);

            if ($response->successful()) {
                $resData = $response->json();
                $currentMessage = $resData['messages'][0] ?? null;

                if ($currentMessage && $currentMessage['status'] == 0) {
                    
                    // 3. Database Entry
                    $log = SmsLog::create([
                        'phone_number'      => $request->to,
                        'type'              => 'outgoing',
                        'text'              => $request->message,
                        'vonage_message_id' => $currentMessage['message-id'] ?? null,
                        'status'            => 'sent'
                    ]);

                    return response()->json([
                        'id' => $log->id,
                        'status' => $log->status,
                        'time' => $log->created_at->format('h:i A')
                    ]);
                } else {
                    $errorText = $currentMessage['error-text'] ?? 'Vonage SMS rejection error.';
                    throw new \Exception("Vonage API Error: " . $errorText);
                }
            }

            throw new \Exception("Vonage Gateway se connect nahi ho paya. HTTP Status: " . $response->status());

        } catch (\Throwable $e) {
            // React ko string structured error return hoga ab
            return response()->json([
                'status' => 'failed', 
                'error'  => $e->getMessage(),
                'file'   => $e->getFile(),
                'line'   => $e->getLine()
            ], 500);
        }
    }

    // --- Vonage Webhook for Incoming Customer Replies ---
    public function handleInboundWebhook(Request $request) {
        $from = $request->input('msisdn'); // Customer phone
        $text = $request->input('text');    // Customer message text
        $messageId = $request->input('messageId');

        if (!$from || !$text) return response()->json([], 200);

        // 1. Save to DB
        $log = SmsLog::create([
            'phone_number' => $from,
            'type' => 'incoming',
            'text' => $text,
            'vonage_message_id' => $messageId,
            'status' => 'unread'
        ]);

        // 2. Broadcast via Pusher/Websockets to React immediately
        $payload = [
            'id' => $log->id,
            'type' => 'incoming',
            'text' => $log->text,
            'status' => 'unread',
            'time' => $log->created_at->format('h:i A')
        ];
        
        broadcast(new SmsReceivedEvent($from, $payload))->toOthers();

        return response()->json([], 200);
    }

    // --- 4. Generate Voice JWT Token for React NexmoClient ---
    // --- 4. Generate Voice JWT Token for React NexmoClient ---
   public function getVoiceToken() {
    try {
        $applicationId = config('services.vonage.application_id');
        $privateKeyPath = base_path(config('services.vonage.private_key'));

        \Log::info('Vonage Token Generation Started', [
            'application_id' => $applicationId,
            'private_key_path' => $privateKeyPath,
        ]);

        if (!$applicationId || !$privateKeyPath) {
            throw new \Exception('Vonage application_id ya private_key settings missing hain!');
        }

        if (!file_exists($privateKeyPath)) {
            \Log::error('Vonage private key file not found', ['path' => $privateKeyPath]);
            throw new \Exception('Private key file (.key) is path par nahi mili: ' . $privateKeyPath);
        }

        $privateKey = file_get_contents($privateKeyPath);

        $issuedAt = time();
        $expiry = $issuedAt + 7200;

        $payload = [
            'iat'  => $issuedAt,
            'exp'  => $expiry,
            'jti'  => bin2hex(random_bytes(16)),
            'application_id' => $applicationId,  // fix: iss ki jaga ye
            'sub'  => 'tgp_portal_user', 
            'acl'  => [
                'paths' => [
                    '/*/users/**'         => new \stdClass(),
                    '/*/conversations/**' => new \stdClass(),
                    '/*/sessions/**'      => new \stdClass(),
                    '/*/devices/**'       => new \stdClass(),
                    '/*/image/**'         => new \stdClass(),
                    '/*/media/**'         => new \stdClass(),
                    '/*/applications/**'  => new \stdClass(),   // 👈 add karein
                    '/*/push/**'          => new \stdClass(),   // 👈 add karein
                    '/*/knocking/**'      => new \stdClass(),
                    '/*/legs/**'          => new \stdClass(),    // 👈 YE MISSING THA — asal fix
                ]
            ]
        ];

        \Log::info('Vonage JWT Payload', ['payload' => $payload]);

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));

        $signature = '';
        $dataToSign = $base64UrlHeader . "." . $base64UrlPayload;

        if (!openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            \Log::error('OpenSSL signing failed', ['openssl_error' => openssl_error_string()]);
            throw new \Exception('OpenSSL signing failed! Private key check karein.');
        }

        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $jwt = $dataToSign . "." . $base64UrlSignature;

        \Log::info('Vonage JWT Generated Successfully', ['jwt_preview' => substr($jwt, 0, 40) . '...']);

        return response()->json([
            'token' => $jwt
        ]);

    } catch (\Throwable $e) {
        \Log::error('Vonage Voice Token Generation Failed', [
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ]);

        return response()->json([
            'status' => 'failed',
            'error'  => $e->getMessage(),
            'file'   => $e->getFile(),
            'line'   => $e->getLine()
        ], 500);
    }
}

public function createVonageUser(Request $request) {
    try {
        $applicationId = config('services.vonage.application_id');
        $privateKeyPath = base_path(config('services.vonage.private_key'));

        if (!$applicationId || !file_exists($privateKeyPath)) {
            throw new \Exception('Application ID ya Private key file missing hai!');
        }

        $privateKey = file_get_contents($privateKeyPath);

        // --- Admin-level JWT banayein (sub ke bagair) ---
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
            'application_id' => $applicationId,
        ];

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        $dataToSign = $base64UrlHeader . "." . $base64UrlPayload;

        openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $adminJwt = $dataToSign . "." . $base64UrlSignature;

        \Log::info('Admin JWT Generated', ['jwt_preview' => substr($adminJwt, 0, 40) . '...']);

        // --- User Create Karne Ki Request ---
        $userName = $request->input('name', 'tgp_portal_user'); // default name

        $response = \Illuminate\Support\Facades\Http::withOptions([
                'verify' => false,
            ])
            ->withToken($adminJwt)
            ->post('https://api.nexmo.com/v1/users', [
                'name' => $userName,
                'display_name' => 'TGP Portal User'
            ]);

        \Log::info('Vonage Create User Response', [
            'status' => $response->status(),
            'body'   => $response->json()
        ]);

        return response()->json([
            'status' => $response->status(),
            'body' => $response->json()
        ], $response->status());

    } catch (\Throwable $e) {
        \Log::error('Vonage User Creation Failed', [
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine()
        ]);

        return response()->json(['error' => $e->getMessage()], 500);
    }
}


public function voiceAnswerWebhook(Request $request) {
    \Log::info('Vonage Answer Webhook Hit', $request->all());

    $toNumber = $request->input('custom_data.number') 
                ?? $request->input('to') 
                ?? null;

    if (!$toNumber) {
        \Log::error('Answer webhook: to number nahi mila', $request->all());
        return response()->json([
            ["action" => "talk", "text" => "Sorry, number nahi mila."]
        ]);
    }

    // Vonage virtual number dynamically .env se uthein, jo numerical format me ho
    // Agar custom phone number set nahi hai, toh default numeric sender use karein, text nahi.
    $fromNumber = config('services.vonage.voice_from') 
                  ?? config('services.vonage.sms_from') 
                  ?? '13159071112'; // Apka actual virtual number yahan fallback me rakhein

    return response()->json([
        [
            "action" => "connect",
            "from" => $fromNumber, 
            "endpoint" => [
                [
                    "type" => "phone",
                    "number" => $toNumber
                ]
            ]
        ]
    ]);
}

    public function voiceEventWebhook(Request $request) {
        $data = $request->all();
        \Log::info('Vonage Event Webhook', $data);

         $status            = $data['status'] ?? null;
        $conversationUuid  = $data['conversation_uuid'] ?? null;
        $direction         = $data['direction'] ?? null;
     
        if (!$conversationUuid || !$status) {
            return response()->json([], 200);
        }
     
        // 🔥 Call terminate/fail hone wale states
        $endStates = [
            'completed', 'busy', 'cancelled', 'canceled',
            'timeout', 'rejected', 'failed',
            'no_answer', 'no-answer', 'unanswered', 'declined'
        ];
     
        // 🔥 NAYA: Call live/progress states — pehle yeh broadcast hi nahi hote the
        $liveStates = ['ringing', 'started', 'answered', 'connected', 'active'];
     
        if (in_array($status, $endStates) || in_array($status, $liveStates)) {
            event(new \App\Events\VonageCallEvent($conversationUuid, $status, $direction));
            \Log::info("Broadcasted Call Status Event for Conversation: {$conversationUuid} with status: {$status} (direction: {$direction})");
        }
     
        return response()->json([], 200);
    }
}