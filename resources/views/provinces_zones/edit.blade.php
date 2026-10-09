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
     */
    $canAddZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.add');

    $canRemoveZone = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.remove');
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

                <div class="mb-3">
                    <label
                        for="province_name"
                        class="form-label"
                    >
                        Province Name *
                    </label>

                    <input
                        type="text"
                        id="province_name"
                        name="name"
                        class="form-control @error('name', 'provinceUpdate') is-invalid @enderror"
                        value="{{ old('name', $province->name) }}"
                        required
                    >

                    @error('name', 'provinceUpdate')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
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
                        class="form-control @error('code', 'provinceUpdate') is-invalid @enderror"
                        value="{{ old('code', $province->code) }}"
                        required
                    >

                    @error('code', 'provinceUpdate')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
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

    <div class="card shadow card-sm">
        <div class="card-header bg-yellow text-dark py-2">
            <strong>
                Zones for: {{ $province->name }}
            </strong>
        </div>

        <div class="card-body p-2">
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
                                for="zone_name"
                                class="form-label small mb-1"
                            >
                                Add Zone
                            </label>

                            <input
                                type="text"
                                id="zone_name"
                                name="name"
                                class="form-control form-control-sm @error('name', 'zoneCreate') is-invalid @enderror"
                                placeholder="Zone name"
                                value="{{ old('name') }}"
                                required
                            >

                            @error('name', 'zoneCreate')
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

            @if(!$canAddZone && !$canRemoveZone)
                <div class="alert alert-light small mt-2 mb-0">
                    <i class="fas fa-eye me-1"></i>
                    You can edit the province, but zone management is read-only.
                </div>
            @endif
        </div>
    </div>
@endif
@endsection