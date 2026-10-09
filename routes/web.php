<?php

use App\Http\Controllers\AppCommunicationController;
use App\Http\Controllers\AppCommunicationCustomerActivityController;
use App\Http\Controllers\AppCommunicationCustomerController;
use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DailyReportPdfController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\LoyaltyProgramController;
use App\Http\Controllers\MobileNotificationController;
use App\Http\Controllers\PremiumRecipesController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeTypeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\UserController;
use App\Models\CustomerAccount;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;

Route::get('/admin/initialize-all-customer-accounts', function () {

    $createdAccounts = 0;
    $initializedExistingAccounts = 0;
    $alreadyInitialized = 0;
    $skipped = [];

    $subscriptions = Subscription::with('customerAccount')
        ->get();

    foreach ($subscriptions as $subscription) {

        /*
        |--------------------------------------------------------------------------
        | Normalize phone
        |--------------------------------------------------------------------------
        */

        $phone = preg_replace(
            '/[^0-9]/',
            '',
            (string) $subscription->subscriber_phone
        );

        if (!$phone) {

            $skipped[] = [
                'subscription_id' => $subscription->id,
                'reason' => 'No phone number',
            ];

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | CASE 1 : NO CUSTOMER ACCOUNT YET
        |--------------------------------------------------------------------------
        */

        if (!$subscription->customerAccount) {

            /*
             * Avoid using a phone already attached
             * to another customer account.
             */

            $existingPhone =
                CustomerAccount::query()
                    ->where(
                        'phone',
                        $phone
                    )
                    ->first();

            if ($existingPhone) {

                $skipped[] = [
                    'subscription_id' =>
                        $subscription->id,

                    'phone' =>
                        $phone,

                    'reason' =>
                        'Phone already linked to customer account #'
                        . $existingPhone->id,
                ];

                continue;
            }


            /*
             * Generate 6-digit password.
             */

            $initialPassword =
                (string) random_int(
                    100000,
                    999999
                );


            /*
             * CustomerAccount has:
             *
             * 'password' => 'hashed'
             *
             * so Laravel hashes it automatically.
             */

            CustomerAccount::create([
                'subscription_id' =>
                    $subscription->id,

                'phone' =>
                    $phone,

                'password' =>
                    $initialPassword,

                'initial_password_encrypted' =>
                    Crypt::encryptString(
                        $initialPassword
                    ),

                'password_changed_at' =>
                    null,

                'access_token_hash' =>
                    null,

                'access_token_expires_at' =>
                    null,
            ]);


            $createdAccounts++;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | CASE 2 : ACCOUNT EXISTS BUT WAS NEVER INITIALIZED
        |--------------------------------------------------------------------------
        */

        $account =
            $subscription->customerAccount;


        if (
            empty(
                $account->initial_password_encrypted
            )
            &&
            empty(
                $account->password_changed_at
            )
        ) {

            $initialPassword =
                (string) random_int(
                    100000,
                    999999
                );


            $account->password =
                $initialPassword;


            $account->initial_password_encrypted =
                Crypt::encryptString(
                    $initialPassword
                );


            $account->password_changed_at =
                null;


            /*
             * Disconnect previous sessions because
             * the password has just been reset.
             */

            $account->access_token_hash =
                null;

            $account->access_token_expires_at =
                null;


            /*
             * Synchronize phone.
             */

            $account->phone =
                $phone;


            $account->save();


            $initializedExistingAccounts++;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | ALREADY INITIALIZED
        |--------------------------------------------------------------------------
        */

        $alreadyInitialized++;
    }


    return response()->json([
        'success' =>
            true,

        'total_subscriptions' =>
            $subscriptions->count(),

        'created_accounts' =>
            $createdAccounts,

        'initialized_existing_accounts' =>
            $initializedExistingAccounts,

        'already_initialized' =>
            $alreadyInitialized,

        'skipped_count' =>
            count(
                $skipped
            ),

        'skipped' =>
            $skipped,
    ]);
});

/*
|--------------------------------------------------------------------------
| Accueil normal et Shopify App Proxy
|--------------------------------------------------------------------------
*/

Route::get('/', function (Request $request) {

    $isShopifyProxy =
        $request->filled('shop') &&
        $request->filled('signature') &&
        $request->filled('timestamp');

    if ($isShopifyProxy) {
        return app(
            CustomerDashboardController::class
        )->index($request);
    }

    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');

})->name('home');



/*
|--------------------------------------------------------------------------
| API du portail client Shopify App Proxy
|--------------------------------------------------------------------------
|
| Ces routes sont utilisées par JavaScript.
| Le token est envoyé dans le corps JSON des requêtes.
| Il n'est plus transmis dans l'URL.
|
*/

Route::post(
    '/customer-login',
    [CustomerAuthController::class, 'login']
)
    ->middleware('throttle:customer-login')
    ->name('customer.login.proxy');

Route::post(
    '/customer-data',
    [CustomerDashboardController::class, 'data']
)->name('customer.data.proxy');

Route::post(
    '/customer-logout',
    [CustomerAuthController::class, 'logout']
)->name('customer.logout.proxy');


/*
|--------------------------------------------------------------------------
| Création du lien de stockage
|--------------------------------------------------------------------------
*/

Route::get('/storage-link', function () {

    $target = storage_path('app/public');
    $link = public_path('storage');

    if (file_exists($link)) {
        return 'Le lien existe déjà.';
    }

    if (symlink($target, $link)) {
        return 'Lien créé avec succès.';
    }

    return 'Échec de création du lien.';
});


/*
|--------------------------------------------------------------------------
| Authentification du back-office
|--------------------------------------------------------------------------
*/

Route::get('/login', [AuthController::class, 'showLogin'])
    ->middleware('guest')
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('guest');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');


/*
|--------------------------------------------------------------------------
| PDF accessibles sans authentification administrateur
|--------------------------------------------------------------------------
*/

Route::get(
    '/daily-report/delivery-pdf/{subscription}',
    [DailyReportPdfController::class, 'deliveryPdf']
)->name('daily-report.delivery-pdf');

Route::get(
    '/daily-report/premium-pdf/{subscription}',
    [DailyReportPdfController::class, 'premiumPdf']
)->name('daily-report.premium-pdf');


/*
|--------------------------------------------------------------------------
| Routes protégées du back-office
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {
    Route::get('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'messages'])
    ->name('support-tickets.messages');
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::resource('users', UserController::class);

    Route::resource('subscriptions', SubscriptionController::class);

    Route::get(
        '/admin/ingredients',
        [IngredientController::class, 'index']
    )->name('admin.ingredients.index');

    Route::get(
        '/admin/recipes',
        [RecipeController::class, 'index']
    )->name('admin.recipes.index');

    Route::resource('ingredients', IngredientController::class);

    Route::resource('recipes', RecipeController::class);

    Route::get(
        '/daily-report',
        [DailyReportController::class, 'index']
    )->name('daily-report.index');

    Route::post(
        '/daily-report/planned-orders/{plannedOrder}/mark-prepared',
        [DailyReportController::class, 'markPrepared']
    )->name('daily-report.planned-orders.mark-prepared');

    Route::resource('recipe-types', RecipeTypeController::class)
        ->except(['show']);

    Route::resource('provinces', ProvinceController::class);

    Route::post(
        'provinces/{province}/zones',
        [ProvinceController::class, 'addZone']
    )->name('provinces.zones.store');

    Route::delete(
        'zones/{zone}',
        [ProvinceController::class, 'deleteZone']
    )->name('zones.destroy');

    Route::get(
        '/provinces-zones',
        [ProvinceController::class, 'index']
    )->name('provinces-zones.index');

    Route::get(
        '/provinces-zones/create',
        [ProvinceController::class, 'create']
    )->name('provinces-zones.create');

    Route::post(
        '/provinces-zones',
        [ProvinceController::class, 'store']
    )->name('provinces-zones.store');

    Route::get(
        '/provinces-zones/{province}/edit',
        [ProvinceController::class, 'edit']
    )->name('provinces-zones.edit');

    Route::put(
        '/provinces-zones/{province}',
        [ProvinceController::class, 'update']
    )->name('provinces-zones.update');

    Route::delete(
        '/provinces-zones/{province}',
        [ProvinceController::class, 'destroy']
    )->name('provinces-zones.destroy');

    Route::post(
        '/provinces-zones/{province}/zones',
        [ProvinceController::class, 'storeZone']
    )->name('provinces-zones.zones.store');

    Route::delete(
        '/provinces-zones/{province}/zones/{zone}',
        [ProvinceController::class, 'destroyZone']
    )->name('provinces-zones.zones.destroy');

    Route::get(
        '/settings',
        [SettingsController::class, 'index']
    )->name('settings.index');

    Route::get(
        '/settings/edit',
        [SettingsController::class, 'edit']
    )->name('settings.edit');

    Route::put(
        '/settings',
        [SettingsController::class, 'update']
    )->name('settings.update');

    Route::get(
        '/daily-report/new-delivery-pdf/{subscription}',
        [\App\Http\Controllers\PdfNewDeliveryController::class, 'newDeliveryPdf']
    )->name('daily-report.new-delivery-pdf');

    Route::get(
        '/daily-report/subscription-qr-promo-pdf/{subscription}',
        [\App\Http\Controllers\PdfNewDeliveryController::class, 'subscriptionQrPromoPdf']
    )->name('daily-report.subscription-qr-promo-pdf');

    Route::get(
        '/daily-report/last-order-renewal-pdf/{subscription}',
        [\App\Http\Controllers\PdfNewDeliveryController::class, 'lastOrderRenewalPdf']
    )->name('daily-report.last-order-renewal-pdf');

    Route::get(
        '/daily-report/premium-recipes',
        [PremiumRecipesController::class, 'index']
    )->name('daily-report.premium-recipes');

    Route::get(
        '/daily-report/full-pdf',
        [DailyReportPdfController::class, 'dailyReportPdf']
    )->name('daily-report.full-pdf');

    Route::get(
        '/daily-report/last-order-pdf/{subscription}',
        [\App\Http\Controllers\PdfNewDeliveryController::class, 'lastOrderPdf']
    )->name('daily-report.last-order-pdf');

    Route::get(
        '/stock',
        [StockController::class, 'index']
    )->name('stock.index');

    Route::get(
        '/stock/edit',
        [StockController::class, 'edit']
    )->name('stock.edit');

    Route::post(
        '/stock',
        [StockController::class, 'store']
    )->name('stock.store');

    Route::get(
        '/customer-service',
        [\App\Http\Controllers\CustomerServiceController::class, 'index']
    )->name('customer-service.index');

    Route::get(
        '/customer-service/customer/{subscription}',
        [\App\Http\Controllers\CustomerServiceController::class, 'customer']
    )->name('customer-service.customer');

    Route::get(
        '/customer-service/reports/create',
        [\App\Http\Controllers\CustomerServiceReportController::class, 'create']
    )->name('customer-service.reports.create');

    Route::post(
        '/customer-service/reports',
        [\App\Http\Controllers\CustomerServiceReportController::class, 'store']
    )->name('customer-service.reports.store');
    
    Route::post(
        '/subscriptions/{subscription}/customer-account/reset',
        [
            SubscriptionController::class,
            'resetCustomerAccount'
        ]
    )->name('subscriptions.customer-account.reset');



    Route::get(
        '/customer-service/reports/{report}/edit',
        [\App\Http\Controllers\CustomerServiceReportController::class, 'edit']
    )->name('customer-service.reports.edit');

    Route::put(
        '/customer-service/reports/{report}',
        [\App\Http\Controllers\CustomerServiceReportController::class, 'update']
    )->name('customer-service.reports.update');

    Route::delete(
        '/customer-service/reports/{report}',
        [\App\Http\Controllers\CustomerServiceReportController::class, 'destroy']
    )->name('customer-service.reports.destroy');

    Route::get(
        '/loyalty-program',
        [LoyaltyProgramController::class, 'index']
    )->name('loyalty.index');

    Route::get(
        '/loyalty-program/subscriptions/{subscription}/history',
        [LoyaltyProgramController::class, 'history']
    )->name('loyalty.history');

    Route::post(
        '/loyalty-program/subscriptions/{subscription}/points',
        [LoyaltyProgramController::class, 'storePoints']
    )->name('loyalty.points.store');
    
   /*
|--------------------------------------------------------------------------
| APP COMMUNICATIONS
|--------------------------------------------------------------------------
*/

Route::get(
    '/app-communications',
    [AppCommunicationController::class, 'index']
)->name('app-communications.index');


/*
|--------------------------------------------------------------------------
| APP COMMUNICATIONS - CUSTOMERS
|--------------------------------------------------------------------------
*/

Route::get(
    '/app-communications/customers',
    [
        AppCommunicationCustomerController::class,
        'index'
    ]
)->name(
    'app-communications.customers.index'
);


Route::get(
    '/app-communications/customers/{customer}/activities',
    [
        AppCommunicationCustomerActivityController::class,
        'index'
    ]
)->name(
    'app-communications.customers.activities'
);
Route::get(
    '/app-communications/activities',
    [
        AppCommunicationCustomerActivityController::class,
        'index'
    ]
)->name(
    'app-communications.activities.index'
);

/*
|--------------------------------------------------------------------------
| APP COMMUNICATIONS - NOTIFICATIONS
|--------------------------------------------------------------------------
*/

Route::get(
    '/app-communications/notifications',
    [AppNotificationController::class, 'index']
)->name('app-communications.notifications.index');


Route::get(
    '/app-communications/notifications/create',
    [AppNotificationController::class, 'create']
)->name('app-communications.notifications.create');


Route::post(
    '/app-communications/notifications',
    [AppNotificationController::class, 'store']
)->name('app-communications.notifications.store');


Route::post(
    '/app-communications/notifications/{notification}/send',
    [AppNotificationController::class, 'send']
)->name('app-communications.notifications.send');


Route::get(
    '/app-communications/notifications/{notification}',
    [AppNotificationController::class, 'show']
)->name('app-communications.notifications.show');


    /*
    |--------------------------------------------------------------------------
    | SUPPORT TICKETS - ADMINISTRATION
    |--------------------------------------------------------------------------
    */

    Route::get('/support-tickets', [SupportTicketController::class, 'index'])
        ->name('support-tickets.index');

    Route::get('/support-tickets/unread-count', [SupportTicketController::class, 'unreadCount'])
        ->name('support-tickets.unread-count');

    Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show'])
        ->whereNumber('ticket')->name('support-tickets.show');

    Route::post('/support-tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])
        ->whereNumber('ticket')->name('support-tickets.reply');

    Route::post('/support-tickets/{ticket}/status', [SupportTicketController::class, 'updateStatus'])
        ->whereNumber('ticket')->name('support-tickets.status');


});