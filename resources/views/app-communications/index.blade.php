@extends('layouts.app')

@section('title', 'App Communications')

@section('content')

@php
    /*
     * Percentages are calculated against the total number of customers.
     * Values are capped at 100% for presentation (e.g. multiple devices/customer).
     */
    $overviewTotal = max((int) ($stats['total_customers'] ?? 0), 0);

    $overviewPercent = function ($value) use ($overviewTotal) {
        if ($overviewTotal <= 0) {
            return 0;
        }

        return min(
            100,
            round(((int) $value / $overviewTotal) * 100, 1)
        );
    };
@endphp

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
            align-items-md-center
            gap-3
            mb-4
        "
    >

        <div>

            <h2 class="mb-1">

                <i class="fas fa-comments text-mauve me-2"></i>

                App Communications

            </h2>

            <p class="text-muted mb-0">

                Manage communication between customer service
                and customers using the Maison Chat mobile app.

            </p>

        </div>


        <div class="d-flex gap-2 flex-wrap">

            {{-- NEW NOTIFICATION --}}
            @if(
                Auth::user()->hasPermission(
                    'app_communications.notifications.send'
                )
            )

                <a
                    href="{{
                        route(
                            'app-communications.notifications.create'
                        )
                    }}"
                    class="btn btn-mauve"
                >

                    <i class="fas fa-plus me-2"></i>

                    New Notification

                </a>

            @endif


            {{-- NEW MESSAGE --}}
            @if(
                Auth::user()->hasPermission(
                    'app_communications.messages.send'
                )
            )

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    disabled
                >

                    <i class="fas fa-paper-plane me-2"></i>

                    New Message

                </button>

            @endif

        </div>

    </div>


    {{-- TOP COMMUNICATION ALERTS --}}
    <div class="d-flex justify-content-end align-items-center gap-2 mb-3">

        {{-- UNREAD MESSAGES --}}
        <div
            class="communication-alert-icon communication-alert-message"
            title="Unread messages"
        >
            <i class="fas fa-envelope"></i>

            @if(($stats['unread_messages'] ?? 0) > 0)
                <span class="communication-alert-badge">
                    {{ $stats['unread_messages'] }}
                </span>
            @endif
        </div>

        {{-- NOTIFICATIONS TO CONFIRM --}}
        <div
            class="communication-alert-icon communication-alert-notification"
            title="Notifications to confirm"
        >
            <i class="fas fa-bell"></i>

            @if(($stats['notifications_to_confirm'] ?? 0) > 0)
                <span class="communication-alert-badge">
                    {{ $stats['notifications_to_confirm'] }}
                </span>
            @endif
        </div>

    </div>


    {{-- CUSTOMER APP OVERVIEW --}}
    <div class="card border-0 shadow-sm mb-4 app-overview-card">

        <div class="card-body p-4 p-xl-5">

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

                    <div class="app-overview-eyebrow">
                        MOBILE APP OVERVIEW
                    </div>

                    <h3 class="mb-1">
                        Customer App Activity
                    </h3>

                    <div class="text-muted">
                        Live overview of installation, onboarding and customer activity.
                    </div>

                </div>


                <div class="app-overview-main-icon">

                    <i class="fas fa-mobile-screen-button"></i>

                </div>

            </div>


            <div class="row g-3">

                {{-- APP INSTALLED --}}
                @php
                    $installedValue = (int) ($stats['installed_devices'] ?? 0);
                    $installedPercent = $overviewPercent($installedValue);
                @endphp
                <div class="col-12 col-sm-6 col-xl">
                    <div class="overview-metric overview-metric-purple">
                        <div class="overview-metric-top">
                            <div class="overview-metric-icon overview-icon-purple">
                                <i class="fas fa-mobile-screen"></i>
                            </div>
                            <div class="overview-percentage overview-percentage-purple">
                                {{ number_format($installedPercent, 1) }}%
                            </div>
                        </div>

                        <div class="overview-metric-value">
                            {{ $installedValue }}
                            <span class="overview-total-reference">/ {{ $overviewTotal }}</span>
                        </div>

                        <div class="overview-metric-label">App installed</div>

                        <div class="overview-progress">
                            <div
                                class="overview-progress-bar overview-progress-purple"
                                style="width: {{ $installedPercent }}%;"
                            ></div>
                        </div>

                        <div class="overview-progress-caption">
                            {{ number_format($installedPercent, 1) }}% of total customers
                        </div>
                    </div>
                </div>


                {{-- LOGGED / INITIAL PASSWORD --}}
                @php
                    $initialValue = (int) ($stats['customers_initial_password'] ?? 0);
                    $initialPercent = $overviewPercent($initialValue);
                @endphp
                <div class="col-12 col-sm-6 col-xl">
                    <div class="overview-metric overview-metric-warning">
                        <div class="overview-metric-top">
                            <div class="overview-metric-icon overview-icon-warning">
                                <i class="fas fa-key"></i>
                            </div>
                            <div class="overview-percentage overview-percentage-warning">
                                {{ number_format($initialPercent, 1) }}%
                            </div>
                        </div>

                        <div class="overview-metric-value">
                            {{ $initialValue }}
                            <span class="overview-total-reference">/ {{ $overviewTotal }}</span>
                        </div>

                        <div class="overview-metric-label">
                            Logged · initial password
                        </div>

                        <div class="overview-progress">
                            <div
                                class="overview-progress-bar overview-progress-warning"
                                style="width: {{ $initialPercent }}%;"
                            ></div>
                        </div>

                        <div class="overview-progress-caption">
                            {{ number_format($initialPercent, 1) }}% of total customers
                        </div>
                    </div>
                </div>


                {{-- LOGGED / PERSONAL PASSWORD --}}
                @php
                    $personalValue = (int) ($stats['customers_personal_password'] ?? 0);
                    $personalPercent = $overviewPercent($personalValue);
                @endphp
                <div class="col-12 col-sm-6 col-xl">
                    <div class="overview-metric overview-metric-success">
                        <div class="overview-metric-top">
                            <div class="overview-metric-icon overview-icon-success">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <div class="overview-percentage overview-percentage-success">
                                {{ number_format($personalPercent, 1) }}%
                            </div>
                        </div>

                        <div class="overview-metric-value">
                            {{ $personalValue }}
                            <span class="overview-total-reference">/ {{ $overviewTotal }}</span>
                        </div>

                        <div class="overview-metric-label">
                            Logged · personal password
                        </div>

                        <div class="overview-progress">
                            <div
                                class="overview-progress-bar overview-progress-success"
                                style="width: {{ $personalPercent }}%;"
                            ></div>
                        </div>

                        <div class="overview-progress-caption">
                            {{ number_format($personalPercent, 1) }}% of total customers
                        </div>
                    </div>
                </div>


                {{-- ACTIVE LAST HOUR --}}
                @php
                    $activeValue = (int) ($stats['active_last_hour'] ?? 0);
                    $activePercent = $overviewPercent($activeValue);
                @endphp
                <div class="col-12 col-sm-6 col-xl">
                    <div class="overview-metric overview-metric-danger">
                        <div class="overview-metric-top">
                            <div class="overview-metric-icon overview-icon-danger">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div class="overview-percentage overview-percentage-danger">
                                {{ number_format($activePercent, 1) }}%
                            </div>
                        </div>

                        <div class="overview-metric-value">
                            {{ $activeValue }}
                            <span class="overview-total-reference">/ {{ $overviewTotal }}</span>
                        </div>

                        <div class="overview-metric-label">
                            Active last 1 hour
                        </div>

                        <div class="overview-progress">
                            <div
                                class="overview-progress-bar overview-progress-danger"
                                style="width: {{ $activePercent }}%;"
                            ></div>
                        </div>

                        <div class="overview-progress-caption">
                            {{ number_format($activePercent, 1) }}% of total customers
                        </div>
                    </div>
                </div>


                {{-- TOTAL CUSTOMERS --}}
                @php
                    $totalPercent = $overviewTotal > 0 ? 100 : 0;
                @endphp
                <div class="col-12 col-sm-6 col-xl">
                    <div class="overview-metric overview-metric-primary">
                        <div class="overview-metric-top">
                            <div class="overview-metric-icon overview-icon-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="overview-percentage overview-percentage-primary">
                                {{ number_format($totalPercent, 0) }}%
                            </div>
                        </div>

                        <div class="overview-metric-value">
                            {{ $overviewTotal }}
                        </div>

                        <div class="overview-metric-label">
                            Total customers
                        </div>

                        <div class="overview-progress">
                            <div
                                class="overview-progress-bar overview-progress-primary"
                                style="width: {{ $totalPercent }}%;"
                            ></div>
                        </div>

                        <div class="overview-progress-caption">
                            Customer base reference
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>


    {{-- COMMUNICATION TOOLS --}}
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">

            <div
                class="
                    d-flex
                    justify-content-between
                    align-items-center
                    gap-3
                "
            >

                <div>

                    <h5 class="mb-1">
                        Communication Tools
                    </h5>

                    <div class="small text-muted">
                        Customers, activity history, notifications and direct messaging.
                    </div>

                </div>

            </div>

        </div>


        <div class="card-body">

            <div class="row g-3">

                {{-- CUSTOMERS --}}
                @if(
                    Auth::user()->hasPermission(
                        'app_communications.customers.view'
                    )
                )

                    <div class="col-12 col-md-6 col-xl-3">

                        <a
                            href="{{
                                route(
                                    'app-communications.customers.index'
                                )
                            }}"
                            class="
                                communication-tool-card
                                communication-tool-customers
                            "
                        >

                            <div class="communication-tool-icon">

                                <i class="fas fa-users"></i>

                            </div>


                            <div class="communication-tool-content">

                                <div class="communication-tool-title">
                                    Customers
                                </div>

                                <div class="communication-tool-description">

                                    View all customers, account status,
                                    app activity and communication actions.

                                </div>


                                <div class="communication-tool-meta">

                                    <span class="badge bg-light text-dark border">

                                        {{ $stats['total_customers'] ?? 0 }}
                                        customers

                                    </span>

                                    <span class="communication-tool-arrow">

                                        <i class="fas fa-arrow-right"></i>

                                    </span>

                                </div>

                            </div>

                        </a>

                    </div>

                @endif


                {{-- CUSTOMER ACTIVITIES --}}
                @if(
                    Auth::user()->hasPermission(
                        'app_communications.customers.view'
                    )
                )

                    <div class="col-12 col-md-6 col-xl-3">

                        <a
                            href="{{
                                route(
                                    'app-communications.activities.index'
                                )
                            }}"
                            class="
                                communication-tool-card
                                communication-tool-activities
                            "
                        >

                            <div class="communication-tool-icon">

                                <i class="fas fa-clock-rotate-left"></i>

                            </div>


                            <div class="communication-tool-content">

                                <div class="communication-tool-title">
                                    Customer Activities
                                </div>

                                <div class="communication-tool-description">

                                    Review customer actions performed in the app
                                    and filter them by customer, operation or date.

                                </div>


                                <div class="communication-tool-meta">

                                    <span class="badge bg-light text-dark border">

                                        {{ $stats['customer_activities_count'] ?? 0 }}
                                        activities

                                    </span>

                                    <span class="communication-tool-arrow">

                                        <i class="fas fa-arrow-right"></i>

                                    </span>

                                </div>

                            </div>

                        </a>

                    </div>

                @endif


                {{-- NOTIFICATIONS --}}
                @if(
                    Auth::user()->hasPermission(
                        'app_communications.notifications.view'
                    )
                )

                    <div class="col-12 col-md-6 col-xl-3">

                        <a
                            href="{{
                                route(
                                    'app-communications.notifications.index'
                                )
                            }}"
                            class="
                                communication-tool-card
                                communication-tool-notifications
                            "
                        >

                            <div class="communication-tool-icon">

                                <i class="fas fa-bell"></i>

                            </div>


                            <div class="communication-tool-content">

                                <div class="communication-tool-title">
                                    Notifications
                                </div>

                                <div class="communication-tool-description">

                                    Create, send and track push notifications
                                    sent to app users.

                                </div>


                                <div class="communication-tool-meta">

                                    <div class="d-flex gap-2 flex-wrap">

                                        <span class="badge bg-success">

                                            {{ $stats['notifications_sent'] ?? 0 }}
                                            sent

                                        </span>

                                        <span class="badge bg-light text-dark border">

                                            {{ $stats['notifications_viewed'] ?? 0 }}
                                            views

                                        </span>

                                    </div>


                                    <span class="communication-tool-arrow">

                                        <i class="fas fa-arrow-right"></i>

                                    </span>

                                </div>

                            </div>

                        </a>

                    </div>

                @endif


                {{-- MESSAGES --}}
                @if(
                    Auth::user()->hasPermission(
                        'app_communications.messages.view'
                    )
                )

                    <div class="col-12 col-md-6 col-xl-3">

                        @if(
                            Route::has(
                                'app-communications.messages.index'
                            )
                        )

                            <a
                                href="{{
                                    route(
                                        'app-communications.messages.index'
                                    )
                                }}"
                                class="
                                    communication-tool-card
                                    communication-tool-messages
                                "
                            >

                        @else

                            <div
                                class="
                                    communication-tool-card
                                    communication-tool-messages
                                "
                            >

                        @endif


                            <div class="communication-tool-icon">

                                <i class="fas fa-comments"></i>

                                @if(($stats['unread_messages'] ?? 0) > 0)

                                    <span class="tool-icon-badge">

                                        {{ $stats['unread_messages'] }}

                                    </span>

                                @endif

                            </div>


                            <div class="communication-tool-content">

                                <div class="communication-tool-title">
                                    Messages
                                </div>

                                <div class="communication-tool-description">

                                    Send and receive direct customer messages
                                    from the mobile app.

                                </div>


                                <div class="communication-tool-meta">

                                    <span class="badge bg-danger">

                                        {{ $stats['unread_messages'] ?? 0 }}
                                        unread

                                    </span>


                                    @if(
                                        Route::has(
                                            'app-communications.messages.index'
                                        )
                                    )

                                        <span class="communication-tool-arrow">

                                            <i class="fas fa-arrow-right"></i>

                                        </span>

                                    @else

                                        <span class="small text-muted">
                                            Coming soon
                                        </span>

                                    @endif

                                </div>

                            </div>


                        @if(
                            Route::has(
                                'app-communications.messages.index'
                            )
                        )

                            </a>

                        @else

                            </div>

                        @endif

                    </div>

                @endif

            </div>

        </div>

    </div>

