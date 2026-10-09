<?php

namespace App\Http\Controllers;

use App\Models\CustomerServiceReport;
use App\Models\Subscription;
use Illuminate\Http\Request;

class CustomerServiceController extends Controller
{
    public function index(Request $request)
    {
        $mode = $request->get('mode', 'reports');

        if ($mode === 'customers') {
            $customers = Subscription::query()
                ->withCount('customerServiceReports')
                ->orderByDesc('customer_service_reports_count')
                ->get();

            return view('customer_service.customers', compact('customers'));
        }

        $reports = CustomerServiceReport::query()
            ->with(['user', 'subscription'])
            ->orderBy('contact_date', 'desc')
            ->orderBy('contact_time', 'desc')
            ->get();

        return view('customer_service.reports', compact('reports'));
    }

    public function customer(Subscription $subscription)
    {
        $subscription->load([
            'customerServiceReports.user',
        ]);

        return view('customer_service.customer_show', compact('subscription'));
    }
}
