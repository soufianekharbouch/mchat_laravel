@extends('layouts.app')

@section('title', 'Users Management')

@section('content')
@php
    $authenticatedUser = auth()->user();
    $canViewUsers = $authenticatedUser && $authenticatedUser->hasPermission('users.view');
    $canCreateUsers = $authenticatedUser && $authenticatedUser->hasPermission('users.create');
    $canUpdateUsers = $authenticatedUser && $authenticatedUser->hasPermission('users.update');
    $canDeleteUsers = $authenticatedUser && $authenticatedUser->hasPermission('users.delete');
@endphp

@if(!$canViewUsers)
<div class="alert alert-danger"><i class="fas fa-lock me-2"></i>You do not have permission to view users.</div>
@else
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="text-mauve h4 mb-1">
            Users Management
        </h2>

        <p class="text-muted mb-0">
            Manage user accounts and permissions.
        </p>
    </div>

    @if($canCreateUsers)
        <a
            href="{{ route('users.create') }}"
            class="btn btn-mauve btn-sm"
        >
            <i class="fas fa-plus me-1"></i>
            Add User
        </a>
    @endif
</div>

@if(session('error'))
    <div
        class="alert alert-danger alert-dismissible fade show"
        role="alert"
    >
        <i class="fas fa-exclamation-triangle me-2"></i>

        {{ session('error') }}

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Close"
        ></button>
    </div>
@endif

@if(session('success'))
    <div
        class="alert alert-success alert-dismissible fade show"
        role="alert"
    >
        <i class="fas fa-check me-2"></i>

        {{ session('success') }}

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Close"
        ></button>
    </div>
@endif

<div class="card card-sm shadow">
    <div class="card-body p-2">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Account Type</th>
                        <th>Permissions</th>
                        <th class="text-center">
                            Actions
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($users as $user)
                        @php
                            $hasContent =
                                $user->ingredients_count > 0
                                || $user->recipes_count > 0;

                            $isRootUser =
                                $user->isRootUser();

                            $hasLegacyFullAccess =
                                $user->hasLegacyFullAccess();
                        @endphp

                        <tr>
                            <td>
                                {{ $user->id }}
                            </td>

                            <td>
                                <div class="fw-bold">
                                    {{ $user->first_name }}
                                    {{ $user->last_name }}
                                </div>
                            </td>

                            <td>
                                {{ $user->username }}

                                @if($isRootUser)
                                    <span class="badge bg-dark ms-1">
                                        Root
                                    </span>
                                @endif
                            </td>

                            <td>
                                {{ $user->email ?: 'N/A' }}
                            </td>

                            <td>
                                {{ $user->phone ?: 'N/A' }}
                            </td>

                            <td>
                                @if($isRootUser)
                                    <span class="badge bg-dark">
                                        Root account
                                    </span>
                                @elseif($hasLegacyFullAccess)
                                    <span
                                        class="badge bg-warning text-dark"
                                        data-bs-toggle="tooltip"
                                        title="Existing user created before the permissions system"
                                    >
                                        Legacy user
                                    </span>
                                @else
                                    <span class="badge bg-secondary">
                                        Standard user
                                    </span>
                                @endif
                            </td>

                            <td>
                                @if($isRootUser)
                                    <span
                                        class="badge bg-dark"
                                        data-bs-toggle="tooltip"
                                        title="The root account always has all permissions"
                                    >
                                        Full access
                                    </span>
                                @elseif($hasLegacyFullAccess)
                                    <span
                                        class="badge bg-warning text-dark"
                                        data-bs-toggle="tooltip"
                                        title="This existing user currently has full access until their permissions are saved"
                                    >
                                        Legacy full access
                                    </span>
                                @elseif($user->permissionsCount() > 0)
                                    <span
                                        class="badge bg-primary"
                                        data-bs-toggle="tooltip"
                                        title="{{ $user->permissionsCount() }} assigned permission(s)"
                                    >
                                        {{ $user->permissionsCount() }}
                                        permission(s)
                                    </span>
                                @else
                                    <span
                                        class="badge bg-danger"
                                        data-bs-toggle="tooltip"
                                        title="This user has no assigned permissions"
                                    >
                                        No permissions
                                    </span>
                                @endif
                            </td>

                            <td>
                                <div class="d-flex justify-content-center gap-1">
                                    @if(
                                        $canUpdateUsers
                                        || auth()->id() === $user->id
                                    )
                                        <a
                                            href="{{ route('users.edit', $user) }}"
                                            class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="tooltip"
                                            title="Edit user"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    @endif

                                    @if($isRootUser)
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            disabled
                                            data-bs-toggle="tooltip"
                                            title="The root user cannot be deleted"
                                        >
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    @elseif(
                                        $canDeleteUsers
                                        && $hasContent
                                    )
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteModal{{ $user->id }}"
                                            title="Delete user"
                                        >
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    @elseif(
                                        $canDeleteUsers
                                    )
                                        <form
                                            action="{{ route('users.destroy', $user) }}"
                                            method="POST"
                                            class="d-inline"
                                            onsubmit="return confirm('Are you sure you want to delete {{ addslashes($user->first_name . ' ' . $user->last_name) }}?');"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="tooltip"
                                                title="Delete user"
                                            >
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="8"
                                class="text-center py-4"
                            >
                                <i class="fas fa-info-circle me-2"></i>
                                No users found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@foreach($users as $user)
    @php
        $hasContent =
            $user->ingredients_count > 0
            || $user->recipes_count > 0;
    @endphp

    @if(
        $hasContent
        && !$user->isRootUser()
        && $canUpdateUsers
    )
        <div
            class="modal fade"
            id="deleteModal{{ $user->id }}"
            tabindex="-1"
            aria-labelledby="deleteModalLabel{{ $user->id }}"
            aria-hidden="true"
        >
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5
                            class="modal-title"
                            id="deleteModalLabel{{ $user->id }}"
                        >
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Cannot Delete User
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"
                        ></button>
                    </div>

                    <div class="modal-body">
                        <p>
                            The user

                            <strong>
                                {{ $user->first_name }}
                                {{ $user->last_name }}
                            </strong>

                            cannot be deleted because they created:
                        </p>

                        <ul>
                            @if($user->ingredients_count > 0)
                                <li>
                                    <strong>
                                        {{ $user->ingredients_count }}
                                    </strong>

                                    ingredient(s)
                                </li>
                            @endif

                            @if($user->recipes_count > 0)
                                <li>
                                    <strong>
                                        {{ $user->recipes_count }}
                                    </strong>

                                    recipe(s)
                                </li>
                            @endif
                        </ul>

                        <p class="mb-0">
                            Reassign or delete this content before deleting
                            the user.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endforeach

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tooltipTriggerList = document.querySelectorAll(
        '[data-bs-toggle="tooltip"]'
    );

    tooltipTriggerList.forEach(function (tooltipTriggerElement) {
        new bootstrap.Tooltip(tooltipTriggerElement);
    });
});
</script>
@endif
@endsection