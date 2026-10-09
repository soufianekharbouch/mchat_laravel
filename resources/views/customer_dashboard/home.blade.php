@verbatim
<script>
(function () {
    const url = new URL(window.location.href);

    const shopifyLocale = "{{ request.locale.iso_code }}"
        .substring(0, 2)
        .toLowerCase();

    const currentLocale = (
        url.searchParams.get('locale') || ''
    )
        .substring(0, 2)
        .toLowerCase();

    if (
        shopifyLocale &&
        currentLocale !== shopifyLocale
    ) {
        url.searchParams.set(
            'locale',
            shopifyLocale
        );

        window.location.replace(
            url.toString()
        );
    }
})();
</script>
@endverbatim

@include('customer_dashboard.partials.styles')

@php
    $locale = $locale ?? app()->getLocale();
    $isRtl = $locale === 'ar';

    $translate = static function (
        string $key,
        string $fallback
    ): string {
        $value = __($key);

        return $value === $key
            ? $fallback
            : $value;
    };

    $translations = [
        'customer_login' => $translate(
            'dashboard.customer_login',
            'Customer login'
        ),

        'login_description' => $translate(
            'dashboard.login_description',
            'Sign in with your phone number and password to access your customer dashboard.'
        ),

        'phone_number' => $translate(
            'dashboard.phone_number',
            'Phone number'
        ),

        'password' => $translate(
            'dashboard.password',
            'Password'
        ),

        'sign_in' => $translate(
            'dashboard.sign_in',
            'Sign in'
        ),

        'signing_in' => $translate(
            'dashboard.signing_in',
            'Signing in...'
        ),

        'loading' => $translate(
            'dashboard.loading',
            'Loading...'
        ),

        'loading_dashboard' => $translate(
            'dashboard.loading_dashboard',
            'Loading your customer dashboard...'
        ),

        'logout' => $translate(
            'dashboard.logout',
            'Logout'
        ),

        'logging_out' => $translate(
            'dashboard.logging_out',
            'Logging out...'
        ),

        'customer' => $translate(
            'dashboard.customer',
            'Customer'
        ),

        'points' => $translate(
            'dashboard.points',
            'points'
        ),

        'no_subscription' => $translate(
            'dashboard.no_subscription',
            'No subscription found'
        ),

        'no_subscription_message' => $translate(
            'dashboard.no_subscription_message',
            'No subscription is linked to your customer account.'
        ),

        'unable_to_load' => $translate(
            'dashboard.unable_to_load',
            'Unable to load your dashboard'
        ),

        'unexpected_error' => $translate(
            'dashboard.unexpected_error',
            'An unexpected error occurred.'
        ),

        'return_to_login' => $translate(
            'dashboard.return_to_login',
            'Return to login'
        ),

        'invalid_phone' => $translate(
            'dashboard.invalid_phone',
            'Please enter your phone number.'
        ),

        'invalid_password' => $translate(
            'dashboard.invalid_password',
            'Please enter your password.'
        ),

        'session_expired' => $translate(
            'dashboard.session_expired',
            'Your session has expired. Please sign in again.'
        ),
        'order' => $translate(
    'dashboard.order',
    'Order'
),

'scheduled_for' => $translate(
    'dashboard.scheduled_for',
    'Scheduled for'
),

'delivered_at' => $translate(
    'dashboard.delivered_at',
    'Delivered at'
),

'prepared_at' => $translate(
    'dashboard.prepared_at',
    'Prepared at'
),

'shipped_at' => $translate(
    'dashboard.shipped_at',
    'Shipped at'
),

'order_items' => $translate(
    'dashboard.order_items',
    'Order items'
),

'planned' => $translate(
    'dashboard.status_planned',
    'Planned'
),

'prepared' => $translate(
    'dashboard.status_prepared',
    'Prepared'
),

'shipped' => $translate(
    'dashboard.status_shipped',
    'Shipped'
),

'delivered' => $translate(
    'dashboard.status_delivered',
    'Delivered'
),

'overdue' => $translate(
    'dashboard.status_overdue',
    'Overdue'
),

'unknown_animal' => $translate(
    'dashboard.unknown_animal',
    'Pet'
),

'unknown_recipe' => $translate(
    'dashboard.unknown_recipe',
    'Recipe'
),
    ];

    $javascriptTranslations = [
        'signIn' => $translations['sign_in'],
        'signingIn' => $translations['signing_in'],
        'logout' => $translations['logout'],
        'loggingOut' => $translations['logging_out'],
        'points' => $translations['points'],
        'customer' => $translations['customer'],
        'invalidPhone' => $translations['invalid_phone'],
        'invalidPassword' => $translations['invalid_password'],
        'sessionExpired' => $translations['session_expired'],
        'noSubscription' => $translations['no_subscription_message'],
        'unexpectedError' => $translations['unexpected_error'],
        'age' => $translate(
    'dashboard.age',
    'Age'
),

'healthStatus' => $translate(
    'dashboard.health_status',
    'Health status'
),

'notes' => $translate(
    'dashboard.notes',
    'Notes'
),
'order' => $translations['order'],

'scheduledFor' =>
    $translations['scheduled_for'],

'deliveredAt' =>
    $translations['delivered_at'],

'preparedAt' =>
    $translations['prepared_at'],

'shippedAt' =>
    $translations['shipped_at'],

'orderItems' =>
    $translations['order_items'],

'planned' =>
    $translations['planned'],

'prepared' =>
    $translations['prepared'],

'shipped' =>
    $translations['shipped'],

'delivered' =>
    $translations['delivered'],

'overdue' =>
    $translations['overdue'],

'unknownAnimal' =>
    $translations['unknown_animal'],

'unknownRecipe' =>
    $translations['unknown_recipe'],
    ];
