<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PlannedOrder;
use Carbon\Carbon;

class MarkOverduePlannedOrders extends Command
{
    protected $signature = 'orders:mark-overdue';
    protected $description = 'Mark planned orders as overdue when scheduled_for is in the past';

    public function handle()
    {
        $today = Carbon::today()->toDateString();

        PlannedOrder::where('status', 'planned')
            ->where('scheduled_for', '<', $today)
            ->update(['status' => 'overdue']);

        return 0;
    }
}
