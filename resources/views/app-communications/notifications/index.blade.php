@extends('layouts.app')

@section('title', 'Notifications')

@section('content')

<div class="container-fluid py-4">

    {{-- HEADER --}}
    <div
        class="
            d-flex
            flex-column
            flex-md-row
            justify-content-between
            align-items-md-center
            gap-3
            mb-4
        "
    >

        <div>

            <div class="mb-2">

                <a
                    href="{{ route('app-communications.index') }}"
                    class="text-decoration-none text-muted"
                >
                    <i class="fas fa-arrow-left me-1"></i>
                    App Communications
                </a>

            </div>

            <h2 class="mb-1">

                <i class="fas fa-bell text-mauve me-2"></i>

                Notifications

            </h2>

            <p class="text-muted mb-0">
                View push notifications,
                delivery status and customer engagement.
            </p>

        </div>


        @if(
            Auth::user()->hasPermission(
                'app_communications.notifications.send'
            )
        )

            <button
                type="button"
                class="btn btn-mauve"
            >
                <i class="fas fa-plus me-2"></i>
                New Notification
            </button>

        @endif

    </div>


    {{-- SUMMARY --}}
    <div class="row g-3 mb-4">

        {{-- TOTAL --}}
        <div class="col-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small mb-1">
                        Total
                    </div>

                    <div class="fs-3 fw-bold">
                        {{ $summary['total'] }}
                    </div>

                </div>

            </div>

        </div>


        {{-- SENT --}}
        <div class="col-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small mb-1">
                        Sent
                    </div>

                    <div class="fs-3 fw-bold text-success">
                        {{ $summary['sent'] }}
                    </div>

                </div>

            </div>

        </div>


        {{-- DRAFT --}}
        <div class="col-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small mb-1">
                        Draft
                    </div>

                    <div class="fs-3 fw-bold text-secondary">
                        {{ $summary['draft'] }}
                    </div>

                </div>

            </div>

        </div>


        {{-- FAILED --}}
        <div class="col-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small mb-1">
                        Failed
                    </div>

                    <div class="fs-3 fw-bold text-danger">
                        {{ $summary['failed'] }}
                    </div>

                </div>

            </div>

        </div>

    </div>


    {{-- OPTIONAL SECONDARY STATUS --}}
    @if(
        isset($summary['sending'])
        && $summary['sending'] > 0
    )

        <div class="alert alert-info d-flex align-items-center mb-4">

            <i class="fas fa-spinner fa-spin me-2"></i>

            <div>

                {{ $summary['sending'] }}

                notification(s) currently being sent.

            </div>

        </div>

    @endif


    {{-- NOTIFICATIONS TABLE --}}
    @include(
        'app-communications.notifications.table',
        [
            'notifications' => $notifications
        ]
    )


    {{-- PAGINATION --}}
    @if(
        method_exists(
            $notifications,
            'links'
        )
    )

        <div class="mt-4">

            {{ $notifications->links() }}

        </div>

    @endif

</div>

@endsection