@endphp

<div
    class="customer-dashboard {{ $isRtl ? 'mc-rtl' : 'mc-ltr' }}"
    dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
    data-locale="{{ $locale }}"
>

    <section id="customer-login-section">

        <div class="customer-login-card">

            <h1 class="customer-login-title">
                {{ $translations['customer_login'] }}
            </h1>

            <p class="customer-login-description">
                {{ $translations['login_description'] }}
            </p>

            <div
                id="customer-login-error"
                class="customer-message customer-message-error customer-hidden"
                role="alert"
            ></div>

            <form
                id="customer-login-form"
                novalidate
            >

                <div class="customer-login-field">

                    <label for="customer-phone">
                        {{ $translations['phone_number'] }}
                    </label>

                    <input
                        id="customer-phone"
                        type="tel"
                        name="phone"
                        required
                        autocomplete="tel"
                        inputmode="tel"
                    >

                </div>

                <div class="customer-login-field">

                    <label for="customer-password">
                        {{ $translations['password'] }}
                    </label>

                    <input
                        id="customer-password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >

                </div>

                <button
                    id="customer-login-button"
                    type="submit"
                    class="customer-login-button"
                >

                    <span id="customer-login-button-text">
                        {{ $translations['sign_in'] }}
                    </span>

                </button>

            </form>

        </div>

    </section>


    <section
        id="customer-loading-section"
        class="customer-hidden"
        aria-live="polite"
    >

        <div class="customer-loading-card">

            <div class="customer-loading-spinner"></div>

            <p class="customer-loading-text">
                {{ $translations['loading_dashboard'] }}
            </p>

        </div>

    </section>


    <section
        id="customer-dashboard-section"
        class="customer-hidden"
    >

        @include('customer_dashboard.layout')

    </section>


    <section
        id="customer-no-subscription-section"
        class="customer-hidden"
    >

        <div class="customer-status-card">

            <div class="mc-status-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <h2>
                {{ $translations['no_subscription'] }}
            </h2>

            <p id="customer-no-subscription-message">
                {{ $translations['no_subscription_message'] }}
            </p>

            <button
                id="customer-no-subscription-logout"
                type="button"
                class="customer-return-login-button"
            >
                {{ $translations['logout'] }}
            </button>

        </div>

    </section>


    <section
        id="customer-error-section"
        class="customer-hidden"
    >

        <div class="customer-status-card">

            <div class="mc-status-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <h2>
                {{ $translations['unable_to_load'] }}
            </h2>

            <p id="customer-general-error-message">
                {{ $translations['unexpected_error'] }}
            </p>

            <button
                id="customer-error-return-button"
                type="button"
                class="customer-return-login-button"
            >
                {{ $translations['return_to_login'] }}
            </button>

        </div>

    </section>

</div>


