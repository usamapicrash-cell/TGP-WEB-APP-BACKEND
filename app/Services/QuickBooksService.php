<?php

namespace App\Services;

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Facades\Invoice as QBOInvoice;
use QuickBooksOnline\API\Facades\Item as QBOItem;
use QuickBooksOnline\API\Facades\Payment as QBOPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Invoice as LocalInvoice;

class QuickBooksService
{
    private function getDataService()
    {
        Log::info('QuickBooks: getDataService called.');
        $tokenData = DB::table('quickbooks_tokens')->first();

        if (!$tokenData) {
            Log::error('QuickBooks: No tokens found in quickbooks_tokens table.');
            return null;
        }

        $dataService = DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => config('services.quickbooks.client_id'),
            'ClientSecret' => config('services.quickbooks.client_secret'),
            'accessTokenKey' => $tokenData->access_token,
            'refreshTokenKey' => $tokenData->refresh_token,
            'QBORealmID' => $tokenData->realm_id,
            'baseUrl' => config('services.quickbooks.environment') === 'Production' ? 'Production' : 'Development'
        ]);

        $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();
        
        try {
            $refreshedTokenObj = $OAuth2LoginHelper->refreshToken();

            if ($refreshedTokenObj) {
                $dataService->updateOAuth2Token($refreshedTokenObj);

                DB::table('quickbooks_tokens')->where('id', $tokenData->id)->update([
                    'access_token' => $refreshedTokenObj->getAccessToken(),
                    'refresh_token' => $refreshedTokenObj->getRefreshToken(),
                    'updated_at' => now()
                ]);
                Log::info('QuickBooks: Token refreshed successfully.');
            }
        } catch (\Exception $e) {
            Log::error('QuickBooks Token Refresh Exception: ' . $e->getMessage());
        }

        return $dataService;
    }

    public function syncLeadAndCreateInvoice(Lead $lead)
    {
        Log::info('QuickBooks: syncLeadAndCreateInvoice started for Lead ID: ' . $lead->id, [
            'client_name' => $lead->client_name,
            'order_no' => $lead->order_no
        ]);

        $dataService = $this->getDataService();
        if (!$dataService) {
            Log::error('QuickBooks: syncLeadAndCreateInvoice aborted - DataService is null.');
            return ['success' => false, 'message' => 'QuickBooks not connected'];
        }

        // 1. Get or Create Customer in QuickBooks
        try {
            $customerId = $this->getOrCreateCustomer($dataService, $lead);
            Log::info('QuickBooks: Customer resolved with ID: ' . $customerId);
        } catch (\Exception $e) {
            Log::error('QuickBooks: Customer resolution failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        // 2. Fetch Lead Approved Quote
        $quote = $lead->approvedQuote ?? $lead->activeQuote;
        if (!$quote || $quote->items->isEmpty()) {
            Log::warning('QuickBooks: No active/approved quote items found for Lead ID: ' . $lead->id);
            return ['success' => false, 'message' => 'No active/approved quote items found for invoice generation'];
        }
        Log::info('QuickBooks: Quote found ID: ' . $quote->id . ' with item count: ' . $quote->items->count());

        // 3. Prepare Line Items for QBO Invoice
        $lineItems = [];
        $incomeAccountRef = $this->getIncomeAccountRef($dataService);

        foreach ($quote->items as $item) {
            $itemId = $this->getOrCreateItem($dataService, $item->description, $item->unit_price, $incomeAccountRef);

            $lineItems[] = [
                'Amount' => $item->total,
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => $itemId],
                    'UnitPrice' => $item->unit_price,
                    'Qty' => $item->qty,
                ],
                'Description' => $item->description,
            ];
        }

        // 4. Create Invoice in QBO (Using Lead order_no as DocNumber)
        $docNumber = 'INV-' . ($lead->order_no ?? time());
        Log::info('QuickBooks: Creating invoice with DocNumber: ' . $docNumber);

        $invoiceObj = QBOInvoice::create([
            'CustomerRef' => ['value' => $customerId],
            'Line' => $lineItems,
            'DocNumber' => $docNumber,
        ]);

        $resultingInvoice = $dataService->Add($invoiceObj);
        $error = $dataService->getLastError();

        if ($error) {
            Log::error('QuickBooks: Invoice creation error response:', [
                'response' => $error->getResponseBody()
            ]);
            return ['success' => false, 'message' => $error->getResponseBody()];
        }

        Log::info('QuickBooks: Invoice created successfully with QBO ID: ' . $resultingInvoice->Id);

        // 5. Sync Payments Associated with this Lead (Auto calculates remaining balance in QBO)
        $payments = Payment::where('lead_id', $lead->id)->get();
        Log::info('QuickBooks: Found local payments to sync count: ' . $payments->count());
        $syncedPaymentsCount = 0;

        foreach ($payments as $payment) {
            try {
                Log::info('QuickBooks: Syncing payment ID: ' . $payment->id . ' amount: ' . $payment->amount);
                $paymentPayload = [
                    "TotalAmt" => $payment->amount,
                    "CustomerRef" => [
                        "value" => $customerId
                    ],
                    "Line" => [
                        [
                            "Amount" => $payment->amount,
                            "LinkedTxn" => [
                                [
                                    "TxnId" => $resultingInvoice->Id,
                                    "TxnType" => "Invoice"
                                ]
                            ]
                        ]
                    ]
                ];

                if (!empty($payment->payment_date)) {
                    $paymentPayload["TxnDate"] = date('Y-m-d', strtotime($payment->payment_date));
                }

                $paymentObj = QBOPayment::create($paymentPayload);
                $resultingPayment = $dataService->Add($paymentObj);
                
                $payError = $dataService->getLastError();
                if (!$payError && $resultingPayment) {
                    $syncedPaymentsCount++;
                    Log::info('QuickBooks: Payment synced successfully. QBO Payment ID: ' . $resultingPayment->Id);
                } else {
                    Log::error('QuickBooks Payment Sync Failed for Payment ID: ' . $payment->id, [
                        'response' => $payError ? $payError->getResponseBody() : 'Unknown error'
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('QuickBooks Payment Sync Exception for Payment ID ' . $payment->id . ': ' . $e->getMessage());
            }
        }

        Log::info('QuickBooks: syncLeadAndCreateInvoice finished successfully.');
        return [
            'success' => true,
            'qbo_invoice_id' => $resultingInvoice->Id,
            'synced_payments_count' => $syncedPaymentsCount
        ];
    }

    public function getOrCreateCustomer($dataService, Lead $lead)
    {
        $baseName = trim($lead->client_name);
        $uniqueRef = !empty($lead->order_no) ? $lead->order_no : ($lead->id ?? time());
        $displayName = "{$baseName} ({$uniqueRef})";

        Log::info('QuickBooks: getOrCreateCustomer checking for DisplayName: "' . $displayName . '"');

        $escapedName = str_replace("'", "\'", $displayName);
        $existingCustomers = $dataService->Query("SELECT * FROM Customer WHERE DisplayName = '{$escapedName}'");

        if (!empty($existingCustomers)) {
            $customer = $existingCustomers[0];
            Log::info('QuickBooks: Customer already exists with ID: ' . $customer->Id);
            
            if (isset($customer->Active) && ($customer->Active === 'false' || $customer->Active === false)) {
                $customer->Active = 'true';
                $updated = $dataService->Update($customer);
                return $updated ? $updated->Id : $customer->Id;
            }
            
            return $customer->Id;
        }

        Log::info('QuickBooks: Customer not found. Creating new customer: ' . $displayName);

        $customerPayload = [
            'GivenName'        => $lead->first_name ?? $baseName,
            'FamilyName'       => $lead->last_name ?? '',
            'DisplayName'      => $displayName,
            'PrimaryEmailAddr' => [
                'Address' => $lead->email ?? ''
            ],
            'PrimaryPhone' => [
                'FreeFormNumber' => $lead->phone ?? ''
            ]
        ];

        $customerObj = Customer::create($customerPayload);
        $resultingCustomerObj = $dataService->Add($customerObj);
        $error = $dataService->getLastError();

        if ($error || !$resultingCustomerObj) {
            $xmlError = $error ? $error->getResponseBody() : 'Null response';
            Log::error('QuickBooks Customer Creation Failed', ['responseBody' => $xmlError]);
            throw new \Exception('QuickBooks Error: ' . $xmlError);
        }

        Log::info('QuickBooks: Customer created successfully with ID: ' . $resultingCustomerObj->Id);
        return $resultingCustomerObj->Id;
    }

    private function getOrCreateItem($dataService, $name, $price, $incomeAccountRef)
    {
        $cleanName = trim(substr($name, 0, 100));
        Log::info('QuickBooks: getOrCreateItem checking item name: "' . $cleanName . '"');

        $escapedItemName = str_replace("'", "\'", $cleanName);

        // 1. Check if item exists and is NOT a Category
        $existing = $dataService->Query("SELECT * FROM Item WHERE Name = '{$escapedItemName}' AND Type != 'Category'");

        if ($existing && count($existing) > 0) {
            Log::info('QuickBooks: Valid Item already exists with ID: ' . $existing[0]->Id);
            return $existing[0]->Id;
        }

        // 2. Check if a Category with this name exists (agar category mili, toh hum alag unique naam se Service item banayenge taake error na aaye)
        $categoryCheck = $dataService->Query("SELECT * FROM Item WHERE Name = '{$escapedItemName}' AND Type = 'Category'");
        if ($categoryCheck && count($categoryCheck) > 0) {
            Log::warning('QuickBooks: Item name is a Category in QBO. Creating a distinct Service item to avoid conflict.');
            $cleanName = $cleanName . ' Service';
        }

        // 3. Create new Service item in QBO
        Log::info('QuickBooks: Creating new Service item: ' . $cleanName);
        $itemObj = QBOItem::create([
            'Name'             => $cleanName,
            'Type'             => 'Service',
            'UnitPrice'        => $price,
            'IncomeAccountRef' => ['value' => $incomeAccountRef]
        ]);

        $created = $dataService->Add($itemObj);
        $error = $dataService->getLastError();

        if ($error || !$created) {
            $errBody = $error ? $error->getResponseBody() : 'Null response';
            Log::error('QuickBooks: Failed to create Item: ' . $errBody);
            throw new \Exception('Failed to create Item in QuickBooks: ' . $errBody);
        }

        Log::info('QuickBooks: Item created successfully with ID: ' . $created->Id);
        return $created->Id;
    }


   public function syncPaymentToQuickBooks(\App\Models\Invoice $localInvoice, \App\Models\Payment $payment)
    {
        Log::info('QuickBooks: syncPaymentToQuickBooks started.', [
            'invoice_id' => $localInvoice->id,
            'invoice_number' => $localInvoice->invoice_number,
            'helcim_invoice_number (QBO ID)' => $localInvoice->helcim_invoice_number,
            'payment_id' => $payment->id,
            'amount' => $payment->amount
        ]);

        $dataService = $this->getDataService();
        if (!$dataService) {
            Log::error('QuickBooks Payment Sync Aborted: DataService is null (Not connected).');
            return;
        }

        if (empty($localInvoice->helcim_invoice_number)) {
            Log::error('QuickBooks Payment Sync Aborted: helcim_invoice_number (QBO Invoice ID) is empty for invoice ID: ' . $localInvoice->id);
            return;
        }

        // QBO mein customer ID find karein ya lead se lein
        $lead = $localInvoice->lead;
        if (!$lead) {
            Log::error('QuickBooks Payment Sync Error: Lead not found for invoice ID: ' . $localInvoice->id);
            return;
        }

        try {
            $customerId = $this->getOrCreateCustomer($dataService, $lead);
            Log::info('QuickBooks Payment Sync: Customer resolved with ID: ' . $customerId);
        } catch (\Exception $e) {
            Log::error('QuickBooks Payment Sync: Failed to resolve customer: ' . $e->getMessage());
            return;
        }

        $paymentPayload = [
            "TotalAmt" => $payment->amount,
            "CustomerRef" => [
                "value" => $customerId
            ],
            "Line" => [
                [
                    "Amount" => $payment->amount,
                    "LinkedTxn" => [
                        [
                            "TxnId" => $localInvoice->helcim_invoice_number, // QBO Invoice ID
                            "TxnType" => "Invoice"
                        ]
                    ]
                ]
            ]
        ];

        if (!empty($payment->payment_date)) {
            $paymentPayload["TxnDate"] = date('Y-m-d', strtotime($payment->payment_date));
        }

        Log::info('QuickBooks Payment Sync: Sending payment payload to QBO', $paymentPayload);

        $paymentObj = \QuickBooksOnline\API\Facades\Payment::create($paymentPayload);
        $resultingPayment = $dataService->Add($paymentObj);
        
        $payError = $dataService->getLastError();
        if ($payError) {
            Log::error('QuickBooks Payment Sync Failed Response: ' . $payError->getResponseBody());
        } else {
            if ($resultingPayment) {
                Log::info('QuickBooks: Payment synced successfully. QBO Payment ID: ' . $resultingPayment->Id);
            } else {
                Log::warning('QuickBooks: Payment sync returned null result without explicit error.');
            }
        }
    }

    private function getIncomeAccountRef($dataService)
    {
        $accounts = $dataService->Query("SELECT * FROM Account WHERE AccountType='Income' MAXRESULTS 1");
        $accId = ($accounts && count($accounts) > 0) ? $accounts[0]->Id : "1";
        Log::info('QuickBooks: getIncomeAccountRef resolved ID: ' . $accId);
        return $accId;
    }
}