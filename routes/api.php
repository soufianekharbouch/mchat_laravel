<?php

use App\Http\Controllers\CustomerAppActivityController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\MobileCustomerSettingsController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileDeviceController;
use App\Http\Controllers\MobileHelpFaqController;
use App\Http\Controllers\MobileLoyaltyController;
use App\Http\Controllers\MobileNotificationController;
use App\Http\Controllers\MobileNotificationDismissController;
use App\Http\Controllers\MobileOtpController;
use App\Http\Controllers\MobilePasswordResetController;
use App\Http\Controllers\MobilePetController;
use App\Http\Controllers\MobileProvinceZoneController;
use App\Http\Controllers\MobilePurchaseController;
use App\Http\Controllers\MobileRegisterController;
use App\Http\Controllers\MobileShopController;
use App\Http\Controllers\MobileSupportTicketController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\MobileOrderTrackingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SHOPIFY WEBHOOKS (orders/create, orders/paid, orders/updated)
|--------------------------------------------------------------------------
|
| URL to give to Shopify:
|   https://<domain>/api/shopify/webhooks/orders
| Signature checked with SHOPIFY_WEBHOOK_SECRET.
|
*/

Route::post('/shopify/webhooks/orders', [ShopifyWebhookController::class, 'orders'])
    ->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| MOBILE CUSTOMER API
|--------------------------------------------------------------------------
*/