<script>
(function () {
    'use strict';

    const STORAGE_KEY =
        'customer_dashboard_access_token';

    const LOGIN_URL =
        '/apps/customer-dashboard/customer-login';

    const DATA_URL =
        '/apps/customer-dashboard/customer-data';

    const LOGOUT_URL =
        '/apps/customer-dashboard/customer-logout';

    const LOCALE = @json($locale);

    const TEXT =
        @json($javascriptTranslations);


    const loginSection =
        document.getElementById(
            'customer-login-section'
        );

    const loadingSection =
        document.getElementById(
            'customer-loading-section'
        );

    const dashboardSection =
        document.getElementById(
            'customer-dashboard-section'
        );

    const noSubscriptionSection =
        document.getElementById(
            'customer-no-subscription-section'
        );

    const errorSection =
        document.getElementById(
            'customer-error-section'
        );


    const loginForm =
        document.getElementById(
            'customer-login-form'
        );

    const phoneInput =
        document.getElementById(
            'customer-phone'
        );

    const passwordInput =
        document.getElementById(
            'customer-password'
        );

    const loginButton =
        document.getElementById(
            'customer-login-button'
        );

    const loginButtonText =
        document.getElementById(
            'customer-login-button-text'
        );

    const loginError =
        document.getElementById(
            'customer-login-error'
        );


    const logoutButton =
        document.getElementById(
            'mc-logout-button'
        );

    const mobileLogoutButton =
        document.getElementById(
            'mc-mobile-logout-button'
        );


    const noSubscriptionLogoutButton =
        document.getElementById(
            'customer-no-subscription-logout'
        );

    const errorReturnButton =
        document.getElementById(
            'customer-error-return-button'
        );


    const noSubscriptionMessage =
        document.getElementById(
            'customer-no-subscription-message'
        );

    const generalErrorMessage =
        document.getElementById(
            'customer-general-error-message'
        );


    const customerName =
        document.getElementById(
            'mc-customer-name'
        );

    const mobileCustomerName =
        document.getElementById(
            'mc-mobile-customer-name'
        );

    const customerPoints =
        document.getElementById(
            'mc-customer-points'
        );

    const mobileCustomerPoints =
        document.getElementById(
            'mc-mobile-customer-points'
        );


    const sidebar =
        document.getElementById(
            'mc-sidebar'
        );

    const mobileOverlay =
        document.getElementById(
            'mc-mobile-overlay'
        );

    const mobileMenuButton =
        document.getElementById(
            'mc-mobile-menu-button'
        );

    const mobileCloseButton =
        document.getElementById(
            'mc-mobile-close-button'
        );


    const sections = [
        loginSection,
        loadingSection,
        dashboardSection,
        noSubscriptionSection,
        errorSection
    ];


    function hideAllSections() {

        sections.forEach(
            function (section) {

                if (section) {
                    section.classList.add(
                        'customer-hidden'
                    );
                }

            }
        );

    }


    function showSection(section) {

        hideAllSections();

        if (section) {
            section.classList.remove(
                'customer-hidden'
            );
        }

    }


    function showLogin(message = '') {

        closeMobileMenu();

        showSection(
            loginSection
        );

        if (!loginError) {
            return;
        }

        loginError.textContent =
            message;

        loginError.classList.toggle(
            'customer-hidden',
            message === ''
        );

    }


    function showLoading() {

        showSection(
            loadingSection
        );

    }


    function showNoSubscription(message = '') {

        if (noSubscriptionMessage) {

            noSubscriptionMessage.textContent =
                message ||
                TEXT.noSubscription;

        }

        showSection(
            noSubscriptionSection
        );

    }


    function showGeneralError(message = '') {

        if (generalErrorMessage) {

            generalErrorMessage.textContent =
                message ||
                TEXT.unexpectedError;

        }

        showSection(
            errorSection
        );

    }


    function formatNumber(value) {

        const numericValue =
            Number(value);

        if (
            !Number.isFinite(
                numericValue
            )
        ) {
            return '0';
        }

        return new Intl.NumberFormat(
            LOCALE
        ).format(
            numericValue
        );

    }


    function getStoredToken() {

        return sessionStorage.getItem(
            STORAGE_KEY
        );

    }


    function storeToken(token) {

        sessionStorage.setItem(
            STORAGE_KEY,
            token
        );

    }


    function removeStoredToken() {

        sessionStorage.removeItem(
            STORAGE_KEY
        );

    }


    function extractErrorMessage(
        result,
        fallback
    ) {

        if (
            result &&
            typeof result.message === 'string' &&
            result.message.trim() !== ''
        ) {

            return result.message;

        }


        if (
            result &&
            result.errors &&
            typeof result.errors === 'object'
        ) {

            const firstErrorGroup =
                Object.values(
                    result.errors
                )[0];

            if (
                Array.isArray(
                    firstErrorGroup
                ) &&
                firstErrorGroup.length > 0
            ) {

                return String(
                    firstErrorGroup[0]
                );

            }

        }


        return fallback;

    }


    async function requestJson(
        url,
        payload
    ) {

        let response;


        try {

            response = await fetch(
                url,
                {
                    method: 'POST',

                    headers: {
                        'Accept':
                            'application/json',

                        'Content-Type':
                            'application/json',

                        'X-Requested-With':
                            'XMLHttpRequest'
                    },

                    body:
                        JSON.stringify(
                            payload
                        )
                }
            );

        } catch (networkError) {

            const error =
                new Error(
                    'Unable to connect to the server.'
                );

            error.status = 0;

            throw error;

        }


        const rawResponse =
            await response.text();


        let result;


        try {

            result =
                rawResponse
                    ? JSON.parse(
                        rawResponse
                    )
                    : {};

        } catch (parseError) {

            const error =
                new Error(
                    'The server did not return JSON.'
                );

            error.status =
                response.status;

            error.rawResponse =
                rawResponse;

            throw error;

        }


        if (!response.ok) {

            const error =
                new Error(
                    extractErrorMessage(
                        result,
                        'An error occurred.'
                    )
                );

            error.status =
                response.status;

            error.result =
                result;

            throw error;

        }


        return result;

    }


    function showDashboard(result) {

        const subscription =
            result.subscription || {};

        const customer =
            result.customer || {};


        const fullName = [

            subscription.first_name,
            subscription.last_name

        ]
            .filter(Boolean)
            .join(' ')
            .trim() ||
            TEXT.customer;


        const points =
            formatNumber(
                subscription.valid_loyalty_points ||
                0
            );


        if (customerName) {

            customerName.textContent =
                fullName;

        }


        if (mobileCustomerName) {

            mobileCustomerName.textContent =
                fullName;

        }


        if (customerPoints) {

            customerPoints.textContent =
                points +
                ' ' +
                TEXT.points;

        }


        if (mobileCustomerPoints) {

            mobileCustomerPoints.textContent =
                points +
                ' ' +
                TEXT.points;

        }

        renderPets(
            subscription.animals || []
        );
        
        renderOrders(
            subscription.orders || []
        );

        showSection(
            dashboardSection
        );


        activatePage(
            'pets'
        );

    }


    function activatePage(pageName) {

        const pages =
            document.querySelectorAll(
                '[data-dashboard-content]'
            );


        let pageExists = false;


        pages.forEach(
            function (page) {

                const isCurrent =
                    page.dataset
                        .dashboardContent ===
                    pageName;

                page.hidden =
                    !isCurrent;

                if (isCurrent) {
                    pageExists = true;
                }

            }
        );


        if (!pageExists) {

            const firstPage =
                document.querySelector(
                    '[data-dashboard-content]'
                );

            if (firstPage) {

                firstPage.hidden =
                    false;

                pageName =
                    firstPage.dataset
                        .dashboardContent;

            }

        }


        document
            .querySelectorAll(
                '[data-dashboard-page]'
            )
            .forEach(
                function (button) {

                    button.classList.toggle(
                        'is-active',
                        button.dataset
                            .dashboardPage ===
                        pageName
                    );

                }
            );


        closeMobileMenu();

    }


    function openMobileMenu() {

        if (!sidebar) {
            return;
        }


        sidebar.classList.add(
            'is-open'
        );


        if (mobileOverlay) {

            mobileOverlay.hidden =
                false;

        }


        document.body.classList.add(
            'mc-menu-open'
        );

    }


    function closeMobileMenu() {

        if (sidebar) {

            sidebar.classList.remove(
                'is-open'
            );

        }


        if (mobileOverlay) {

            mobileOverlay.hidden =
                true;

        }


        document.body.classList.remove(
            'mc-menu-open'
        );

    }


    async function loadDashboard(token) {

        if (!token) {

            showLogin();

            return;

        }


        showLoading();


        try {

            const result =
                await requestJson(
                    DATA_URL,
                    {
                        access_token:
                            token
                    }
                );


            if (
                !result ||
                result.success !== true
            ) {

                throw new Error(
                    result?.message ||
                    TEXT.unexpectedError
                );

            }


            if (!result.subscription) {

                showNoSubscription();

                return;

            }


            showDashboard(
                result
            );

        } catch (error) {

            console.error(
                'Dashboard loading error:',
                error
            );


            if (
                error.status === 401 ||
                error.status === 403
            ) {

                removeStoredToken();

                showLogin(
                    TEXT.sessionExpired
                );

                return;

            }


            if (
                error.status === 404
            ) {

                showNoSubscription(
                    error.message
                );

                return;

            }


            showGeneralError(
                error.message ||
                TEXT.unexpectedError
            );

        }

    }


    async function logoutCustomer() {

        const token =
            getStoredToken();


        if (logoutButton) {

            logoutButton.disabled =
                true;

        }


        if (mobileLogoutButton) {

            mobileLogoutButton.disabled =
                true;

        }


        try {

            if (token) {

                await requestJson(
                    LOGOUT_URL,
                    {
                        access_token:
                            token
                    }
                );

            }

        } catch (error) {

            console.error(
                'Customer logout failed:',
                error
            );

        } finally {

            removeStoredToken();


            if (logoutButton) {

                logoutButton.disabled =
                    false;

            }


            if (mobileLogoutButton) {

                mobileLogoutButton.disabled =
                    false;

            }


            if (loginForm) {

                loginForm.reset();

            }


            showLogin();

        }

    }


    if (loginForm) {

        loginForm.addEventListener(
            'submit',
            async function (event) {

                event.preventDefault();


                const phone =
                    phoneInput
                        ? phoneInput.value.trim()
                        : '';


                const password =
                    passwordInput
                        ? passwordInput.value
                        : '';


                if (!phone) {

                    showLogin(
                        TEXT.invalidPhone
                    );


                    if (phoneInput) {
                        phoneInput.focus();
                    }


                    return;

                }


                if (!password) {

                    showLogin(
                        TEXT.invalidPassword
                    );


                    if (passwordInput) {
                        passwordInput.focus();
                    }


                    return;

                }


                if (loginError) {

                    loginError.classList.add(
                        'customer-hidden'
                    );

                }


                if (loginButton) {

                    loginButton.disabled =
                        true;

                }


                if (loginButtonText) {

                    loginButtonText.textContent =
                        TEXT.signingIn;

                }


                try {

                    const result =
                        await requestJson(
                            LOGIN_URL,
                            {
                                phone:
                                    phone,

                                password:
                                    password,

                                locale:
                                    LOCALE
                            }
                        );


                    if (
                        !result ||
                        result.success !== true ||
                        !result.access_token
                    ) {

                        throw new Error(
                            result?.message ||
                            'Unable to sign in.'
                        );

                    }


                    storeToken(
                        result.access_token
                    );


                    if (passwordInput) {

                        passwordInput.value =
                            '';

                    }


                    await loadDashboard(
                        result.access_token
                    );

                } catch (error) {

                    removeStoredToken();


                    showLogin(
                        error.message ||
                        TEXT.unexpectedError
                    );

                } finally {

                    if (loginButton) {

                        loginButton.disabled =
                            false;

                    }


                    if (loginButtonText) {

                        loginButtonText.textContent =
                            TEXT.signIn;

                    }

                }

            }
        );

    }


    document
        .querySelectorAll(
            '[data-dashboard-page]'
        )
        .forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        const pageName =
                            button.dataset
                                .dashboardPage;

                        if (!pageName) {
                            return;
                        }


                        activatePage(
                            pageName
                        );

                    }
                );

            }
        );


    if (logoutButton) {

        logoutButton.addEventListener(
            'click',
            logoutCustomer
        );

    }


    if (mobileLogoutButton) {

        mobileLogoutButton.addEventListener(
            'click',
            logoutCustomer
        );

    }


    if (noSubscriptionLogoutButton) {

        noSubscriptionLogoutButton.addEventListener(
            'click',
            logoutCustomer
        );

    }


    if (errorReturnButton) {

        errorReturnButton.addEventListener(
            'click',
            function () {

                removeStoredToken();

                showLogin();

            }
        );

    }


    if (mobileMenuButton) {

        mobileMenuButton.addEventListener(
            'click',
            openMobileMenu
        );

    }


    if (mobileCloseButton) {

        mobileCloseButton.addEventListener(
            'click',
            closeMobileMenu
        );

    }


    if (mobileOverlay) {

        mobileOverlay.addEventListener(
            'click',
            closeMobileMenu
        );

    }


    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape'
            ) {

                closeMobileMenu();

            }

        }
    );


    const existingToken =
        getStoredToken();


    if (existingToken) {

        loadDashboard(
            existingToken
        );

    } else {

        showLogin();

    }
    
    const petsGrid =
    document.getElementById(
        'mc-pets-grid'
    );

