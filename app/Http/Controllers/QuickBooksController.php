<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Item;
use Illuminate\Support\Facades\DB;
use Exception;

class QuickBooksController extends Controller
{
    private function getDataService($tokenData = null)
    {
        $config = [
            'auth_mode' => 'oauth2',
            'ClientID' => config('services.quickbooks.client_id'),
            'ClientSecret' => config('services.quickbooks.client_secret'),
            'RedirectURI' => config('services.quickbooks.redirect_uri'),
            'scope' => 'com.intuit.quickbooks.accounting',
            'baseUrl' => config('services.quickbooks.environment') === 'Production' ? 'Production' : 'Development'
        ];

        if ($tokenData) {
            $config['accessTokenKey'] = $tokenData->access_token;
            $config['refreshTokenKey'] = $tokenData->refresh_token;
            $config['QBORealmID'] = $tokenData->realm_id;
        }

        return DataService::Configure($config);
    }

    // --- NEW: Status Check Endpoint ---
    public function getStatus()
    {
        $tokenData = DB::table('quickbooks_tokens')->first();

        if (!$tokenData) {
            return response()->json(['connected' => false]);
        }

        try {
            $dataService = $this->getDataService($tokenData);
            $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();
            
            // Try refreshing token to verify if connection is active
            $refreshedTokenObj = $OAuth2LoginHelper->refreshToken();

            if ($refreshedTokenObj) {
                DB::table('quickbooks_tokens')->where('id', $tokenData->id)->update([
                    'access_token' => $refreshedTokenObj->getAccessToken(),
                    'refresh_token' => $refreshedTokenObj->getRefreshToken(),
                    'updated_at' => now()
                ]);
            }

            return response()->json([
                'connected' => true,
                'realm_id' => $tokenData->realm_id
            ]);
        } catch (Exception $e) {
            // Token invalid or revoked
            return response()->json(['connected' => false, 'error' => 'Token expired or invalid']);
        }
    }

    // --- NEW: Disconnect Endpoint ---
    public function disconnect()
    {
        DB::table('quickbooks_tokens')->truncate();
        return response()->json(['success' => true, 'message' => 'QuickBooks disconnected successfully']);
    }

    // 1. Redirect user to QuickBooks Auth URL
    public function connect()
    {
        $dataService = $this->getDataService();
        $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();
        $authUrl = $OAuth2LoginHelper->getAuthorizationCodeURL();
        
        return redirect($authUrl);
    }

    // 2. Callback handling - Tokens Save
    public function callback(Request $request)
    {
        $dataService = $this->getDataService();
        $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();

        $accessTokenObj = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken(
            $request->query('code'),
            $request->query('realmId')
        );

        DB::table('quickbooks_tokens')->truncate();
        DB::table('quickbooks_tokens')->insert([
            'access_token' => $accessTokenObj->getAccessToken(),
            'refresh_token' => $accessTokenObj->getRefreshToken(),
            'realm_id' => $request->query('realmId'),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Production ya Local React App par Redirect
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        return redirect($frontendUrl . '/admin/leads?qb=connected');
    }

    // 3. Inventory Fetching Logic with Safe Token Refresh
    public function getInventory()
    {
        $tokenData = DB::table('quickbooks_tokens')->first();

        if (!$tokenData) {
            return response()->json(['error' => 'Please connect to QuickBooks first'], 400);
        }

        try {
            $dataService = $this->getDataService($tokenData);
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

            $items = $dataService->Query("SELECT * FROM Item STARTPOSITION 1 MAXRESULTS 1000");

            $error = $dataService->getLastError();
            if ($error) {
                return response()->json(['error' => $error->getResponseBody()], 500);
            }

            if (!$items) {
                return response()->json(['success' => true, 'count' => 0, 'items' => []]);
            }

            $itemNames = array_map(function($item) {
                return [
                    'id' => $item->Id,
                    'name' => $item->Name,
                    'type' => $item->Type
                ];
            }, $items);

            return response()->json([
                'success' => true,
                'count' => count($itemNames),
                'items' => $itemNames
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'QuickBooks auth failed. Re-authentication required.',
                'details' => $e->getMessage()
            ], 401);
        }
    }

    public function createItem(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'unit_price' => 'nullable|numeric',
            'description' => 'nullable|string'
        ]);

        $tokenData = DB::table('quickbooks_tokens')->first();
        if (!$tokenData) {
            return response()->json(['error' => 'QuickBooks connection missing'], 400);
        }

        try {
            $dataService = $this->getDataService($tokenData);
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

            $accounts = $dataService->Query("SELECT * FROM Account WHERE AccountType='Income' MAXRESULTS 1");
            $incomeAccountRef = ($accounts && count($accounts) > 0) ? $accounts[0]->Id : "1";

            $itemObj = Item::create([
                "Name" => $request->name,
                "Type" => "Service",
                "UnitPrice" => $request->unit_price ?? 0,
                "Description" => $request->description ?? $request->name,
                "IncomeAccountRef" => [
                    "value" => $incomeAccountRef
                ]
            ]);

            $resultingObj = $dataService->Add($itemObj);
            $error = $dataService->getLastError();

            if ($error) {
                return response()->json(['error' => $error->getResponseBody()], 500);
            }

            return response()->json([
                'success' => true,
                'item' => [
                    'id' => $resultingObj->Id,
                    'name' => $resultingObj->Name,
                    'type' => $resultingObj->Type,
                    'unit_price' => $resultingObj->UnitPrice ?? 0,
                    'description' => $resultingObj->Description ?? ''
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'QuickBooks transaction failed.',
                'details' => $e->getMessage()
            ], 401);
        }
    }
}