Route::prefix('mobile')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | PROVINCES & ZONES (PUBLIC)
    |--------------------------------------------------------------------------
    |
    | Renvoie pour chaque province / zone :
    |   - name    : libellé anglais
    |   - name_ar : libellé arabe
    |   - label   : libellé dans la langue demandée
    |
    | Langue : ?lang=ar ou ?lang=en (défaut : en)
    | Exemple : GET /api/mobile/provinces-zones?lang=ar
    |
    */

    Route::get('/provinces-zones', [MobileProvinceZoneController::class, 'index'])
        ->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | HELP & KNOWLEDGE BASE (PUBLIC FAQ)
    |--------------------------------------------------------------------------
    */

    Route::get('/help/faqs', [MobileHelpFaqController::class, 'index'])
        ->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | APP VERSION CHECK
    |--------------------------------------------------------------------------
    */

    Route::post('/app-version', function () {

        $latestVersion = '1.0.37';
        $minimumVersion = '1.0.37';
        $forceUpdate = true;
        $playStoreUrl = 'https://play.google.com/store/apps/details?id=com.maisonchat.mchat';

        return response()->json([
            'success' => true,
            'latest_version' => $latestVersion,
            'minimum_version' => $minimumVersion,
            'force_update' => $forceUpdate,
            'play_store_url' => $playStoreUrl,
            'message_en' => 'A new version of Maison Chat is available. Update now to enjoy the latest improvements.',
            'message_ar' => 'يتوفر إصدار جديد من تطبيق ميزون شات. قم بالتحديث الآن للاستفادة من أحدث التحسينات.',
        ]);

    })->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | LOGIN
    |--------------------------------------------------------------------------
    */

    Route::post('/login', [CustomerAuthController::class, 'login'])
        ->middleware('throttle:customer-login');

    /*
    |--------------------------------------------------------------------------
    | SIGN IN / SIGN UP WITH A WHATSAPP CODE (PUBLIC)
    |--------------------------------------------------------------------------
    |
    | 1. /auth/send-code  { phone }                    -> account_status, requires_name
    | 2. /auth/verify     { phone, code, full_name? }  -> access_token (no expiry)
    |
    | /login (password) stays available but is not used by the app.
    |
    */

    // Per phone: 1 code / 60 s and OTP max per hour (OtpService).
    // Per IP: 5 requests / minute (anti-abuse, does not block tests).
    Route::post('/auth/send-code', [MobileAuthController::class, 'sendCode'])
        ->middleware('throttle:5,1');

    Route::post('/auth/verify', [MobileAuthController::class, 'verify'])
        ->middleware('throttle:10,1');

    /*
    |--------------------------------------------------------------------------
    | SHOP
    |--------------------------------------------------------------------------
    |
    | Catalog: public (visitors), with the pets when a token is sent.
    | Checkout: signed in (creates the pet / first address + the purchase).
    |
    */

    Route::post('/shop/products', [MobileShopController::class, 'products'])
        ->middleware('throttle:120,1');

    Route::post('/shop/products/{product}', [MobileShopController::class, 'product'])
        ->middleware('throttle:120,1');

    Route::post('/shop/checkout', [MobileShopController::class, 'checkout'])
        ->middleware('throttle:20,1');

    /*
    |--------------------------------------------------------------------------
    | REGISTER (PUBLIC)
    |--------------------------------------------------------------------------
    |
    | Creates subscription + customer account + default address + pets,
    | then returns the same response as /login.
    | Limited to 10 attempts per minute per IP address
    | (a wrong WhatsApp code also counts as an attempt).
    |
    */

    Route::post('/register', [MobileRegisterController::class, 'register'])
        ->middleware('throttle:10,1');

    /*
    |--------------------------------------------------------------------------
    | WHATSAPP OTP (PUBLIC)
    |--------------------------------------------------------------------------
    |
    | Sends a 6-digit code on WhatsApp through Zoko.
    | Extra limits per phone in OtpService (1/min, 5/hour).
    |
    */

    Route::post('/otp/send', [MobileOtpController::class, 'send'])
        ->middleware('throttle:10,60');

    /*
    |--------------------------------------------------------------------------
    | FORGOT PASSWORD (PUBLIC)
    |--------------------------------------------------------------------------
    |
    | 1. /otp/send with purpose "reset_password"
    | 2. /password/verify-otp  -> reset_token
    | 3. /password/reset       -> new password
    |
    */

    Route::post('/password/verify-otp', [MobilePasswordResetController::class, 'verifyOtp'])
        ->middleware('throttle:10,1');

    Route::post('/password/reset', [MobilePasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1');

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER DASHBOARD DATA
    |--------------------------------------------------------------------------
    */

    Route::post('/data', [CustomerDashboardController::class, 'data']);

    /*
    |--------------------------------------------------------------------------
    | CHANGE PASSWORD
    |--------------------------------------------------------------------------
    */

    Route::post('/change-password', [CustomerAuthController::class, 'changePassword']);

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER SETTINGS
    |--------------------------------------------------------------------------
    */

    Route::post('/settings', [MobileCustomerSettingsController::class, 'show']);

    Route::post('/settings/update', [MobileCustomerSettingsController::class, 'updateSettings']);

    Route::post('/settings/language', [MobileCustomerSettingsController::class, 'updateLanguage']);

    Route::post('/settings/password', [MobileCustomerSettingsController::class, 'updatePassword']);

    Route::post('/settings/phone', [MobileCustomerSettingsController::class, 'updatePhone']);

    Route::post('/settings/email', [MobileCustomerSettingsController::class, 'updateEmail']);

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER ADDRESSES
    |--------------------------------------------------------------------------
    */

    Route::post('/settings/addresses', [MobileCustomerSettingsController::class, 'addresses']);

    Route::post('/settings/addresses/create', [MobileCustomerSettingsController::class, 'storeAddress']);

    Route::post('/settings/addresses/{address}/update', [MobileCustomerSettingsController::class, 'updateAddress']);

    Route::post('/settings/addresses/{address}/default', [MobileCustomerSettingsController::class, 'setDefaultAddress']);

    Route::post('/settings/addresses/{address}/delete', [MobileCustomerSettingsController::class, 'destroyAddress']);

    /*
    |--------------------------------------------------------------------------
    | APP ACTIVITY TIME TRACKING
    |--------------------------------------------------------------------------
    */

    Route::post('/activity', [CustomerAuthController::class, 'activity']);

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER BUSINESS ACTIVITY HISTORY
    |--------------------------------------------------------------------------
    */

    Route::post('/activities', [CustomerAppActivityController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | PETS
    |--------------------------------------------------------------------------
    */

    Route::post('/pets', [MobilePetController::class, 'store']);

    Route::post('/pets/{pet}/update', [MobilePetController::class, 'update']);

    Route::post('/pets/{pet}/photo', [MobilePetController::class, 'updatePhoto']);

    Route::post('/pets/{pet}/delete', [MobilePetController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | PRODUCTS (PACKS) & PURCHASES
    |--------------------------------------------------------------------------
    |
    | /products                         catalog, per pet
    | /purchases                        my purchases (shown as subscriptions)
    | /purchases/create                 buy a pack (pending until paid)
    | /purchases/{id}                   detail + planning data
    | /purchases/{id}/payment-proof     bank transfer receipt (multipart)
    | /purchases/{id}/cancel            cancel a purchase not paid yet
    | /purchases/{id}/schedule          save the deliveries (dates + recipes)
    | /purchases/{id}/deliveries/{d}/cancel   skip one delivery
    |
    */

    Route::post('/products', [MobilePurchaseController::class, 'products'])
        ->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | ORDER TRACKING (status of the deliveries)
    |--------------------------------------------------------------------------
    |
    | /orders/tracking            upcoming + recent deliveries with their step
    | /orders/tracking/{number}   one delivery (DL-261021-1234)
    |
    */

    Route::post('/orders/tracking', [MobileOrderTrackingController::class, 'index'])
        ->middleware('throttle:60,1');

    Route::post('/orders/tracking/{number}', [MobileOrderTrackingController::class, 'show'])
        ->middleware('throttle:60,1');

    Route::prefix('purchases')
        ->middleware('throttle:60,1')
        ->group(function () {

            Route::post('/', [MobilePurchaseController::class, 'index']);

            Route::post('/create', [MobilePurchaseController::class, 'store'])
                ->middleware('throttle:10,1');

            Route::post('/{purchase}', [MobilePurchaseController::class, 'show'])
                ->whereNumber('purchase');

            Route::post('/{purchase}/payment-proof', [MobilePurchaseController::class, 'uploadPaymentProof'])
                ->whereNumber('purchase');

            // Card payment (KNET / Visa / Mastercard) - simulation for now
            Route::post('/{purchase}/pay', [MobilePurchaseController::class, 'pay'])
                ->whereNumber('purchase')
                ->middleware('throttle:10,1');

            Route::post('/{purchase}/cancel', [MobilePurchaseController::class, 'cancel'])
                ->whereNumber('purchase');

            Route::post('/{purchase}/schedule', [MobilePurchaseController::class, 'schedule'])
                ->whereNumber('purchase');

            // One tap: day of the first delivery, the rest planned automatically
            Route::post('/{purchase}/quick-plan', [MobilePurchaseController::class, 'quickPlan'])
                ->whereNumber('purchase')
                ->middleware('throttle:20,1');

            Route::post('/{purchase}/deliveries/{delivery}/cancel', [MobilePurchaseController::class, 'cancelDelivery'])
                ->whereNumber('purchase')
                ->whereNumber('delivery');
        });

    /*
    |--------------------------------------------------------------------------
    | LOYALTY POINTS (per pet)
    |--------------------------------------------------------------------------
    |
    | /loyalty   points, level, points usable now and history of each pet
    |
    */

    Route::post('/loyalty', [MobileLoyaltyController::class, 'index'])
        ->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | SUPPORT TICKETS
    |--------------------------------------------------------------------------
    |
    | All customer operations use the access_token.
    | Each customer can only access their own tickets.
    |
    */

    Route::prefix('support-tickets')
        ->middleware('throttle:60,1')
        ->group(function () {

            Route::post('/', [MobileSupportTicketController::class, 'index']);

            Route::post('/create', [MobileSupportTicketController::class, 'store']);

            Route::post('/{ticket}', [MobileSupportTicketController::class, 'show'])
                ->whereNumber('ticket');

            Route::post('/{ticket}/reply', [MobileSupportTicketController::class, 'reply'])
                ->whereNumber('ticket');

            Route::post('/{ticket}/close', [MobileSupportTicketController::class, 'close'])
                ->whereNumber('ticket');
        });

    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', [CustomerAuthController::class, 'logout']);
});

/*
|--------------------------------------------------------------------------
| MOBILE DEVICES
|--------------------------------------------------------------------------
*/

Route::post('/mobile/device/detach-customer', [MobileDeviceController::class, 'detachCustomer']);

Route::post('/mobile/device/register', [MobileDeviceController::class, 'register']);

Route::post('/mobile/device/unregister', [MobileDeviceController::class, 'unregister']);

/*
|--------------------------------------------------------------------------
| MOBILE NOTIFICATIONS
|--------------------------------------------------------------------------
*/

Route::post('/mobile/notifications', [MobileNotificationController::class, 'index']);

Route::post('/mobile/notifications/unread-count', [MobileNotificationController::class, 'unreadCount']);

Route::post('/mobile/notifications/{notification}/view', [MobileNotificationController::class, 'markViewed']);

Route::post('/mobile/notifications/{notification}/dismiss', [MobileNotificationDismissController::class, 'dismiss']);

/*
|--------------------------------------------------------------------------
| NOTIFICATION SENDING
|--------------------------------------------------------------------------
*/

Route::post('/notifications/{notification}/send', [MobileNotificationController::class, 'send']);
