@extends('layouts.app')

@section('title', 'Add Province')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canCreateProvince = $authenticatedUser
        && $authenticatedUser->hasPermission('zones.create');
@endphp

@if(!$canCreateProvince)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        You do not have permission to add provinces.
    </div>

    <a
        href="{{ route('provinces-zones.index') }}"
        class="btn btn-outline-secondary"
    >
        <i class="fas fa-arrow-left me-1"></i>
        Back to provinces and zones
    </a>
@else
    <div class="card shadow card-sm">
        <div class="card-header bg-mauve text-white py-2">
            <strong>Add Province</strong>
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
                action="{{ route('provinces-zones.store') }}"
            >
                @csrf

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
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name') }}"
                        required
                        autofocus
                    >

                    @error('name')
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
                        class="form-control @error('code') is-invalid @enderror"
                        value="{{ old('code') }}"
                        required
                    >

                    @error('code')
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
                        Create
                    </button>

                    <a
                        href="{{ route('provinces-zones.index') }}"
                        class="btn btn-outline-secondary"
                    >
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection