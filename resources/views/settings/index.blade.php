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
{{-- Load FAQ items when the Settings controller has not provided them yet. --}}
@php
    $helpFaqs = $helpFaqs ?? \App\Models\HelpFaq::query()
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();
@endphp
{{-- Help & Knowledge Base: separate collapsible section --}}
<div class="card shadow card-sm mt-3">
    <div class="card-header bg-mauve text-white py-2">
        <button class="btn w-100 d-flex align-items-center justify-content-between text-white p-0 border-0 shadow-none"
                type="button" data-bs-toggle="collapse" data-bs-target="#helpFaqSection"
                aria-expanded="false" aria-controls="helpFaqSection">
            <strong><i class="fas fa-question-circle me-2"></i>Help &amp; Knowledge Base</strong>
            <span class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark">{{ $helpFaqs->count() }}</span>
                <i class="fas fa-chevron-down"></i>
            </span>
        </button>
    </div>
    <div id="helpFaqSection" class="collapse">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <p class="text-muted small mb-0">Manage the questions displayed in the mobile application's Help &amp; Knowledge Base.</p>
                @if(\Illuminate\Support\Facades\Route::has('settings.help-faqs.create'))
                    <a href="{{ route('settings.help-faqs.create') }}" class="btn btn-mauve btn-sm">
                        <i class="fas fa-plus me-1"></i>Add FAQ
                    </a>
                @endif
            </div>
            @if($helpFaqs->isEmpty())
                <div class="text-muted text-center border rounded py-4">No FAQ items available.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:65px">#</th>
                                <th>Question (English / Arabic)</th>
                                <th class="text-center">Order</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($helpFaqs as $faq)
                                <tr>
                                    <td class="text-muted">{{ $loop->iteration }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ $faq->question_en }}</div>
                                        <div class="text-muted small mt-1" dir="rtl">{{ $faq->question_ar }}</div>
                                        <details class="mt-2">
                                            <summary class="small text-primary" style="cursor:pointer">View answers</summary>
                                            <div class="small border rounded bg-light p-2 mt-2">
                                                <div class="mb-2" style="white-space:pre-line">{{ $faq->answer_en }}</div>
                                                <div dir="rtl" style="white-space:pre-line">{{ $faq->answer_ar }}</div>
                                            </div>
                                        </details>
                                    </td>
                                    <td class="text-center">{{ $faq->sort_order }}</td>
                                    <td class="text-center">
                                        @if($faq->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if(\Illuminate\Support\Facades\Route::has('settings.help-faqs.edit'))
                                            <a href="{{ route('settings.help-faqs.edit', $faq->id) }}" class="btn btn-outline-primary btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        @endif
                                        @if(\Illuminate\Support\Facades\Route::has('settings.help-faqs.destroy'))
                                            <form method="POST" action="{{ route('settings.help-faqs.destroy', $faq->id) }}" class="d-inline" onsubmit="return confirm('Delete this FAQ?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
