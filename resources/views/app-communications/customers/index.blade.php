@extends('layouts.app')

@section('title', 'Customers')

@section('content')

<div class="container-fluid py-4">

    {{-- HEADER --}}
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

            <a
                href="{{ route('app-communications.index') }}"
                class="text-decoration-none small"
            >
                <i class="fas fa-arrow-left me-1"></i>
                App Communications
            </a>

            <h2 class="mb-1 mt-2">

                <i class="fas fa-users text-success me-2"></i>

                Customers

            </h2>

            <p class="text-muted mb-0">

                Customer account activity, mobile usage and direct communication.

            </p>

        </div>


        <div class="d-flex gap-2 flex-wrap">

            <span class="badge bg-light text-dark border p-2">

                {{ $customers->total() }}
                customers

            </span>

        </div>

    </div>


    {{-- FILTERS --}}
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <form
                method="GET"
                action="{{ route('app-communications.customers.index') }}"
                class="row g-3 align-items-end"
            >

                <div class="col-12 col-md-5">

                    <label class="form-label">
                        Search
                    </label>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        value="{{ request('search') }}"
                        placeholder="Phone, customer ID or subscription..."
                    >

                </div>


                <div class="col-12 col-md-3">

                    <label class="form-label">
                        Password status
                    </label>

                    <select
                        name="password_status"
                        class="form-select"
                    >

                        <option value="">
                            All
                        </option>

                        <option
                            value="initial"
                            {{ request('password_status') === 'initial' ? 'selected' : '' }}
                        >
                            Initial password
                        </option>

                        <option
                            value="personal"
                            {{ request('password_status') === 'personal' ? 'selected' : '' }}
                        >
                            Personal password
                        </option>

                    </select>

                </div>


                <div class="col-12 col-md-4">

                    <div class="d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-mauve"
                        >
                            <i class="fas fa-filter me-1"></i>
                            Filter
                        </button>

                        <a
                            href="{{ route('app-communications.customers.index') }}"
                            class="btn btn-outline-secondary"
                        >
                            Reset
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>


    {{-- TABLE --}}
    <div class="card border-0 shadow-sm">

        <div class="card-body p-0">

            <div class="table-responsive">

                <table
                    class="
                        table
                        table-hover
                        align-middle
                        mb-0
                    "
                >

                    @php
                        $currentSort = request('sort', 'id');
                        $currentDirection = request('direction', 'desc');

                        $sortUrl = function (
                            string $field,
                            string $defaultDirection = 'asc'
                        ) use (
                            $currentSort,
                            $currentDirection
                        ) {
                            $direction =
                                $currentSort === $field
                                    ? (
                                        $currentDirection === 'asc'
                                            ? 'desc'
                                            : 'asc'
                                    )
                                    : $defaultDirection;

                            return request()->fullUrlWithQuery([
                                'sort' => $field,
                                'direction' => $direction,
                                'page' => 1,
                            ]);
                        };

                        $sortIcon = function (
                            string $field
                        ) use (
                            $currentSort,
                            $currentDirection
                        ) {
                            if ($currentSort !== $field) {
                                return 'fa-sort text-muted';
                            }

                            return $currentDirection === 'asc'
                                ? 'fa-sort-up'
                                : 'fa-sort-down';
                        };
                    @endphp

                    <thead class="table-light">

                        <tr>

                            <th class="ps-3">

                                <a
                                    href="{{ $sortUrl('name', 'asc') }}"
                                    class="customer-sort-link"
                                >
                                    Customer

                                    <i
                                        class="fas {{ $sortIcon('name') }} ms-1"
                                    ></i>
                                </a>

                            </th>

                            <th>

                                <a
                                    href="{{ $sortUrl('password_status', 'asc') }}"
                                    class="customer-sort-link"
                                >
                                    Password status

                                    <i
                                        class="fas {{ $sortIcon('password_status') }} ms-1"
                                    ></i>
                                </a>

                            </th>

                            <th>

                                <a
                                    href="{{ $sortUrl('logins', 'desc') }}"
                                    class="customer-sort-link"
                                >
                                    Logins

                                    <i
                                        class="fas {{ $sortIcon('logins') }} ms-1"
                                    ></i>
                                </a>

                            </th>

                            <th>

                                <a
                                    href="{{ $sortUrl('app_time', 'desc') }}"
                                    class="customer-sort-link"
                                >
                                    App time

                                    <i
                                        class="fas {{ $sortIcon('app_time') }} ms-1"
                                    ></i>
                                </a>

                            </th>

                            <th>

                                <a
                                    href="{{ $sortUrl('last_login', 'desc') }}"
                                    class="customer-sort-link"
                                >
                                    Last login

                                    <i
                                        class="fas {{ $sortIcon('last_login') }} ms-1"
                                    ></i>
                                </a>

                            </th>

                            <th>

                                <a
                                    href="{{ $sortUrl('orders', 'desc') }}"
                                    class="customer-sort-link"
                                >
                                    Orders

                                    <i
                                        class="fas {{ $sortIcon('orders') }} ms-1"
                                    ></i>
                                </a>

                            </th>

                            <th>

                                <a
                                    href="{{ $sortUrl('seniority', 'asc') }}"
                                    class="customer-sort-link"
                                >
                                    Seniority

                                    <i
                                        class="fas {{ $sortIcon('seniority') }} ms-1"
                                    ></i>
                                </a>

                            </th>

                            <th class="text-end pe-3">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        @forelse($customers as $customer)

                            <tr>

                                <td class="ps-3">

                                    <div class="fw-semibold">

                                        @if(!empty($customer->full_name))

                                            {{ $customer->full_name }}

                                        @else

                                            Customer #{{ $customer->id }}

                                        @endif

                                    </div>

                                    <div class="small text-muted">

                                        <i class="fas fa-phone me-1"></i>

                                        {{ $customer->display_phone ?: 'No phone' }}

                                    </div>

                                    <div class="small text-muted">

                                        Customer #{{ $customer->id }}

                                        @if($customer->subscription_id)

                                            · Subscription #{{ $customer->subscription_id }}

                                        @endif

                                    </div>

                                </td>


                                <td>

                                    @if($customer->password_changed_at)

                                        <span class="badge bg-success">

                                            <i class="fas fa-check me-1"></i>
                                            Personal password

                                        </span>

                                    @else

                                        <div class="d-flex flex-column align-items-start gap-2">

                                            <span class="badge bg-warning text-dark">

                                                <i class="fas fa-key me-1"></i>
                                                Initial password

                                            </span>

                                            @if(!empty($customer->initial_password_plain))

                                                <div
                                                    class="initial-password-box"
                                                    data-password-wrapper
                                                >

                                                    <span
                                                        class="initial-password-value"
                                                        data-password-value
                                                        data-password="{{ e($customer->initial_password_plain) }}"
                                                    >
                                                        ••••••
                                                    </span>

                                                    <button
                                                        type="button"
                                                        class="
                                                            btn
                                                            btn-sm
                                                            btn-outline-secondary
                                                            initial-password-toggle
                                                        "
                                                        data-password-toggle
                                                        aria-label="Show initial password"
                                                        title="Show initial password"
                                                    >

                                                        <i
                                                            class="fas fa-eye"
                                                            data-password-icon
                                                        ></i>

                                                    </button>

                                                </div>

                                            @else

                                                <span class="small text-muted">
                                                    Password unavailable
                                                </span>

                                            @endif

                                        </div>

                                    @endif

                                </td>


                                <td>

                                    {{ number_format((int) ($customer->login_count ?? 0)) }}

                                </td>


                                <td>

                                    {{
                                        $customer->formatted_app_duration
                                        ?? '—'
                                    }}

                                </td>


                                <td>

                                    @if($customer->last_login_at)

                                        <div>
                                            {{ $customer->last_login_at->format('Y-m-d H:i') }}
                                        </div>

                                        <div class="small text-muted">
                                            {{ $customer->last_login_at->diffForHumans() }}
                                        </div>

                                    @else

                                        <span class="text-muted">
                                            Never
                                        </span>

                                    @endif

                                </td>


                                <td>

                                    <span class="fw-semibold">

                                        {{ number_format((int) ($customer->orders_count ?? 0)) }}

                                    </span>

                                </td>


                                <td>

                                    @if($customer->subscription_created_at)

                                        <div>
                                            {{ $customer->subscription_created_at->diffForHumans(null, true) }}
                                        </div>

                                        <div class="small text-muted">
                                            since {{ $customer->subscription_created_at->format('Y-m-d') }}
                                        </div>

                                    @else

                                        <span class="text-muted">
                                            —
                                        </span>

                                    @endif

                                </td>


                                <td class="text-end pe-3">

                                    <div
                                        class="
                                            d-flex
                                            justify-content-end
                                            gap-2
                                            flex-wrap
                                        "
                                    >

                                        @if(
                                            Auth::user()->hasPermission(
                                                'app_communications.notifications.send'
                                            )
                                        )

                                            <a
                                                href="{{
                                                    route(
                                                        'app-communications.notifications.create',
                                                        [
                                                            'customer_id' => $customer->id,
                                                            'target_type' => 'selected_customers',
                                                        ]
                                                    )
                                                }}"
                                                class="
                                                    btn
                                                    btn-sm
                                                    btn-outline-warning
                                                "
                                                title="Send personalized notification"
                                            >

                                                <i class="fas fa-bell me-1"></i>
                                                Notification

                                            </a>

                                        @endif


                                        @if(
                                            Auth::user()->hasPermission(
                                                'app_communications.messages.send'
                                            )
                                        )

                                            @if(
                                                Route::has(
                                                    'app-communications.messages.create'
                                                )
                                            )

                                                <a
                                                    href="{{
                                                        route(
                                                            'app-communications.messages.create',
                                                            [
                                                                'customer_id' => $customer->id,
                                                            ]
                                                        )
                                                    }}"
                                                    class="
                                                        btn
                                                        btn-sm
                                                        btn-outline-primary
                                                    "
                                                    title="Send personalized message"
                                                >

                                                    <i class="fas fa-paper-plane me-1"></i>
                                                    Message

                                                </a>

                                            @else

                                                <button
                                                    type="button"
                                                    class="
                                                        btn
                                                        btn-sm
                                                        btn-outline-primary
                                                    "
                                                    disabled
                                                    title="Messaging module is not available yet"
                                                >

                                                    <i class="fas fa-paper-plane me-1"></i>
                                                    Message

                                                </button>

                                            @endif

                                        @endif

                                    </div>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td
                                    colspan="8"
                                    class="
                                        text-center
                                        text-muted
                                        py-5
                                    "
                                >

                                    <i
                                        class="
                                            fas
                                            fa-users
                                            fs-3
                                            d-block
                                            mb-2
                                        "
                                    ></i>

                                    No customers found.

                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </div>


        @if($customers->hasPages())
        
            <div class="card-footer bg-white py-3">
        
                <div
                    class="
                        d-flex
                        flex-column
                        flex-md-row
                        align-items-center
                        justify-content-between
                        gap-3
                    "
                >
        
                    <div class="small text-muted">
        
                        Showing
        
                        <strong>
                            {{ $customers->firstItem() }}
                        </strong>
        
                        to
        
                        <strong>
                            {{ $customers->lastItem() }}
                        </strong>
        
                        of
        
                        <strong>
                            {{ $customers->total() }}
                        </strong>
        
                        customers
        
                    </div>
        
                    <div class="customers-pagination">
        
                        {{
                            $customers
                                ->onEachSide(1)
                                ->links('pagination::bootstrap-5')
                        }}
        
                    </div>
        
                </div>
        
            </div>
        
        @endif

    </div>

