@extends('layouts.app')

@section('title', 'Create User')

@section('content')
<div class="card shadow">
    <div class="card-header bg-mauve text-white">
        <h4 class="mb-0">
            <i class="fas fa-user-plus me-2"></i>
            Create New User
        </h4>
    </div>

    <div class="card-body">
        @if($errors->any())
            <div class="alert alert-danger">
                <div class="fw-bold mb-2">
                    Please correct the following errors:
                </div>

                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('users.store') }}"
            id="user-form"
        >
            @csrf

            <h5 class="text-mauve mb-3">
                <i class="fas fa-user me-2"></i>
                Account Information
            </h5>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label
                            for="first_name"
                            class="form-label"
                        >
                            First Name
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            class="form-control @error('first_name') is-invalid @enderror"
                            id="first_name"
                            name="first_name"
                            value="{{ old('first_name') }}"
                            required
                        >

                        @error('first_name')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label
                            for="last_name"
                            class="form-label"
                        >
                            Last Name
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            class="form-control @error('last_name') is-invalid @enderror"
                            id="last_name"
                            name="last_name"
                            value="{{ old('last_name') }}"
                            required
                        >

                        @error('last_name')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label
                            for="username"
                            class="form-label"
                        >
                            Username
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            class="form-control @error('username') is-invalid @enderror"
                            id="username"
                            name="username"
                            value="{{ old('username') }}"
                            autocomplete="off"
                            required
                        >

                        <small class="text-muted">
                            The username "root" is reserved.
                        </small>

                        @error('username')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label
                            for="email"
                            class="form-label"
                        >
                            Email Address
                        </label>

                        <input
                            type="email"
                            class="form-control @error('email') is-invalid @enderror"
                            id="email"
                            name="email"
                            value="{{ old('email') }}"
                        >

                        @error('email')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label
                            for="phone"
                            class="form-label"
                        >
                            Phone Number
                        </label>

                        <input
                            type="text"
                            class="form-control @error('phone') is-invalid @enderror"
                            id="phone"
                            name="phone"
                            value="{{ old('phone') }}"
                        >

                        @error('phone')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label
                            for="password"
                            class="form-label"
                        >
                            Password
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="password"
                            class="form-control @error('password') is-invalid @enderror"
                            id="password"
                            name="password"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >

                        @error('password')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label
                            for="password_confirmation"
                            class="form-label"
                        >
                            Confirm Password
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="password"
                            class="form-control"
                            id="password_confirmation"
                            name="password_confirmation"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h5 class="text-mauve mb-1">
                        <i class="fas fa-user-shield me-2"></i>
                        User Permissions
                    </h5>

                    <p class="text-muted mb-0">
                        Select the actions this user is allowed to perform.
                    </p>
                </div>

                <div class="d-flex gap-2">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-success"
                        id="select-all-permissions"
                    >
                        <i class="fas fa-check-double me-1"></i>
                        Select All
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        id="clear-all-permissions"
                    >
                        <i class="fas fa-times me-1"></i>
                        Clear All
                    </button>
                </div>
            </div>

            @error('permissions')
                <div class="alert alert-danger">
                    {{ $message }}
                </div>
            @enderror

            @error('permissions.*')
                <div class="alert alert-danger">
                    {{ $message }}
                </div>
            @enderror

            @php
                $selectedPermissions = old(
                    'permissions',
                    []
                );
            @endphp

            <div class="row g-3">
                @foreach($permissionGroups as $groupKey => $group)
                    <div class="col-12 col-lg-6">
                        <div class="card h-100 border">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <h6 class="mb-1 fw-bold">
                                            {{ $group['title'] }}
                                        </h6>

                                        <small class="text-muted">
                                            {{ $group['description'] }}
                                        </small>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary select-group-permissions"
                                        data-group="{{ $groupKey }}"
                                    >
                                        Select group
                                    </button>
                                </div>
                            </div>

                            <div class="card-body">
                                @foreach($group['permissions'] as $permission => $label)
                                    @php
                                        $permissionId =
                                            'permission-'
                                            . str_replace(
                                                '.',
                                                '-',
                                                $permission
                                            );
                                    @endphp

                                    <div class="form-check form-switch mb-3">
                                        <input
                                            class="form-check-input permission-checkbox permission-group-{{ $groupKey }}"
                                            type="checkbox"
                                            role="switch"
                                            id="{{ $permissionId }}"
                                            name="permissions[]"
                                            value="{{ $permission }}"
                                            @checked(
                                                in_array(
                                                    $permission,
                                                    $selectedPermissions,
                                                    true
                                                )
                                            )
                                        >

                                        <label
                                            class="form-check-label"
                                            for="{{ $permissionId }}"
                                        >
                                            {{ $label }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex gap-2 mt-4">
                <button
                    type="submit"
                    class="btn btn-mauve"
                >
                    <i class="fas fa-save me-2"></i>
                    Create User
                </button>

                <a
                    href="{{ route('users.index') }}"
                    class="btn btn-outline-secondary"
                >
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const permissionCheckboxes = document.querySelectorAll(
        '.permission-checkbox'
    );

    const selectAllButton = document.getElementById(
        'select-all-permissions'
    );

    const clearAllButton = document.getElementById(
        'clear-all-permissions'
    );

    selectAllButton.addEventListener('click', function () {
        permissionCheckboxes.forEach(function (checkbox) {
            checkbox.checked = true;
        });
    });

    clearAllButton.addEventListener('click', function () {
        permissionCheckboxes.forEach(function (checkbox) {
            checkbox.checked = false;
        });
    });

    document
        .querySelectorAll('.select-group-permissions')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                const group = this.dataset.group;

                const groupCheckboxes =
                    document.querySelectorAll(
                        '.permission-group-' + group
                    );

                const allSelected =
                    Array.from(groupCheckboxes)
                        .every(function (checkbox) {
                            return checkbox.checked;
                        });

                groupCheckboxes.forEach(function (checkbox) {
                    checkbox.checked = !allSelected;
                });

                this.textContent = allSelected
                    ? 'Select group'
                    : 'Clear group';
            });
        });
});
</script>
@endsection