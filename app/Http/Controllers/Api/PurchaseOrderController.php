<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PurchaseOrderController extends Controller
{

    public function index(Request $request)
    {
        $user = auth()->user();
        $query = PurchaseOrder::with(['supplier', 'lead.gjob', 'items'])->latest();

        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }
        // Searching Logic
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('po_number', 'LIKE', "%{$search}%")
                  ->orWhereHas('supplier', function($sq) use ($search) {
                      $sq->where('name', 'LIKE', "%{$search}%");
                  })
                  ->orWhereHas('lead', function($lq) use ($search) {
                      $lq->where('lead_number', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Role Based Filtering
        if ($user->role->level > 2) {
            $query->where('created_by', $user->id);
        }

        return $query->get();
    }

    public function po_glazier(Request $request)
    {
        $user = auth()->user();
        $query = PurchaseOrder::with(['supplier', 'lead.gjob', 'items'])->latest();

        // Searching Logic
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('po_number', 'LIKE', "%{$search}%")
                  ->orWhereHas('supplier', function($sq) use ($search) {
                      $sq->where('name', 'LIKE', "%{$search}%");
                  })
                  ->orWhereHas('lead', function($lq) use ($search) {
                      $lq->where('lead_number', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Role Based Filtering
         if (auth()->user()->role->level > 3) {
                $query->whereHas('lead.gjob', function ($q) {
                    $q->where('glazier_id', auth()->id()); 
                    // Note: Ensure the column name is 'glazier_id' or whatever you named it in 'jobs' table
                });
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        // Debugging ke liye: Agar 422 aaye to Network tab -> Response mein ye dikhega
        // return response()->json($request->all()); 

        $request->validate([
            'lead_id'     => 'required', // exists hata kar check karein filhaal
            'supplier_id' => 'required',
            'items'       => 'required',
        ]);

        $itemsData = json_decode($request->items, true);

        if (!is_array($itemsData)) {
            return response()->json(['message' => 'Items format is invalid'], 422);
        }

        return DB::transaction(function () use ($request, $itemsData) {
            $subTotal = collect($itemsData)->sum(fn($i) => (float)($i['quantity'] ?? 1) * (float)($i['cost'] ?? 0));

            // Drawings Handle
            $drawingPaths = [];
            if ($request->hasFile('drawings')) {
                // Laravel automatically handles 'drawings[]' as an array
                foreach ($request->file('drawings') as $file) {
                    $path = $file->store('po-sketches', 'public');
                    $drawingPaths[] = $path;
                }
            }

            // Attachments Handle
            $attachmentPaths = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('po-attachments', 'public');
                    $attachmentPaths[] = $path;
                }
            }

            // PO Create
            $po = PurchaseOrder::create([
                'po_number'      => $this->generatePoNumber(),
                'supplier_id'    => $request->supplier_id,
                'lead_id'        => $request->lead_id,
                'status'         => 'draft',
                'payment_status' => 'unpaid',
                'sub_total'      => $subTotal,
                'total'          => $subTotal,
                'drawing_data'   => $drawingPaths,
                'attachments'    => $attachmentPaths,
                'notes'          => $request->notes,
                'created_by'     => auth()->id()
            ]);

            // Items Save
            foreach ($itemsData as $row) {
                $itemQty = (float)($row['quantity'] ?? 1);
                $itemCost = (float)($row['cost'] ?? 0);
                $po->items()->create([
                    'item_name' => $row['description'] ?? 'No Description',
                    'qty'       => $itemQty,
                    'price'     => $itemCost,
                    'total'     => $itemQty * $itemCost
                ]);
            }

            $this->logActivity($po->lead, 'PO Created', "Purchase Order #{$po->po_number} created. Total: $" . number_format($po->total, 2));
            
            if ($request->send_email == 1) {
                $this->sendPoEmailToSupplier($po, $request);
            }

            return response()->json([
                'message' => 'PO Created Successfully',
                'purchase_order' => $po->load('items')
            ], 201);
        });
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $request->validate([
            'supplier_id' => 'required',
            'items'        => 'required',
        ]);

        $itemsData = json_decode($request->items, true);

        if (!is_array($itemsData)) {
            return response()->json(['message' => 'Items format is invalid'], 422);
        }

        return DB::transaction(function () use ($request, $purchaseOrder, $itemsData) {
            
            // 1. Calculate Totals based on incoming cost/quantity
            $subTotal = collect($itemsData)->sum(function($i) {
                return (float)($i['quantity'] ?? 1) * (float)($i['cost'] ?? 0);
            });

            // 2. Handle New Drawings (if any)
            $drawingPaths = is_array($purchaseOrder->drawing_data) ? $purchaseOrder->drawing_data : [];
            if ($request->hasFile('drawings')) {
                foreach ($request->file('drawings') as $file) {
                    $path = $file->store('po-sketches', 'public');
                    $drawingPaths[] = $path;
                }
            }

            // 3. Handle New Attachments (if any)
            $attachmentPaths = is_array($purchaseOrder->attachments) ? $purchaseOrder->attachments : [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('po-attachments', 'public');
                    $attachmentPaths[] = $path;
                }
            }

            // 4. Update PO Main Data
            $purchaseOrder->update([
                'supplier_id'  => $request->supplier_id,
                'sub_total'    => $subTotal,
                'total'        => $subTotal, // Aap tax/discount yahan add kar sakte hain baad mein
                'drawing_data' => $drawingPaths,
                'attachments'  => $attachmentPaths,
                'notes'        => $request->notes,
            ]);

            // 5. Update Items (Old delete karke new add karna sabse safe approach hai)
            $purchaseOrder->items()->delete();

            foreach ($itemsData as $row) {
                $itemQty = (float)($row['quantity'] ?? 1);
                $itemCost = (float)($row['cost'] ?? 0);

                $purchaseOrder->items()->create([
                    'item_name' => $row['description'] ?? 'No Description',
                    'qty'       => $itemQty,
                    'price'     => $itemCost,
                    'total'     => $itemQty * $itemCost
                ]);
            }

            $this->logActivity($purchaseOrder->lead, 'PO Updated', "Purchase Order #{$purchaseOrder->po_number} was updated.");

            if ($request->send_email == 1) {
                $this->sendPoEmailToSupplier($purchaseOrder, $request, true);
            }

            return response()->json([
                'message' => 'PO Updated Successfully',
                'purchase_order' => $purchaseOrder->load('items')
            ]);
        });
    }

    // --- PAYMENT METHOD (Client Requirement) ---
    public function addPayment(Request $request, PurchaseOrder $purchaseOrder)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string' // Cash, Online, etc.
        ]);

        // Logic: Paid amount update karna
        $newPaidAmount = $purchaseOrder->paid_amount + $request->amount;
        
        if ($newPaidAmount > $purchaseOrder->total) {
            return response()->json(['message' => 'Payment exceeds total PO cost'], 422);
        }

        $paymentStatus = 'partial';
        if ($newPaidAmount >= $purchaseOrder->total) {
            $paymentStatus = 'paid';
        }

        $purchaseOrder->update([
            'paid_amount' => $newPaidAmount,
            'payment_status' => $paymentStatus
        ]);

        $this->logActivity($purchaseOrder->lead, 'PO Payment', "Payment of $" . number_format($request->amount, 2) . " recorded for PO #{$purchaseOrder->po_number}. Status: " . strtoupper($paymentStatus));

        return response()->json([
            'message' => 'Payment recorded successfully',
            'paid_amount' => $purchaseOrder->paid_amount,
            'payment_status' => $purchaseOrder->payment_status
        ]);
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder)
    {
        $request->validate([
            'status' => 'required|in:draft,pending,approved,delivered,cancelled'
        ]);

        $oldStatus = $purchaseOrder->status;
        $purchaseOrder->update(['status' => $request->status]);

        $this->logActivity($purchaseOrder->lead, 'PO Status Change', "PO #{$purchaseOrder->po_number} status changed from " . strtoupper($oldStatus) . " to " . strtoupper($request->status));

        return response()->json([
            'message' => 'PO Status updated',
            'status' => $purchaseOrder->status
        ]);
    }

    public function delete(PurchaseOrder $purchaseOrder)
    {
        // Allowed statuses for deletion
        $allowedStatuses = ['draft', 'pending', 'cancelled'];

        // Check conditions
        if ($purchaseOrder->payment_status !== 'unpaid') {
            return response()->json(['message' => 'Cannot delete PO with payments already made.'], 422);
        }

        $poNumber = $purchaseOrder->po_number;
        $lead = $purchaseOrder->lead;

        if (!in_array($purchaseOrder->status, $allowedStatuses)) {
            return response()->json(['message' => 'Only Draft, Pending, or Cancelled POs can be deleted.'], 422);
        }

        $purchaseOrder->delete();
        $this->logActivity($lead, 'PO Deleted', "Purchase Order #{$poNumber} was deleted.");
        return response()->json(['message' => 'Purchase Order deleted successfully']);
    }


    public function deleteFile(Request $request)
    {
        $request->validate([
            'po_id' => 'required|exists:purchase_orders,id',
            'file_path' => 'required',
            'type' => 'required|in:drawing,attachment'
        ]);

        $po = PurchaseOrder::with('lead')->find($request->po_id); // Lead ke sath load karein log ke liye
        $filePath = $request->file_path;
        $fileName = basename($filePath); // File ka naam nikaalne ke liye log mein dikhane ko

        // 1. Storage se file delete karein (Physical Delete)
        if (\Storage::disk('public')->exists($filePath)) {
            \Storage::disk('public')->delete($filePath);
        }

        // 2. Database ka JSON array update karein
        if ($request->type === 'drawing') {
            $currentDrawings = is_array($po->drawing_data) ? $po->drawing_data : [];
            $po->drawing_data = array_values(array_diff($currentDrawings, [$filePath]));
            $typeLabel = "Sketch/Drawing";
        } else {
            $currentAttachments = is_array($po->attachments) ? $po->attachments : [];
            $po->attachments = array_values(array_diff($currentAttachments, [$filePath]));
            $typeLabel = "Attachment";
        }

        $po->save();

        // 3. Log Activity (Lead history mein save hoga)
        $description = "A $typeLabel file ($fileName) was permanently deleted from PO #{$po->po_number}.";
        $this->logActivity($po->lead, 'PO File Deleted', $description);

        return response()->json([
            'status' => 'success',
            'message' => 'File permanently deleted and record updated',
            'description' => $description // Optional: frontend par toast dikhane ke liye
        ]);
    }


    protected function logActivity($lead, $action, $description)
    {
        if ($lead && $lead->gjob) {
            $lead->gjob->activities()->create([
                'user_id'     => Auth::id(),
                'action'      => $action,
                'description' => $description,
            ]);
        }
    }

    protected function generatePoNumber()
    {
        $year = now()->format('Y');
        $count = PurchaseOrder::whereYear('created_at', $year)->count() + 1;
        return "PO-$year-" . str_pad($count, 3, '0', STR_PAD_LEFT);
    }


    protected function sendPoEmailToSupplier($po, $request, $isUpdate = false)
    {
        $po->load(['supplier', 'lead']);
        $supplierEmail = $po->supplier->email ?? null;

        if (!$supplierEmail) {
            \Log::error("PO Email Failed: Supplier email not found for PO #{$po->po_number}");
            return false;
        }

        // 1. Subject setup
        $subject = $request->email_subject ?? (($isUpdate ? "UPDATED: " : "") . "Purchase Order #{$po->po_number} - The Glass People");
        
        // 2. Body setup (Fixing \n with nl2br and wrapping in basic HTML)
        if ($request->filled('email_body')) {
            $body = "<html><body><p>" . nl2br($request->email_body) . "</p></body></html>";
        } else {
            $body = "<h2>" . ($isUpdate ? "Updated " : "") . "Purchase Order Request</h2>";
            $body .= "<p><strong>PO Number:</strong> {$po->po_number}</p>";
            $body .= "<p><strong>Total Amount:</strong> $" . number_format($po->total, 2) . "</p>";
            $body .= "<p>Please find the attached documents for this order.</p>";
        }

        $sender = env('SENDER_EMAIL', 'sales@theglasspeople.com');

        // 3. Pehle Email ka record create karein taake humein ID mil jaye attachments ke liye
        $emailRecord = \App\Models\Email::create([
            'sender'    => $sender,
            'receiver'  => $supplierEmail,
            'subject'   => $subject,
            'html_body' => $body,
            'type'      => 'sent',
            'is_read'   => true
        ]);

        $attachmentsForMail = [];

        // 4. Drawings ko attach karna aur EmailAttachment model mein save karna
        if (is_array($po->drawing_data)) {
            foreach ($po->drawing_data as $path) {
                $fullPath = storage_path('app/public/' . $path);
                if (file_exists($fullPath)) {
                    $attachmentsForMail[] = $fullPath;
                    
                    \App\Models\EmailAttachment::create([
                        'email_id'  => $emailRecord->id,
                        'file_name' => basename($path),
                        'file_path' => $path,
                        'file_type' => mime_content_type($fullPath),
                        'file_size' => filesize($fullPath),
                    ]);
                }
            }
        }

        // 5. Normal Attachments ko attach karna aur EmailAttachment model mein save karna
        if (is_array($po->attachments)) {
            foreach ($po->attachments as $path) {
                $fullPath = storage_path('app/public/' . $path);
                if (file_exists($fullPath)) {
                    $attachmentsForMail[] = $fullPath;
                    
                    \App\Models\EmailAttachment::create([
                        'email_id'  => $emailRecord->id,
                        'file_name' => basename($path),
                        'file_path' => $path,
                        'file_type' => mime_content_type($fullPath),
                        'file_size' => filesize($fullPath),
                    ]);
                }
            }
        }

        // 6. Final Email Send
        try {
            Mail::send([], [], function ($message) use ($supplierEmail, $sender, $subject, $body, $attachmentsForMail) {
                $message->to($supplierEmail)
                    ->from($sender, 'The Glass People')
                    ->subject($subject)
                    ->html($body);

                // Send all collected valid attachments
                foreach ($attachmentsForMail as $filePath) {
                    $message->attach($filePath);
                }
            });

            return true;

        } catch (\Exception $e) {
            \Log::error("PO Email Failed: " . $e->getMessage());
            return false;
        }
    }
    
}