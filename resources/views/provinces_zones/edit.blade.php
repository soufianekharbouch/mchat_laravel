@extends('layouts.app')

@section('title', 'Edit Province')

@section('content')
@php
    $authenticatedUser = auth()->user();

    /*
     * Province permissions
     */
    $canUpdateProvince = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.update');

    /*
     * Zone permissions
     * (updateZone utilise la permission zones.update)
     */
    $canAddZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.add');

    $canUpdateZone = $canUpdateProvince;

    $canRemoveZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.remove');

    $hasZoneActions = $canUpdateZone || $canRemoveZone;

    /*
     * Formulaire soumis en dernier.
     * Les trois formulaires utilisent le champ "name" : sans ce repère,
     * old('name') et les erreurs s'afficheraient dans tous les formulaires.
     *
     *   province     : modification de la province
     *   zone_create  : ajout d'une zone
     *   zone_update  : modification d'une zone (+ form_zone_id)
     */
    $formContext = old('form_context');
    $submittedZoneId = (int) old('form_zone_id');

    $isProvinceSubmitted = $formContext === 'province';
    $isZoneCreateSubmitted = $formContext === 'zone_create';
@endphp

@if(!$canUpdateProvince)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        You do not have permission to edit provinces.
    </div>

    <a
        href="{{ route('provinces-zones.index') }}"
        class="btn btn-outline-secondary"
    >
        <i class="fas fa-arrow-left me-1"></i>
        Back to provinces and zones
    </a>
@else
    {{-- ================================================================
         PROVINCE
    ================================================================ --}}
    <div class="card shadow card-sm mb-3">
        <div class="card-header bg-mauve text-white py-2">
            <strong>Edit Province</strong>
        </div>

        <div class="card-body">
            @if(session('success'))
                <div class="alert alert-success small mb-3">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger small mb-3">
                    {{ session('error') }}
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('provinces-zones.update', $province) }}"
            >
                @csrf
                @method('PUT')

                <input type="hidden" name="form_context" value="province">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label
                            for="province_name"
                            class="form-label"
                        >
                            Province Name (English) *
                        </label>

                        <input
                            type="text"
                            id="province_name"
                            name="name"
                            class="form-control {{ $isProvinceSubmitted && $errors->has('name') ? 'is-invalid' : '' }}"
                            value="{{ $isProvinceSubmitted ? old('name') : $province->name }}"
                            required
                        >

                        @if($isProvinceSubmitted && $errors->has('name'))
                            <div class="invalid-feedback">
                                {{ $errors->first('name') }}
                            </div>
                        @endif
                    </div>

                    <div class="col-md-6 mb-3">
                        <label
                            for="province_name_ar"
                            class="form-label"
                        >
                            Province Name (Arabic) *
                        </label>

                        <input
                            type="text"
                            id="province_name_ar"
                            name="name_ar"
                            dir="rtl"
                            lang="ar"
                            class="form-control {{ $isProvinceSubmitted && $errors->has('name_ar') ? 'is-invalid' : '' }}"
                            placeholder="اسم الإقليم"
                            value="{{ $isProvinceSubmitted ? old('name_ar') : $province->name_ar }}"
                            required
                        >

                        @if($isProvinceSubmitted && $errors->has('name_ar'))
                            <div class="invalid-feedback">
                                {{ $errors->first('name_ar') }}
                            </div>
                        @endif
                    </div>
                </div>

                <div class="mb-3">
                    <label
                        for="province_code"
                        class="form-label"
                    >
                        Code *
                    </label>

                    <input
                        type="text"
                        id="province_code"
                        name="code"
                        class="form-control {{ $isProvinceSubmitted && $errors->has('code') ? 'is-invalid' : '' }}"
                        value="{{ $isProvinceSubmitted ? old('code') : $province->code }}"
                        required
                    >

                    @if($isProvinceSubmitted && $errors->has('code'))
                        <div class="invalid-feedback">
                            {{ $errors->first('code') }}
                        </div>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    <button
                        type="submit"
                        class="btn btn-mauve"
                    >
                        <i class="fas fa-save me-1"></i>
                        Update
                    </button>

                    <a
                        href="{{ route('provinces-zones.index') }}"
                        class="btn btn-outline-secondary"
                    >
                        Back
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- ================================================================
         ZONES
    ================================================================ --}}
    <div class="card shadow card-sm">
        <div class="card-header bg-yellow text-dark py-2">
            <strong>
                Zones for: {{ $province->name }}

                @if(filled($province->name_ar))
                    <span class="ms-1" dir="rtl" lang="ar">— {{ $province->name_ar }}</span>
                @endif
            </strong>
        </div>

        <div class="card-body p-2">
            {{-- ADD ZONE --}}
            @if($canAddZone)
                <form
                    method="POST"
                    action="{{ route('provinces-zones.zones.store', $province) }}"
                    class="mb-2"
                >
                    @csrf

                    <input type="hidden" name="form_context" value="zone_create">

                    <div class="small fw-semibold mb-1">
                        Add Zone
                    </div>

                    <div class="row g-2 align-items-start">
                        <div class="col-md-5">
                            <label
                                for="zone_name"
                                class="form-label small mb-1"
                            >
                                Name (English)
                            </label>

                            <input
                                type="text"
                                id="zone_name"
                                name="name"
                                class="form-control form-control-sm {{ $isZoneCreateSubmitted && $errors->has('name') ? 'is-invalid' : '' }}"
                                placeholder="Zone name"
                                value="{{ $isZoneCreateSubmitted ? old('name') : '' }}"
                                required
                            >

                            @if($isZoneCreateSubmitted && $errors->has('name'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('name') }}
                                </div>
                            @endif
                        </div>

                        <div class="col-md-5">
                            <label
                                for="zone_name_ar"
                                class="form-label small mb-1"
                            >
                                Name (Arabic)
                            </label>

                            <input
                                type="text"
                                id="zone_name_ar"
                                name="name_ar"
                                dir="rtl"
                                lang="ar"
                                class="form-control form-control-sm {{ $isZoneCreateSubmitted && $errors->has('name_ar') ? 'is-invalid' : '' }}"
                                placeholder="اسم المنطقة"
                                value="{{ $isZoneCreateSubmitted ? old('name_ar') : '' }}"
                                required
                            >

                            @if($isZoneCreateSubmitted && $errors->has('name_ar'))
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
                                    $isEditSubmitted = $formContext === 'zone_update'
                                        && $submittedZoneId === (int) $zone->id;
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

                                                <input type="hidden" name="form_context" value="zone_update">
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

            @if(!$canAddZone && !$canRemoveZone)
                <div class="alert alert-light small mt-2 mb-0">
                    <i class="fas fa-eye me-1"></i>
                    Zone creation and deletion are not available with your permissions.
                </div>
            @endif
        </div>
    </div>
@endif
@endsection
