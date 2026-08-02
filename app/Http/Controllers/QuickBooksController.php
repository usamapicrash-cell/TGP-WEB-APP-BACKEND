<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use QuickBooksOnline\API\DataService\DataService;
use Illuminate\Support\Facades\DB;

class QuickBooksController extends Controller
{
    private function getDataService()
    {
        return DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => config('services.quickbooks.client_id'),
            'ClientSecret' => config('services.quickbooks.client_secret'),
            'RedirectURI' => config('services.quickbooks.redirect_uri'),
            'scope' => 'com.intuit.quickbooks.accounting',
            'baseUrl' => config('services.quickbooks.environment') === 'Production' ? 'Production' : 'Development'
        ]);
    }

    // 1. User ko Connect karne ke liye Auth URL par redirect karen
    public function connect()
    {
        $dataService = $this->getDataService();
        $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();
        $authUrl = $OAuth2LoginHelper->getAuthorizationCodeURL();
        
        return redirect($authUrl);
    }

    // 2. Callback handling - Tokens Receive karke DB me save karen
    public function callback(Request $request)
    {
        $dataService = $this->getDataService();
        $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();

        $accessTokenObj = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken(
            $request->query('code'),
            $request->query('realmId')
        );

        DB::table('quickbooks_tokens')->truncate(); // Purane tokens clear karne ke liye
        DB::table('quickbooks_tokens')->insert([
            'access_token' => $accessTokenObj->getAccessToken(),
            'refresh_token' => $accessTokenObj->getRefreshToken(),
            'realm_id' => $request->query('realmId'),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json(['message' => 'QuickBooks Connected Successfully!']);
    }

    // 3. Inventory Fetching Logic with Auto Token Refresh
    // 3. Inventory Fetching Logic
    public function getInventory()
    {
        $tokenData = DB::table('quickbooks_tokens')->first();

        if (!$tokenData) {
            return response()->json(['error' => 'Please connect to QuickBooks first at /quickbooks/connect'], 400);
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

        // Auto Refresh Token if expired
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

        // UPDATE 1: Sub items fetch karne ke liye filter hata kar MAXRESULTS 1000 kar diya
        $items = $dataService->Query("SELECT * FROM Item STARTPOSITION 1 MAXRESULTS 1000");

        $error = $dataService->getLastError();
        if ($error) {
            return response()->json(['error' => $error->getResponseBody()], 500);
        }

        if (!$items) {
            return response()->json(['success' => true, 'count' => 0, 'items' => []]);
        }

        // UPDATE 2: Sirf Item Name extract karne ke liye array mapping
        $itemNames = array_map(function($item) {
            return $item->Name; 
            // Note: Agar full category path chahiye to $item->FullyQualifiedName use kar sakte hain
        }, $items);

        return response()->json([
            'success' => true,
            'count' => count($itemNames),
            'items' => $itemNames
        ]);
    }
}