const petsEmpty =
    document.getElementById(
        'mc-pets-empty'
    );
    
    function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}


function renderPets(animals) {

    if (!petsGrid) {
        return;
    }

    const pets =
        Array.isArray(animals)
            ? animals
            : [];


    petsGrid.innerHTML = '';


    if (pets.length === 0) {

        if (petsEmpty) {
            petsEmpty.classList.remove(
                'customer-hidden'
            );
        }

        return;
    }


    if (petsEmpty) {
        petsEmpty.classList.add(
            'customer-hidden'
        );
    }


    pets.forEach(function (animal) {

        const isDog =
            animal.species === 'Dog';

        const speciesIcon =
            isDog
                ? 'fa-dog'
                : 'fa-cat';


        const size =
            animal.breed_size_category
                ? `
                    <span class="mc-pet-meta-separator">
                        •
                    </span>

                    <span>
                        ${escapeHtml(
                            animal.breed_size_category
                        )}
                    </span>
                `
                : '';


        const card =
            document.createElement(
                'article'
            );


        card.className =
            'mc-pet-card';


        card.innerHTML = `
            <div class="mc-pet-card-header">

                <div class="mc-pet-icon">
                    <i
                        class="fa-solid ${speciesIcon}"
                        aria-hidden="true"
                    ></i>
                </div>

                <div class="mc-pet-heading">

                    <h2 class="mc-pet-name">
                        ${escapeHtml(
                            animal.name || '—'
                        )}
                    </h2>

                    <div class="mc-pet-meta">

                        <span>
                            ${escapeHtml(
                                animal.species || '—'
                            )}
                        </span>

                        ${size}

                    </div>

                </div>

            </div>

            <div class="mc-pet-details">

                <div class="mc-pet-detail">

                    <span class="mc-pet-detail-label">
                        ${escapeHtml(TEXT.age)}
                    </span>

                    <strong>
                        ${escapeHtml(
                            animal.age || '—'
                        )}
                    </strong>

                </div>

                <div class="mc-pet-detail">

                    <span class="mc-pet-detail-label">
                        ${escapeHtml(TEXT.healthStatus)}
                    </span>

                    <strong>
                        ${escapeHtml(
                            animal.health_status || '—'
                        )}
                    </strong>

                </div>

                ${
                    animal.note
                        ? `
                            <div class="
                                mc-pet-detail
                                mc-pet-detail-full
                            ">

                                <span class="
                                    mc-pet-detail-label
                                ">
                                    ${escapeHtml(
                                        TEXT.notes
                                    )}
                                </span>

                                <p>
                                    ${escapeHtml(
                                        animal.note
                                    )}
                                </p>

                            </div>
                        `
                        : ''
                }

            </div>
        `;


        petsGrid.appendChild(
            card
        );

    });

}
function formatDate(value) {

    if (!value) {
        return '—';
    }

    const date =
        new Date(
            value.length === 10
                ? value + 'T00:00:00'
                : value
        );

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return value;
    }

    return new Intl.DateTimeFormat(
        LOCALE,
        {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        }
    ).format(
        date
    );
}


