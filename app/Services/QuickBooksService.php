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

        Log::info('QuickBooks: Token found for Realm ID: ' . $tokenData->realm_id);

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
            Log::error('QuickBooks: syncLeadAndCreateInvoice aborted - DataService is null (Not connected).');
            return ['success' => false, 'message' => 'QuickBooks not connected'];
        }

        // 1. Get or Create Customer in QuickBooks
        try {
            $customerId = $this->getOrCreateCustomer($dataService, $lead);
            Log::info('QuickBooks: Customer resolved with ID: ' . $customerId);
        } catch (\Exception $e) {
            Log::error('QuickBooks: Customer resolution failed with exception: ' . $e->getMessage());
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
        Log::info('QuickBooks: Income Account Ref resolved to: ' . $incomeAccountRef);

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

        // 4. Create Invoice in QBO
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

        // 5. Sync Payments Associated with this Lead
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
        $displayName = trim($lead->client_name);
        Log::info('QuickBooks: getOrCreateCustomer checking for DisplayName: "' . $displayName . '"');

        if (empty($displayName)) {
            Log::error('QuickBooks: Client name is empty for lead ID: ' . $lead->id);
            throw new \Exception('Client name is required for QuickBooks customer creation.');
        }

        $escapedName = str_replace("'", "\'", $displayName);

        // 1. Check if Customer already exists
        $existingCustomers = $dataService->Query("SELECT * FROM Customer WHERE DisplayName = '{$escapedName}'");

        if (!empty($existingCustomers)) {
            $customer = $existingCustomers[0];
            Log::info('QuickBooks: Customer already exists with ID: ' . $customer->Id);
            
            // Reactivate soft-deleted customer if inactive
            if (isset($customer->Active) && ($customer->Active === 'false' || $customer->Active === false)) {
                Log::info('QuickBooks: Customer was inactive, reactivating ID: ' . $customer->Id);
                $customer->Active = 'true';
                $updated = $dataService->Update($customer);
                return $updated ? $updated->Id : $customer->Id;
            }
            
            return $customer->Id;
        }

        Log::info('QuickBooks: Customer not found. Creating new customer for: ' . $displayName);

        // 2. Base Customer Payload
        $customerPayload = [
            'GivenName'        => $lead->first_name ?? $displayName,
            'FamilyName'       => $lead->last_name ?? '',
            'DisplayName'      => $displayName,
            'PrimaryEmailAddr' => [
                'Address' => $lead->email ?? ''
            ],
            'PrimaryPhone' => [
                'FreeFormNumber' => $lead->phone ?? ''
            ]
        ];

        // 3. Attempt Initial Creation
        $customerObj = Customer::create($customerPayload);
        $resultingCustomerObj = $dataService->Add($customerObj);
        $error = $dataService->getLastError();

        if (!$error && $resultingCustomerObj) {
            Log::info('QuickBooks: Customer created successfully with ID: ' . $resultingCustomerObj->Id);
            return $resultingCustomerObj->Id;
        }

        $xmlError = $error ? $error->getResponseBody() : '';
        Log::warning('QuickBooks: Initial customer creation failed. Error body: ' . $xmlError);

        // 4. Handle Duplicate Error (Code 6240)
        if (str_contains($xmlError, '6240')) {
            Log::info('QuickBooks: Encountered 6240 duplicate name error. Retrying with unique suffix.');
            $uniqueRef = !empty($lead->order_no) ? $lead->order_no : 'ID-' . ($lead->id ?? time());
            $uniqueDisplayName = "{$displayName} ({$uniqueRef}-" . substr(md5(microtime()), 0, 4) . ")";

            $customerPayload['DisplayName'] = $uniqueDisplayName;
            Log::info('QuickBooks: Retry customer display name: ' . $uniqueDisplayName);

            $retryCustomerObj = Customer::create($customerPayload);
            $resultingCustomerObj = $dataService->Add($retryCustomerObj);

            $retryError = $dataService->getLastError();
            if ($retryError) {
                Log::error('QuickBooks Customer Retry Failed', [
                    'payload_name' => $uniqueDisplayName,
                    'response'     => $retryError->getResponseBody()
                ]);
                throw new \Exception('QuickBooks Error after retry: ' . $retryError->getResponseBody());
            }

            Log::info('QuickBooks: Customer retry succeeded with ID: ' . $resultingCustomerObj->Id);
            return $resultingCustomerObj->Id;
        }

        Log::error('QuickBooks Customer Creation Failed permanently', [
            'responseBody' => $xmlError
        ]);
        throw new \Exception('QuickBooks Error: ' . $xmlError);
    }

    private function getOrCreateItem($dataService, $name, $price, $incomeAccountRef)
    {
        $cleanName = str_replace("'", "\'", substr($name, 0, 100));
        Log::info('QuickBooks: getOrCreateItem checking item name: "' . $cleanName . '"');

        $existing = $dataService->Query("SELECT * FROM Item WHERE Name = '{$cleanName}'");

        if ($existing && count($existing) > 0) {
            Log::info('QuickBooks: Item already exists with ID: ' . $existing[0]->Id);
            return $existing[0]->Id;
        }

        Log::info('QuickBooks: Item not found. Creating new item: ' . $cleanName);
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

    private function getIncomeAccountRef($dataService)
    {
        $accounts = $dataService->Query("SELECT * FROM Account WHERE AccountType='Income' MAXRESULTS 1");
        $accId = ($accounts && count($accounts) > 0) ? $accounts[0]->Id : "1";
        Log::info('QuickBooks: getIncomeAccountRef resolved ID: ' . $accId);
        return $accId;
    }
}