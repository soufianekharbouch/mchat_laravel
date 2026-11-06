<?php

use App\Http\Controllers\AppCommunicationController;
use App\Http\Controllers\AppCommunicationCustomerActivityController;
use App\Http\Controllers\AppCommunicationCustomerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationTypeController;
use App\Http\Controllers\AutoSubscriptionController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\SubscriptionDetailsController;
use App\Http\Controllers\ShopifyOrderTestController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DailyReportPdfController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\PdfNewDeliveryController;
use App\Http\Controllers\PetLoyaltyController;
use App\Http\Controllers\PremiumRecipesController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\PurchaseController;
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
/*
|--------------------------------------------------------------------------
| Initialisation des comptes clients
|--------------------------------------------------------------------------
*/

Route::get('/admin/initialize-all-customer-accounts', function () {

    $createdAccounts = 0;
    $initializedExistingAccounts = 0;
    $alreadyInitialized = 0;
    $skipped = [];

    $subscriptions = Subscription::with('customerAccount')->get();

    foreach ($subscriptions as $subscription) {

        /*
        | Normalize phone
        */

        $phone = preg_replace('/[^0-9]/', '', (string) $subscription->subscriber_phone);

        if (!$phone) {
            $skipped[] = [
                'subscription_id' => $subscription->id,
                'reason' => 'No phone number',
            ];

            continue;
        }

        /*
        | CASE 1 : NO CUSTOMER ACCOUNT YET
        */

        if (!$subscription->customerAccount) {

            // Avoid using a phone already attached to another customer account.
            $existingPhone = CustomerAccount::query()
                ->where('phone', $phone)
                ->first();

            if ($existingPhone) {
                $skipped[] = [
                    'subscription_id' => $subscription->id,
                    'phone' => $phone,
                    'reason' => 'Phone already linked to customer account #' . $existingPhone->id,
                ];

                continue;
            }

            // Generate 6-digit password.
            $initialPassword = (string) random_int(100000, 999999);

            // CustomerAccount has 'password' => 'hashed', so Laravel hashes it automatically.
            CustomerAccount::create([
                'subscription_id' => $subscription->id,
                'phone' => $phone,
                'password' => $initialPassword,
                'initial_password_encrypted' => Crypt::encryptString($initialPassword),
                'password_changed_at' => null,
                'access_token_hash' => null,
                'access_token_expires_at' => null,
            ]);

            $createdAccounts++;

            continue;
        }

        /*
        | CASE 2 : ACCOUNT EXISTS BUT WAS NEVER INITIALIZED
        */

        $account = $subscription->customerAccount;

        if (
            empty($account->initial_password_encrypted)
            && empty($account->password_changed_at)
        ) {
            $initialPassword = (string) random_int(100000, 999999);

            $account->password = $initialPassword;
            $account->initial_password_encrypted = Crypt::encryptString($initialPassword);
            $account->password_changed_at = null;

            // Disconnect previous sessions because the password has just been reset.
            $account->access_token_hash = null;
            $account->access_token_expires_at = null;

            // Synchronize phone.
            $account->phone = $phone;

            $account->save();

            $initializedExistingAccounts++;

            continue;
        }

        /*
        | ALREADY INITIALIZED
        */

        $alreadyInitialized++;
    }

    return response()->json([
        'success' => true,
        'total_subscriptions' => $subscriptions->count(),
        'created_accounts' => $createdAccounts,
        'initialized_existing_accounts' => $initializedExistingAccounts,
        'already_initialized' => $alreadyInitialized,
        'skipped_count' => count($skipped),
        'skipped' => $skipped,
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
        return app(CustomerDashboardController::class)->index($request);
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

Route::post('/customer-login', [CustomerAuthController::class, 'login'])
    ->middleware('throttle:customer-login')
    ->name('customer.login.proxy');

Route::post('/customer-data', [CustomerDashboardController::class, 'data'])
    ->name('customer.data.proxy');

Route::post('/customer-logout', [CustomerAuthController::class, 'logout'])
    ->name('customer.logout.proxy');

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

Route::get('/daily-report/delivery-pdf/{subscription}', [DailyReportPdfController::class, 'deliveryPdf'])
    ->name('daily-report.delivery-pdf');

Route::get('/daily-report/premium-pdf/{subscription}', [DailyReportPdfController::class, 'premiumPdf'])
    ->name('daily-report.premium-pdf');

/*
|--------------------------------------------------------------------------
| Routes protégées du back-office
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD, UTILISATEURS, ABONNEMENTS
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::resource('users', UserController::class);

    Route::resource('subscriptions', SubscriptionController::class)
        ->except(['show']);

    // Subscription details (read only)
    Route::get('/subscriptions/{subscription}', [SubscriptionDetailsController::class, 'show'])
        ->whereNumber('subscription')
        ->name('subscriptions.show');

    Route::post('/subscriptions/{subscription}/customer-account/reset', [SubscriptionController::class, 'resetCustomerAccount'])
        ->name('subscriptions.customer-account.reset');

    /*
    |--------------------------------------------------------------------------
    | PRODUITS (packs de recettes)
    |--------------------------------------------------------------------------
    |
    | products.index / create / store / edit / update / destroy
    | + products.toggle-active (activer / désactiver depuis la liste)
    |
    */

    Route::resource('products', ProductController::class)
        ->except(['show']);

    Route::patch('/products/{product}/toggle-active', [ProductController::class, 'toggleActive'])
        ->name('products.toggle-active');

    // Media library of the product form: upload one file (image / video)
    Route::post('/products-media/upload', [ProductController::class, 'uploadMedia'])
        ->name('products.media.upload');

    // Copy of a product (inactive, -COPY code). PUT accepted: button of the edit form
    Route::match(['post', 'put'], '/products/{product}/duplicate', [ProductController::class, 'duplicate'])
        ->name('products.duplicate');

    /*
    |--------------------------------------------------------------------------
    | ACHATS (purchases)
    |--------------------------------------------------------------------------
    |
    | Pas de suppression : un achat est annulé ou remboursé (historique).
    |
    */

    Route::resource('purchases', PurchaseController::class)
        ->except(['destroy']);

    Route::post('/purchases/{purchase}/status', [PurchaseController::class, 'updateStatus'])
        ->name('purchases.status');

    // New purchases from the app: "Mark all as seen"
    Route::post('/purchases-app-seen', [PurchaseController::class, 'markAppSeen'])
        ->name('purchases.app-seen');

    // PREMIUM-... pack: assign the custom product of the customer
    Route::post('/purchases/{purchase}/premium-product', [\App\Http\Controllers\PurchasePremiumController::class, 'assignProduct'])
        ->name('purchases.premium-product');

    /*
    |--------------------------------------------------------------------------
    | PET LOYALTY PROGRAM (points per pet)
    |--------------------------------------------------------------------------
    |
    | Permissions: pet_loyalty.view / .adjust / .recalculate / .settings
    |
    */

    Route::get('/pet-loyalty', [PetLoyaltyController::class, 'index'])
        ->name('pet-loyalty.index');

    Route::get('/pet-loyalty/customers/{subscription}', [PetLoyaltyController::class, 'show'])
        ->whereNumber('subscription')
        ->name('pet-loyalty.show');

    Route::post('/pet-loyalty/settings', [PetLoyaltyController::class, 'updateSettings'])
        ->name('pet-loyalty.settings');

    Route::post('/pet-loyalty/recalculate', [PetLoyaltyController::class, 'recalculate'])
        ->name('pet-loyalty.recalculate');

    Route::post('/pet-loyalty/pets/{animal}/adjust', [PetLoyaltyController::class, 'adjust'])
        ->whereNumber('animal')
        ->name('pet-loyalty.adjust');

    Route::delete('/pet-loyalty/transactions/{transaction}', [PetLoyaltyController::class, 'destroyAdjustment'])
        ->whereNumber('transaction')
        ->name('pet-loyalty.adjust.destroy');

    /*
    |--------------------------------------------------------------------------
    | AUTO SUBSCRIPTION (Shopify orders imported by webhook)
    |--------------------------------------------------------------------------
    */

    Route::get('/auto-subscriptions', [AutoSubscriptionController::class, 'index'])
        ->name('auto-subscriptions.index');

    // "Refresh": Shopify orders whose webhook was not received
    Route::post('/auto-subscriptions-sync', [AutoSubscriptionController::class, 'sync'])
        ->name('auto-subscriptions.sync');

    // TEMPORARY test page (remove these 2 routes after the tests)
    Route::get('/auto-subscriptions-test', [ShopifyOrderTestController::class, 'form'])
        ->name('auto-subscriptions.test');

    Route::post('/auto-subscriptions-test', [ShopifyOrderTestController::class, 'run'])
        ->name('auto-subscriptions.test.run');

    Route::get('/auto-subscriptions/{shopifyOrder}', [AutoSubscriptionController::class, 'show'])
        ->name('auto-subscriptions.show');

    Route::post('/auto-subscriptions/{shopifyOrder}/reprocess', [AutoSubscriptionController::class, 'reprocess'])
        ->name('auto-subscriptions.reprocess');

    Route::post('/auto-subscriptions/{shopifyOrder}/review', [AutoSubscriptionController::class, 'review'])
        ->name('auto-subscriptions.review');

    Route::post('/auto-subscriptions/{shopifyOrder}/ignore', [AutoSubscriptionController::class, 'ignore'])
        ->name('auto-subscriptions.ignore');

    Route::post('/auto-subscriptions/{shopifyOrder}/purchases/{purchase}/replan', [AutoSubscriptionController::class, 'replan'])
        ->name('auto-subscriptions.replan');

    Route::post('/auto-subscriptions/{shopifyOrder}/purchases/{purchase}/deliveries', [AutoSubscriptionController::class, 'saveDeliveries'])
        ->name('auto-subscriptions.deliveries');

    // Staff corrections before validating: customer, pets, pack -> other pet
    Route::post('/auto-subscriptions/{shopifyOrder}/customer', [AutoSubscriptionController::class, 'updateCustomer'])
        ->name('auto-subscriptions.customer');

    Route::post('/auto-subscriptions/{shopifyOrder}/pets', [AutoSubscriptionController::class, 'storePet'])
        ->name('auto-subscriptions.pets.store');

    Route::post('/auto-subscriptions/{shopifyOrder}/pets/{animal}', [AutoSubscriptionController::class, 'updatePet'])
        ->whereNumber('animal')
        ->name('auto-subscriptions.pets.update');

    Route::post('/auto-subscriptions/{shopifyOrder}/pets/{animal}/delete', [AutoSubscriptionController::class, 'destroyPet'])
        ->whereNumber('animal')
        ->name('auto-subscriptions.pets.destroy');

    Route::post('/auto-subscriptions/{shopifyOrder}/purchases/{purchase}/pet', [AutoSubscriptionController::class, 'assignPet'])
        ->name('auto-subscriptions.pet');

    /*
    |--------------------------------------------------------------------------
    | INGRÉDIENTS & RECETTES
    |--------------------------------------------------------------------------
    */

    Route::get('/admin/ingredients', [IngredientController::class, 'index'])
        ->name('admin.ingredients.index');

    Route::get('/admin/recipes', [RecipeController::class, 'index'])
        ->name('admin.recipes.index');

    Route::resource('ingredients', IngredientController::class);

    Route::resource('recipes', RecipeController::class);

    Route::resource('recipe-types', RecipeTypeController::class)
        ->except(['show']);

    /*
    |--------------------------------------------------------------------------
    | PROVINCES & ZONES (libellés : name = anglais, name_ar = arabe)
    |--------------------------------------------------------------------------
    |
    | - Ressource "provinces" conservée pour les anciens noms de routes
    |   (provinces.index, provinces.edit, ...) sans la route "show"
    |   qui n'existe pas dans le contrôleur.
    | - Les anciennes routes vers addZone / deleteZone (méthodes inexistantes)
    |   sont supprimées ou redirigées vers storeZone.
    | - Ajout de la route de modification d'une zone (updateZone).
    |
    */

    Route::resource('provinces', ProvinceController::class)
        ->except(['show']);

    // Ancien nom de route conservé, pointe désormais vers la bonne méthode.
    Route::post('/provinces/{province}/zones', [ProvinceController::class, 'storeZone'])
        ->name('provinces.zones.store');

    Route::get('/provinces-zones', [ProvinceController::class, 'index'])
        ->name('provinces-zones.index');

    Route::get('/provinces-zones/create', [ProvinceController::class, 'create'])
        ->name('provinces-zones.create');

    Route::post('/provinces-zones', [ProvinceController::class, 'store'])
        ->name('provinces-zones.store');

    Route::get('/provinces-zones/{province}/edit', [ProvinceController::class, 'edit'])
        ->name('provinces-zones.edit');

    Route::put('/provinces-zones/{province}', [ProvinceController::class, 'update'])
        ->name('provinces-zones.update');

    Route::delete('/provinces-zones/{province}', [ProvinceController::class, 'destroy'])
        ->name('provinces-zones.destroy');

    Route::post('/provinces-zones/{province}/zones', [ProvinceController::class, 'storeZone'])
        ->name('provinces-zones.zones.store');

    Route::put('/provinces-zones/{province}/zones/{zone}', [ProvinceController::class, 'updateZone'])
        ->name('provinces-zones.zones.update');

    Route::delete('/provinces-zones/{province}/zones/{zone}', [ProvinceController::class, 'destroyZone'])
        ->name('provinces-zones.zones.destroy');

    /*
    |--------------------------------------------------------------------------
    | PARAMÈTRES
    |--------------------------------------------------------------------------
    */

    Route::get('/settings', [SettingsController::class, 'index'])
        ->name('settings.index');

    Route::get('/settings/edit', [SettingsController::class, 'edit'])
        ->name('settings.edit');

    Route::put('/settings', [SettingsController::class, 'update'])
        ->name('settings.update');

    /*
    |--------------------------------------------------------------------------
    | RAPPORT JOURNALIER & PDF
    |--------------------------------------------------------------------------
    */

    Route::get('/daily-report', [DailyReportController::class, 'index'])
        ->name('daily-report.index');

    Route::post('/daily-report/planned-orders/{plannedOrder}/mark-prepared', [DailyReportController::class, 'markPrepared'])
        ->name('daily-report.planned-orders.mark-prepared');

    /*
    |--------------------------------------------------------------------------
    | ORDERS (history + JAAK delivery)
    |--------------------------------------------------------------------------
    */

    Route::get('/orders', [OrdersController::class, 'index'])
        ->name('orders.index');

    Route::post('/orders/jaak/request-drivers', [OrdersController::class, 'requestDrivers'])
        ->name('orders.jaak.request-drivers');

    Route::post('/orders/jaak/sync', [OrdersController::class, 'sync'])
        ->name('orders.jaak.sync');

    Route::get('/orders/jaak/check', [OrdersController::class, 'check'])
        ->name('orders.jaak.check');

    Route::get('/orders/{plannedOrder}', [OrdersController::class, 'show'])
        ->whereNumber('plannedOrder')
        ->name('orders.show');

    Route::post('/orders/{plannedOrder}/jaak/send', [OrdersController::class, 'send'])
        ->whereNumber('plannedOrder')
        ->name('orders.jaak.send');

    Route::get('/daily-report/new-delivery-pdf/{subscription}', [PdfNewDeliveryController::class, 'newDeliveryPdf'])
        ->name('daily-report.new-delivery-pdf');

    Route::get('/daily-report/subscription-qr-promo-pdf/{subscription}', [PdfNewDeliveryController::class, 'subscriptionQrPromoPdf'])
        ->name('daily-report.subscription-qr-promo-pdf');

    Route::get('/daily-report/last-order-renewal-pdf/{subscription}', [PdfNewDeliveryController::class, 'lastOrderRenewalPdf'])
        ->name('daily-report.last-order-renewal-pdf');

    Route::get('/daily-report/last-order-pdf/{subscription}', [PdfNewDeliveryController::class, 'lastOrderPdf'])
        ->name('daily-report.last-order-pdf');

    Route::get('/daily-report/premium-recipes', [PremiumRecipesController::class, 'index'])
        ->name('daily-report.premium-recipes');

    Route::get('/daily-report/full-pdf', [DailyReportPdfController::class, 'dailyReportPdf'])
        ->name('daily-report.full-pdf');

    /*
    |--------------------------------------------------------------------------
    | STOCK
    |--------------------------------------------------------------------------
    */

    Route::get('/stock', [StockController::class, 'index'])
        ->name('stock.index');

    Route::get('/stock/edit', [StockController::class, 'edit'])
        ->name('stock.edit');

    Route::post('/stock', [StockController::class, 'store'])
        ->name('stock.store');

    /*
    |--------------------------------------------------------------------------
    | APP COMMUNICATIONS
    |--------------------------------------------------------------------------
    */

    Route::get('/app-communications', [AppCommunicationController::class, 'index'])
        ->name('app-communications.index');

    Route::get('/app-communications/customers', [AppCommunicationCustomerController::class, 'index'])
        ->name('app-communications.customers.index');

    Route::get('/app-communications/customers/{customer}/activities', [AppCommunicationCustomerActivityController::class, 'index'])
        ->name('app-communications.customers.activities');

    Route::get('/app-communications/activities', [AppCommunicationCustomerActivityController::class, 'index'])
        ->name('app-communications.activities.index');

    // Old notification pages: moved to the "Notifications" menu
    Route::get('/app-communications/notifications', fn (\Illuminate\Http\Request $request) => redirect()->route('notifications.index', $request->query()))
        ->name('app-communications.notifications.index');

    Route::get('/app-communications/notifications/create', fn (\Illuminate\Http\Request $request) => redirect()->route('notifications.create', $request->query()))
        ->name('app-communications.notifications.create');

    Route::post('/app-communications/notifications', fn () => redirect()->route('notifications.create'))
        ->name('app-communications.notifications.store');

    Route::post('/app-communications/notifications/{notification}/send', fn ($notification) => redirect()->route('notifications.show', $notification))
        ->name('app-communications.notifications.send');

    Route::get('/app-communications/notifications/{notification}', fn ($notification) => redirect()->route('notifications.show', $notification))
        ->name('app-communications.notifications.show');

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS (automatic + manual, scheduled)
    |--------------------------------------------------------------------------
    */

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    Route::get('/notifications/create', [NotificationController::class, 'create'])
        ->name('notifications.create');

    Route::post('/notifications', [NotificationController::class, 'store'])
        ->name('notifications.store');

    Route::get('/notifications-customers', [NotificationController::class, 'customers'])
        ->name('notifications.customers');

    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])
        ->whereNumber('notification')
        ->name('notifications.show');

    Route::get('/notifications/{notification}/edit', [NotificationController::class, 'edit'])
        ->whereNumber('notification')
        ->name('notifications.edit');

    Route::put('/notifications/{notification}', [NotificationController::class, 'update'])
        ->whereNumber('notification')
        ->name('notifications.update');

    Route::post('/notifications/{notification}/cancel', [NotificationController::class, 'cancel'])
        ->whereNumber('notification')
        ->name('notifications.cancel');

    Route::post('/notifications/{notification}/restore', [NotificationController::class, 'restore'])
        ->whereNumber('notification')
        ->name('notifications.restore');

    Route::post('/notifications/{notification}/send-now', [NotificationController::class, 'sendNow'])
        ->whereNumber('notification')
        ->name('notifications.send-now');

    Route::delete('/notifications/{notification}/recipients/{recipient}', [NotificationController::class, 'removeRecipient'])
        ->whereNumber('notification')
        ->name('notifications.recipients.destroy');

    Route::get('/notification-types', [NotificationTypeController::class, 'index'])
        ->name('notification-types.index');

    Route::put('/notification-types/{key}', [NotificationTypeController::class, 'update'])
        ->where('key', '[a-z0-9_]+')
        ->name('notification-types.update');

    Route::post('/notification-types/plan-reminders', [NotificationTypeController::class, 'planReminders'])
        ->name('notification-types.plan-reminders');

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
        ->whereNumber('ticket')
        ->name('support-tickets.show');

    Route::get('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'messages'])
        ->whereNumber('ticket')
        ->name('support-tickets.messages');

    Route::post('/support-tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])
        ->whereNumber('ticket')
        ->name('support-tickets.reply');

    Route::post('/support-tickets/{ticket}/status', [SupportTicketController::class, 'updateStatus'])
        ->whereNumber('ticket')
        ->name('support-tickets.status');
});