function formatDateTime(value) {

    if (!value) {
        return '—';
    }

    const date =
        new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return value;
    }

    return new Intl.DateTimeFormat(
        LOCALE,
        {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }
    ).format(
        date
    );
}
function renderOrders(orders) {

    const upcomingList =
        document.getElementById(
            'mc-upcoming-orders-list'
        );

    const historyList =
        document.getElementById(
            'mc-delivered-orders-list'
        );

    const upcomingEmpty =
        document.getElementById(
            'mc-upcoming-orders-empty'
        );

    const historyEmpty =
        document.getElementById(
            'mc-delivered-orders-empty'
        );

    const upcomingCount =
        document.getElementById(
            'mc-upcoming-orders-count'
        );

    const historyCount =
        document.getElementById(
            'mc-delivered-orders-count'
        );

    if (
        !upcomingList ||
        !historyList
    ) {
        return;
    }

    const allOrders =
        Array.isArray(orders)
            ? orders
            : [];

    const upcomingOrders =
        allOrders.filter(
            function (order) {
                return (
                    order.dashboard_status ===
                    'planned'
                );
            }
        );

    const historyOrders =
        allOrders.filter(
            function (order) {
                return (
                    order.dashboard_status ===
                        'prepared' ||
                    order.dashboard_status ===
                        'overdue'
                );
            }
        );

    upcomingOrders.sort(
        function (a, b) {
            return (
                new Date(
                    a.scheduled_for +
                    'T00:00:00'
                ) -
                new Date(
                    b.scheduled_for +
                    'T00:00:00'
                )
            );
        }
    );

    historyOrders.sort(
        function (a, b) {
            return (
                new Date(
                    b.scheduled_for +
                    'T00:00:00'
                ) -
                new Date(
                    a.scheduled_for +
                    'T00:00:00'
                )
            );
        }
    );

    upcomingList.innerHTML = '';
    historyList.innerHTML = '';

    if (upcomingCount) {
        upcomingCount.textContent =
            String(
                upcomingOrders.length
            );
    }

    if (historyCount) {
        historyCount.textContent =
            String(
                historyOrders.length
            );
    }

    if (upcomingEmpty) {
        upcomingEmpty.classList.toggle(
            'customer-hidden',
            upcomingOrders.length > 0
        );
    }

    if (historyEmpty) {
        historyEmpty.classList.toggle(
            'customer-hidden',
            historyOrders.length > 0
        );
    }

    upcomingOrders.forEach(
        function (order) {
            upcomingList.appendChild(
                createOrderCard(order)
            );
        }
    );

    historyOrders.forEach(
        function (order) {
            historyList.appendChild(
                createOrderCard(order)
            );
        }
    );
}

