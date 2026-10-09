@extends('layouts.app')

@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-users me-2"></i>Customer Service - By Customer</h3>

        <a href="{{ route('customer-service.index') }}" class="btn btn-outline-secondary">
            View Reports
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">

            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Subscription Code</th>
                        <th>Customer Name</th>
                        <th>Phone</th>
                        <th>Reports Count</th>
                        <th width="120">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($customers as $customer)
                    <tr>
                        <td>{{ $customer->code }}</td>
                        <td>{{ $customer->subscriber_first_name }} {{ $customer->subscriber_last_name }}</td>
                        <td>{{ $customer->subscriber_phone }}</td>
                        <td>{{ $customer->customer_service_reports_count }}</td>
                        <td>
                            <a href="{{ route('customer-service.customer', $customer) }}"
                               class="btn btn-sm btn-mauve">
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted">
                            No customers found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

        </div>
    </div>

</div>
@endsection
