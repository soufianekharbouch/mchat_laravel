<div class="mc-orders-page">

    <div class="mc-page-header">
        <div>
            <h1 class="mc-page-title">
                {{ __('dashboard.orders') }}
            </h1>

            <p class="mc-page-description">
                {{ __('dashboard.orders_description') }}
            </p>
        </div>
    </div>

    <div class="mc-orders-sections">

        <section class="mc-orders-section">

            <div class="mc-orders-section-header">

                <div class="mc-orders-section-title-wrap">

                    <div class="mc-orders-section-icon">
                        <i class="fa-solid fa-truck-fast"></i>
                    </div>

                    <div>
                        <h2 class="mc-orders-section-title">
                            {{ __('dashboard.upcoming_orders') }}
                        </h2>

                        <p class="mc-orders-section-subtitle">
                            {{ __('dashboard.upcoming_orders_description') }}
                        </p>
                    </div>

                </div>

                <span
                    id="mc-upcoming-orders-count"
                    class="mc-orders-count"
                >
                    0
                </span>

            </div>

            <div
                id="mc-upcoming-orders-empty"
                class="mc-empty-state customer-hidden"
            >
                {{ __('dashboard.no_upcoming_orders') }}
            </div>

            <div
                id="mc-upcoming-orders-list"
                class="mc-orders-list"
            ></div>

        </section>


        <section class="mc-orders-section">

            <div class="mc-orders-section-header">

                <div class="mc-orders-section-title-wrap">

                    <div class="mc-orders-section-icon mc-orders-section-icon-history">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>

                    <div>
                        <h2 class="mc-orders-section-title">
                            {{ __('dashboard.order_history') }}
                        </h2>

                        <p class="mc-orders-section-subtitle">
                            {{ __('dashboard.order_history_description') }}
                        </p>
                    </div>

                </div>

                <span
                    id="mc-delivered-orders-count"
                    class="mc-orders-count"
                >
                    0
                </span>

            </div>

            <div
                id="mc-delivered-orders-empty"
                class="mc-empty-state customer-hidden"
            >
                {{ __('dashboard.no_delivered_orders') }}
            </div>

            <div
                id="mc-delivered-orders-list"
                class="mc-orders-list"
            ></div>

        </section>

    </div>

</div>