</div>


<style>
    .app-overview-card {
        overflow: hidden;
        position: relative;
        border-radius: 18px;
        background:
            linear-gradient(
                135deg,
                #ffffff 0%,
                #fbf8fd 55%,
                #f5eef9 100%
            );
    }

    .app-overview-card::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        right: -110px;
        top: -125px;
        background:
            rgba(
                154,
                91,
                179,
                0.08
            );
        pointer-events: none;
    }

    .app-overview-eyebrow {
        color: #9a5bb3;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.09em;
        margin-bottom: 5px;
    }

    .app-overview-main-icon {
        width: 58px;
        height: 58px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #9a5bb3;
        background: rgba(154, 91, 179, 0.10);
        font-size: 1.55rem;
        flex-shrink: 0;
    }

    .overview-metric {
        min-height: 176px;
        height: 100%;
        padding: 17px;
        border-radius: 16px;
        border: 1px solid rgba(0, 0, 0, 0.055);
        background: rgba(255, 255, 255, 0.94);
        position: relative;
        overflow: hidden;
        transition:
            transform 0.18s ease,
            box-shadow 0.18s ease,
            border-color 0.18s ease;
    }

    .overview-metric:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px rgba(35, 28, 39, 0.07);
    }

    .overview-metric::after {
        content: "";
        position: absolute;
        width: 90px;
        height: 90px;
        border-radius: 50%;
        right: -35px;
        top: -40px;
        opacity: 0.55;
        pointer-events: none;
    }

    .overview-metric-purple::after {
        background: rgba(154, 91, 179, 0.08);
    }

    .overview-metric-warning::after {
        background: rgba(255, 193, 7, 0.10);
    }

    .overview-metric-success::after {
        background: rgba(25, 135, 84, 0.08);
    }

    .overview-metric-danger::after {
        background: rgba(220, 53, 69, 0.08);
    }

    .overview-metric-primary::after {
        background: rgba(13, 110, 253, 0.08);
    }

    .overview-metric-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 13px;
        position: relative;
        z-index: 1;
    }

    .overview-metric-icon {
        width: 44px;
        height: 44px;
        min-width: 44px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
    }

    .overview-icon-purple {
        color: #9a5bb3;
        background: rgba(154, 91, 179, 0.10);
    }

    .overview-icon-warning {
        color: #ba8100;
        background: rgba(255, 193, 7, 0.14);
    }

    .overview-icon-success {
        color: #198754;
        background: rgba(25, 135, 84, 0.11);
    }

    .overview-icon-danger {
        color: #dc3545;
        background: rgba(220, 53, 69, 0.10);
    }

    .overview-icon-primary {
        color: #0d6efd;
        background: rgba(13, 110, 253, 0.10);
    }

    .overview-percentage {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 58px;
        height: 29px;
        padding: 0 9px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 800;
        position: relative;
        z-index: 1;
    }

    .overview-percentage-purple {
        color: #8b4aa5;
        background: rgba(154, 91, 179, 0.10);
    }

    .overview-percentage-warning {
        color: #9a6a00;
        background: rgba(255, 193, 7, 0.15);
    }

    .overview-percentage-success {
        color: #157347;
        background: rgba(25, 135, 84, 0.11);
    }

    .overview-percentage-danger {
        color: #c72f3e;
        background: rgba(220, 53, 69, 0.10);
    }

    .overview-percentage-primary {
        color: #0b5ed7;
        background: rgba(13, 110, 253, 0.10);
    }

    .overview-metric-value {
        color: #25212a;
        font-size: 1.65rem;
        font-weight: 800;
        line-height: 1.05;
        position: relative;
        z-index: 1;
    }

    .overview-total-reference {
        color: #a19aa6;
        font-size: 0.76rem;
        font-weight: 600;
        margin-left: 3px;
    }

    .overview-metric-label {
        color: #77717d;
        font-size: 0.77rem;
        line-height: 1.25;
        margin-top: 5px;
        min-height: 30px;
        position: relative;
        z-index: 1;
    }

    .overview-progress {
        width: 100%;
        height: 6px;
        margin-top: 12px;
        overflow: hidden;
        border-radius: 999px;
        background: #eeeaf0;
        position: relative;
        z-index: 1;
    }

    .overview-progress-bar {
        height: 100%;
        min-width: 0;
        border-radius: inherit;
        transition: width 0.35s ease;
    }

    .overview-progress-purple {
        background: #9a5bb3;
    }

    .overview-progress-warning {
        background: #d99a00;
    }

    .overview-progress-success {
        background: #198754;
    }

    .overview-progress-danger {
        background: #dc3545;
    }

    .overview-progress-primary {
        background: #0d6efd;
    }

    .overview-progress-caption {
        margin-top: 7px;
        color: #9a949e;
        font-size: 0.66rem;
        font-weight: 600;
        position: relative;
        z-index: 1;
    }

    .communication-alert-icon {
        width: 42px;
        height: 42px;
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e6e1e8;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(0, 0, 0, 0.045);
        font-size: 1rem;
    }

    .communication-alert-message {
        color: #dc3545;
    }

    .communication-alert-notification {
        color: #9a5bb3;
    }

    .communication-alert-badge {
        position: absolute;
        top: -7px;
        right: -7px;
        min-width: 20px;
        height: 20px;
        padding: 0 5px;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #dc3545;
        color: #fff;
        border: 2px solid #fff;
        font-size: 0.66rem;
        font-weight: 800;
        line-height: 1;
    }

    .communication-tool-card {
        min-height: 230px;
        height: 100%;
        padding: 24px;
        border: 1px solid #e9e5eb;
        border-radius: 16px;
        background: #fff;
        display: flex;
        flex-direction: column;
        text-decoration: none;
        color: inherit;
        transition:
            transform 0.18s ease,
            box-shadow 0.18s ease,
            border-color 0.18s ease;
    }

    a.communication-tool-card:hover {
        color: inherit;
        transform: translateY(-3px);
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.07);
        border-color: #d9c8df;
    }

    .communication-tool-icon {
        position: relative;
        width: 52px;
        height: 52px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 15px;
        font-size: 1.3rem;
        margin-bottom: 20px;
    }

    .communication-tool-customers .communication-tool-icon {
        color: #198754;
        background: rgba(25, 135, 84, 0.10);
    }

    .communication-tool-activities .communication-tool-icon {
        color: #d97706;
        background: rgba(217, 119, 6, 0.10);
    }

    .communication-tool-notifications .communication-tool-icon {
        color: #9a5bb3;
        background: rgba(154, 91, 179, 0.10);
    }

    .communication-tool-messages .communication-tool-icon {
        color: #0d6efd;
        background: rgba(13, 110, 253, 0.10);
    }

    .tool-icon-badge {
        position: absolute;
        top: -7px;
        right: -7px;
        min-width: 21px;
        height: 21px;
        padding: 0 5px;
        border-radius: 20px;
        background: #dc3545;
        color: #fff;
        border: 2px solid #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.67rem;
        font-weight: 800;
    }

    .communication-tool-content {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .communication-tool-title {
        color: #25212a;
        font-size: 1.06rem;
        font-weight: 750;
    }

    .communication-tool-description {
        color: #77717d;
        font-size: 0.84rem;
        line-height: 1.5;
        margin-top: 6px;
    }

    .communication-tool-meta {
        margin-top: auto;
        padding-top: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .communication-tool-arrow {
        color: #9a5bb3;
        font-size: 0.85rem;
    }

    @media (max-width: 767.98px) {
        .app-overview-card .card-body {
            padding: 20px !important;
        }

        .overview-metric {
            min-height: 165px;
        }

        .communication-tool-card {
            min-height: 205px;
        }
    }
</style>

@endsection
