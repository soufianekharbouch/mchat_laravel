@extends('layouts.app')

@section('title', 'Notification Details')

@section('content')

<div class="container-fluid py-4">

    {{-- FLASH MESSAGES --}}
    @if(session('success'))

        <div
            class="
                alert
                alert-success
                alert-dismissible
                fade
                show
            "
            role="alert"
        >
            <i class="fas fa-check-circle me-2"></i>

            {{ session('success') }}

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>
        </div>

    @endif


    @if(session('error'))

        <div
            class="
                alert
                alert-danger
                alert-dismissible
                fade
                show
            "
            role="alert"
        >
            <i class="fas fa-exclamation-circle me-2"></i>

            {{ session('error') }}

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>
        </div>

    @endif


    {{-- HEADER --}}
    <div
        class="
            d-flex
            flex-column
            flex-md-row
            justify-content-between
            align-items-md-start
            gap-3
            mb-4
        "
    >

        <div>

            <a
                href="{{
                    route(
                        'app-communications.notifications.index'
                    )
                }}"
                class="text-decoration-none text-muted"
            >
                <i class="fas fa-arrow-left me-1"></i>

                Notifications
            </a>


            <h2 class="mt-3 mb-1">

                <i class="fas fa-bell text-mauve me-2"></i>

                Notification
                #{{ $notification->id }}

            </h2>


            <p class="text-muted mb-0">
                Delivery and customer engagement details.
            </p>

        </div>


        {{-- ACTIONS --}}
        <div class="d-flex gap-2 flex-wrap">

            {{-- SEND DRAFT --}}
            @if(
                $notification->status === 'draft'
                &&
                Auth::user()->hasPermission(
                    'app_communications.notifications.send'
                )
            )

                <form
                    method="POST"
                    action="{{
                        route(
                            'app-communications.notifications.send',
                            $notification
                        )
                    }}"
                    onsubmit="
                        return confirm(
                            'Are you sure you want to send this notification now?'
                        );
                    "
                >

                    @csrf

                    <button
                        type="submit"
                        class="btn btn-mauve"
                    >
                        <i class="fas fa-paper-plane me-2"></i>

                        Send Notification
                    </button>

                </form>

            @endif


            {{-- SENDING --}}
            @if(
                $notification->status === 'sending'
            )

                <button
                    type="button"
                    class="btn btn-primary"
                    disabled
                >
                    <i class="fas fa-spinner fa-spin me-2"></i>

                    Sending...
                </button>

            @endif


            {{-- SENT --}}
            @if(
                $notification->status === 'sent'
            )

                <button
                    type="button"
                    class="btn btn-success"
                    disabled
                >
                    <i class="fas fa-check me-2"></i>

                    Sent
                </button>

            @endif


            {{-- FAILED --}}
            @if(
                $notification->status === 'failed'
                &&
                Auth::user()->hasPermission(
                    'app_communications.notifications.send'
                )
            )

                <form
                    method="POST"
                    action="{{
                        route(
                            'app-communications.notifications.send',
                            $notification
                        )
                    }}"
                    onsubmit="
                        return confirm(
                            'Retry sending this notification?'
                        );
                    "
                >

                    @csrf

                    <button
                        type="submit"
                        class="btn btn-danger"
                    >
                        <i class="fas fa-rotate-right me-2"></i>

                        Retry Send
                    </button>

                </form>

            @endif

        </div>

    </div>


    {{-- SUMMARY --}}
    <div class="row g-3 mb-4">

        {{-- STATUS --}}
        <div class="col-12 col-sm-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">
                        Status
                    </div>

                    <div class="mt-2">

                        @php

                            $statusClass = match(
                                $notification->status
                            ) {
                                'sent' =>
                                    'bg-success',

                                'sending' =>
                                    'bg-primary',

                                'failed' =>
                                    'bg-danger',

                                'draft' =>
                                    'bg-secondary',

                                default =>
                                    'bg-secondary',
                            };

                        @endphp

                        <span
                            class="
                                badge
                                {{ $statusClass }}
                            "
                        >
                            {{ $notification->status_label }}
                        </span>

                    </div>

                </div>

            </div>

        </div>


        {{-- RECIPIENTS --}}
        <div class="col-12 col-sm-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">
                        Recipients
                    </div>

                    <div class="fs-3 fw-bold">
                        {{ $stats['total_recipients'] }}
                    </div>

                </div>

            </div>

        </div>


        {{-- VIEWED --}}
        <div class="col-12 col-sm-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">
                        Viewed
                    </div>

                    <div class="fs-3 fw-bold text-success">
                        {{ $stats['viewed_count'] }}
                    </div>

                </div>

            </div>

        </div>


        {{-- VIEW RATE --}}
        <div class="col-12 col-sm-6 col-lg-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">
                        View Rate
                    </div>

                    <div class="fs-3 fw-bold text-mauve">

                        {{
                            number_format(
                                $stats['view_percentage'],
                                1
                            )
                        }}%

                    </div>

                </div>

            </div>

        </div>

    </div>


    {{-- DELIVERY STATS --}}
    <div class="row g-3 mb-4">

        {{-- SENT --}}
        <div class="col-12 col-sm-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">
                        Push Sent
                    </div>

                    <div class="fs-4 fw-bold text-success">
                        {{ $stats['sent_count'] }}
                    </div>

                </div>

            </div>

        </div>


        {{-- PENDING --}}
        <div class="col-12 col-sm-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">
                        Pending
                    </div>

                    <div class="fs-4 fw-bold text-warning">
                        {{ $stats['pending_count'] }}
                    </div>

                </div>

            </div>

        </div>


        {{-- FAILED --}}
        <div class="col-12 col-sm-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">
                        Failed
                    </div>

                    <div class="fs-4 fw-bold text-danger">
                        {{ $stats['failed_count'] }}
                    </div>

                </div>

            </div>

        </div>

    </div>


    {{-- NOTIFICATION META --}}
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <h5 class="mb-0">
                Notification Information
            </h5>

        </div>


        <div class="card-body">

            <div class="row g-4">

                {{-- TARGET --}}
                <div class="col-md-4">

                    <div class="text-muted small mb-1">
                        Target
                    </div>

                    <div class="fw-semibold">
                        {{ $notification->target_label }}
                    </div>

                </div>


                {{-- CREATED --}}
                <div class="col-md-4">

                    <div class="text-muted small mb-1">
                        Created At
                    </div>

                    <div class="fw-semibold">

                        {{
                            optional(
                                $notification->created_at
                            )->format(
                                'd M Y - H:i'
                            )
                            ?? '—'
                        }}

                    </div>

                </div>


                {{-- SENT --}}
                <div class="col-md-4">

                    <div class="text-muted small mb-1">
                        Sent At
                    </div>

                    <div class="fw-semibold">

                        {{
                            optional(
                                $notification->sent_at
                            )->format(
                                'd M Y - H:i'
                            )
                            ?? '—'
                        }}

                    </div>

                </div>

            </div>

        </div>

    </div>


    {{-- NOTIFICATION CONTENT --}}
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <h5 class="mb-0">
                Notification Content
            </h5>

        </div>


        <div class="card-body">

            <div class="row g-4">

                {{-- ENGLISH --}}
                <div class="col-lg-6">

                    <div
                        class="
                            border
                            rounded
                            p-3
                            h-100
                        "
                    >

                        <div class="text-muted small mb-2">
                            English
                        </div>

                        <div class="fw-semibold fs-5 mb-3">

                            {{
                                $notification->title_en
                                ?: '—'
                            }}

                        </div>

                        <div class="text-muted small mb-1">
                            Message
                        </div>

                        <div style="white-space: pre-line;">{{
                            $notification->body_en
                            ?: '—'
                        }}</div>

                    </div>

                </div>


                {{-- ARABIC --}}
                <div class="col-lg-6">

                    <div
                        class="
                            border
                            rounded
                            p-3
                            h-100
                            text-end
                        "
                        dir="rtl"
                    >

                        <div class="text-muted small mb-2">
                            العربية
                        </div>

                        <div class="fw-semibold fs-5 mb-3">

                            {{
                                $notification->title_ar
                                ?: '—'
                            }}

                        </div>

                        <div class="text-muted small mb-1">
                            الرسالة
                        </div>

                        <div style="white-space: pre-line;">{{
                            $notification->body_ar
                            ?: '—'
                        }}</div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    {{-- VIEW PROGRESS --}}
    @if($stats['sent_count'] > 0)

        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body">

                <div
                    class="
                        d-flex
                        justify-content-between
                        align-items-center
                        mb-2
                    "
                >

                    <div>

                        <div class="fw-semibold">
                            Customer Engagement
                        </div>

                        <div class="small text-muted">

                            {{ $stats['viewed_count'] }}

                            of

                            {{ $stats['sent_count'] }}

                            delivered notifications viewed

                        </div>

                    </div>


                    <div
                        class="
                            fs-4
                            fw-bold
                            text-success
                        "
                    >

                        {{
                            number_format(
                                $stats['view_percentage'],
                                1
                            )
                        }}%

                    </div>

                </div>


                <div
                    class="progress"
                    style="height: 8px;"
                >

                    <div
                        class="progress-bar bg-success"
                        role="progressbar"
                        style="
                            width:
                            {{
                                min(
                                    100,
                                    $stats[
                                        'view_percentage'
                                    ]
                                )
                            }}%;
                        "
                        aria-valuenow="{{
                            $stats['view_percentage']
                        }}"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    ></div>

                </div>

            </div>

        </div>

    @endif


    {{-- RECIPIENTS --}}
    <div class="card border-0 shadow-sm">

        <div
            class="
                card-header
                bg-white
                py-3
                d-flex
                flex-column
                flex-md-row
                justify-content-between
                align-items-md-center
                gap-2
            "
        >

            <div>

                <h5 class="mb-0">
                    Recipients
                </h5>

                <div class="small text-muted mt-1">
                    Customer delivery and notification view details.
                </div>

            </div>


            <span class="text-muted small">

                {{ $stats['viewed_count'] }}

                viewed

            </span>

        </div>


        <div class="table-responsive">

            <table
                class="
                    table
                    table-hover
                    align-middle
                    mb-0
                "
            >

                <thead class="table-light">

                    <tr>

                        <th>
                            Customer
                        </th>

                        <th>
                            Phone
                        </th>

                        <th>
                            Device
                        </th>

                        <th>
                            Locale
                        </th>

                        <th>
                            Push
                        </th>

                        <th>
                            Sent At
                        </th>

                        <th>
                            Viewed
                        </th>

                        <th>
                            Viewed At
                        </th>

                    </tr>

                </thead>


                <tbody>

                    @forelse(
                        $recipients
                        as $recipient
                    )

                        <tr>

                            {{-- CUSTOMER --}}
                            <td>

                                <div class="fw-semibold">

                                    {{
                                        $recipient[
                                            'name'
                                        ]
                                    }}

                                </div>


                                @if(
                                    !empty(
                                        $recipient[
                                            'customer_id'
                                        ]
                                    )
                                )

                                    <div class="small text-muted">

                                        Customer
                                        #{{
                                            $recipient[
                                                'customer_id'
                                            ]
                                        }}

                                    </div>

                                @else

                                    <div class="small text-muted">
                                        Anonymous device
                                    </div>

                                @endif

                            </td>


                            {{-- PHONE --}}
                            <td>

                                {{
                                    $recipient[
                                        'phone'
                                    ]
                                }}

                            </td>


                            {{-- DEVICE --}}
                            <td>

                                @php

                                    $platform =
                                        strtolower(
                                            (string) (
                                                $recipient[
                                                    'platform'
                                                ]
                                                ?? ''
                                            )
                                        );

                                @endphp


                                @if(
                                    $platform ===
                                    'android'
                                )

                                    <i
                                        class="
                                            fab
                                            fa-android
                                            text-success
                                            me-1
                                        "
                                    ></i>

                                    Android

                                @elseif(
                                    $platform ===
                                    'ios'
                                )

                                    <i
                                        class="
                                            fab
                                            fa-apple
                                            me-1
                                        "
                                    ></i>

                                    iOS

                                @else

                                    <span class="text-muted">
                                        —
                                    </span>

                                @endif


                                @if(
                                    !empty(
                                        $recipient[
                                            'device_name'
                                        ]
                                    )
                                    &&
                                    $recipient[
                                        'device_name'
                                    ] !== '—'
                                )

                                    <div class="small text-muted">

                                        {{
                                            $recipient[
                                                'device_name'
                                            ]
                                        }}

                                    </div>

                                @endif

                            </td>


                            {{-- LOCALE --}}
                            <td>

                                @if(
                                    $recipient[
                                        'locale'
                                    ] === 'ar'
                                )

                                    <span
                                        class="
                                            badge
                                            bg-light
                                            text-dark
                                            border
                                        "
                                    >
                                        Arabic
                                    </span>

                                @elseif(
                                    $recipient[
                                        'locale'
                                    ] === 'en'
                                )

                                    <span
                                        class="
                                            badge
                                            bg-light
                                            text-dark
                                            border
                                        "
                                    >
                                        English
                                    </span>

                                @else

                                    <span class="text-muted">
                                        —
                                    </span>

                                @endif

                            </td>


                            {{-- PUSH STATUS --}}
                            <td>

                                @if(
                                    $recipient[
                                        'push_status'
                                    ] === 'sent'
                                )

                                    <span class="badge bg-success">

                                        <i
                                            class="
                                                fas
                                                fa-check
                                                me-1
                                            "
                                        ></i>

                                        Sent

                                    </span>

                                @elseif(
                                    $recipient[
                                        'push_status'
                                    ] === 'pending'
                                )

                                    <span
                                        class="
                                            badge
                                            bg-warning
                                            text-dark
                                        "
                                    >

                                        <i
                                            class="
                                                fas
                                                fa-clock
                                                me-1
                                            "
                                        ></i>

                                        Pending

                                    </span>

                                @elseif(
                                    $recipient[
                                        'push_status'
                                    ] === 'failed'
                                )

                                    <span class="badge bg-danger">

                                        <i
                                            class="
                                                fas
                                                fa-xmark
                                                me-1
                                            "
                                        ></i>

                                        Failed

                                    </span>

                                @else

                                    <span class="badge bg-secondary">

                                        {{
                                            ucfirst(
                                                (string)
                                                $recipient[
                                                    'push_status'
                                                ]
                                            )
                                        }}

                                    </span>

                                @endif

                            </td>


                            {{-- PUSH SENT AT --}}
                            <td>

                                @if(
                                    $recipient[
                                        'push_sent_at'
                                    ]
                                )

                                    {{
                                        $recipient[
                                            'push_sent_at'
                                        ]->format(
                                            'd M Y - H:i'
                                        )
                                    }}

                                @else

                                    <span class="text-muted">
                                        —
                                    </span>

                                @endif

                            </td>


                            {{-- VIEWED --}}
                            <td>

                                @if(
                                    $recipient[
                                        'viewed'
                                    ]
                                )

                                    <span
                                        class="
                                            text-success
                                            fw-semibold
                                        "
                                    >

                                        <i
                                            class="
                                                fas
                                                fa-check-circle
                                                me-1
                                            "
                                        ></i>

                                        Yes

                                    </span>

                                @else

                                    <span class="text-muted">

                                        <i
                                            class="
                                                far
                                                fa-circle
                                                me-1
                                            "
                                        ></i>

                                        No

                                    </span>

                                @endif

                            </td>


                            {{-- VIEWED AT --}}
                            <td>

                                @if(
                                    $recipient[
                                        'viewed_at'
                                    ]
                                )

                                    {{
                                        $recipient[
                                            'viewed_at'
                                        ]->format(
                                            'd M Y - H:i'
                                        )
                                    }}

                                @else

                                    <span class="text-muted">
                                        —
                                    </span>

                                @endif

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="8"
                                class="
                                    text-center
                                    py-5
                                    text-muted
                                "
                            >

                                <i
                                    class="
                                        fas
                                        fa-users
                                        fs-2
                                        mb-3
                                        d-block
                                    "
                                ></i>

                                No recipients found
                                for this notification.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </div>

</div>

@endsection