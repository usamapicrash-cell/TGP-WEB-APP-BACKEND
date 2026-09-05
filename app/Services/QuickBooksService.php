<?php

namespace App\Services;

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Facades\Invoice as QBOInvoice;
use QuickBooksOnline\API\Facades\Item as QBOItem;
use Illuminate\Support\Facades\DB;
use App\Models\Lead;
use App\Models\Invoice as LocalInvoice;

class QuickBooksService
{
    private function getDataService()
    {
        $tokenData = DB::table('quickbooks_tokens')->first();

        if (!$tokenData) {
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
        $refreshedTokenObj = $OAuth2LoginHelper->refreshToken();

        if ($refreshedTokenObj) {
            $dataService->updateOAuth2Token($refreshedTokenObj);

            DB::table('quickbooks_tokens')->where('id', $tokenData->id)->update([
                'access_token' => $refreshedTokenObj->getAccessToken(),
                'refresh_token' => $refreshedTokenObj->getRefreshToken(),
                'updated_at' => now()
            ]);
        }

        return $dataService;
    }

    public function syncLeadAndCreateInvoice(Lead $lead)
    {
        $dataService = $this->getDataService();
        if (!$dataService) {
            return ['success' => false, 'message' => 'QuickBooks not connected'];
        }

        // 1. Get or Create Customer in QuickBooks
        $customerId = $this->getOrCreateCustomer($dataService, $lead);

        // 2. Fetch Lead Approved Quote
        $quote = $lead->approvedQuote ?? $lead->activeQuote;
        if (!$quote || $quote->items->isEmpty()) {
            return ['success' => false, 'message' => 'No active/approved quote items found for invoice generation'];
        }

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

        // 4. Create Invoice in QBO
        $invoiceObj = QBOInvoice::create([
            'CustomerRef' => ['value' => $customerId],
            'Line' => $lineItems,
            'DocNumber' => 'INV-' . $lead->order_no,
        ]);

        $resultingInvoice = $dataService->Add($invoiceObj);
        $error = $dataService->getLastError();

        if ($error) {
            return ['success' => false, 'message' => $error->getResponseBody()];
        }

        // // 5. Store / Update Invoice in Local Database
        // $localInvoice = LocalInvoice::updateOrCreate(
        //     ['lead_id' => $lead->id],
        //     [
        //         'invoice_number' => $resultingInvoice->DocNumber ?? ('INV-' . $lead->order_no),
        //         'helcim_invoice_number' => $resultingInvoice->Id, // Storing QBO ID for reference
        //         'total_amount' => $quote->total_amount ?? $quote->items->sum('total'),
        //         'paid_amount' => 0.00,
        //         'status' => 'unpaid',
        //         'due_date' => now()->addDays(30),
        //     ]
        // );

        return [
            'success' => true,
            'qbo_invoice_id' => $resultingInvoice->Id,
            // 'local_invoice' => $localInvoice
        ];
    }

    public function getOrCreateCustomer($dataService, Lead $lead)
    {
        $displayName = trim($lead->client_name);

        if (empty($displayName)) {
            throw new \Exception('Client name is required for QuickBooks customer creation.');
        }

        $escapedName = str_replace("'", "\'", $displayName);

        // 1. Check if Customer already exists (Active or Inactive)
        $existingCustomers = $dataService->Query("SELECT * FROM Customer WHERE DisplayName = '{$escapedName}'");

        if (!empty($existingCustomers)) {
            $customer = $existingCustomers[0];
            
            // Reactivate soft-deleted customer if inactive
            if (isset($customer->Active) && ($customer->Active === 'false' || $customer->Active === false)) {
                $customer->Active = 'true';
                $updated = $dataService->Update($customer);
                return $updated ? $updated->Id : $customer->Id;
            }
            
            return $customer->Id;
        }

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
            return $resultingCustomerObj->Id;
        }

        $xmlError = $error ? $error->getResponseBody() : '';

        // 4. Handle Duplicate Error (Code 6240 - Vendor/Other Name Conflict or Soft-Deleted Name)
        if (str_contains($xmlError, '6240')) {
            // Append explicit unique reference with microtime timestamp to eliminate collision
            $uniqueRef = !empty($lead->order_no) ? $lead->order_no : 'ID-' . ($lead->id ?? time());
            $uniqueDisplayName = "{$displayName} ({$uniqueRef}-" . substr(md5(microtime()), 0, 4) . ")";

            $customerPayload['DisplayName'] = $uniqueDisplayName;

            // Re-instantiate SDK Object
            $retryCustomerObj = Customer::create($customerPayload);
            $resultingCustomerObj = $dataService->Add($retryCustomerObj);

            $retryError = $dataService->getLastError();
            if ($retryError) {
                \Log::error('QuickBooks Customer Retry Failed', [
                    'payload_name' => $uniqueDisplayName,
                    'response'     => $retryError->getResponseBody()
                ]);
                throw new \Exception('QuickBooks Error after retry: ' . $retryError->getResponseBody());
            }

            return $resultingCustomerObj->Id;
        }

        \Log::error('QuickBooks Customer Creation Failed', [
            'statusCode'   => $error->getHttpStatusCode(),
            'responseBody' => $xmlError
        ]);
        throw new \Exception('QuickBooks Error: ' . $xmlError);
    }

    private function getOrCreateItem($dataService, $name, $price, $incomeAccountRef)
    {
        $cleanName = str_replace("'", "\'", substr($name, 0, 100));
        $existing = $dataService->Query("SELECT * FROM Item WHERE Name = '{$cleanName}'");

        if ($existing && count($existing) > 0) {
            return $existing[0]->Id;
        }

        $itemObj = QBOItem::create([
            'Name'             => $cleanName,
            'Type'             => 'Service',
            'UnitPrice'        => $price,
            'IncomeAccountRef' => ['value' => $incomeAccountRef]
        ]);

        $created = $dataService->Add($itemObj);
        $error = $dataService->getLastError();

        if ($error || !$created) {
            throw new \Exception('Failed to create Item in QuickBooks: ' . ($error ? $error->getResponseBody() : 'Null response'));
        }

        return $created->Id;
    }

    private function getIncomeAccountRef($dataService)
    {
        $accounts = $dataService->Query("SELECT * FROM Account WHERE AccountType='Income' MAXRESULTS 1");
        return ($accounts && count($accounts) > 0) ? $accounts[0]->Id : "1";
    }
}