</div>


<style>
    .customer-sort-link {
        color: inherit;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 3px;
        white-space: nowrap;
    }

    .customer-sort-link:hover {
        color: var(--bs-primary);
    }

    .customer-sort-link .fa-sort,
    .customer-sort-link .fa-sort-up,
    .customer-sort-link .fa-sort-down {
        font-size: 0.78rem;
    }

    .table > :not(caption) > * > * {
        vertical-align: middle;
    }
    .customers-pagination nav {
    margin: 0;
    }
    
    .customers-pagination .pagination {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
    }
    
    .customers-pagination .page-item {
        margin: 0;
    }
    
    .customers-pagination .page-link {
        min-width: 38px;
        height: 38px;
    
        display: flex;
        align-items: center;
        justify-content: center;
    
        padding: 6px 11px;
    
        border: 1px solid #dee2e6;
        border-radius: 7px !important;
    
        background: #fff;
        color: #6f42c1;
    
        font-size: 14px;
        font-weight: 500;
    
        text-decoration: none;
    
        box-shadow: none !important;
    }
    
    .customers-pagination .page-link:hover {
        background: #f4eefb;
        border-color: #9b59c6;
        color: #6f42c1;
    }
    
    .customers-pagination .page-item.active .page-link {
        background: #9b59c6;
        border-color: #9b59c6;
        color: #fff;
    }
    
    .customers-pagination .page-item.disabled .page-link {
        background: #f8f9fa;
        border-color: #e9ecef;
        color: #adb5bd;
        opacity: 0.8;
    }
    
    @media (max-width: 767.98px) {
    
        .customers-pagination {
            width: 100%;
            overflow-x: auto;
        }
    
        .customers-pagination nav {
            min-width: max-content;
        }
    
        .customers-pagination .pagination {
            justify-content: center;
        }
    }

    .initial-password-box {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 5px 6px 5px 10px;
        border: 1px solid #e6e1da;
        border-radius: 9px;
        background: #fffdf9;
    }

    .initial-password-value {
        min-width: 62px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 1px;
        color: #3e3b37;
    }

    .initial-password-toggle {
        width: 31px;
        height: 29px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 7px;
    }

    .initial-password-toggle i {
        font-size: 12px;
    }

