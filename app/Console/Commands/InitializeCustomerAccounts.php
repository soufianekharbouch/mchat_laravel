<?php

namespace App\Console\Commands;

use App\Models\CustomerAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class InitializeCustomerAccounts extends Command
{
    protected $signature =
        'customers:initialize-accounts';

    protected $description =
        'Initialize all customer accounts without an initial password';

    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | Find accounts not initialized
        |--------------------------------------------------------------------------
        */

        $accounts =
            CustomerAccount::query()
                ->whereNull(
                    'initial_password_encrypted'
                )
                ->whereNull(
                    'password_changed_at'
                )
                ->get();

        if ($accounts->isEmpty()) {

            $this->info(
                'All customer accounts are already initialized.'
            );

            return self::SUCCESS;
        }

        $this->info(
            $accounts->count()
            . ' customer account(s) will be initialized.'
        );

        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | Initialize accounts
        |--------------------------------------------------------------------------
        */

        foreach ($accounts as $account) {

            $initialPassword =
                (string) random_int(
                    100000,
                    999999
                );

            /*
             * IMPORTANT:
             *
             * Because CustomerAccount already has:
             *
             * 'password' => 'hashed'
             *
             * Laravel will hash the password automatically.
             */

            $account->password =
                $initialPassword;

            $account->initial_password_encrypted =
                Crypt::encryptString(
                    $initialPassword
                );

            $account->password_changed_at =
                null;

            /*
             * Disconnect old sessions because
             * the previous password is replaced.
             */

            $account->access_token_hash =
                null;

            $account->access_token_expires_at =
                null;

            $account->save();

            $this->line(
                'Customer #'
                . $account->id
                . ' | Phone: '
                . $account->phone
                . ' | Initial password: '
                . $initialPassword
            );
        }

        $this->newLine();

        $this->info(
            'Initialization completed successfully.'
        );

        return self::SUCCESS;
    }
}