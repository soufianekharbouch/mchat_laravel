<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\MobileDevice;
use App\Models\MobileNotification;
use App\Models\MobileNotificationRecipient;

class AppCommunicationController extends Controller
{
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | APP INSTALLED
        |--------------------------------------------------------------------------
        |
        | Nombre de devices actifs enregistrés.
        |
        */

        $installedDevices =
            MobileDevice::query()
                ->where(
                    'is_active',
                    true
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | TOTAL CUSTOMERS
        |--------------------------------------------------------------------------
        */

        $totalCustomers =
            CustomerAccount::query()
                ->count();


        /*
        |--------------------------------------------------------------------------
        | CUSTOMERS USING INITIAL PASSWORD
        |--------------------------------------------------------------------------
        |
        | Customer déjà créé / initialisé
        | mais qui n'a pas encore choisi son propre password.
        |
        | On exige aussi qu'il se soit déjà connecté au moins une fois.
        |
        */

        $customersInitialPassword =
            CustomerAccount::query()
                ->whereNull(
                    'password_changed_at'
                )
                ->whereNotNull(
                    'last_login_at'
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | CUSTOMERS USING PERSONAL PASSWORD
        |--------------------------------------------------------------------------
        |
        | Le customer a déjà changé son password initial.
        |
        */

        $customersPersonalPassword =
            CustomerAccount::query()
                ->whereNotNull(
                    'password_changed_at'
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | ACTIVE LAST 1 HOUR
        |--------------------------------------------------------------------------
        |
        | Utilise last_app_activity_at.
        |
        | Le heartbeat React Native met ce champ à jour.
        |
        */

        $activeLastHour =
            CustomerAccount::query()
                ->whereNotNull(
                    'last_app_activity_at'
                )
                ->where(
                    'last_app_activity_at',
                    '>=',
                    now()->subHour()
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATIONS SENT
        |--------------------------------------------------------------------------
        */

        $notificationsSent =
            MobileNotification::query()
                ->where(
                    'status',
                    'sent'
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATIONS VIEWED
        |--------------------------------------------------------------------------
        |
        | Nombre total de recipient rows viewed.
        |
        */

        $notificationsViewed =
            MobileNotificationRecipient::query()
                ->whereNotNull(
                    'viewed_at'
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | UNREAD MESSAGES
        |--------------------------------------------------------------------------
        |
        | Le module messaging n'est pas encore implémenté.
        |
        */

        $unreadMessages =
            0;


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATIONS TO CONFIRM
        |--------------------------------------------------------------------------
        |
        | Pour le moment, on considère comme "à confirmer"
        | les recipients qui ont reçu une notification mais
        | ne l'ont pas encore consultée.
        |
        */

        $notificationsToConfirm =
            MobileNotificationRecipient::query()
                ->whereNull(
                    'viewed_at'
                )
                ->whereNull(
                    'dismissed_at'
                )
                ->whereHas(
                    'notification',
                    function ($query) {

                        $query->where(
                            'status',
                            'sent'
                        );
                    }
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | STATS
        |--------------------------------------------------------------------------
        */

        $stats = [

            'installed_devices' =>
                $installedDevices,

            'customers_initial_password' =>
                $customersInitialPassword,

            'customers_personal_password' =>
                $customersPersonalPassword,

            'active_last_hour' =>
                $activeLastHour,

            'total_customers' =>
                $totalCustomers,

            'unread_messages' =>
                $unreadMessages,

            'notifications_to_confirm' =>
                $notificationsToConfirm,

            'notifications_sent' =>
                $notificationsSent,

            'notifications_viewed' =>
                $notificationsViewed,
        ];


        /*
        |--------------------------------------------------------------------------
        | VIEW
        |--------------------------------------------------------------------------
        |
        | Recent Activity a été supprimé du Blade.
        | Donc recentViews et recentDevices ne sont plus chargés.
        |
        */

        return view(
            'app-communications.index',
            compact(
                'stats'
            )
        );
    }
}