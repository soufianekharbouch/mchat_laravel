<?php

namespace App\Console\Commands;

use App\Services\ShopifyOrderSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Same as the "Refresh" button of Auto subscription:
 * php artisan shopify:sync-orders
 * (optional: every 30 minutes in routes/console.php)
 */
class ShopifySyncOrders extends Command
{
    protected $signature = 'shopify:sync-orders';

    protected $description = 'Import the Shopify orders whose webhook was not received (since the start of the auto subscription only).';

    public function handle(ShopifyOrderSync $sync): int
    {
        try {
            $result = $sync->run();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Since {$result['from']->format('Y-m-d')}: {$result['checked']} checked, {$result['created']} imported, {$result['updated']} processed again.");

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}