</style>


<script>
    document.addEventListener(
        "DOMContentLoaded",
        function () {

            document
                .querySelectorAll(
                    "[data-password-toggle]"
                )
                .forEach(
                    function (button) {

                        button.addEventListener(
                            "click",
                            function () {

                                const wrapper =
                                    button.closest(
                                        "[data-password-wrapper]"
                                    );

                                if (!wrapper) {
                                    return;
                                }

                                const value =
                                    wrapper.querySelector(
                                        "[data-password-value]"
                                    );

                                const icon =
                                    button.querySelector(
                                        "[data-password-icon]"
                                    );

                                if (!value) {
                                    return;
                                }

                                const password =
                                    value.dataset.password || "";

                                const isVisible =
                                    value.dataset.visible === "1";


                                if (isVisible) {

                                    value.textContent =
                                        "••••••";

                                    value.dataset.visible =
                                        "0";

                                    button.setAttribute(
                                        "aria-label",
                                        "Show initial password"
                                    );

                                    button.setAttribute(
                                        "title",
                                        "Show initial password"
                                    );

                                    if (icon) {
                                        icon.classList.remove(
                                            "fa-eye-slash"
                                        );

                                        icon.classList.add(
                                            "fa-eye"
                                        );
                                    }

                                } else {

                                    value.textContent =
                                        password;

                                    value.dataset.visible =
                                        "1";

                                    button.setAttribute(
                                        "aria-label",
                                        "Hide initial password"
                                    );

                                    button.setAttribute(
                                        "title",
                                        "Hide initial password"
                                    );

                                    if (icon) {
                                        icon.classList.remove(
                                            "fa-eye"
                                        );

                                        icon.classList.add(
                                            "fa-eye-slash"
                                        );
                                    }
                                }
                            }
                        );
                    }
                );
        }
    );
</script>

@endsection
