<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\CustomerAppActivityController;
use App\Http\Controllers\MobileNotificationController;
use App\Http\Controllers\MobileDeviceController;
use App\Http\Controllers\MobileNotificationDismissController;
use App\Http\Controllers\MobilePetController;
use App\Http\Controllers\MobileCustomerSettingsController;
use App\Http\Controllers\MobileSupportTicketController;

/*
|--------------------------------------------------------------------------
| MOBILE CUSTOMER API
|--------------------------------------------------------------------------
*/

Route::prefix('mobile')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | APP VERSION CHECK
    |--------------------------------------------------------------------------
    */

    Route::post('/app-version', function () {

        $latestVersion = '1.0.27';
        $minimumVersion = '1.0.27';
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

    Route::post(
        '/login',
        [
            CustomerAuthController::class,
            'login'
        ]
    )->middleware('throttle:customer-login');

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER DASHBOARD DATA
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/data',
        [
            CustomerDashboardController::class,
            'data'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | CHANGE PASSWORD
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/change-password',
        [
            CustomerAuthController::class,
            'changePassword'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER SETTINGS
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/settings',
        [
            MobileCustomerSettingsController::class,
            'show'
        ]
    );

    Route::post(
        '/settings/update',
        [
            MobileCustomerSettingsController::class,
            'updateSettings'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | LANGUAGE
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/settings/language',
        [
            MobileCustomerSettingsController::class,
            'updateLanguage'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | SETTINGS - CHANGE PASSWORD
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/settings/password',
        [
            MobileCustomerSettingsController::class,
            'updatePassword'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | SETTINGS - PHONE
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/settings/phone',
        [
            MobileCustomerSettingsController::class,
            'updatePhone'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | SETTINGS - EMAIL
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/settings/email',
        [
            MobileCustomerSettingsController::class,
            'updateEmail'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER ADDRESSES
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/settings/addresses',
        [
            MobileCustomerSettingsController::class,
            'addresses'
        ]
    );

    Route::post(
        '/settings/addresses/create',
        [
            MobileCustomerSettingsController::class,
            'storeAddress'
        ]
    );

    Route::post(
        '/settings/addresses/{address}/update',
        [
            MobileCustomerSettingsController::class,
            'updateAddress'
        ]
    );

    Route::post(
        '/settings/addresses/{address}/default',
        [
            MobileCustomerSettingsController::class,
            'setDefaultAddress'
        ]
    );

    Route::post(
        '/settings/addresses/{address}/delete',
        [
            MobileCustomerSettingsController::class,
            'destroyAddress'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | APP ACTIVITY TIME TRACKING
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/activity',
        [
            CustomerAuthController::class,
            'activity'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER BUSINESS ACTIVITY HISTORY
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/activities',
        [
            CustomerAppActivityController::class,
            'index'
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | PETS
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/pets',
        [
            MobilePetController::class,
            'store'
        ]
    );

    Route::post(
        '/pets/{pet}/update',
        [
            MobilePetController::class,
            'update'
        ]
    );

    Route::post(
        '/pets/{pet}/photo',
        [
            MobilePetController::class,
            'updatePhoto'
        ]
    );

    Route::post(
        '/pets/{pet}/delete',
        [
            MobilePetController::class,
            'destroy'
        ]
    );

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

            /*
            | LIST CUSTOMER TICKETS
            */

            Route::post(
                '/',
                [
                    MobileSupportTicketController::class,
                    'index'
                ]
            );

            /*
            | CREATE SUPPORT TICKET
            */

            Route::post(
                '/create',
                [
                    MobileSupportTicketController::class,
                    'store'
                ]
            );

            /*
            | VIEW TICKET AND CONVERSATION
            */

            Route::post(
                '/{ticket}',
                [
                    MobileSupportTicketController::class,
                    'show'
                ]
            )->whereNumber('ticket');

            /*
            | SEND MESSAGE
            */

            Route::post(
                '/{ticket}/reply',
                [
                    MobileSupportTicketController::class,
                    'reply'
                ]
            )->whereNumber('ticket');

            /*
            | CLOSE TICKET
            */

            Route::post(
                '/{ticket}/close',
                [
                    MobileSupportTicketController::class,
                    'close'
                ]
            )->whereNumber('ticket');

        });

    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/logout',
        [
            CustomerAuthController::class,
            'logout'
        ]
    );

});

/*
|--------------------------------------------------------------------------
| MOBILE DEVICES
|--------------------------------------------------------------------------
*/

Route::post(
    '/mobile/device/detach-customer',
    [
        MobileDeviceController::class,
        'detachCustomer'
    ]
);

Route::post(
    '/mobile/device/register',
    [
        MobileDeviceController::class,
        'register'
    ]
);

Route::post(
    '/mobile/device/unregister',
    [
        MobileDeviceController::class,
        'unregister'
    ]
);

/*
|--------------------------------------------------------------------------
| MOBILE NOTIFICATIONS
|--------------------------------------------------------------------------
*/

Route::post(
    '/mobile/notifications',
    [
        MobileNotificationController::class,
        'index'
    ]
);

Route::post(
    '/mobile/notifications/unread-count',
    [
        MobileNotificationController::class,
        'unreadCount'
    ]
);

Route::post(
    '/mobile/notifications/{notification}/view',
    [
        MobileNotificationController::class,
        'markViewed'
    ]
);

Route::post(
    '/mobile/notifications/{notification}/dismiss',
    [
        MobileNotificationDismissController::class,
        'dismiss'
    ]
);

/*
|--------------------------------------------------------------------------
| NOTIFICATION SENDING
|--------------------------------------------------------------------------
*/

Route::post(
    '/notifications/{notification}/send',
    [
        MobileNotificationController::class,
        'send'
    ]
);