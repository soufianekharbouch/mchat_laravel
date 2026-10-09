@php
    $locale = $locale ?? app()->getLocale();
    $isRtl = $locale === 'ar';
@endphp

<style>
.customer-login-page {
    max-width: 520px;
    margin: 50px auto;
    padding: 20px;
    font-family: Arial, Helvetica, sans-serif;
}

.customer-login-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 38px;
    box-shadow: 0 10px 35px rgba(0, 0, 0, .08);
}

.customer-login-title {
    margin: 0 0 10px;
    font-size: 30px;
    color: #202336;
}

.customer-login-description {
    margin: 0 0 28px;
    color: #6d7280;
    line-height: 1.6;
}

.customer-login-field {
    margin-bottom: 20px;
}

.customer-login-field label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #303446;
}

.customer-login-field input {
    width: 100%;
    height: 52px;
    border: 1px solid #d9dce5;
    border-radius: 12px;
    padding: 0 15px;
    font-size: 16px;
    box-sizing: border-box;
    outline: none;
}

.customer-login-field input:focus {
    border-color: #5b7cfa;
    box-shadow: 0 0 0 3px rgba(91, 124, 250, .12);
}

.customer-login-button {
    width: 100%;
    min-height: 52px;
    border: 0;
    border-radius: 12px;
    background: linear-gradient(
        135deg,
        #5b7cfa,
        #6e4dff
    );
    color: #ffffff;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
}

.customer-login-button:disabled {
    opacity: .65;
    cursor: not-allowed;
}

.customer-login-error {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 10px;
    background: #fff0f0;
    color: #c0392b;
    font-size: 14px;
    line-height: 1.5;
}

.customer-login-page[dir="rtl"] {
    text-align: right;
}

.customer-login-page[dir="rtl"] .customer-login-field input {
    direction: rtl;
    text-align: right;
}

.customer-login-page[dir="ltr"] {
    text-align: left;
}

@media (max-width: 600px) {
    .customer-login-page {
        margin: 20px auto;
        padding: 15px;
    }

    .customer-login-card {
        padding: 26px 20px;
    }

    .customer-login-title {
        font-size: 25px;
    }
}
</style>

<div
    class="customer-login-page"
    dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
    lang="{{ $locale }}"
>
    <div class="customer-login-card">

        <h1 class="customer-login-title">
            {{ __('dashboard.customer_login') }}
        </h1>

        <p class="customer-login-description">
            {{ __('dashboard.login_description') }}
        </p>

        @if (!empty($loginError))
            <div class="customer-login-error">
                {{ __('dashboard.invalid_credentials') }}
            </div>
        @endif

        @if (!empty($accountError))
            <div class="customer-login-error">
                {{ $accountError }}
            </div>
        @endif

        <form
            method="POST"
            action="/apps/customer-dashboard/customer-login"
        >
            <input
                type="hidden"
                name="locale"
                value="{{ $locale }}"
            >

            <div class="customer-login-field">
                <label for="customer-phone">
                    {{ __('dashboard.phone_number') }}
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
                    {{ __('dashboard.password') }}
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
                type="submit"
                class="customer-login-button"
            >
                {{ __('dashboard.sign_in') }}
            </button>
        </form>

    </div>
</div>