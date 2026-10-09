@extends('layouts.app')

@section('content')
@php
    $authenticatedUser = auth()->user();

    /*
     * Province permissions
     */
    $canCreateProvince = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.create');

    $canUpdateProvince = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.update');

    $canDeleteProvince = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.delete');

    /*
     * Zone permissions
     */
    $canAddZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.add');

    $canRemoveZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.remove');
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">
        Provinces / Zones
    </h2>

    @if($canCreateProvince)
        <a
            href="{{ route('provinces-zones.create') }}"
            class="btn btn-mauve btn-sm"
        >
            <i class="fas fa-plus me-1"></i>
            Add Province
        </a>
    @endif
</div>

<div class="card shadow card-sm">
    <div class="card-header bg-mauve text-white py-2">
        <strong>Manage Provinces & Zones</strong>
    </div>

    <div class="card-body p-2">
        @if(session('success'))
            <div class="alert alert-success small mb-2">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger small mb-2">
                {{ session('error') }}
            </div>
        @endif

        @if($provinces->isEmpty())
            <div class="alert alert-info mb-0 small">
                No provinces yet.
            </div>
        @else
            <div
                class="accordion"
                id="provincesAccordion"
            >
                @foreach($provinces as $province)
                    @php
                        $collapseId = 'province_' . $province->id;
                    @endphp

                    <div class="accordion-item">
                        <h2
                            class="accordion-header"
                            id="heading_{{ $province->id }}"
                        >
                            <button
                                class="accordion-button collapsed py-2"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#{{ $collapseId }}"
                                aria-expanded="false"
                                aria-controls="{{ $collapseId }}"
                            >
                                <div class="d-flex justify-content-between align-items-center w-100">
                                    <span class="fw-semibold">
                                        <i class="fas fa-map-marker-alt me-2 text-mauve"></i>

                                        {{ $province->name }}

                                        <span class="text-muted small ms-2">
                                            ({{ $province->code }})
                                        </span>
                                    </span>

                                    <span class="badge bg-yellow text-dark me-3">
                                        {{ $province->zones->count() }}
                                        {{ $province->zones->count() === 1 ? 'zone' : 'zones' }}
                                    </span>
                                </div>
                            </button>
                        </h2>

                        <div
                            id="{{ $collapseId }}"
                            class="accordion-collapse collapse"
                            aria-labelledby="heading_{{ $province->id }}"
                            data-bs-parent="#provincesAccordion"
                        >
                            <div class="accordion-body p-2">
                                @if($canUpdateProvince || $canDeleteProvince)
                                    <div class="d-flex justify-content-end gap-2 mb-2">
                                        @if($canUpdateProvince)
                                            <a
                                                href="{{ route('provinces-zones.edit', $province) }}"
                                                class="btn btn-sm btn-outline-secondary"
                                            >
                                                <i class="fas fa-edit me-1"></i>
                                                Edit
                                            </a>
                                        @endif

                                        @if($canDeleteProvince)
                                            <form
                                                method="POST"
                                                action="{{ route('provinces-zones.destroy', $province) }}"
                                                onsubmit="return confirm('Delete this province?')"
                                            >
                                                @csrf
                                                @method('DELETE')

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                >
                                                    <i class="fas fa-trash me-1"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @endif

                                @if($canAddZone)
                                    <form
                                        method="POST"
                                        action="{{ route('provinces-zones.zones.store', $province) }}"
                                        class="mb-2"
                                    >
                                        @csrf

                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-8">
                                                <label
                                                    for="zone_name_{{ $province->id }}"
                                                    class="form-label small mb-1"
                                                >
                                                    Add Zone
                                                </label>

                                                <input
                                                    type="text"
                                                    id="zone_name_{{ $province->id }}"
                                                    name="name"
                                                    class="form-control form-control-sm @error('name') is-invalid @enderror"
                                                    placeholder="Zone name"
                                                    value="{{ old('name') }}"
                                                    required
                                                >

                                                @error('name')
                                                    <div class="invalid-feedback">
                                                        {{ $message }}
                                                    </div>
                                                @enderror
                                            </div>

                                            <div class="col-md-4">
                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-mauve w-100"
                                                >
                                                    <i class="fas fa-plus me-1"></i>
                                                    Add
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                @endif

                                @if($province->zones->isEmpty())
                                    <div class="alert alert-light small mb-0">
                                        No zones yet.
                                    </div>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Zone</th>

                                                    @if($canRemoveZone)
                                                        <th
                                                            class="text-end"
                                                            style="width: 120px;"
                                                        >
                                                            Action
                                                        </th>
                                                    @endif
                                                </tr>
                                            </thead>

                                            <tbody>
                                                @foreach($province->zones as $zone)
                                                    <tr>
                                                        <td class="small">
                                                            {{ $zone->name }}
                                                        </td>

                                                        @if($canRemoveZone)
                                                            <td class="text-end">
                                                                <form
                                                                    method="POST"
                                                                    action="{{ route(
                                                                        'provinces-zones.zones.destroy',
                                                                        [$province, $zone]
                                                                    ) }}"
                                                                    onsubmit="return confirm('Delete this zone?')"
                                                                >
                                                                    @csrf
                                                                    @method('DELETE')

                                                                    <button
                                                                        type="submit"
                                                                        class="btn btn-sm btn-outline-danger"
                                                                        title="Delete zone"
                                                                    >
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        @endif
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif

                                @if(
                                    !$canCreateProvince
                                    && !$canUpdateProvince
                                    && !$canDeleteProvince
                                    && !$canAddZone
                                    && !$canRemoveZone
                                )
                                    <div class="alert alert-light small mt-2 mb-0">
                                        <i class="fas fa-eye me-1"></i>
                                        View-only access.
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection