<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function ingredients()
    {
        return $this->hasMany(
            Ingredient::class,
            'created_by'
        );
    }

    public function recipes()
    {
        return $this->hasMany(
            Recipe::class,
            'created_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Root user
    |--------------------------------------------------------------------------
    */

    public function isRootUser(): bool
    {
        return $this->username === 'root';
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions configuration
    |--------------------------------------------------------------------------
    */

    public static function permissionGroups(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Settings
            |--------------------------------------------------------------------------
            */

            'settings' => [
                'title' => 'Settings',
                'description' =>
                    'View and manage application settings.',

                'permissions' => [
                    'settings.view' =>
                        'View settings',

                    'settings.update' =>
                        'Edit settings',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Users
            |--------------------------------------------------------------------------
            */

            'users' => [
                'title' => 'Users',
                'description' =>
                    'View and manage user accounts.',

                'permissions' => [
                    'users.view' =>
                        'View users',

                    'users.create' =>
                        'Add users',

                    'users.update' =>
                        'Edit users',

                    'users.delete' =>
                        'Delete users',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Recipe Types
            |--------------------------------------------------------------------------
            */

            'recipe_types' => [
                'title' => 'Recipe Types',
                'description' =>
                    'View and manage recipe types.',

                'permissions' => [
                    'recipe_types.view' =>
                        'View recipe types',

                    'recipe_types.create' =>
                        'Add recipe types',

                    'recipe_types.update' =>
                        'Edit recipe types',

                    'recipe_types.delete' =>
                        'Delete recipe types',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Ingredients
            |--------------------------------------------------------------------------
            */

            'ingredients' => [
                'title' => 'Ingredients',
                'description' =>
                    'View and manage recipe ingredients.',

                'permissions' => [
                    'ingredients.view' =>
                        'View ingredients',

                    'ingredients.create' =>
                        'Add ingredients',

                    'ingredients.update' =>
                        'Edit ingredients',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Recipes
            |--------------------------------------------------------------------------
            */

            'recipes' => [
                'title' => 'Recipes',
                'description' =>
                    'View and manage recipes.',

                'permissions' => [
                    'recipes.view' =>
                        'View recipes',

                    'recipes.create' =>
                        'Add recipes',

                    'recipes.update' =>
                        'Edit recipes',

                    'recipes.delete' =>
                        'Delete recipes',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Provinces / Zones
            |--------------------------------------------------------------------------
            */

            'zones' => [
                'title' => 'Provinces and Zones',

                'description' =>
                    'View and manage provinces and delivery zones.',

                'permissions' => [
                    'zones.view' =>
                        'View provinces and zones',

                    'zones.create' =>
                        'Add provinces',

                    'zones.update' =>
                        'Edit provinces',

                    'zones.delete' =>
                        'Delete provinces',

                    'zones.add' =>
                        'Add zones',

                    'zones.remove' =>
                        'Delete zones',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Subscriptions
            |--------------------------------------------------------------------------
            */

            'subscriptions' => [
                'title' => 'Subscriptions',

                'description' =>
                    'View and manage customer subscriptions.',

                'permissions' => [
                    'subscriptions.view' =>
                        'View subscriptions',

                    'subscriptions.create' =>
                        'Add subscriptions',

                    'subscriptions.update' =>
                        'Edit subscriptions',

                    'subscriptions.delete' =>
                        'Delete subscriptions',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Daily Reports
            |--------------------------------------------------------------------------
            */

            'daily_reports' => [
                'title' => 'Daily Reports',

                'description' =>
                    'View and process daily preparation reports.',

                'permissions' => [
                    'daily_reports.view' =>
                        'View daily reports',

                    'daily_reports.mark_prepared' =>
                        'Mark orders as prepared',

                    'daily_reports.generate_pdf' =>
                        'Generate PDF reports',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Loyalty Program
            |--------------------------------------------------------------------------
            */

            'loyalty' => [
                'title' => 'Loyalty Program',

                'description' =>
                    'View and manage customer loyalty points.',

                'permissions' => [
                    'loyalty.view' =>
                        'View loyalty program',

                    'loyalty.update' =>
                        'Manage loyalty points',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Stock
            |--------------------------------------------------------------------------
            */

            'stock' => [
                'title' => 'Stock Management',

                'description' =>
                    'View and manage product stock.',

                'permissions' => [
                    'stock.view' =>
                        'View stock',

                    'stock.update' =>
                        'Update stock',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | App Communications
            |--------------------------------------------------------------------------
            |
            | Communication between administration/customer service
            | and customers using the Maison Chat mobile application.
            |
            */

            'app_communications' => [
                'title' => 'App Communications',

                'description' =>
                    'Manage mobile app communications, push notifications, '
                    . 'customer messages, devices and automated alerts.',

                'permissions' => [

                    /*
                     * Main module access
                     */

                    'app_communications.view' =>
                        'View app communications',

                    /*
                     * Push notifications
                     */

                    'app_communications.notifications.view' =>
                        'View notifications',

                    'app_communications.notifications.send' =>
                        'Create and send notifications',

                    /*
                     * Customer messages
                     */

                    'app_communications.messages.view' =>
                        'View customer messages',

                    'app_communications.messages.send' =>
                        'Send messages to customers',

                    /*
                     * Customers / devices
                     */

                    'app_communications.customers.view' =>
                        'View app customers and devices',

                    /*
                     * Automated subscription alerts
                     */

                    'app_communications.subscription_alerts' =>
                        'Manage subscription alerts',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Customer Service
            |--------------------------------------------------------------------------
            */

            /*
            |--------------------------------------------------------------------------
            | Support Tickets
            |--------------------------------------------------------------------------
            */

            'support_tickets' => [
                'title' => 'Support Tickets',
                'description' =>
                    'View customer support tickets, reply and manage their status.',
                'permissions' => [
                    'support_tickets.view' => 'View support tickets',
                    'support_tickets.reply' => 'Reply to support tickets',
                    'support_tickets.update_status' => 'Update ticket status',
                    'support_tickets.view_history' => 'View ticket activity history',
                ],
            ],

            'customer_service' => [
                'title' => 'Customer Service',

                'description' =>
                    'View customers and manage service reports.',

                'permissions' => [
                    'customer_service.view' =>
                        'View customer service',

                    'customer_service.create' =>
                        'Add customer service reports',

                    'customer_service.update' =>
                        'Edit customer service reports',

                    'customer_service.delete' =>
                        'Delete customer service reports',
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Available permissions
    |--------------------------------------------------------------------------
    */

    /**
     * Return all valid permissions.
     */
    public static function availablePermissions(): array
    {
        $permissions = [];

        foreach (
            self::permissionGroups()
            as $group
        ) {
            $permissions = array_merge(
                $permissions,
                array_keys(
                    $group['permissions']
                )
            );
        }

        return array_values(
            array_unique(
                $permissions
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Permission checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check whether the user has a permission.
     */
    public function hasPermission(
        string $permission
    ): bool {

        /*
         * Root always has every permission.
         */

        if ($this->isRootUser()) {
            return true;
        }

        /*
         * Compatibility with users created
         * before the permissions system.
         *
         * permissions = NULL means legacy full access.
         */

        if (is_null($this->permissions)) {
            return true;
        }

        return in_array(
            $permission,
            $this->permissions,
            true
        );
    }

    /**
     * Check whether the user has at least
     * one permission from the supplied list.
     */
    public function hasAnyPermission(
        array $permissions
    ): bool {

        foreach (
            $permissions
            as $permission
        ) {
            if (
                $this->hasPermission(
                    $permission
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the user has all
     * supplied permissions.
     */
    public function hasAllPermissions(
        array $permissions
    ): bool {

        foreach (
            $permissions
            as $permission
        ) {
            if (
                !$this->hasPermission(
                    $permission
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return number of assigned permissions.
     */
    public function permissionsCount(): int
    {
        if (
            $this->isRootUser()
            || is_null(
                $this->permissions
            )
        ) {
            return count(
                self::availablePermissions()
            );
        }

        return count(
            $this->permissions
        );
    }

    /**
     * Existing users created before the
     * permissions system retain full access
     * until their account is edited.
     */
    public function hasLegacyFullAccess(): bool
    {
        return !$this->isRootUser()
            && is_null(
                $this->permissions
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Created content
    |--------------------------------------------------------------------------
    */

    public function hasCreatedContent(): bool
    {
        return $this
                ->ingredients()
                ->exists()
            ||
            $this
                ->recipes()
                ->exists();
    }

    public function getIngredientsCount(): int
    {
        return $this
            ->ingredients()
            ->count();
    }

    public function getRecipesCount(): int
    {
        return $this
            ->recipes()
            ->count();
    }
}