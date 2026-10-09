@extends('layouts.app')

@section('title', 'New Notification')

@section('content')

<div class="container-fluid py-4">

    <div class="mb-4">

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

            New Notification

        </h2>

        <p class="text-muted mb-0">
            Create a push notification for Maison Chat app users.
        </p>

    </div>


    @if($errors->any())

        <div class="alert alert-danger">

            <ul class="mb-0">

                @foreach(
                    $errors->all()
                    as $error
                )

                    <li>
                        {{ $error }}
                    </li>

                @endforeach

            </ul>

        </div>

    @endif


    <form
        method="POST"
        action="{{
            route(
                'app-communications.notifications.store'
            )
        }}"
    >

        @csrf


        <div class="row g-4">

            {{-- CONTENT --}}
            <div class="col-12 col-xl-8">

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

                                <h6 class="mb-3">
                                    English
                                </h6>

                                <div class="mb-3">

                                    <label
                                        class="form-label"
                                        for="title_en"
                                    >
                                        Title
                                    </label>

                                    <input
                                        type="text"
                                        id="title_en"
                                        name="title_en"
                                        class="form-control"
                                        maxlength="255"
                                        value="{{
                                            old(
                                                'title_en'
                                            )
                                        }}"
                                        required
                                    >

                                </div>

                                <div>

                                    <label
                                        class="form-label"
                                        for="body_en"
                                    >
                                        Message
                                    </label>

                                    <textarea
                                        id="body_en"
                                        name="body_en"
                                        class="form-control"
                                        rows="7"
                                        maxlength="2000"
                                        required
                                    >{{ old('body_en') }}</textarea>

                                </div>

                            </div>


                            {{-- ARABIC --}}
                            <div class="col-lg-6">

                                <div
                                    dir="rtl"
                                    class="text-end"
                                >

                                    <h6 class="mb-3">
                                        العربية
                                    </h6>

                                    <div class="mb-3">

                                        <label
                                            class="form-label"
                                            for="title_ar"
                                        >
                                            العنوان
                                        </label>

                                        <input
                                            type="text"
                                            id="title_ar"
                                            name="title_ar"
                                            class="form-control text-end"
                                            maxlength="255"
                                            value="{{
                                                old(
                                                    'title_ar'
                                                )
                                            }}"
                                            required
                                        >

                                    </div>

                                    <div>

                                        <label
                                            class="form-label"
                                            for="body_ar"
                                        >
                                            الرسالة
                                        </label>

                                        <textarea
                                            id="body_ar"
                                            name="body_ar"
                                            class="form-control text-end"
                                            rows="7"
                                            maxlength="2000"
                                            required
                                        >{{ old('body_ar') }}</textarea>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            {{-- TARGET --}}
            <div class="col-12 col-xl-4">

                <div class="card border-0 shadow-sm">

                    <div class="card-header bg-white py-3">

                        <h5 class="mb-0">
                            Target
                        </h5>

                    </div>

                    <div class="card-body">

                        <div class="mb-4">

                            <label
                                for="target_type"
                                class="form-label"
                            >
                                Send to
                            </label>

                            <select
                                id="target_type"
                                name="target_type"
                                class="form-select"
                                required
                            >

                                <option
                                    value="all_devices"
                                    {{
                                        old(
                                            'target_type',
                                            'all_devices'
                                        )
                                        ===
                                        'all_devices'
                                            ? 'selected'
                                            : ''
                                    }}
                                >
                                    All Devices
                                </option>

                                <option
                                    value="all_customers"
                                    {{
                                        old(
                                            'target_type'
                                        )
                                        ===
                                        'all_customers'
                                            ? 'selected'
                                            : ''
                                    }}
                                >
                                    All Customers
                                </option>

                                <option
                                    value="anonymous_devices"
                                    {{
                                        old(
                                            'target_type'
                                        )
                                        ===
                                        'anonymous_devices'
                                            ? 'selected'
                                            : ''
                                    }}
                                >
                                    Anonymous Devices
                                </option>

                                <option
                                    value="selected_customers"
                                    {{
                                        old(
                                            'target_type'
                                        )
                                        ===
                                        'selected_customers'
                                            ? 'selected'
                                            : ''
                                    }}
                                >
                                    Selected Customers
                                </option>

                            </select>

                        </div>


                        <div
                            id="selectedCustomersBox"
                            class="mb-4"
                            style="display:none;"
                        >

                            <label class="form-label">
                                Customers
                            </label>

                            <div
                                class="border rounded p-2"
                                style="
                                    max-height: 300px;
                                    overflow-y: auto;
                                "
                            >

                                @foreach(
                                    $customers
                                    as $customer
                                )

                                    <div class="form-check mb-2">

                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="customers[]"
                                            value="{{ $customer->id }}"
                                            id="customer-{{ $customer->id }}"
                                            {{
                                                in_array(
                                                    $customer->id,
                                                    old(
                                                        'customers',
                                                        []
                                                    )
                                                )
                                                    ? 'checked'
                                                    : ''
                                            }}
                                        >

                                        <label
                                            class="form-check-label"
                                            for="customer-{{ $customer->id }}"
                                        >

                                            Customer
                                            #{{ $customer->id }}

                                            <span class="text-muted">
                                                —
                                                {{ $customer->phone }}
                                            </span>

                                        </label>

                                    </div>

                                @endforeach

                            </div>

                        </div>


                        <div class="d-grid gap-2">

                            <button
                                type="submit"
                                name="action"
                                value="send"
                                class="btn btn-mauve"
                            >

                                <i class="fas fa-paper-plane me-2"></i>

                                Send Now

                            </button>


                            <button
                                type="submit"
                                name="action"
                                value="draft"
                                class="btn btn-outline-secondary"
                            >

                                <i class="fas fa-save me-2"></i>

                                Save Draft

                            </button>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </form>

</div>

@endsection


@push('scripts')

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const targetSelect =
            document.getElementById(
                'target_type'
            );

        const customersBox =
            document.getElementById(
                'selectedCustomersBox'
            );

        function updateCustomersVisibility() {

            if (
                targetSelect.value ===
                'selected_customers'
            ) {
                customersBox.style.display =
                    'block';
            } else {
                customersBox.style.display =
                    'none';
            }
        }

        targetSelect.addEventListener(
            'change',
            updateCustomersVisibility
        );

        updateCustomersVisibility();

    }
);

</script>

@endpush