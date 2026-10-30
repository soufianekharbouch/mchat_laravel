<?php

namespace App\Console\Commands;

use App\Services\JaakClient;
use App\Services\JaakDispatcher;
use Illuminate\Console\Command;

/**
 * JAAK has no webhook: every 10 minutes the open deliveries are read
 * (GET /tasks?external_id=...) and the daily report orders follow:
 * PICKUP / DROPOFF -> shipped, DELIVERED -> delivered.
 * Orders not sent because of a network error are retried too.
 */
class JaakSyncStatuses extends Command
{
    protected $signature = 'jaak:sync {--check : only test the API key (GET /me)}';

    protected $description = 'Sync JAAK delivery statuses and retry unsent orders';

    public function handle(JaakDispatcher $dispatcher, JaakClient $client): int
    {
        if (!JaakClient::enabled()) {
            $this->warn('JAAK disabled: set JAAK_ENABLED=true and JAAK_API_KEY in .env.');

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            try {
                $me = $client->me();
                $this->info('JAAK key OK. user_id=' . ($me['user_id'] ?? '?') . ' org_id=' . ($me['org_id'] ?? '?'));

                return self::SUCCESS;
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $retried = $dispatcher->retryQueued();
        $result = $dispatcher->sync();

        $this->info("JAAK: {$result['checked']} checked, {$result['changed']} changed, {$retried} retried.");

        if ($result['error']) {
            $this->error($result['error']);
        }

        return self::SUCCESS;
    }
}
