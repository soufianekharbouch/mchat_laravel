<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        @yield('title', 'Pet Nutrition App')
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --mauve: #8B5FBF;
            --yellow: #FFD700;
        }

        .bg-mauve {
            background-color: var(--mauve);
        }

        .bg-yellow {
            background-color: var(--yellow);
        }

        .text-mauve {
            color: var(--mauve);
        }

        .text-yellow {
            color: var(--yellow);
        }

        .btn-mauve {
            background-color: var(--mauve);
            border-color: var(--mauve);
            color: white;
        }

        .btn-mauve:hover {
            background-color: #7a4da6;
            border-color: #7a4da6;
            color: white;
        }

        .sidebar {
            background: linear-gradient(
                135deg,
                var(--mauve),
                #6a4a8a
            );

            min-height: 100vh;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            position: fixed;
            top: 0;
            left: -300px;
            width: 280px;
            transition: all 0.3s;
            z-index: 1000;
        }

        .sidebar.active {
            left: 0;
        }

        .nav-link {
            color: white !important;
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .nav-link.active {
            background-color: var(--yellow);
            color: var(--mauve) !important;
        }

        .sidebar-datetime {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px;
            margin: 10px 15px 20px;
            text-align: center;
        }

        .sidebar-time {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--yellow);
            letter-spacing: 1px;
        }

        .sidebar-date {
            font-size: 0.85rem;
            color: #fff;
            opacity: 0.9;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        .main-content {
            margin-left: 0;
            transition: all 0.3s;
            padding: 15px;
        }

        .main-content.sidebar-active {
            margin-left: 280px;
        }

        .mobile-header {
            display: none;
            background: var(--mauve);
            color: white;
            padding: 10px 15px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .user-permissions-count {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.12);
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.72rem;
        }

        @media (max-width: 768px) {
            .mobile-header {
                display: flex;
                align-items: center;
            }
        }

        @media (min-width: 769px) {
            .sidebar {
                left: 0;
            }

            .main-content {
                margin-left: 280px;
            }
        }
    </style>

    @stack('styles')
</head>

<body>

@if(Auth::check())

    @php
        $authenticatedUser = Auth::user();
    @endphp

    <div class="mobile-header">

        <button
            type="button"
            class="btn btn-sm btn-warning"
            id="sidebarToggle"
        >
            <i class="fas fa-bars"></i>
        </button>

        <h5 class="mb-0 ms-2">
            <i class="fas fa-paw me-2"></i>
            Pet Nutrition
        </h5>

    </div>

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>

    <nav
        class="sidebar"
        id="sidebar"
    >

        <div class="position-sticky pt-3">

            <div class="sidebar-datetime">

                <div
                    class="sidebar-time"
                    id="sidebarTime"
                    data-time="{{ now()->format('H:i:s') }}"
                >
                    {{ now()->format('H:i') }}
                </div>

                <div class="sidebar-date">
                    {{ now()->translatedFormat('l d F Y') }}
                </div>

            </div>

            <div class="text-center mb-4 mt-3 px-3">

                <h5 class="text-white mb-1">
                    <i class="fas fa-paw me-2"></i>
                    Pet Nutrition
                </h5>

                <div class="text-white">
                    {{ $authenticatedUser->first_name }}
                    {{ $authenticatedUser->last_name }}
                </div>

                <small class="user-permissions-count">

                    @if($authenticatedUser->isRootUser())

                        Root administrator

                    @elseif($authenticatedUser->hasLegacyFullAccess())

                        Legacy full access

                    @else

                        {{ $authenticatedUser->permissionsCount() }}
                        permission(s)

                    @endif

                </small>

            </div>

            <ul class="nav flex-column">

                {{-- Dashboard --}}
                <li class="nav-item">

                    <a
                        class="nav-link {{
                            request()->routeIs('dashboard')
                                ? 'active'
                                : ''
                        }}"
                        href="{{ route('dashboard') }}"
                    >
                        <i class="fas fa-tachometer-alt me-2"></i>
                        Dashboard
                    </a>

                </li>


                {{-- Settings --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'settings.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('settings.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('settings.index') }}"
                        >
                            <i class="fas fa-cog me-2"></i>
                            Settings
                        </a>

                    </li>

                @endif


                {{-- Users --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'users.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('users.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('users.index') }}"
                        >
                            <i class="fas fa-users me-2"></i>
                            Users
                        </a>

                    </li>

                @endif


                {{-- Recipe Types --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'recipe_types.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('recipe-types.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('recipe-types.index') }}"
                        >
                            <i class="fas fa-tags me-2"></i>
                            Recipe Types
                        </a>

                    </li>

                @endif


                {{-- Ingredients --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'ingredients.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('ingredients.*')
                                || request()->routeIs('admin.ingredients.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('ingredients.index') }}"
                        >
                            <i class="fas fa-carrot me-2"></i>
                            Ingredients
                        </a>

                    </li>

                @endif


                {{-- Recipes --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'recipes.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('recipes.*')
                                || request()->routeIs('admin.recipes.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('recipes.index') }}"
                        >
                            <i class="fas fa-utensils me-2"></i>
                            Recipes
                        </a>

                    </li>

                @endif


                {{-- Provinces / Zones --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'zones.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('provinces.*')
                                || request()->routeIs('provinces-zones.*')
                                || request()->routeIs('zones.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('provinces.index') }}"
                        >
                            <i class="fas fa-map-marked-alt me-2"></i>
                            Provinces / Zones
                        </a>

                    </li>

                @endif


                {{-- Subscriptions --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'subscriptions.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('subscriptions.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('subscriptions.index') }}"
                        >
                            <i class="fas fa-receipt me-2"></i>
                            Subscriptions
                        </a>

                    </li>

                @endif


                {{-- Daily Reports --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'daily_reports.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('daily-report.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('daily-report.index') }}"
                        >
                            <i class="fas fa-clipboard-list me-2"></i>
                            Daily Report
                        </a>

                    </li>

                @endif


                {{-- Loyalty Program --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'loyalty.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('loyalty.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('loyalty.index') }}"
                        >
                            <i class="fas fa-gift me-2"></i>
                            Loyalty Program
                        </a>

                    </li>

                @endif


                {{-- Stock Management --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'stock.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('stock.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('stock.index') }}"
                        >
                            <i class="fas fa-boxes me-2"></i>
                            Stock Management
                        </a>

                    </li>

                @endif


                {{-- App Communications --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'app_communications.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs(
                                    'app-communications.*'
                                )
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{
                                route(
                                    'app-communications.index'
                                )
                            }}"
                        >

                            <i
                                class="
                                    fas
                                    fa-comments
                                    me-2
                                "
                            ></i>

                            App Communications ---

                        </a>

                    </li>

                @endif


                {{-- Customer Service --}}
                @if(
                    $authenticatedUser->hasPermission(
                        'customer_service.view'
                    )
                )

                    <li class="nav-item">

                        <a
                            class="nav-link {{
                                request()->routeIs('customer-service.*')
                                    ? 'active'
                                    : ''
                            }}"
                            href="{{ route('customer-service.index') }}"
                        >
                            <i class="fas fa-headset me-2"></i>
                            Customer Service
                        </a>

                    </li>

                @endif


                {{-- Support Tickets --}}
                @if($authenticatedUser->hasPermission('support_tickets.view'))
                    @php
                        $supportUnreadCount = \App\Models\SupportTicket::query()
                            ->whereNull('admin_read_at')
                            ->count();
                    @endphp
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('support-tickets.*') ? 'active' : '' }}"
                           href="{{ route('support-tickets.index') }}">
                            <i class="fas fa-ticket-alt me-2"></i>
                            Support Tickets
                            @if($supportUnreadCount > 0)
                                <span class="badge bg-danger rounded-pill float-end">{{ $supportUnreadCount }}</span>
                            @endif
                        </a>
                    </li>
                @endif

                {{-- Logout --}}
                <li class="nav-item mt-3">

                    <form
                        method="POST"
                        action="{{ route('logout') }}"
                    >

                        @csrf

                        <button
                            type="submit"
                            class="
                                nav-link
                                btn
                                btn-link
                                text-start
                                w-100
                            "
                        >
                            <i class="fas fa-sign-out-alt me-2"></i>
                            Logout
                        </button>

                    </form>

                </li>

            </ul>

        </div>

    </nav>

@endif


<main
    class="main-content"
    id="mainContent"
>

    @yield('content')

</main>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        /*
         * Sidebar clock
         */

        const timeElement =
            document.getElementById(
                'sidebarTime'
            );

        if (timeElement) {

            let [
                hours,
                minutes,
                seconds
            ] =
                timeElement
                    .dataset
                    .time
                    .split(':')
                    .map(Number);

            setInterval(
                function () {

                    seconds++;

                    if (seconds === 60) {

                        seconds = 0;
                        minutes++;

                    }

                    if (minutes === 60) {

                        minutes = 0;
                        hours++;

                    }

                    if (hours === 24) {

                        hours = 0;

                    }

                    timeElement.textContent =
                        String(hours)
                            .padStart(2, '0')
                        +
                        ':'
                        +
                        String(minutes)
                            .padStart(2, '0');

                },
                1000
            );

        }


        /*
         * Mobile sidebar
         */

        const sidebar =
            document.getElementById(
                'sidebar'
            );

        const sidebarToggle =
            document.getElementById(
                'sidebarToggle'
            );

        const sidebarOverlay =
            document.getElementById(
                'sidebarOverlay'
            );

        const mainContent =
            document.getElementById(
                'mainContent'
            );


        function toggleSidebar() {

            if (
                !sidebar
                ||
                !sidebarOverlay
                ||
                !mainContent
            ) {
                return;
            }

            sidebar.classList.toggle(
                'active'
            );

            sidebarOverlay.classList.toggle(
                'active'
            );

            mainContent.classList.toggle(
                'sidebar-active'
            );

        }


        if (sidebarToggle) {

            sidebarToggle.addEventListener(
                'click',
                toggleSidebar
            );

        }


        if (sidebarOverlay) {

            sidebarOverlay.addEventListener(
                'click',
                toggleSidebar
            );

        }

    }
);

</script>


@stack('scripts')

</body>
</html>
