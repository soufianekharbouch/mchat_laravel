@extends('layouts.app')

@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-headset me-2"></i>Customer Service - Reports</h3>

        <div>
            <a href="{{ route('customer-service.index', ['mode' => 'customers']) }}" class="btn btn-outline-secondary me-2">
                View by Customer
            </a>

            <a href="{{ route('customer-service.reports.create') }}" class="btn btn-mauve">
                <i class="fas fa-plus me-1"></i>New Report
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body table-responsive">

            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Customer</th>
                        <th>User</th>
                        <th>Channel</th>
                        <th>Subject</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($reports as $report)
                    <tr>
                        <td>{{ $report->contact_date->format('Y-m-d') }}</td>
                        <td>{{ $report->contact_time }}</td>
                        <td>
                            <a href="{{ route('customer-service.customer', $report->subscription) }}">
                                {{ $report->subscription->code }}
                            </a>
                        </td>
                        <td>{{ $report->user->name }}</td>
                        <td>{{ ucfirst($report->channel) }}</td>
                        <td>{{ $report->subject }}</td>
                        <td>
                            <a href="{{ route('customer-service.reports.edit', $report) }}"
                               class="btn btn-sm btn-warning">
                                Edit
                            </a>

                            <form action="{{ route('customer-service.reports.destroy', $report) }}"
                                  method="POST"
                                  class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger"
                                        onclick="return confirm('Delete this report?')">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            No reports found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

        </div>
    </div>

</div>
@endsection
