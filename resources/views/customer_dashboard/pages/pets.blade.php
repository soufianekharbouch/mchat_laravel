<div class="mc-pets-page">

    <div class="mc-page-header">
        <div>
            <h1 class="mc-page-title">
                {{ __('dashboard.my_pets') }}
            </h1>

            <p class="mc-page-description">
                {{ __('dashboard.pets_description') }}
            </p>
        </div>
    </div>

    <div
        id="mc-pets-empty"
        class="mc-empty-state customer-hidden"
    >
        {{ __('dashboard.no_pets') }}
    </div>

    <div
        id="mc-pets-grid"
        class="mc-pets-grid"
    ></div>

</div>