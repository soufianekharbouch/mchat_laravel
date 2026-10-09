<?php

namespace App\Http\Controllers;

use App\Models\CustomerServiceReport;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerServiceReportController extends Controller
{
    public function create(Request $request)
    {
        $subscriptions = Subscription::orderBy('code')->get();
        $selectedSubscriptionId = $request->get('subscription_id');

        return view('customer_service.report_form', compact('subscriptions', 'selectedSubscriptionId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'integer', 'exists:subscriptions,id'],
            'contact_date' => ['required', 'date'],
            'contact_time' => ['required', 'date_format:H:i'],
            'subject' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'channel' => ['required', 'string', 'max:50'],
        ]);

        $data['user_id'] = Auth::id();

        CustomerServiceReport::create($data);

        return redirect()->route('customer-service.index', ['mode' => 'reports'])->with('success', 'Report created.');
    }

    public function edit(CustomerServiceReport $report)
    {
        $subscriptions = Subscription::orderBy('code')->get();
        return view('customer_service.report_form', compact('report', 'subscriptions'));
    }

    public function update(Request $request, CustomerServiceReport $report)
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'integer', 'exists:subscriptions,id'],
            'contact_date' => ['required', 'date'],
            'contact_time' => ['required', 'date_format:H:i'],
            'subject' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'channel' => ['required', 'string', 'max:50'],
        ]);

        $report->update($data);

        return redirect()->route('customer-service.index', ['mode' => 'reports'])->with('success', 'Report updated.');
    }

    public function destroy(CustomerServiceReport $report)
    {
        $report->delete();
        return redirect()->route('customer-service.index', ['mode' => 'reports'])->with('success', 'Report deleted.');
    }
}
