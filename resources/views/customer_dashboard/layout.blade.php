```blade
@php
    $locale = $locale ?? app()->getLocale();
    $isRtl = $locale === 'ar';

    $menuItems = [
        'pets' => [
            'title' => __('dashboard.my_pets'),
            'icon' => 'fa-solid fa-paw',
        ],

        'loyalty-points' => [
            'title' => __('dashboard.loyalty_points'),
            'icon' => 'fa-solid fa-gift',
        ],

        'orders' => [
            'title' => __('dashboard.orders'),
            'icon' => 'fa-solid fa-box',
        ],
    ];
@endphp

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    crossorigin="anonymous"
    referrerpolicy="no-referrer"
>

<div
    id="mc-dashboard"
    class="mc-dashboard {{ $isRtl ? 'mc-rtl' : 'mc-ltr' }}"
    dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
>
    <div
        id="mc-mobile-overlay"
        class="mc-mobile-overlay"
        hidden
    ></div>

    <div class="mc-shell">

        <aside
            id="mc-sidebar"
            class="mc-sidebar"
            aria-label="Customer dashboard navigation"
        >

            <div class="mc-sidebar-header">

                <div class="mc-customer-avatar">
                    <i class="fa-solid fa-user"></i>
                </div>

                <div class="mc-customer-information">

                    <strong id="mc-customer-name">
                        {{ __('dashboard.customer') }}
                    </strong>

                    <span id="mc-customer-points">
                        0 {{ __('dashboard.points') }}
                    </span>

                </div>

                <button
                    type="button"
                    id="mc-mobile-close-button"
                    class="mc-mobile-close-button"
                    aria-label="Close menu"
                >
                    <i class="fa-solid fa-xmark"></i>
                </button>

            </div>

            <nav class="mc-sidebar-menu">

                @foreach ($menuItems as $page => $item)

                    <button
                        type="button"
                        class="mc-menu-item {{ $page === 'pets' ? 'is-active' : '' }}"
                        data-dashboard-page="{{ $page }}"
                    >
                        <i class="{{ $item['icon'] }}"></i>

                        <span>
                            {{ $item['title'] }}
                        </span>
                    </button>

                @endforeach

                <button
                    type="button"
                    id="mc-logout-button"
                    class="mc-menu-item mc-logout-item"
                >
                    <i class="fa-solid fa-right-from-bracket"></i>

                    <span id="mc-logout-button-text">
                        {{ __('dashboard.logout') }}
                    </span>
                </button>

            </nav>

        </aside>

        <main class="mc-main">

            <header class="mc-mobile-header">

                <button
                    type="button"
                    id="mc-mobile-menu-button"
                    class="mc-mobile-menu-button"
                    aria-label="Menu"
                >
                    <i class="fa-solid fa-bars"></i>
                </button>

                <div class="mc-mobile-customer">

                    <strong id="mc-mobile-customer-name">
                        {{ __('dashboard.customer') }}
                    </strong>

                    <span id="mc-mobile-customer-points">
                        0 {{ __('dashboard.points') }}
                    </span>

                </div>

            </header>

            <section class="mc-content-card">

                <div
                    id="mc-page-pets"
                    class="mc-dashboard-page"
                    data-dashboard-content="pets"
                >
                    @include('customer_dashboard.pages.pets')
                </div>

                <div
                    id="mc-page-loyalty-points"
                    class="mc-dashboard-page"
                    data-dashboard-content="loyalty-points"
                    hidden
                >
                    @include('customer_dashboard.pages.loyalty-points')
                </div>

                <div
                    id="mc-page-orders"
                    class="mc-dashboard-page"
                    data-dashboard-content="orders"
                    hidden
                >
                    @include('customer_dashboard.pages.orders')
                </div>

            </section>

        </main>

    </div>
</div>
```
