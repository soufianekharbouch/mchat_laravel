@extends('layouts.app')

@section('content')
<div class="container-fluid">

    <div class="mb-3">
        <h3>
            Customer: {{ $subscription->subscriber_first_name }}
            {{ $subscription->subscriber_last_name }}
            ({{ $subscription->code }})
        </h3>

        <a href="{{ route('customer-service.index', ['mode' => 'customers']) }}"
           class="btn btn-outline-secondary mt-2">
            Back
        </a>

        <a href="{{ route('customer-service.reports.create', ['subscription_id' => $subscription->id]) }}"
           class="btn btn-mauve mt-2">
            New Report
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">

            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>User</th>
                        <th>Channel</th>
                        <th>Subject</th>
                        <th>Summary</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($subscription->customerServiceReports as $report)
                    <tr>
                        <td>{{ $report->contact_date->format('Y-m-d') }}</td>
                        <td>{{ $report->contact_time }}</td>
                        <td>{{ $report->user->name }}</td>
                        <td>{{ ucfirst($report->channel) }}</td>
                        <td>{{ $report->subject }}</td>
                        <td>{{ $report->summary }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            No reports for this customer.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

        </div>
    </div>

</div>
@endsection
