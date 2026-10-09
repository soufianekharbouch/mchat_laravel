@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">Settings</h2>
    <a href="{{ route('settings.edit') }}" class="btn btn-mauve btn-sm">
        <i class="fas fa-edit me-1"></i>Edit
    </a>
</div>

<div class="card shadow card-sm">
    <div class="card-header bg-mauve text-white py-2">
        <strong>Current Settings</strong>
    </div>

    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fw-semibold mb-2">Logo</div>
                    @if($settings->logo)
                        <img src="{{ asset('storage/app/public/'.$settings->logo) }}" style="max-width:200px;">
                    @else
                        <div class="text-muted small">No logo</div>
                    @endif
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fw-semibold mb-2">Logo Premium</div>
                    @if($settings->logo_premium)
                        <img src="{{ asset('storage/app/public/'.$settings->logo_premium) }}" style="max-width:200px;">
                    @else
                        <div class="text-muted small">No premium logo</div>
                    @endif
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fw-semibold mb-2">PDF First Delivery</div>
                    @if($settings->pdf_first_delivery)
                        <a href="{{ asset('storage/app/public/'.$settings->pdf_first_delivery) }}" target="_blank" class="btn btn-outline-primary btn-sm">
                            Open PDF
                        </a>
                    @else
                        <div class="text-muted small">No file</div>
                    @endif
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fw-semibold mb-2">PDF Last Delivery</div>
                    @if($settings->pdf_last_delivery)
                        <a href="{{ asset('storage/app/public/'.$settings->pdf_last_delivery) }}" target="_blank" class="btn btn-outline-primary btn-sm">
                            Open PDF
                        </a>
                    @else
                        <div class="text-muted small">No file</div>
                    @endif
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fw-semibold mb-2">PDF Language</div>
                    <div>{{ strtoupper($settings->pdf_lang) }}</div>
                </div>
            </div>

            <div class="col-12">
                <div class="border rounded p-3">
                    <div class="fw-semibold mb-2">Meal Storage Instructions</div>
                    @if(!empty($settings->meal_storage_instructions))
                        <div class="p-2 border rounded bg-light">
                            {!! $settings->meal_storage_instructions !!}
                        </div>
                    @else
                        <div class="text-muted small">No content</div>
                    @endif
                </div>
            </div>

            <div class="col-12">
                <div class="border rounded p-3">
                    <div class="fw-semibold mb-2">Welcome Message</div>
                    @if(!empty($settings->welcome_message))
                        <div class="p-2 border rounded bg-light">
                            {!! $settings->welcome_message !!}
                        </div>
                    @else
                        <div class="text-muted small">No content</div>
                    @endif
                </div>
            </div>

            <div class="col-12">
                <div class="border rounded p-3">
                    <div class="fw-semibold mb-2">Subscription QR Message Template</div>
                    @if(!empty($settings->subscription_qr_message))
                        <div class="p-2 border rounded bg-light">
                            {!! $settings->subscription_qr_message !!}
                        </div>
                    @else
                        <div class="text-muted small">No content</div>
                    @endif
                </div>
            </div>

            <div class="col-12">
                <div class="border rounded p-3">
                    <div class="fw-semibold mb-2">Last Order Message Template</div>
                    @if(!empty($settings->last_order_subscription_message))
                        <div class="p-2 border rounded bg-light">
                            {!! $settings->last_order_subscription_message !!}
                        </div>
                    @else
                        <div class="text-muted small">No content</div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</div>
@endsection