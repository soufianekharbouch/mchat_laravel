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
     * (updateZone utilise la même permission que la province : zones.update)
     */
    $canAddZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.add');

    $canUpdateZone = $canUpdateProvince;

    $canRemoveZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.remove');

    $hasZoneActions = $canUpdateZone || $canRemoveZone;

    /*
     * Formulaire soumis en dernier (pour réafficher les erreurs
     * uniquement dans la bonne province / zone et rouvrir l'accordéon).
     */
    $submittedProvinceId = (int) old('form_province_id');
    $submittedZoneId = (int) old('form_zone_id');
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
                        $isSubmittedProvince = $submittedProvinceId === (int) $province->id;
                        $isAddZoneSubmitted = $isSubmittedProvince && !$submittedZoneId;
                    @endphp

                    <div class="accordion-item">
                        <h2
                            class="accordion-header"
                            id="heading_{{ $province->id }}"
                        >
                            <button
                                class="accordion-button py-2 {{ $isSubmittedProvince ? '' : 'collapsed' }}"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#{{ $collapseId }}"
                                aria-expanded="{{ $isSubmittedProvince ? 'true' : 'false' }}"
                                aria-controls="{{ $collapseId }}"
                            >
                                <div class="d-flex justify-content-between align-items-center w-100">
                                    <span class="fw-semibold">
                                        <i class="fas fa-map-marker-alt me-2 text-mauve"></i>

                                        {{ $province->name }}

                                        @if(filled($province->name_ar))
                                            <span
                                                class="text-muted ms-2"
                                                dir="rtl"
                                                lang="ar"
                                            >
                                                — {{ $province->name_ar }}
                                            </span>
                                        @else
                                            <span class="badge bg-light text-danger border ms-2 small">
                                                Arabic missing
                                            </span>
                                        @endif

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
                            class="accordion-collapse collapse {{ $isSubmittedProvince ? 'show' : '' }}"
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

                                {{-- ADD ZONE --}}
                                @if($canAddZone)
                                    <form
                                        method="POST"
                                        action="{{ route('provinces-zones.zones.store', $province) }}"
                                        class="mb-2"
                                    >
                                        @csrf

                                        <input type="hidden" name="form_province_id" value="{{ $province->id }}">

                                        <div class="small fw-semibold mb-1">
                                            Add Zone
                                        </div>

                                        <div class="row g-2 align-items-start">
                                            <div class="col-md-5">
                                                <label
                                                    for="zone_name_{{ $province->id }}"
                                                    class="form-label small mb-1"
                                                >
                                                    Name (English)
                                                </label>

                                                <input
                                                    type="text"
                                                    id="zone_name_{{ $province->id }}"
                                                    name="name"
                                                    class="form-control form-control-sm {{ $isAddZoneSubmitted && $errors->has('name') ? 'is-invalid' : '' }}"
                                                    placeholder="Zone name"
                                                    value="{{ $isAddZoneSubmitted ? old('name') : '' }}"
                                                    required
                                                >

                                                @if($isAddZoneSubmitted && $errors->has('name'))
                                                    <div class="invalid-feedback">
                                                        {{ $errors->first('name') }}
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="col-md-5">
                                                <label
                                                    for="zone_name_ar_{{ $province->id }}"
                                                    class="form-label small mb-1"
                                                >
                                                    Name (Arabic)
                                                </label>

                                                <input
                                                    type="text"
                                                    id="zone_name_ar_{{ $province->id }}"
                                                    name="name_ar"
                                                    dir="rtl"
                                                    lang="ar"
                                                    class="form-control form-control-sm {{ $isAddZoneSubmitted && $errors->has('name_ar') ? 'is-invalid' : '' }}"
                                                    placeholder="اسم المنطقة"
                                                    value="{{ $isAddZoneSubmitted ? old('name_ar') : '' }}"
                                                    required
                                                >

                                                @if($isAddZoneSubmitted && $errors->has('name_ar'))
                                                    <div class="invalid-feedback">
                                                        {{ $errors->first('name_ar') }}
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="col-md-2">
                                                <label class="form-label small mb-1 d-none d-md-block">&nbsp;</label>

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

                                {{-- ZONES LIST --}}
                                @if($province->zones->isEmpty())
                                    <div class="alert alert-light small mb-0">
                                        No zones yet.
                                    </div>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Zone (English)</th>
                                                    <th class="text-end">Zone (Arabic)</th>

                                                    @if($hasZoneActions)
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
                                                    @php
                                                        $editRowId = 'zone_edit_' . $zone->id;
                                                        $isEditSubmitted = $submittedZoneId === (int) $zone->id;
                                                    @endphp

                                                    <tr>
                                                        <td class="small">
                                                            {{ $zone->name }}
                                                        </td>

                                                        <td
                                                            class="small text-end"
                                                            dir="rtl"
                                                            lang="ar"
                                                        >
                                                            @if(filled($zone->name_ar))
                                                                {{ $zone->name_ar }}
                                                            @else
                                                                <span class="text-danger">—</span>
                                                            @endif
                                                        </td>

                                                        @if($hasZoneActions)
                                                            <td class="text-end">
                                                                <div class="d-inline-flex gap-1">
                                                                    @if($canUpdateZone)
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-sm btn-outline-secondary"
                                                                            title="Edit zone"
                                                                            data-bs-toggle="collapse"
                                                                            data-bs-target="#{{ $editRowId }}"
                                                                            aria-expanded="{{ $isEditSubmitted ? 'true' : 'false' }}"
                                                                            aria-controls="{{ $editRowId }}"
                                                                        >
                                                                            <i class="fas fa-edit"></i>
                                                                        </button>
                                                                    @endif

                                                                    @if($canRemoveZone)
                                                                        <form
                                                                            method="POST"
                                                                            action="{{ route('provinces-zones.zones.destroy', [$province, $zone]) }}"
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
                                                                    @endif
                                                                </div>
                                                            </td>
                                                        @endif
                                                    </tr>

                                                    {{-- INLINE ZONE EDIT --}}
                                                    @if($canUpdateZone)
                                                        <tr
                                                            id="{{ $editRowId }}"
                                                            class="collapse {{ $isEditSubmitted ? 'show' : '' }}"
                                                        >
                                                            <td colspan="{{ $hasZoneActions ? 3 : 2 }}">
                                                                <form
                                                                    method="POST"
                                                                    action="{{ route('provinces-zones.zones.update', [$province, $zone]) }}"
                                                                >
                                                                    @csrf
                                                                    @method('PUT')

                                                                    <input type="hidden" name="form_province_id" value="{{ $province->id }}">
                                                                    <input type="hidden" name="form_zone_id" value="{{ $zone->id }}">

                                                                    <div class="row g-2 align-items-start">
                                                                        <div class="col-md-5">
                                                                            <input
                                                                                type="text"
                                                                                name="name"
                                                                                class="form-control form-control-sm {{ $isEditSubmitted && $errors->has('name') ? 'is-invalid' : '' }}"
                                                                                placeholder="Zone name"
                                                                                value="{{ $isEditSubmitted ? old('name') : $zone->name }}"
                                                                                required
                                                                            >

                                                                            @if($isEditSubmitted && $errors->has('name'))
                                                                                <div class="invalid-feedback">
                                                                                    {{ $errors->first('name') }}
                                                                                </div>
                                                                            @endif
                                                                        </div>

                                                                        <div class="col-md-5">
                                                                            <input
                                                                                type="text"
                                                                                name="name_ar"
                                                                                dir="rtl"
                                                                                lang="ar"
                                                                                class="form-control form-control-sm {{ $isEditSubmitted && $errors->has('name_ar') ? 'is-invalid' : '' }}"
                                                                                placeholder="اسم المنطقة"
                                                                                value="{{ $isEditSubmitted ? old('name_ar') : $zone->name_ar }}"
                                                                                required
                                                                            >

                                                                            @if($isEditSubmitted && $errors->has('name_ar'))
                                                                                <div class="invalid-feedback">
                                                                                    {{ $errors->first('name_ar') }}
                                                                                </div>
                                                                            @endif
                                                                        </div>

                                                                        <div class="col-md-2">
                                                                            <button
                                                                                type="submit"
                                                                                class="btn btn-sm btn-mauve w-100"
                                                                            >
                                                                                <i class="fas fa-save me-1"></i>
                                                                                Save
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    @endif
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
