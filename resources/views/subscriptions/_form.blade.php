@php
    $isEdit = isset($subscription);

    /*
    |--------------------------------------------------------------------------
    | Customer dashboard account status
    |--------------------------------------------------------------------------
    |
    | Requires these customer_accounts columns:
    | - initial_password_encrypted (nullable text)
    | - password_changed_at (nullable timestamp)
    |
    */
    $customerAccount = $isEdit
        ? $subscription->customerAccount
        : null;

    $customerAccountExists = !empty($customerAccount);

    $customerUsesOwnPassword = $customerAccountExists
        && !empty($customerAccount->password_changed_at);

    $customerInitialPassword = null;

    if (
        $customerAccountExists
        && !$customerUsesOwnPassword
        && !empty($customerAccount->initial_password_encrypted)
    ) {
        try {
            $customerInitialPassword =
                \Illuminate\Support\Facades\Crypt::decryptString(
                    $customerAccount->initial_password_encrypted
                );
        } catch (\Throwable $e) {
            $customerInitialPassword = null;
        }
    }

    $provinceCodes = $provinces->mapWithKeys(function ($province) {
        return [
            $province->name => str_pad($province->code, 2, '0', STR_PAD_LEFT)
        ];
    })->toArray();
    $selectedProvince = old('subscriber_province', $subscription->subscriber_province ?? '');
    $selectedZone = old('subscriber_zone', $subscription->subscriber_zone ?? '');
    $selectedDeliverySlot = old('subscriber_delivery_slot', $subscription->subscriber_delivery_slot ?? '');

    $oldAnimals = old('animals');
    $existingAnimals = $isEdit ? ($subscription->animals ?? collect()) : collect();

    $animalsData = $oldAnimals ?? ($existingAnimals->count() ? ($existingAnimals->map(function($a) {
        $days = [];

        $mealDates = $a->relationLoaded('mealDates') ? ($a->mealDates ?? collect()) : collect();
        if (!is_iterable($mealDates)) $mealDates = collect();

        foreach ($mealDates as $m) {
            $d = null;

            if (!empty($m->meal_date)) {
                try {
                    $d = \Carbon\Carbon::parse($m->meal_date)->format('Y-m-d');
                } catch (\Throwable $e) {
                    $d = null;
                }
            }

            if (!$d) continue;

            if (!isset($days[$d])) $days[$d] = [];
            $days[$d][(int) $m->recipe_id] = (int) $m->quantity;
        }

        return [
            'id' => $a->id,
            'name' => $a->name,
            'species' => $a->species,
            'breed_size_category' => $a->breed_size_category ?? '',
            'age' => $a->age,
            'health_status' => $a->health_status,
            'note' => $a->note,
            'photo_path' => $a->photo_path ?? '',
            'transition' => (int) ($a->transition ?? 0),
            'qr_code_image' => $a->qr_code_image ?? '',
            'last_order' => (int) ($a->last_order ?? 0),
            'last_order_qr_code_image' => $a->last_order_qr_code_image ?? '',
            'subscription_start' => optional($a->subscription_start)->format('Y-m-d'),
            'subscription_end' => optional($a->subscription_end)->format('Y-m-d'),
            'day_meals' => $days,
        ];
    })->toArray()) : []);

    if (!$animalsData) {
        $animalsData = [[
            'name' => '',
            'species' => 'Cat',
            'breed_size_category' => '',
            'age' => '',
            'health_status' => '',
            'note' => '',
            'photo_path' => '',
            'transition' => 0,
            'qr_code_image' => '',
            'last_order' => 0,
            'last_order_qr_code' => '',
            'subscription_start' => '',
            'subscription_end' => '',
            'day_meals' => [],
        ]];
    }

    $deliverySlots = [
        '09:00-12:00' => '09:00 → 12:00',
        '12:00-15:00' => '12:00 → 15:00',
    ];

    if (!empty($selectedDeliverySlot) && !isset($deliverySlots[$selectedDeliverySlot])) {
        $deliverySlots = [$selectedDeliverySlot => $selectedDeliverySlot] + $deliverySlots;
    }

    $animalAgeOptionsCat = [
        'Kitten' => 'Kitten',
        'Adult' => 'Adult',
        'Senior' => 'Senior',
    ];

    $animalAgeOptionsDog = [
        'Puppy' => 'Puppy',
        'Adult' => 'Adult',
        'Senior' => 'Senior',
    ];

    $animalSpeciesOptions = [
        'Cat' => 'Cat',
        'Dog' => 'Dog',
    ];

    $breedSizeCategoryOptions = [
        'Small' => 'Small',
        'Medium' => 'Medium',
        'Large' => 'Large',
    ];
@endphp

<form method="POST" action="{{ $isEdit ? route('subscriptions.update', $subscription) : route('subscriptions.store') }}" enctype="multipart/form-data">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="code" class="form-label">Code *</label>
            <input type="text"
                class="form-control"
                id="code"
                name="code"
                value="{{ old('code', $subscription->code ?? ($nextSubscriptionCode ?? '')) }}"
                required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="creation_date" class="form-label">Creation Date</label>
            <input type="date" class="form-control" id="creation_date" name="creation_date"
                   value="{{ old('creation_date', $isEdit ? optional($subscription->creation_date)->format('Y-m-d') : now()->format('Y-m-d')) }}" readonly>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="subscriber_first_name" class="form-label">First Name *</label>
            <input type="text" class="form-control" id="subscriber_first_name" name="subscriber_first_name"
                   value="{{ old('subscriber_first_name', $subscription->subscriber_first_name ?? '') }}" required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="subscriber_last_name" class="form-label">Last Name</label>
            <input type="text" class="form-control" id="subscriber_last_name" name="subscriber_last_name"
                   value="{{ old('subscriber_last_name', $subscription->subscriber_last_name ?? '') }}">
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="subscriber_province" class="form-label">Province/Region *</label>
            <select class="form-select" id="subscriber_province" name="subscriber_province" required>
                <option value="">Select province</option>
                @foreach($provinces as $province)
                    <option value="{{ $province->name }}" {{ $selectedProvince === $province->name ? 'selected' : '' }}>
                        {{ $province->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-md-4 mb-3">
            <label for="subscriber_zone" class="form-label">Zone *</label>
            <select class="form-select"
                    id="subscriber_zone"
                    name="subscriber_zone"
                    data-selected-zone="{{ $selectedZone }}"
                    required>
                <option value="">Select zone</option>
            </select>
        </div>

        <div class="col-md-4 mb-3">
            <label for="subscriber_address" class="form-label">Address details *</label>
            <textarea class="form-control" id="subscriber_address" name="subscriber_address" rows="3" required>{{ old('subscriber_address', $subscription->subscriber_address ?? '') }}</textarea>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="subscriber_phone" class="form-label">Phone</label>
            <input
                type="text"
                class="form-control @error('subscriber_phone') is-invalid @enderror"
                id="subscriber_phone"
                name="subscriber_phone"
                value="{{ old('subscriber_phone', $subscription->subscriber_phone ?? '') }}"
            >

            @error('subscriber_phone')
                <div class="invalid-feedback">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div class="col-md-4 mb-3">
            <label class="form-label">
                Customer Dashboard
            </label>

            @if(!$isEdit)
                <div class="customer-account-status-box customer-account-status-pending">
                    <div class="customer-account-status-title">
                        <i class="fas fa-user-clock me-2"></i>
                        Dashboard will be activated automatically
                    </div>

                    <div class="customer-account-status-text">
                        A 6-digit initial password will be generated when the subscription is created.
                    </div>
                </div>

            @elseif(!$customerAccountExists)
                <div class="customer-account-status-box customer-account-status-warning">
                    <div class="customer-account-status-title">
                        <i class="fas fa-triangle-exclamation me-2"></i>
                        Customer account is not initialized
                    </div>

                    <div class="customer-account-status-text">
                        This subscription does not currently have a customer dashboard account.
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-warning mt-2 reset-customer-account-btn"
                        data-reset-url="{{ route('subscriptions.customer-account.reset', $subscription) }}"
                    >
                        <i class="fas fa-key me-1"></i>
                        Initialiser le compte
                    </button>
                </div>

            @elseif(!$customerUsesOwnPassword)
                <div class="customer-account-status-box customer-account-status-initial">
                    <div class="customer-account-status-title">
                        <i class="fas fa-key me-2"></i>
                        Customer has not changed the initial password yet
                    </div>

                    @if($customerInitialPassword)
                        <div class="customer-account-password-row">
                            <span class="customer-account-password-label">
                                Initial password
                            </span>

                            <span class="customer-account-password-value">
                                {{ $customerInitialPassword }}
                            </span>
                        </div>
                    @else
                        <div class="customer-account-status-text text-danger">
                            The initial password cannot be displayed for this existing account.
                        </div>

                        <button
                            type="button"
                            class="btn btn-sm btn-warning mt-2 reset-customer-account-btn"
                            data-reset-url="{{ route('subscriptions.customer-account.reset', $subscription) }}"
                        >
                            <i class="fas fa-key me-1"></i>
                            Initialiser le compte
                        </button>
                    @endif
                </div>

            @else
                <div class="customer-account-status-box customer-account-status-active">
                    <div class="customer-account-status-title">
                        <i class="fas fa-user-check me-2"></i>
                        Customer uses their own password
                    </div>

                    <div class="customer-account-status-text">
                        The customer has already changed the initial password.
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-danger mt-2 reset-customer-account-btn"
                        data-reset-url="{{ route('subscriptions.customer-account.reset', $subscription) }}"
                    >
                        <i class="fas fa-rotate me-1"></i>
                        Initialiser le compte
                    </button>
                </div>
            @endif
        </div>

        {{-- Shopify Customer ID is currently hidden from the form.
             Keep the current value on edit so it is not lost. --}}
        <input
            type="hidden"
            id="shopify_customer_id"
            name="shopify_customer_id"
            value="{{ old('shopify_customer_id', $subscription->shopify_customer_id ?? '') }}"
        >

        <div class="col-md-4 mb-3">
            <label for="subscriber_delivery_slot" class="form-label">Delivery Time Slot</label>
            <select class="form-select" id="subscriber_delivery_slot" name="subscriber_delivery_slot">
                <option value="">Select a time slot</option>
                @foreach($deliverySlots as $value => $label)
                    <option value="{{ $value }}" {{ $selectedDeliverySlot === $value ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-md-4 mb-3">
            <label for="subscriber_note" class="form-label">Note</label>
            <textarea class="form-control" id="subscriber_note" name="subscriber_note" rows="2"
                      placeholder="Free text...">{{ old('subscriber_note', $subscription->subscriber_note ?? '') }}</textarea>
        </div>
    </div>

    @if($errors->has('animals'))
        <div class="alert alert-danger">{{ $errors->first('animals') }}</div>
    @endif

    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
        <h5 class="mb-0">Animals</h5>
        <button type="button" class="btn btn-sm btn-mauve" id="add-animal-btn">
            <i class="fas fa-plus me-1"></i>Add Animal
        </button>
    </div>

    <div id="animals-container" class="row g-3">
        @foreach($animalsData as $index => $animal)
            @php
                $animalDayMeals = $animal['day_meals'] ?? [];
                if (!is_array($animalDayMeals)) $animalDayMeals = [];
                $animalAgeSelected = $animal['age'] ?? '';
                $animalSpeciesSelected = $animal['species'] ?? 'Cat';
                $animalBreedSizeCategorySelected = $animal['breed_size_category'] ?? '';
                $transitionEnabled = !empty($animal['transition']);
                $lastOrderEnabled = !empty($animal['last_order']);
            @endphp

            <div class="col-12 col-md-4">
                <div class="card animal-card h-100" data-animal-index="{{ $index }}">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="animal-avatar me-2">
                                @if(!empty($animal['photo_path']))
                                    <img
                                        src="{{ asset('storage/app/public/' . ltrim($animal['photo_path'], '/')) }}"
                                        alt="{{ $animal['name'] ?? 'Pet' }}"
                                        class="animal-avatar-image"
                                    >
                                @else
                                    <i class="fas fa-paw"></i>
                                @endif
                            </div>
                            <div>
                                <div class="animal-title">
                                    Animal {{ $index + 1 }}@if(!empty($animal['name'])) – {{ $animal['name'] }}@endif
                                </div>
                                <div class="animal-subtitle small text-muted">
                                    @php
                                        $parts = [];
                                        if (!empty($animal['species'])) $parts[] = $animal['species'];
                                        if (!empty($animal['breed_size_category']) && ($animal['species'] ?? '') === 'Dog') $parts[] = $animal['breed_size_category'];
                                        if (!empty($animal['age'])) $parts[] = $animal['age'];
                                        if (!empty($animal['health_status'])) $parts[] = $animal['health_status'];
                                        echo implode(' • ', $parts);
                                    @endphp
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-animal-btn" title="Remove animal">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="animal-section">
                            <div class="section-header section-toggle">
                                <span class="section-title">Animal details</span>
                                <i class="fas fa-chevron-down section-caret"></i>
                            </div>

                            <div class="section-body">
                                @if(!empty($animal['id']))
                                    <input
                                        type="hidden"
                                        name="animals[{{ $index }}][id]"
                                        value="{{ $animal['id'] }}"
                                    >
                                @endif

                                <div class="mb-2">
                                    <label class="form-label">Name *</label>
                                    <input type="text" class="form-control" name="animals[{{ $index }}][name]" value="{{ $animal['name'] ?? '' }}" required>
                                </div>

                                <div class="mb-2 pet-photo-field">
                                    <label class="form-label">Pet photo</label>

                                    <input
                                        type="file"
                                        class="form-control pet-photo-input @error('animals.' . $index . '.photo') is-invalid @enderror"
                                        name="animals[{{ $index }}][photo]"
                                        accept="image/png,image/jpeg,image/jpg,image/webp"
                                    >

                                    <div
                                        class="mt-2 pet-photo-preview-wrap"
                                        style="{{ !empty($animal['photo_path']) ? '' : 'display:none;' }}"
                                    >
                                        <img
                                            src="{{ !empty($animal['photo_path']) ? asset('storage/app/public/' . ltrim($animal['photo_path'], '/')) : '' }}"
                                            alt="Pet photo"
                                            class="pet-photo-preview-image"
                                        >
                                        <div class="small text-muted mt-1">
                                            Upload a new image only if you want to replace the current photo.
                                        </div>
                                    </div>

                                    @error('animals.' . $index . '.photo')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Species *</label>
                                    <select class="form-select animal-species-select" name="animals[{{ $index }}][species]" required>
                                        @foreach($animalSpeciesOptions as $v => $lbl)
                                            <option value="{{ $v }}" {{ $animalSpeciesSelected === $v ? 'selected' : '' }}>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-2 breed-size-category-wrap" style="{{ $animalSpeciesSelected === 'Dog' ? '' : 'display:none;' }}">
                                    <label class="form-label">Breed size category *</label>
                                    <select class="form-select animal-breed-size-category-select" name="animals[{{ $index }}][breed_size_category]">
                                        <option value="">Select breed size category</option>
                                        @foreach($breedSizeCategoryOptions as $v => $lbl)
                                            <option value="{{ $v }}" {{ $animalBreedSizeCategorySelected === $v ? 'selected' : '' }}>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                    @error('animals.' . $index . '.breed_size_category')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Age</label>
                                    <select class="form-select" name="animals[{{ $index }}][age]">
                                        <option value="">Select age</option>

                                        @php
                                            $currentAgeOptions = $animalSpeciesSelected === 'Dog'
                                                ? $animalAgeOptionsDog
                                                : $animalAgeOptionsCat;
                                        @endphp

                                        @foreach($currentAgeOptions as $v => $lbl)
                                            <option value="{{ $v }}" {{ $animalAgeSelected === $v ? 'selected' : '' }}>
                                                {{ $lbl }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Health Status</label>
                                    <input type="text" class="form-control" name="animals[{{ $index }}][health_status]" value="{{ $animal['health_status'] ?? '' }}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label d-block">Transition</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input transition-toggle"
                                            type="checkbox"
                                            role="switch"
                                            id="animals_{{ $index }}_transition"
                                            name="animals[{{ $index }}][transition]"
                                            value="1"
                                            {{ $transitionEnabled ? 'checked' : '' }}>
                                        <label class="form-check-label" for="animals_{{ $index }}_transition">
                                            Yes / No
                                        </label>
                                    </div>
                                </div>

                                <div class="mb-2 transition-qr-block" style="{{ $transitionEnabled ? '' : 'display:none;' }}">
                                    <label class="form-label">QR code Image</label>
                                    <input type="file"
                                           class="form-control @error('animals.' . $index . '.qr_code_image') is-invalid @enderror"
                                           name="animals[{{ $index }}][qr_code_image]"
                                           accept="image/png,image/jpeg,image/jpg,image/webp">
                                    <input type="hidden" name="animals[{{ $index }}][existing_qr_code_image]" value="{{ $animal['qr_code_image'] ?? '' }}">

                                    @if(!empty($animal['qr_code_image']))
                                        <div class="mt-2 qr-preview-wrap">
                                            <img src="{{ asset('storage/app/public/' . $animal['qr_code_image']) }}" alt="QR code image" class="qr-preview-image">
                                        </div>
                                    @else
                                        <div class="mt-2 qr-preview-wrap" style="display:none;">
                                            <img src="" alt="QR code image" class="qr-preview-image">
                                        </div>
                                    @endif

                                    @error('animals.' . $index . '.qr_code_image')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-2">
                                    <label class="form-label d-block">Last order</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input last-order-toggle"
                                            type="checkbox"
                                            role="switch"
                                            id="animals_{{ $index }}_last_order"
                                            name="animals[{{ $index }}][last_order]"
                                            value="1"
                                            {{ $lastOrderEnabled ? 'checked' : '' }}>
                                        <label class="form-check-label" for="animals_{{ $index }}_last_order">
                                            Yes / No
                                        </label>
                                    </div>
                                </div>

                                <div class="mb-2 last-order-qr-block" style="{{ $lastOrderEnabled ? '' : 'display:none;' }}">
                                    <label class="form-label">Last order QR code</label>
                                    <input type="file"
                                           class="form-control @error('animals.' . $index . '.last_order_qr_code_image') is-invalid @enderror"
                                           name="animals[{{ $index }}][last_order_qr_code_image]"
                                           accept="image/png,image/jpeg,image/jpg,image/webp">

                                    <input type="hidden"
                                           name="animals[{{ $index }}][existing_last_order_qr_code_image]"
                                           value="{{ $animal['last_order_qr_code_image'] ?? '' }}">

                                    @if(!empty($animal['last_order_qr_code_image']))
                                        <div class="mt-2 qr-preview-wrap">
                                            <img src="{{ asset('storage/app/public/' . $animal['last_order_qr_code_image']) }}" alt="Last order QR code" class="qr-preview-image">
                                        </div>
                                    @else
                                        <div class="mt-2 qr-preview-wrap" style="display:none;">
                                            <img src="" alt="Last order QR code" class="qr-preview-image">
                                        </div>
                                    @endif

                                    @error('animals.' . $index . '.last_order_qr_code_image')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Note</label>
                                    <textarea class="form-control" name="animals[{{ $index }}][note]" rows="2"
                                              placeholder="Free text...">{{ $animal['note'] ?? '' }}</textarea>
                                </div>

                                <input type="hidden" name="animals[{{ $index }}][subscription_start]" value="{{ $animal['subscription_start'] ?? '' }}">
                                <input type="hidden" name="animals[{{ $index }}][subscription_end]" value="{{ $animal['subscription_end'] ?? '' }}">
                            </div>
                        </div>

                        <div class="animal-section">
                            <div class="section-header">
                                <span class="section-title">Calendar</span>
                            </div>
                            <div class="section-body" style="display:block;">
                                <div class="calendar-toolbar d-flex justify-content-between align-items-center mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-dir="-7">
                                        <i class="fas fa-chevron-up"></i>
                                    </button>
                                    <div class="small text-muted cal-range-label"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-dir="7">
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                </div>

                                <div class="calendar-grid" data-animal-index="{{ $index }}"></div>

                                <div class="selected-days-wrap mt-3">
                                    <div class="small fw-semibold mb-2">Selected days</div>
                                    <div class="selected-days-list" data-animal-index="{{ $index }}"></div>
                                </div>

                                <div class="hidden-day-inputs" data-animal-index="{{ $index }}">
                                    @foreach($animalDayMeals as $dateKey => $recMap)
                                        @if(is_array($recMap))
                                            @foreach($recMap as $rid => $qty)
                                                @if((int) $qty > 0)
                                                    <input type="hidden"
                                                           name="animals[{{ $index }}][day_meals][{{ $dateKey }}][{{ $rid }}]"
                                                           value="{{ (int) $qty }}"
                                                           class="daymeal-hidden-input"
                                                           data-date="{{ $dateKey }}"
                                                           data-recipe-id="{{ $rid }}">
                                                @endif
                                            @endforeach
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <button type="submit" class="btn btn-mauve mt-3">
        <i class="fas fa-save me-2"></i>{{ $isEdit ? 'Update' : 'Create' }} Subscription
    </button>
    <a href="{{ route('subscriptions.index') }}" class="btn btn-outline-secondary mt-3">Cancel</a>
</form>

<div class="modal fade" id="dayMealsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content daymeals-modal">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Meals for <span id="dm-date-label"></span></h5>
                    <div class="small text-muted">Select quantities, then save</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group input-group-sm mb-3">
                    <input type="text" class="form-control" id="dm-search" placeholder="Search by recipe name or code...">
                    <button class="btn btn-outline-secondary" type="button" id="dm-clear">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div id="dm-list" class="daymeals-list">
                    @foreach($recipes as $recipe)
                        <div class="daymeals-row"
                             data-recipe-id="{{ $recipe->id }}"
                             data-recipe-name="{{ $recipe->name }}"
                             data-recipe-code="{{ $recipe->code ?? '' }}"
                             data-recipe-stage="{{ $recipe->life_stage ?? 'All' }}"
                             data-recipe-species="{{ $recipe->species ?? 'Cat' }}"
                             data-recipe-breed-size-category="{{ $recipe->breed_size_category ?? '' }}">
                            <div class="daymeals-row-left">
                                <div class="fw-semibold">
                                    {{ $recipe->name }}
                                    @if($recipe->code)
                                        <span class="text-muted small">({{ $recipe->code }})</span>
                                    @endif
                                </div>
                            </div>
                            <div class="daymeals-row-right">
                                <div class="qty-control">
                                    <button type="button" class="btn btn-sm btn-light dm-minus">−</button>
                                    <input type="text" class="dm-qty" value="0" readonly>
                                    <button type="button" class="btn btn-sm btn-light dm-plus">+</button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div id="dm-no-results" class="no-results" style="display:none;">No recipes found</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-danger" id="dm-clear-all">Clear</button>
                <button type="button" class="btn btn-mauve" id="dm-save">Save</button>
            </div>
        </div>
    </div>
</div>

<style>
.animal-card { border-radius: 10px; }
.animal-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: #6c5ce7;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 14px;
}
.animal-avatar {
    overflow: hidden;
}
.animal-avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.pet-photo-preview-wrap {
    border: 1px solid #ececec;
    border-radius: 10px;
    padding: 8px;
    background: #fafafa;
    max-width: 220px;
}
.pet-photo-preview-image {
    width: 100%;
    max-height: 180px;
    object-fit: cover;
    display: block;
    border-radius: 8px;
}
.animal-title { font-weight: 600; font-size: 0.95rem; }
.animal-subtitle { font-size: 0.8rem; }
.animal-section {
    border-radius: 8px;
    border: 1px solid #f1f1f1;
    margin-bottom: 10px;
    overflow: hidden;
}
.section-header {
    padding: 8px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background-color: #fafafa;
    cursor: pointer;
}
.section-header .section-title { font-size: 0.9rem; font-weight: 500; }
.section-body { padding: 10px; display: none; }
.section-caret { font-size: 0.8rem; transition: transform 0.2s ease; }
.section-caret.open { transform: rotate(180deg); }

.bg-mauve { background-color: #6c5ce7 !important; color: #fff; }
.btn-mauve { background-color: #6c5ce7; color: #fff; border-color: #6c5ce7; }
.btn-mauve:hover { background-color: #5849c5; border-color: #5849c5; }

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    max-height: 208px;
    overflow-y: auto;
    padding-right: 4px;
}

.calendar-grid::-webkit-scrollbar { width: 8px; }
.calendar-grid::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 8px; }

.cal-day {
    height: 46px;
    border-radius: 10px;
    border: 1px solid #e7e7e7;
    background: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    user-select: none;
    transition: transform .06s ease, box-shadow .15s ease, background .15s ease, border-color .15s ease;
}
.cal-day:hover { box-shadow: 0 6px 18px rgba(0,0,0,0.08); transform: translateY(-1px); }
.cal-day .cal-dow { font-size: 0.62rem; color: #6c757d; line-height: 1; }
.cal-day .cal-num { font-size: 0.95rem; font-weight: 800; line-height: 1.1; }
.cal-day .cal-mon { font-size: 0.62rem; color: #6c757d; line-height: 1; }

.cal-day.has-meals {
    background: linear-gradient(135deg, rgba(108,92,231,0.18), rgba(255, 193, 7, 0.18));
    border-color: rgba(108,92,231,0.45);
}
.cal-day.is-active {
    outline: 2px solid rgba(108,92,231,0.55);
    outline-offset: 2px;
}

.cal-day.is-sunday-disabled {
    background: #fff1f1;
    border-color: #f5b5b5;
    color: #9f1239;
    cursor: not-allowed;
    opacity: 0.82;
}

.cal-day.is-sunday-disabled:hover {
    box-shadow: none;
    transform: none;
}

.cal-day.is-sunday-disabled .cal-dow,
.cal-day.is-sunday-disabled .cal-mon {
    color: #9f1239;
}

.selected-days-wrap {
    border-top: 1px dashed #e7e7e7;
    padding-top: 10px;
}

.selected-day-item {
    border: 1px solid #f0f0f0;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 8px;
    background: #fff;
}
.selected-day-head {
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    background: #fbfbfb;
}
.selected-day-title {
    font-weight: 700;
    font-size: 0.9rem;
}
.selected-day-count {
    font-size: 0.8rem;
    color: #6c757d;
}
.selected-day-body {
    padding: 10px 12px;
    display: none;
}
.meal-line {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 8px;
    border-radius: 8px;
    background: #f7f7ff;
    margin-bottom: 6px;
}
.meal-line:last-child { margin-bottom: 0; }
.meal-line .ml-name { font-weight: 600; font-size: 0.88rem; }
.meal-line .ml-qty { font-weight: 700; }

.daymeals-modal .modal-content { border-radius: 14px; }
.daymeals-list {
    max-height: 420px;
    overflow: auto;
    border: 1px solid #eee;
    border-radius: 12px;
}
.daymeals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid #f3f3f3;
    background: #fff;
}
.daymeals-row:last-child { border-bottom: 0; }
.daymeals-row.selected {
    background: linear-gradient(135deg, rgba(255,193,7,0.16), rgba(108,92,231,0.12));
}
.qty-control { display: flex; align-items: center; gap: 6px; }
.dm-qty {
    width: 48px;
    text-align: center;
    border-radius: 8px;
    border: 1px solid #ddd;
    padding: 3px 6px;
    font-size: 0.9rem;
    background: #fff;
}

.no-results {
    padding: 12px;
    text-align: center;
    color: #6c757d;
    font-style: italic;
}

.qr-preview-wrap {
    border: 1px solid #ececec;
    border-radius: 10px;
    padding: 8px;
    background: #fafafa;
    max-width: 180px;
}

.qr-preview-image {
    max-width: 100%;
    height: auto;
    display: block;
    border-radius: 8px;
}


.customer-account-status-box {
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    padding: 12px;
    min-height: 98px;
    background: #fff;
}

.customer-account-status-title {
    font-weight: 700;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    margin-bottom: 6px;
}

.customer-account-status-text {
    color: #6c757d;
    font-size: 0.82rem;
    line-height: 1.45;
}

.customer-account-status-pending {
    background: #f8f9fa;
    border-color: #dee2e6;
}

.customer-account-status-warning {
    background: #fff8e1;
    border-color: #ffe082;
}

.customer-account-status-initial {
    background: #eef6ff;
    border-color: #bddcff;
}

.customer-account-status-active {
    background: #eefaf2;
    border-color: #b9e3c6;
}

.customer-account-password-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 10px;
    padding: 9px 10px;
    border-radius: 8px;
    background: #fff;
    border: 1px dashed #9dc8f5;
}

.customer-account-password-label {
    color: #6c757d;
    font-size: 0.78rem;
}

.customer-account-password-value {
    font-family: monospace;
    font-size: 1.05rem;
    font-weight: 800;
    letter-spacing: 2px;
    color: #212529;
}

.reset-customer-account-btn[disabled] {
    opacity: 0.65;
    cursor: wait;
}

@media (max-width: 767.98px) {
    .calendar-grid { grid-template-columns: repeat(7, 1fr); gap: 6px; max-height: 200px; }
    .cal-day { height: 44px; border-radius: 10px; }
    .daymeals-list { max-height: 360px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER ACCOUNT RESET / INITIALIZE
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.reset-customer-account-btn').forEach(function(button) {
        button.addEventListener('click', async function() {
            var resetUrl = button.getAttribute('data-reset-url');

            if (!resetUrl) {
                alert('Unable to initialize the customer account.');
                return;
            }

            var confirmed = confirm(
                "Attention : le customer ne pourra plus utiliser son mot de passe actuel. "
                + "Le compte sera réinitialisé avec un nouveau mot de passe de 6 chiffres. "
                + "Voulez-vous continuer ?"
            );

            if (!confirmed) {
                return;
            }

            var csrfInput = document.querySelector('input[name="_token"]');
            var csrfToken = csrfInput ? csrfInput.value : '';

            var originalHtml = button.innerHTML;

            button.disabled = true;
            button.innerHTML =
                '<i class="fas fa-spinner fa-spin me-1"></i> Initialisation...';

            try {
                var response = await fetch(resetUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({})
                });

                var data = await response.json();

                if (!response.ok || data.success !== true) {
                    throw new Error(
                        data.message || 'Unable to initialize the customer account.'
                    );
                }

                var message =
                    'Compte initialisé avec succès.';

                if (data.initial_password) {
                    message +=
                        '\nNouveau mot de passe : '
                        + data.initial_password;
                }

                alert(message);

                window.location.reload();

            } catch (error) {
                console.error(
                    'Customer account initialization error:',
                    error
                );

                alert(
                    error && error.message
                        ? error.message
                        : 'Unable to initialize the customer account.'
                );

                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        });
    });

    var zonesByProvince = @json(
        $provinces->mapWithKeys(function($p){
            return [$p->name => $p->zones->pluck('name')->values()];
        })
    );

    var provinceSelect = document.getElementById('subscriber_province');
    var zoneSelect = document.getElementById('subscriber_zone');
    var codeInput = document.getElementById('code');
    var isEditMode = @json($isEdit);
    var nextSequenceNumber = "{{ isset($nextSubscriptionCode) ? substr($nextSubscriptionCode, 3) : '00001' }}";

    function updateSubscriptionCodeByProvince(province) {
        if (!codeInput || isEditMode) return;
    
        var provinceCode = '00';
    
        if (province && zonesByProvince[province] !== undefined) {
            var provinceOptions = @json($provinceCodes);
            provinceCode = provinceOptions[province] || '00';
        }
    
        codeInput.value = 'C' + provinceCode + nextSequenceNumber;
    }
    function populateZonesForProvince(province, selectedZone) {
        if (!zoneSelect) return;

        zoneSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select zone';
        zoneSelect.appendChild(placeholder);

        if (!province || !zonesByProvince[province]) {
            zoneSelect.value = '';
            return;
        }

        zonesByProvince[province].forEach(function (zone) {
            var opt = document.createElement('option');
            opt.value = zone;
            opt.textContent = zone;
            if (selectedZone && selectedZone === zone) opt.selected = true;
            zoneSelect.appendChild(opt);
        });
    }

    if (provinceSelect && zoneSelect) {
        var initialProvince = provinceSelect.value;
        var initialSelectedZone = zoneSelect.getAttribute('data-selected-zone') || '';
        populateZonesForProvince(initialProvince, initialSelectedZone);
        updateSubscriptionCodeByProvince(initialProvince);
        provinceSelect.addEventListener('change', function () {
            populateZonesForProvince(provinceSelect.value, '');
            updateSubscriptionCodeByProvince(provinceSelect.value);
        });
    }

    function pad2(n){ return (n < 10 ? '0' : '') + n; }
    function formatYMD(d){
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }
    function addDays(date, days){
        var d = new Date(date.getTime());
        d.setDate(d.getDate() + days);
        return d;
    }
    function dowShort(d){
        return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
    }
    function monShort(d){
        return ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
    }

    var recipesData = @json($recipes->map(function($r){
        return [
            'id' => $r->id,
            'name' => $r->name,
            'code' => $r->code ?? ''
        ];
    })->values());

    var animalsData = @json($animalsData);
    animalsData = Array.isArray(animalsData) ? animalsData : [];

    var state = {
        offsets: {},
        dayMeals: {}
    };

    animalsData.forEach(function(a, idx){
        state.offsets[idx] = 0;
        var m = a && a.day_meals ? a.day_meals : {};
        state.dayMeals[idx] = (m && typeof m === 'object') ? m : {};
    });

    function getTotalForDate(animalIndex, dateYmd){
        var map = state.dayMeals[animalIndex] && state.dayMeals[animalIndex][dateYmd] ? state.dayMeals[animalIndex][dateYmd] : {};
        var total = 0;
        Object.keys(map || {}).forEach(function(rid){
            total += parseInt(map[rid] || 0);
        });
        return total;
    }

    function buildCalendar(animalIndex){
        var grid = document.querySelector('.calendar-grid[data-animal-index="' + animalIndex + '"]');
        if (!grid) return;

        var card = grid.closest('.animal-card');
        var rangeLabel = card.querySelector('.cal-range-label');

        grid.innerHTML = '';

        var today = new Date();
        var start = addDays(today, state.offsets[animalIndex] || 0);
        var end = addDays(start, 27);

        if (rangeLabel) {
            rangeLabel.textContent = formatYMD(start) + ' → ' + formatYMD(end);
        }

        for (var i = 0; i < 28; i++) {
            var d = addDays(start, i);
            var ymd = formatYMD(d);
            var total = getTotalForDate(animalIndex, ymd);

            var el = document.createElement('div');
            var isSunday = d.getDay() === 0;
            el.className = 'cal-day' + (total > 0 ? ' has-meals' : '') + (isSunday ? ' is-sunday-disabled' : '');
            el.setAttribute('data-date', ymd);
            el.setAttribute('data-animal-index', animalIndex);
            el.setAttribute('data-day-index', String(d.getDay()));
            if (isSunday) {
                el.setAttribute('title', 'Sunday is a rest day. Orders cannot be created on Sundays.');
            }

            el.innerHTML = '<div class="cal-dow">' + dowShort(d) + '</div>' +
                           '<div class="cal-num">' + d.getDate() + '</div>' +
                           '<div class="cal-mon">' + monShort(d) + '</div>';

            grid.appendChild(el);
        }

        renderSelectedDaysList(animalIndex);
    }

    function renderSelectedDaysList(animalIndex){
        var list = document.querySelector('.selected-days-list[data-animal-index="' + animalIndex + '"]');
        if (!list) return;

        var map = state.dayMeals[animalIndex] || {};
        var dates = Object.keys(map).filter(function(dateKey){
            return getTotalForDate(animalIndex, dateKey) > 0;
        }).sort();

        if (!dates.length){
            list.innerHTML = '<div class="small text-muted">No selected days yet.</div>';
            return;
        }

        var html = '';
        dates.forEach(function(dateKey){
            var dayMap = map[dateKey] || {};
            var lines = [];
            Object.keys(dayMap).forEach(function(rid){
                var qty = parseInt(dayMap[rid] || 0);
                if (qty <= 0) return;
                var r = recipesData.find(function(x){ return String(x.id) === String(rid); });
                var title = r ? (r.name + (r.code ? ' (' + r.code + ')' : '')) : ('Recipe ' + rid);
                lines.push({ title: title, qty: qty });
            });

            lines.sort(function(a,b){ return a.title.localeCompare(b.title); });

            var count = lines.reduce(function(acc, x){ return acc + x.qty; }, 0);
            var uid = 'sel_' + animalIndex + '_' + dateKey.replaceAll('-', '');

            html += '<div class="selected-day-item">' +
                        '<div class="selected-day-head" data-target="' + uid + '">' +
                            '<div>' +
                                '<div class="selected-day-title">' + dateKey + '</div>' +
                                '<div class="selected-day-count">' + count + ' total</div>' +
                            '</div>' +
                            '<div class="text-muted"><i class="fas fa-chevron-down"></i></div>' +
                        '</div>' +
                        '<div class="selected-day-body" id="' + uid + '">';

            lines.forEach(function(x){
                html += '<div class="meal-line">' +
                            '<div class="ml-name">' + x.title + '</div>' +
                            '<div class="ml-qty">× ' + x.qty + '</div>' +
                        '</div>';
            });

            html += '</div></div>';
        });

        list.innerHTML = html;
    }

    function syncHiddenInputs(animalIndex){
        var container = document.querySelector('.hidden-day-inputs[data-animal-index="' + animalIndex + '"]');
        if (!container) return;

        container.querySelectorAll('.daymeal-hidden-input').forEach(function(el){ el.remove(); });

        var map = state.dayMeals[animalIndex] || {};
        Object.keys(map).forEach(function(dateKey){
            var dayMap = map[dateKey] || {};
            Object.keys(dayMap).forEach(function(rid){
                var qty = parseInt(dayMap[rid] || 0);
                if (qty <= 0) return;

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'animals[' + animalIndex + '][day_meals][' + dateKey + '][' + rid + ']';
                input.value = String(qty);
                input.className = 'daymeal-hidden-input';
                input.setAttribute('data-date', dateKey);
                input.setAttribute('data-recipe-id', rid);
                container.appendChild(input);
            });
        });
    }

    function toggleBlockByCard(card, toggleSelector, blockSelector) {
        if (!card) return;
        var toggle = card.querySelector(toggleSelector);
        var block = card.querySelector(blockSelector);
        if (!toggle || !block) return;
        block.style.display = toggle.checked ? '' : 'none';
    }

    function previewQrImage(input) {
        var wrap = input.closest('.mb-2').querySelector('.qr-preview-wrap');
        var img = input.closest('.mb-2').querySelector('.qr-preview-image');

        if (!wrap || !img) return;

        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                wrap.style.display = '';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    document.addEventListener('change', function(e) {
        var input = e.target.closest('.pet-photo-input');
        if (!input) return;

        var field = input.closest('.pet-photo-field');
        if (!field) return;

        var wrap = field.querySelector('.pet-photo-preview-wrap');
        var img = field.querySelector('.pet-photo-preview-image');

        if (!wrap || !img) return;

        if (input.files && input.files[0]) {
            var reader = new FileReader();

            reader.onload = function(event) {
                img.src = event.target.result;
                wrap.style.display = '';
            };

            reader.readAsDataURL(input.files[0]);
        }
    });

    function updateAgeOptions(card) {
        const speciesSelect = card.querySelector('.animal-species-select');
        const ageSelect = card.querySelector('select[name*="[age]"]');

        if (!speciesSelect || !ageSelect) return;

        const currentValue = ageSelect.value;

        let options = [];

        if (speciesSelect.value === 'Dog') {
            options = [
                { value: 'Puppy', text: 'Puppy' },
                { value: 'Adult', text: 'Adult' },
                { value: 'Senior', text: 'Senior' }
            ];
        } else {
            options = [
                { value: 'Kitten', text: 'Kitten' },
                { value: 'Adult', text: 'Adult' },
                { value: 'Senior', text: 'Senior' }
            ];
        }

        ageSelect.innerHTML = '<option value="">Select age</option>';

        options.forEach(function(option) {
            const opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = option.text;

            if (currentValue === option.value) {
                opt.selected = true;
            }

            ageSelect.appendChild(opt);
        });
    }

    function toggleBreedSizeCategoryForCard(card) {
        if (!card) return;

        var speciesSelect = card.querySelector('.animal-species-select');
        var breedWrap = card.querySelector('.breed-size-category-wrap');
        var breedSelect = card.querySelector('.animal-breed-size-category-select');

        if (!speciesSelect || !breedWrap || !breedSelect) return;

        if (speciesSelect.value === 'Dog') {
            breedWrap.style.display = '';
        } else {
            breedWrap.style.display = 'none';
            breedSelect.value = '';
        }
    }

    var modalEl = document.getElementById('dayMealsModal');
    var modalObj = null;
    if (modalEl && window.bootstrap && window.bootstrap.Modal) {
        modalObj = new bootstrap.Modal(modalEl);
    }

    var current = { animalIndex: null, date: null };
    var dmDateLabel = document.getElementById('dm-date-label');
    var dmList = document.getElementById('dm-list');
    var dmSearch = document.getElementById('dm-search');
    var dmClear = document.getElementById('dm-clear');
    var dmClearAll = document.getElementById('dm-clear-all');
    var dmSave = document.getElementById('dm-save');
    var dmNoResults = document.getElementById('dm-no-results');

    var filters = { animalIndex: null, age: '', species: '', breedSizeCategory: '' };

    function getAnimalAge(animalIndex){
        var card = document.querySelector('.animal-card[data-animal-index="' + animalIndex + '"]');
        if (!card) return '';
        var ageSelect = card.querySelector('select[name="animals[' + animalIndex + '][age]"]');
        if (!ageSelect) return '';
        return (ageSelect.value || '').trim();
    }

    function getAnimalSpecies(animalIndex){
        var card = document.querySelector('.animal-card[data-animal-index="' + animalIndex + '"]');
        if (!card) return '';
        var speciesSelect = card.querySelector('select[name="animals[' + animalIndex + '][species]"]');
        if (!speciesSelect) return '';
        return (speciesSelect.value || '').trim();
    }

    function getAnimalBreedSizeCategory(animalIndex){
        var card = document.querySelector('.animal-card[data-animal-index="' + animalIndex + '"]');
        if (!card) return '';
        var breedSelect = card.querySelector('select[name="animals[' + animalIndex + '][breed_size_category]"]');
        if (!breedSelect) return '';
        return (breedSelect.value || '').trim();
    }

    function applyRecipeFilters(animalIndex){
        filters.animalIndex = animalIndex;
        filters.age = getAnimalAge(animalIndex);
        filters.species = getAnimalSpecies(animalIndex);
        filters.breedSizeCategory = getAnimalBreedSizeCategory(animalIndex);

        if (!dmList) return;

        filterModal(dmSearch ? dmSearch.value : '');
    }

    function setModalQuantities(animalIndex, dateYmd){
        var map = state.dayMeals[animalIndex] && state.dayMeals[animalIndex][dateYmd] ? state.dayMeals[animalIndex][dateYmd] : {};
        var rows = dmList ? dmList.querySelectorAll('.daymeals-row') : [];
        rows.forEach(function(row){
            var rid = row.getAttribute('data-recipe-id');
            var qty = parseInt(map[rid] || 0);
            row.querySelector('.dm-qty').value = String(qty);
            if (qty > 0) row.classList.add('selected'); else row.classList.remove('selected');
        });
        if (dmSearch) dmSearch.value = '';
        if (dmNoResults) dmNoResults.style.display = 'none';
        applyRecipeFilters(animalIndex);
    }

    function filterModal(term){
        if (!dmList) return;

        term = (term || '').toLowerCase().trim();
        var rows = dmList.querySelectorAll('.daymeals-row');
        var visible = 0;

        rows.forEach(function(row){
            var name = (row.getAttribute('data-recipe-name') || '').toLowerCase();
            var code = (row.getAttribute('data-recipe-code') || '').toLowerCase();
            var stage = (row.getAttribute('data-recipe-stage') || '').trim();
            var species = (row.getAttribute('data-recipe-species') || '').trim();
            var breedSizeCategory = (row.getAttribute('data-recipe-breed-size-category') || '').trim();

            var stageOk = true;
            if (filters.age) stageOk = (stage === 'All' || stage === filters.age);

            var speciesOk = true;
            if (filters.species) speciesOk = (species === filters.species);

            var breedSizeOk = true;
            if (filters.species === 'Dog') {
                breedSizeOk = breedSizeCategory === filters.breedSizeCategory;
            }

            var textOk = !term || name.includes(term) || code.includes(term);
            var ok = stageOk && speciesOk && breedSizeOk && textOk;

            row.style.display = ok ? 'flex' : 'none';
            if (ok) visible++;
        });

        if (dmNoResults) dmNoResults.style.display = visible ? 'none' : 'block';
    }

    document.addEventListener('click', function(e){
        var nav = e.target.closest('.cal-nav');
        if (nav) {
            var card = nav.closest('.animal-card');
            var idx = parseInt(card.getAttribute('data-animal-index'));
            var dir = parseInt(nav.getAttribute('data-dir') || '0');
            state.offsets[idx] = (state.offsets[idx] || 0) + dir;
            buildCalendar(idx);
            return;
        }

        var day = e.target.closest('.cal-day');
        if (day) {
            var idx2 = parseInt(day.getAttribute('data-animal-index'));
            var date = day.getAttribute('data-date');
            var dayIndex = parseInt(day.getAttribute('data-day-index') || '-1');

            if (dayIndex === 0) {
                alert('Orders cannot be created on Sunday because it is a rest day.');
                return;
            }

            current.animalIndex = idx2;
            current.date = date;

            applyRecipeFilters(idx2);

            document.querySelectorAll('.cal-day.is-active').forEach(function(x){ x.classList.remove('is-active'); });
            day.classList.add('is-active');

            if (dmDateLabel) dmDateLabel.textContent = date;
            setModalQuantities(idx2, date);

            if (modalObj) modalObj.show();
            return;
        }

        var head = e.target.closest('.selected-day-head');
        if (head) {
            var id = head.getAttribute('data-target');
            var body = document.getElementById(id);
            if (!body) return;
            var isOpen = body.style.display === 'block';
            body.style.display = isOpen ? 'none' : 'block';
            return;
        }

        if (e.target.closest('.section-toggle')) {
            var header = e.target.closest('.section-toggle');
            var body2 = header.closest('.animal-section').querySelector('.section-body');
            var caret = header.querySelector('.section-caret');

            if (body2.style.display === 'block') {
                body2.style.display = 'none';
                if (caret) caret.classList.remove('open');
            } else {
                body2.style.display = 'block';
                if (caret) caret.classList.add('open');
            }
            return;
        }

        if (e.target.closest('.remove-animal-btn')) {
            var removeBtn = e.target.closest('.remove-animal-btn');
            var animalCard = removeBtn.closest('.animal-card');
            if (document.querySelectorAll('.animal-card').length > 1) {
                animalCard.parentElement.remove();
            }
            return;
        }

        var plus = e.target.closest('.dm-plus');
        var minus = e.target.closest('.dm-minus');

        if (plus || minus) {
            var row = e.target.closest('.daymeals-row');
            var qtyInput = row.querySelector('.dm-qty');
            var val = parseInt(qtyInput.value || '0');
            if (plus) val++;
            if (minus && val > 0) val--;
            qtyInput.value = String(val);
            if (val > 0) row.classList.add('selected'); else row.classList.remove('selected');
            return;
        }

        if (e.target === dmClear || e.target.closest('#dm-clear')) {
            if (dmSearch) dmSearch.value = '';
            filterModal('');
            if (dmSearch) dmSearch.focus();
            return;
        }

        if (e.target === dmClearAll || e.target.closest('#dm-clear-all')) {
            if (!dmList) return;

            dmList.querySelectorAll('.daymeals-row').forEach(function(row){
                row.querySelector('.dm-qty').value = '0';
                row.classList.remove('selected');
            });

            if (dmSearch) dmSearch.value = '';
            if (dmNoResults) dmNoResults.style.display = 'none';
            applyRecipeFilters(current.animalIndex);
            return;
        }

        if (e.target === dmSave || e.target.closest('#dm-save')) {
            var idx3 = current.animalIndex;
            var date3 = current.date;
            if (idx3 === null || !date3) return;

            var map = {};
            var rows2 = dmList.querySelectorAll('.daymeals-row');
            rows2.forEach(function(row){
                if (row.style.display === 'none') return;
                var rid = row.getAttribute('data-recipe-id');
                var qty = parseInt(row.querySelector('.dm-qty').value || '0');
                if (qty > 0) map[rid] = qty;
            });

            if (!state.dayMeals[idx3]) state.dayMeals[idx3] = {};
            if (Object.keys(map).length) {
                state.dayMeals[idx3][date3] = map;
            } else {
                if (state.dayMeals[idx3][date3]) delete state.dayMeals[idx3][date3];
            }

            syncHiddenInputs(idx3);
            buildCalendar(idx3);

            if (modalObj) modalObj.hide();
            return;
        }
    });

    document.addEventListener('input', function(e){
        if (e.target && e.target.id === 'dm-search') {
            filterModal(e.target.value);
            return;
        }

        if (e.target.name && e.target.name.includes('[name]')) {
            var animalCard = e.target.closest('.animal-card');
            if (!animalCard) return;
            var animalTitle = animalCard.querySelector('.animal-title');
            var idx = parseInt(animalCard.getAttribute('data-animal-index'));
            var animalName = e.target.value.trim();
            if (animalTitle) {
                if (animalName) animalTitle.textContent = 'Animal ' + (idx + 1) + ' – ' + animalName;
                else animalTitle.textContent = 'Animal ' + (idx + 1);
            }
        }
    });

    document.addEventListener('change', function(e){
        if (e.target && e.target.matches('select[name*="[age]"]')) {
            if (current.animalIndex !== null) {
                applyRecipeFilters(current.animalIndex);
            }
        }

        if (e.target && e.target.matches('select[name*="[species]"]')) {
            var cardSpecies = e.target.closest('.animal-card');

            toggleBreedSizeCategoryForCard(cardSpecies);
            updateAgeOptions(cardSpecies);

            if (current.animalIndex !== null) {
                applyRecipeFilters(current.animalIndex);
            }
        }

        if (e.target && e.target.matches('select[name*="[breed_size_category]"]')) {
            if (current.animalIndex !== null) {
                applyRecipeFilters(current.animalIndex);
            }
        }

        if (e.target && e.target.classList.contains('transition-toggle')) {
            var card = e.target.closest('.animal-card');
            toggleBlockByCard(card, '.transition-toggle', '.transition-qr-block');
        }

        if (e.target && e.target.classList.contains('last-order-toggle')) {
            var card2 = e.target.closest('.animal-card');
            toggleBlockByCard(card2, '.last-order-toggle', '.last-order-qr-block');
        }

        if (e.target && e.target.matches('input[type="file"][name*="[qr_code_image]"]')) {
            previewQrImage(e.target);
        }

        if (e.target && e.target.matches('input[type="file"][name*="[last_order_qr_code_image]"]')) {
            previewQrImage(e.target);
        }
    });

    document.querySelectorAll('.calendar-grid').forEach(function(grid){
        var idx = parseInt(grid.getAttribute('data-animal-index'));
        buildCalendar(idx);
        syncHiddenInputs(idx);
    });

    document.querySelectorAll('.animal-card').forEach(function(card){
        toggleBlockByCard(card, '.transition-toggle', '.transition-qr-block');
        toggleBlockByCard(card, '.last-order-toggle', '.last-order-qr-block');
        toggleBreedSizeCategoryForCard(card);
        updateAgeOptions(card);
    });

    var animalCounter = document.querySelectorAll('.animal-card').length;

    document.getElementById('add-animal-btn').addEventListener('click', function() {
        var animalsContainer = document.getElementById('animals-container');
        var newIndex = animalCounter;

        state.offsets[newIndex] = 0;
        state.dayMeals[newIndex] = {};

        var newAnimalHtml = `
            <div class="col-12 col-md-4">
                <div class="card animal-card h-100" data-animal-index="${newIndex}">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="animal-avatar me-2">
                                <i class="fas fa-paw"></i>
                            </div>
                            <div>
                                <div class="animal-title">Animal ${newIndex + 1}</div>
                                <div class="animal-subtitle small text-muted"></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-animal-btn" title="Remove animal">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="animal-section">
                            <div class="section-header section-toggle">
                                <span class="section-title">Animal details</span>
                                <i class="fas fa-chevron-down section-caret"></i>
                            </div>

                            <div class="section-body">
                                <div class="mb-2">
                                    <label class="form-label">Name *</label>
                                    <input type="text" class="form-control" name="animals[${newIndex}][name]" value="" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Species *</label>
                                    <select class="form-select animal-species-select" name="animals[${newIndex}][species]" required>
                                        <option value="Cat" selected>Cat</option>
                                        <option value="Dog">Dog</option>
                                    </select>
                                </div>
                                <div class="mb-2 breed-size-category-wrap" style="display:none;">
                                    <label class="form-label">Breed size category *</label>
                                    <select class="form-select animal-breed-size-category-select" name="animals[${newIndex}][breed_size_category]">
                                        <option value="">Select breed size category</option>
                                        <option value="Small">Small</option>
                                        <option value="Medium">Medium</option>
                                        <option value="Large">Large</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Age</label>
                                    <select class="form-select" name="animals[${newIndex}][age]">
                                        <option value="">Select age</option>
                                        <option value="Kitten">Kitten</option>
                                        <option value="Adult">Adult</option>
                                        <option value="Senior">Senior</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Health Status</label>
                                    <input type="text" class="form-control" name="animals[${newIndex}][health_status]" value="">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label d-block">Transition</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input transition-toggle"
                                            type="checkbox"
                                            role="switch"
                                            id="animals_${newIndex}_transition"
                                            name="animals[${newIndex}][transition]"
                                            value="1">
                                        <label class="form-check-label" for="animals_${newIndex}_transition">
                                            Yes / No
                                        </label>
                                    </div>
                                </div>

                                <div class="mb-2 transition-qr-block" style="display:none;">
                                    <label class="form-label">QR code Image</label>
                                    <input type="file"
                                           class="form-control"
                                           name="animals[${newIndex}][qr_code_image]"
                                           accept="image/png,image/jpeg,image/jpg,image/webp">
                                    <input type="hidden" name="animals[${newIndex}][existing_qr_code_image]" value="">
                                    <div class="mt-2 qr-preview-wrap" style="display:none;">
                                        <img src="" alt="QR code image" class="qr-preview-image">
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label d-block">Last order</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input last-order-toggle"
                                            type="checkbox"
                                            role="switch"
                                            id="animals_${newIndex}_last_order"
                                            name="animals[${newIndex}][last_order]"
                                            value="1">
                                        <label class="form-check-label" for="animals_${newIndex}_last_order">
                                            Yes / No
                                        </label>
                                    </div>
                                </div>

                                <div class="mb-2 last-order-qr-block" style="display:none;">
                                    <label class="form-label">Last order QR code</label>
                                    <input type="file"
                                           class="form-control"
                                           name="animals[${newIndex}][last_order_qr_code_image]"
                                           accept="image/png,image/jpeg,image/jpg,image/webp">
                                    <input type="hidden" name="animals[${newIndex}][existing_last_order_qr_code_image]" value="">
                                    <div class="mt-2 qr-preview-wrap" style="display:none;">
                                        <img src="" alt="Last order QR code" class="qr-preview-image">
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Note</label>
                                    <textarea class="form-control" name="animals[${newIndex}][note]" rows="2" placeholder="Free text..."></textarea>
                                </div>

                                <input type="hidden" name="animals[${newIndex}][subscription_start]" value="">
                                <input type="hidden" name="animals[${newIndex}][subscription_end]" value="">
                            </div>
                        </div>

                        <div class="animal-section">
                            <div class="section-header">
                                <span class="section-title">Calendar</span>
                            </div>
                            <div class="section-body" style="display:block;">
                                <div class="calendar-toolbar d-flex justify-content-between align-items-center mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-dir="-7">
                                        <i class="fas fa-chevron-up"></i>
                                    </button>
                                    <div class="small text-muted cal-range-label"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-dir="7">
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                </div>

                                <div class="calendar-grid" data-animal-index="${newIndex}"></div>

                                <div class="selected-days-wrap mt-3">
                                    <div class="small fw-semibold mb-2">Selected days</div>
                                    <div class="selected-days-list" data-animal-index="${newIndex}"></div>
                                </div>

                                <div class="hidden-day-inputs" data-animal-index="${newIndex}"></div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        `;

        animalsContainer.insertAdjacentHTML('beforeend', newAnimalHtml);
        animalCounter++;

        buildCalendar(newIndex);
        syncHiddenInputs(newIndex);

        var newCard = animalsContainer.querySelector('.animal-card[data-animal-index="' + newIndex + '"]');
        toggleBlockByCard(newCard, '.transition-toggle', '.transition-qr-block');
        toggleBlockByCard(newCard, '.last-order-toggle', '.last-order-qr-block');
        toggleBreedSizeCategoryForCard(newCard);
    });

    if (dmSearch) {
        dmSearch.addEventListener('input', function(){
            filterModal(dmSearch.value);
        });
    }
});
</script>
