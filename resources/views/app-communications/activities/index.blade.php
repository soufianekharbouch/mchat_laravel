@extends('layouts.app')

@section('title', 'Customer Activities')

@section('content')

<div class="container-fluid py-4">

    {{-- HEADER --}}
    <div
        class="
            d-flex
            flex-column
            flex-lg-row
            justify-content-between
            align-items-lg-center
            gap-3
            mb-4
        "
    >

        <div>

            <a
                href="{{ route('app-communications.index') }}"
                class="text-decoration-none small"
            >
                <i class="fas fa-arrow-left me-1"></i>
                App Communications
            </a>

            <h2 class="mb-1 mt-2">

                <i class="fas fa-clock-rotate-left text-warning me-2"></i>

                Customer Activities

            </h2>

            <p class="text-muted mb-0">

                Review operations performed by customers in the mobile app.

            </p>

        </div>


        <span class="badge bg-light text-dark border p-2">

            {{ number_format($activities->total()) }}
            activities

        </span>

    </div>


    {{-- FILTERS --}}
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <form
                method="GET"
                action="{{ route('app-communications.activities.index') }}"
                class="row g-3 align-items-end"
            >

                <div class="col-12 col-lg-3">

                    <label class="form-label">
                        Search customer
                    </label>

                    <input
                        type="text"
                        name="search"
                        value="{{ request('search') }}"
                        class="form-control"
                        placeholder="Name or phone..."
                    >

                </div>


                <div class="col-12 col-lg-3">

                    <label class="form-label">
                        Customer
                    </label>

                    <select
                        name="customer_id"
                        class="form-select"
                    >

                        <option value="">
                            All customers
                        </option>

                        @foreach($customers as $customer)

                            @php
                                $subscription =
                                    $customer->subscription;

                                $customerName =
                                    trim(
                                        (
                                            $subscription->subscriber_first_name
                                            ?? ''
                                        )
                                        . ' '
                                        . (
                                            $subscription->subscriber_last_name
                                            ?? ''
                                        )
                                    );

                                $customerPhone =
                                    $subscription->subscriber_phone
                                    ?? $customer->phone;
                            @endphp

                            <option
                                value="{{ $customer->id }}"
                                {{
                                    (string) request('customer_id')
                                    ===
                                    (string) $customer->id
                                        ? 'selected'
                                        : ''
                                }}
                            >

                                {{
                                    $customerName !== ''
                                        ? $customerName
                                        : 'Customer #' . $customer->id
                                }}

                                @if($customerPhone)
                                    · {{ $customerPhone }}
                                @endif

                            </option>

                        @endforeach

                    </select>

                </div>


                <div class="col-12 col-lg-2">

                    <label class="form-label">
                        Operation
                    </label>

                    <select
                        name="code"
                        class="form-select"
                    >

                        <option value="">
                            All operations
                        </option>

                        @foreach($activityCodes as $activityCode)

                            <option
                                value="{{ $activityCode }}"
                                {{
                                    request('code') === $activityCode
                                        ? 'selected'
                                        : ''
                                }}
                            >
                                {{ str_replace('_', ' ', $activityCode) }}
                            </option>

                        @endforeach

                    </select>

                </div>


                <div class="col-6 col-lg-2">

                    <label class="form-label">
                        From
                    </label>

                    <input
                        type="date"
                        name="date_from"
                        value="{{ request('date_from') }}"
                        class="form-control"
                    >

                </div>


                <div class="col-6 col-lg-2">

                    <label class="form-label">
                        To
                    </label>

                    <input
                        type="date"
                        name="date_to"
                        value="{{ request('date_to') }}"
                        class="form-control"
                    >

                </div>


                <div class="col-12">

                    <div class="d-flex gap-2 flex-wrap">

                        <button
                            type="submit"
                            class="btn btn-mauve"
                        >
                            <i class="fas fa-filter me-1"></i>
                            Filter
                        </button>

                        <a
                            href="{{ route('app-communications.activities.index') }}"
                            class="btn btn-outline-secondary"
                        >
                            Reset
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>


    {{-- ACTIVITIES --}}
    <div class="card border-0 shadow-sm">

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">

                        <tr>

                            <th class="ps-3">
                                Customer
                            </th>

                            <th>
                                Operation
                            </th>

                            <th>
                                Details
                            </th>

                            <th>
                                Device
                            </th>

                            <th class="pe-3">
                                Date
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        @forelse($activities as $activity)

                            @php
                                $customer =
                                    $activity->customerAccount;

                                $subscription =
                                    $customer
                                        ? $customer->subscription
                                        : null;

                                $customerName =
                                    $subscription
                                        ? trim(
                                            (
                                                $subscription->subscriber_first_name
                                                ?? ''
                                            )
                                            . ' '
                                            . (
                                                $subscription->subscriber_last_name
                                                ?? ''
                                            )
                                        )
                                        : '';

                                $customerPhone =
                                    $subscription->subscriber_phone
                                    ?? $customer->phone
                                    ?? null;

                                $metadata =
                                    is_array($activity->metadata)
                                        ? $activity->metadata
                                        : [];
                            @endphp

                            <tr>

                                <td class="ps-3">

                                    <div class="fw-semibold">

                                        {{
                                            $customerName !== ''
                                                ? $customerName
                                                : (
                                                    $customer
                                                        ? 'Customer #' . $customer->id
                                                        : 'Unknown customer'
                                                )
                                        }}

                                    </div>

                                    @if($customerPhone)

                                        <div class="small text-muted">

                                            <i class="fas fa-phone me-1"></i>
                                            {{ $customerPhone }}

                                        </div>

                                    @endif

                                </td>


                                <td>

                                    <span class="activity-code-badge">

                                        {{
                                            str_replace(
                                                '_',
                                                ' ',
                                                $activity->code
                                            )
                                        }}

                                    </span>

                                </td>


                                <td>

                                    @if(!empty($metadata))

                                        <div class="activity-metadata">

                                            @foreach($metadata as $key => $value)

                                                @if(
                                                    is_scalar($value)
                                                    ||
                                                    $value === null
                                                )

                                                    <div>

                                                        <span class="text-muted">
                                                            {{
                                                                ucfirst(
                                                                    str_replace(
                                                                        '_',
                                                                        ' ',
                                                                        $key
                                                                    )
                                                                )
                                                            }}:
                                                        </span>

                                                        <strong>
                                                            {{ $value ?? '—' }}
                                                        </strong>

                                                    </div>

                                                @endif

                                            @endforeach

                                        </div>

                                    @else

                                        <span class="text-muted">
                                            —
                                        </span>

                                    @endif

                                </td>


                                <td>

                                    @if($activity->mobileDevice)

                                        <div class="small">

                                            {{
                                                $activity
                                                    ->mobileDevice
                                                    ->device_name
                                                ?? 'Mobile device'
                                            }}

                                        </div>

                                        <div class="small text-muted">

                                            {{
                                                $activity
                                                    ->mobileDevice
                                                    ->platform
                                                ?? ''
                                            }}

                                        </div>

                                    @else

                                        <span class="text-muted">
                                            —
                                        </span>

                                    @endif

                                </td>


                                <td class="pe-3">

                                    <div>
                                        {{ $activity->created_at->format('Y-m-d H:i') }}
                                    </div>

                                    <div class="small text-muted">
                                        {{ $activity->created_at->diffForHumans() }}
                                    </div>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td
                                    colspan="5"
                                    class="text-center text-muted py-5"
                                >

                                    <i
                                        class="
                                            fas
                                            fa-clock-rotate-left
                                            fs-3
                                            d-block
                                            mb-2
                                        "
                                    ></i>

                                    No customer activities found.

                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </div>


        @if($activities->hasPages())

            <div class="card-footer bg-white py-3">

                <div
                    class="
                        d-flex
                        flex-column
                        flex-md-row
                        align-items-center
                        justify-content-between
                        gap-3
                    "
                >

                    <div class="small text-muted">

                        Showing

                        <strong>
                            {{ $activities->firstItem() }}
                        </strong>

                        to

                        <strong>
                            {{ $activities->lastItem() }}
                        </strong>

                        of

                        <strong>
                            {{ $activities->total() }}
                        </strong>

                        activities

                    </div>


                    <div class="activities-pagination">

                        {{
                            $activities
                                ->onEachSide(1)
                                ->links('pagination::bootstrap-5')
                        }}

                    </div>

                </div>

            </div>

        @endif

    </div>

</div>


<style>
    .activity-code-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 9px;
        border-radius: 8px;
        background: #fff7e8;
        color: #9a6500;
        border: 1px solid #f1dfb9;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    .activity-metadata {
        min-width: 190px;
        font-size: 12px;
        line-height: 1.6;
    }

    .activities-pagination .pagination {
        margin: 0;
        gap: 5px;
        flex-wrap: wrap;
    }

    .activities-pagination .page-link {
        min-width: 38px;
        height: 38px;
        padding: 6px 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 7px !important;
        color: #6f42c1;
    }

    .activities-pagination .page-item.active .page-link {
        background: #9b59c6;
        border-color: #9b59c6;
        color: #fff;
    }
</style>

@endsection