function createOrderCard(order) {

    const status =
        order.dashboard_status
        'planned00';


    const animal =
        order.animal || {};


    const animalName =
        animal.name ||
        TEXT.unknownAnimal;


    const animalSpecies =
        animal.species || '';


    const animalIcon =
        animalSpecies === 'Dog'
            ? 'fa-dog'
            : 'fa-cat';


    const statusLabels = {
        planned:
            TEXT.planned,

        prepared:
            TEXT.prepared,

        shipped:
            TEXT.shipped,

        delivered:
            TEXT.delivered,

        overdue:
            TEXT.overdue
    };


    const statusLabel =
        statusLabels[status] ||
        status;


    const items =
        Array.isArray(
            order.items
        )
            ? order.items
            : [];


    const itemsHtml =
        items.length > 0
            ? items
                .map(
                    function (item) {

                        const recipeName =
                            item.recipe?.name ||
                            TEXT.unknownRecipe;

                        return `
                            <div class="mc-order-item">

                                <span>
                                    ${escapeHtml(
                                        recipeName
                                    )}
                                </span>

                                <span class="
                                    mc-order-item-quantity
                                ">
                                    ×${escapeHtml(
                                        item.quantity || 0
                                    )}
                                </span>

                            </div>
                        `;
                    }
                )
                .join('')
            : '';


    const preparedCompleted =
        Boolean(
            order.prepared_at ||
            [
                'prepared',
                'shipped',
                'delivered'
            ].includes(status)
        );


    const shippedCompleted =
        Boolean(
            order.shipped_at ||
            [
                'shipped',
                'delivered'
            ].includes(status)
        );


    const deliveredCompleted =
        Boolean(
            order.delivered_at ||
            status === 'delivered'
        );


    const card =
        document.createElement(
            'article'
        );


    card.className =
        'mc-order-card';


    card.innerHTML = `

        <div class="mc-order-card-header">

            <div class="mc-order-main-info">

                <div class="mc-order-animal-icon">

                    <i
                        class="fa-solid ${animalIcon}"
                        aria-hidden="true"
                    ></i>

                </div>

                <div class="mc-order-heading">

                    <h3 class="mc-order-title">

                        ${escapeHtml(
                            TEXT.order
                        )}

                        #${escapeHtml(
                            order.id || ''
                        )}

                    </h3>

                    <div class="mc-order-animal">

                        <i
                            class="fa-solid fa-paw"
                            aria-hidden="true"
                        ></i>

                        <span>
                            ${escapeHtml(
                                animalName
                            )}
                        </span>

                    </div>

                </div>

            </div>


            <span
                class="
                    mc-order-status
                    mc-order-status-${escapeHtml(status)}
                "
            >
                ${escapeHtml(
                    statusLabel
                )}
            </span>

        </div>


        <div class="mc-order-info-grid">

            <div class="mc-order-info-item">

                <span class="mc-order-info-label">
                    ${escapeHtml(
                        TEXT.scheduledFor
                    )}
                </span>

                <strong class="mc-order-info-value">
                    ${escapeHtml(
                        formatDate(
                            order.scheduled_for
                        )
                    )}
                </strong>

            </div>


            ${
                order.prepared_at
                    ? `
                        <div class="
                            mc-order-info-item
                        ">

                            <span class="
                                mc-order-info-label
                            ">
                                ${escapeHtml(
                                    TEXT.preparedAt
                                )}
                            </span>

                            <strong class="
                                mc-order-info-value
                            ">
                                ${escapeHtml(
                                    formatDateTime(
                                        order.prepared_at
                                    )
                                )}
                            </strong>

                        </div>
                    `
                    : ''
            }


            ${
                order.shipped_at
                    ? `
                        <div class="
                            mc-order-info-item
                        ">

                            <span class="
                                mc-order-info-label
                            ">
                                ${escapeHtml(
                                    TEXT.shippedAt
                                )}
                            </span>

                            <strong class="
                                mc-order-info-value
                            ">
                                ${escapeHtml(
                                    formatDateTime(
                                        order.shipped_at
                                    )
                                )}
                            </strong>

                        </div>
                    `
                    : ''
            }


            ${
                order.delivered_at
                    ? `
                        <div class="
                            mc-order-info-item
                        ">

                            <span class="
                                mc-order-info-label
                            ">
                                ${escapeHtml(
                                    TEXT.deliveredAt
                                )}
                            </span>

                            <strong class="
                                mc-order-info-value
                            ">
                                ${escapeHtml(
                                    formatDateTime(
                                        order.delivered_at
                                    )
                                )}
                            </strong>

                        </div>
                    `
                    : ''
            }

        </div>


        ${
            items.length > 0
                ? `
                    <div class="mc-order-items">

                        <h4 class="
                            mc-order-items-title
                        ">

                            <i
                                class="
                                    fa-solid
                                    fa-bowl-food
                                "
                                aria-hidden="true"
                            ></i>

                            ${escapeHtml(
                                TEXT.orderItems
                            )}

                        </h4>

                        <div class="
                            mc-order-items-list
                        ">
                            ${itemsHtml}
                        </div>

                    </div>
                `
                : ''
        }


        <div class="mc-order-timeline">

            <div
                class="
                    mc-order-timeline-step
                    ${preparedCompleted
                        ? 'is-completed'
                        : ''}
                "
            >

                <span class="
                    mc-order-timeline-dot
                ">
                    <i class="
                        fa-solid
                        fa-box
                    "></i>
                </span>

            </div>


            <div
                class="
                    mc-order-timeline-step
                    ${shippedCompleted
                        ? 'is-completed'
                        : ''}
                "
            >

                <span class="
                    mc-order-timeline-dot
                ">
                    <i class="
                        fa-solid
                        fa-truck
                    "></i>
                </span>

            </div>


            <div
                class="
                    mc-order-timeline-step
                    ${deliveredCompleted
                        ? 'is-completed'
                        : ''}
                "
            >

                <span class="
                    mc-order-timeline-dot
                ">
                    <i class="
                        fa-solid
                        fa-check
                    "></i>
                </span>

            </div>

        </div>
    `;


    return card;
}
})();


</script>