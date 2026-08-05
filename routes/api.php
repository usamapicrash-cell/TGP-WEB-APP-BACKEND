<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    DashboardController,
    AuthController,
    LogoutController,
    UserController,
    LeadController,
    LeadTypeController,
    JobController,
    JobMediaController,
    JobPaymentController,
    JobChatController,
    JobSiteVisitController,
    SupplierController,
    ItemController,
    PurchaseOrderController,
    QuoteController,
    LeadActivityController,
    InvoiceController,
    HelcimController,
    AppointmentController,
    NotificationController,
    EmailController,
    SmsCommunicationController,
};
use App\Http\Controllers\QuickBooksController;
use Illuminate\Http\Request;

Route::get('/quickbooks/connect', [QuickBooksController::class, 'connect']);
Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback']);
Route::get('/quickbooks/inventory', [QuickBooksController::class, 'getInventory']);
Route::post('/quickbooks/create-item', [QuickBooksController::class, 'createItem']);

Route::post('/payment/webhook', [HelcimController::class, 'handleWebhook']);
Route::post('/website/lead', [LeadController::class, 'web_store']);
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    
    Route::post('/logout', [AuthController::class, 'logout']);
    
    Route::get('/user', function(Request $request) {
        return $request->user();
    });

    Route::put('/user/profile-update', [UserController::class, 'updateProfile']);
    Route::put('/user/password-update', [UserController::class, 'updatePassword']);

    
    // 🔐 Super Admin
    Route::middleware('role:super_admin')->group(function () {
        Route::post('/executives', [UserController::class, 'createExecutive']);
    });

    // 🔐 Executive
    Route::middleware('role:executive')->group(function () {
        Route::post('/admins', [UserController::class, 'createAdmin']);
    });

    // 🔐 Admin + Executive
    Route::middleware('role:executive,admin')->group(function () {
        Route::get('/dashboard-data', [DashboardController::class, 'index']);
        // Get emails for a specific lead/customer
        Route::get('/emails', [EmailController::class, 'index']);
        Route::get('/emails_supplier', [EmailController::class, 'emails_supplier']);
        Route::patch('/emails/{email}/read', [EmailController::class, 'markAsRead']);
        // Send a new email manually with attachments
        Route::post('/emails/send', [EmailController::class, 'sendEmail']);
        
        Route::get('/users', [UserController::class, 'index']); // 👈 Add this line
        Route::delete('/users/{id}', [UserController::class, 'destroy']); // 👈 Add this line
        Route::get('/lead-types', [LeadTypeController::class, 'index']);
        Route::put('/users/{id}', [UserController::class, 'update']); // 👈 Yeh line add karein
        Route::post('/lead-types', [LeadTypeController::class, 'store']);
        Route::delete('/lead-types/{id}', [LeadTypeController::class, 'delete']);
        
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']); // ✅ single view
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);

        // Items
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/{item}', [ItemController::class, 'show']); // ✅ single view
        Route::post('/items', [ItemController::class, 'store']);
        Route::put('/items/{item}', [ItemController::class, 'update']);
        Route::delete('/items/{item}', [ItemController::class, 'destroy']);

        Route::post('/glaziers', [UserController::class, 'createGlazier']);

        // Leads
        Route::apiResource('leads', LeadController::class);
        Route::get('/admin/dashboard-stats', [LeadController::class, 'getDashboardStats']);
        Route::patch('/leads/{lead}/assign', [JobController::class, 'assign']);

        
        // Convert Lead → Job
        Route::post('/leads/{lead}/convert-to-job', [JobController::class, 'store']);

        Route::post('/leads/{lead}/quote', [QuoteController::class, 'storeOrUpdate']);
        Route::get('/leads/{lead}/quotes', [QuoteController::class, 'index']);
        Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'generatePdf']);
        Route::patch('/quotes/{quote}/status', [QuoteController::class, 'updateStatus']);

        
        Route::delete('/purchase-orders/delete-file', [PurchaseOrderController::class, 'deleteFile']);
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'delete']);
        
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::post('/purchase-orders/{purchaseOrder}/payment', [PurchaseOrderController::class, 'addPayment']);

        Route::patch('/purchase-orders/{purchaseOrder}/status',[PurchaseOrderController::class, 'updateStatus']);
        Route::patch('/purchase-orders/{purchaseOrder}/update',[PurchaseOrderController::class, 'update']);
    });

    // 🔐 Glazier + Admin
    Route::middleware('role:admin,glazier')->group(function () {
        Route::get('lead/{leadid}', [LeadController::class, 'getlead_Glazier']);
        // Jobs
        Route::get('/jobs', [JobController::class, 'index']);
        Route::get('/jobs/{job}', [JobController::class, 'show']);
        Route::put('/jobs/{job}', [JobController::class, 'update']);

        Route::post('/glazier/attendance', [JobController::class, 'recordAttendance']);
        Route::post('/jobs/glazier', [JobController::class, 'glazierJob']);
        Route::post('/jobs/glazier/all', [JobController::class, 'glazierJobAll']);
        Route::put('/jobs/Markedcomplete/{job}', [JobController::class, 'Markedcomplete']);
        // Job Progress
        Route::post('/jobs/{job}/progress', [JobController::class, 'updateProgress']);
        Route::post('/jobs/{job}/schedule', [JobController::class, 'updateSchedule']);

        // Media
        Route::post('/jobs/{job}/media', [JobMediaController::class, 'store']);
        Route::get('/jobs/{job}/media', [JobMediaController::class, 'index']);
        Route::delete('/jobs/{job}/media/{media}', [JobMediaController::class, 'destroy']);

        // Invoice & Payment routes
        Route::get('leads/{lead}/invoices', [InvoiceController::class, 'index']);
        Route::post('payments/manual', [InvoiceController::class, 'recordManualPayment']);
        
        // Get payment summary
        Route::get('/jobs/{job}/payments/summary', [JobPaymentController::class, 'summary']);


        Route::post('/leads/{lead}/helcim-link', [HelcimController::class, 'generateLink']);
        Route::post('/payments/manual', [HelcimController::class, 'recordManual']);
        Route::get('/invoice/{id}/pdf', [HelcimController::class, 'downloadPDF']);
        Route::post('/invoice/{id}/resend-link', [HelcimController::class, 'resendHelcimLink']);

        Route::get('/leads/{leadId}/appointments', [AppointmentController::class, 'index']);
        Route::post('/leads/{leadId}/appointments', [AppointmentController::class, 'store']);
        Route::delete('/appointments/{id}', [AppointmentController::class, 'destroy']);

        Route::get('/glazier/appointments', [AppointmentController::class, 'glazier_appointments']);
        Route::get('/appointments', [AppointmentController::class, 'all_site_visit_get']);
        Route::get('/appointments/lead/{leadId}', [AppointmentController::class, 'site_visit_get']);
        Route::post('/appointments/lead/{leadId}', [AppointmentController::class, 'site_visit_store']);
        Route::put('/appointments/{leadId}', [AppointmentController::class, 'site_visit_update']);
        Route::put('/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);
        // Chat
        // Send chat message
        Route::get('/chat/conversations', [JobChatController::class, 'getConversations']);
        Route::post('/jobs/{job}/chat', [JobChatController::class, 'store']);

        // Get all chat messages for a job
        Route::get('/jobs/{job}/chat', [JobChatController::class, 'index']);

        Route::get('/purchase-orders-glazier', [PurchaseOrderController::class, 'po_glazier']);

        // Site Visits
        Route::post('/jobs/{job}/site-visits', [JobSiteVisitController::class, 'store']);

        Route::get('/leads/{leadId}/activities', [LeadActivityController::class, 'index']);
        Route::post('/leads/{leadId}/activities', [LeadActivityController::class, 'store']);


        Route::prefix('communications')->group(function () {
            Route::get('/voice-token', [SmsCommunicationController::class, 'getVoiceToken']);
            Route::get('/sms-history', [SmsCommunicationController::class, 'getHistory']);
            Route::post('/send-sms', [SmsCommunicationController::class, 'sendSms']);
        });



    });



    
});
        // Yeh route Vonage Webhook ke liye hai jahan customer ke reply ayenge
        Route::post('/communications/vonage/answer', [SmsCommunicationController::class, 'voiceAnswerWebhook']);
        Route::post('/communications/vonage/event', [SmsCommunicationController::class, 'voiceEventWebhook']);
        
        Route::post('/vonage/webhook-sms', [SmsCommunicationController::class, 'handleInboundWebhook']);

        Route::post('/communications/create-vonage-user', [SmsCommunicationController::class, 'createVonageUser']);
