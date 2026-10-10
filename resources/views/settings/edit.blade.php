@extends('layouts.app')

@section('title', 'Edit Settings')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">Edit Settings</h2>
    <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary btn-sm">
        Back
    </a>
</div>

<div class="card shadow card-sm">
    <div class="card-header bg-mauve text-white py-2">
        <strong>Update Settings</strong>
    </div>
    <div class="card-body">

        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" id="settingsForm">
            @csrf
            @method('PUT')

            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">Logo</label>
                    <input type="file" name="logo" class="form-control @error('logo') is-invalid @enderror" accept="image/*">
                    @error('logo')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($settings->logo)
                        <div class="mt-2">
                            <img src="{{ asset('storage/app/public/'.$settings->logo) }}" alt="Logo" style="max-width:180px; height:auto;">
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <label class="form-label">Logo Premium</label>
                    <input type="file" name="logo_premium" class="form-control @error('logo_premium') is-invalid @enderror" accept="image/*">
                    @error('logo_premium')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($settings->logo_premium)
                        <div class="mt-2">
                            <img src="{{ asset('storage/app/public/'.$settings->logo_premium) }}" alt="Logo Premium" style="max-width:180px; height:auto;">
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <label class="form-label">PDF for First Delivery</label>
                    <input type="file" name="pdf_first_delivery" class="form-control @error('pdf_first_delivery') is-invalid @enderror" accept="application/pdf">
                    @error('pdf_first_delivery')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($settings->pdf_first_delivery)
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="{{ asset('storage/app/public/'.$settings->pdf_first_delivery) }}">
                                <i class="fas fa-file-pdf me-1"></i>Open PDF
                            </a>
                            <div class="text-muted small">{{ $settings->pdf_first_delivery }}</div>
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <label class="form-label">PDF for Last Delivery</label>
                    <input type="file" name="pdf_last_delivery" class="form-control @error('pdf_last_delivery') is-invalid @enderror" accept="application/pdf">
                    @error('pdf_last_delivery')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($settings->pdf_last_delivery)
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="{{ asset('storage/app/public/'.$settings->pdf_last_delivery) }}">
                                <i class="fas fa-file-pdf me-1"></i>Open PDF
                            </a>
                            <div class="text-muted small">{{ $settings->pdf_last_delivery }}</div>
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <label class="form-label">PDF Document Language</label>
                    <select name="pdf_lang" class="form-select @error('pdf_lang') is-invalid @enderror">
                        <option value="en" {{ old('pdf_lang', $settings->pdf_lang ?? 'en') === 'en' ? 'selected' : '' }}>English (EN)</option>
                        <option value="ar" {{ old('pdf_lang', $settings->pdf_lang ?? 'en') === 'ar' ? 'selected' : '' }}>Arabic (AR)</option>
                    </select>
                    @error('pdf_lang')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12">
                    <label class="form-label">إرشادات حفظ الوجبات (Native Rich Text)</label>

                    <div class="border rounded p-2">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="bold"><i class="fas fa-bold"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="italic"><i class="fas fa-italic"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="underline"><i class="fas fa-underline"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="insertUnorderedList"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="insertOrderedList"><i class="fas fa-list-ol"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="justifyLeft"><i class="fas fa-align-left"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="justifyCenter"><i class="fas fa-align-center"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="meal" data-cmd="justifyRight"><i class="fas fa-align-right"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-link="meal"><i class="fas fa-link"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-rt-toolbar="meal" data-cmd="removeFormat"><i class="fas fa-eraser"></i></button>
                        </div>

                        <div id="mealEditor" class="form-control" contenteditable="true" style="min-height: 220px; white-space: normal;">{!! old('meal_storage_instructions', $settings->meal_storage_instructions) !!}</div>

                        <textarea name="meal_storage_instructions" id="meal_storage_instructions" class="@error('meal_storage_instructions') is-invalid @enderror" hidden>{{ old('meal_storage_instructions', $settings->meal_storage_instructions) }}</textarea>

                        @error('meal_storage_instructions')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror

                        <div class="form-text mt-2">Rich text saved as HTML.</div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">Welcome Message (Native Rich Text)</label>

                    <div class="border rounded p-2">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="bold"><i class="fas fa-bold"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="italic"><i class="fas fa-italic"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="underline"><i class="fas fa-underline"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="insertUnorderedList"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="insertOrderedList"><i class="fas fa-list-ol"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="justifyLeft"><i class="fas fa-align-left"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="justifyCenter"><i class="fas fa-align-center"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="welcome" data-cmd="justifyRight"><i class="fas fa-align-right"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-link="welcome"><i class="fas fa-link"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-rt-toolbar="welcome" data-cmd="removeFormat"><i class="fas fa-eraser"></i></button>
                        </div>

                        <div id="welcomeEditor" class="form-control" contenteditable="true" style="min-height: 220px; white-space: normal;">{!! old('welcome_message', $settings->welcome_message) !!}</div>

                        <textarea name="welcome_message" id="welcome_message" class="@error('welcome_message') is-invalid @enderror" hidden>{{ old('welcome_message', $settings->welcome_message) }}</textarea>

                        @error('welcome_message')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror

                        <div class="form-text mt-2">Rich text saved as HTML.</div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">Subscription QR Message Template (Arabic Rich Text)</label>

                    <div class="border rounded p-2">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="bold"><i class="fas fa-bold"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="italic"><i class="fas fa-italic"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="underline"><i class="fas fa-underline"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="insertUnorderedList"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="insertOrderedList"><i class="fas fa-list-ol"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="justifyLeft"><i class="fas fa-align-left"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="justifyCenter"><i class="fas fa-align-center"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="subscriptionqr" data-cmd="justifyRight"><i class="fas fa-align-right"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-link="subscriptionqr"><i class="fas fa-link"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-rt-toolbar="subscriptionqr" data-cmd="removeFormat"><i class="fas fa-eraser"></i></button>
                        </div>

                        <div id="subscriptionQrEditor" class="form-control" contenteditable="true" style="min-height: 260px; white-space: normal; direction: rtl; text-align: right;">{!! old('subscription_qr_message', $settings->subscription_qr_message) !!}</div>

                        <textarea name="subscription_qr_message" id="subscription_qr_message" class="@error('subscription_qr_message') is-invalid @enderror" hidden>{{ old('subscription_qr_message', $settings->subscription_qr_message) }}</textarea>

                        @error('subscription_qr_message')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror

                        <div class="form-text mt-2">
                            Use <strong>[qr_code]</strong> to place the QR image inside the future template.<br>
                            Use <strong>[customer_name]</strong> to place the customer name dynamically.<br>
                            This content will be used later for PDF generation.
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">Last Order Message Template (Arabic Rich Text)</label>

                    <div class="border rounded p-2">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="bold"><i class="fas fa-bold"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="italic"><i class="fas fa-italic"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="underline"><i class="fas fa-underline"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="insertUnorderedList"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="insertOrderedList"><i class="fas fa-list-ol"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="justifyLeft"><i class="fas fa-align-left"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="justifyCenter"><i class="fas fa-align-center"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-toolbar="lastorder" data-cmd="justifyRight"><i class="fas fa-align-right"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-rt-link="lastorder"><i class="fas fa-link"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-rt-toolbar="lastorder" data-cmd="removeFormat"><i class="fas fa-eraser"></i></button>
                        </div>

                        <div
                            id="lastOrderEditor"
                            class="form-control"
                            contenteditable="true"
                            style="min-height: 260px; white-space: normal; direction: rtl; text-align: right;"
                        >{!! old('last_order_subscription_message', $settings->last_order_subscription_message) !!}</div>

                        <textarea
                            name="last_order_subscription_message"
                            id="last_order_subscription_message"
                            class="@error('last_order_subscription_message') is-invalid @enderror"
                            hidden
                        >{{ old('last_order_subscription_message', $settings->last_order_subscription_message) }}</textarea>

                        @error('last_order_subscription_message')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror

                        <div class="form-text mt-2">
                            Use <strong>[qr_code]</strong> to place the QR image inside the future template.<br>
                            Use <strong>[customer_name]</strong> to place the customer name dynamically.<br>
                            This content will be used later for Last Order PDF generation.
                        </div>
                    </div>
                </div>

            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-mauve">
                    <i class="fas fa-save me-1"></i>Save
                </button>
                <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>

        </form>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('settingsForm');
    const langSelect = document.querySelector('select[name="pdf_lang"]');

    function bindRichText({ editorId, hiddenId, scope }) {
        const editor = document.getElementById(editorId);
        const hidden = document.getElementById(hiddenId);

        function sync() {
            hidden.value = editor.innerHTML;
        }

        document.querySelectorAll(`[data-rt-toolbar="${scope}"][data-cmd]`).forEach(btn => {
            btn.addEventListener('click', function () {
                const cmd = this.getAttribute('data-cmd');
                editor.focus();
                document.execCommand(cmd, false, null);
                sync();
            });
        });

        const linkBtn = document.querySelector(`[data-rt-link="${scope}"]`);
        if (linkBtn) {
            linkBtn.addEventListener('click', function () {
                editor.focus();
                const url = prompt('Enter URL:');
                if (!url) return;
                document.execCommand('createLink', false, url);
                sync();
            });
        }

        editor.addEventListener('input', sync);
        editor.addEventListener('blur', sync);

        return {
            editor,
            sync
        };
    }

    const mealRT = bindRichText({
        editorId: 'mealEditor',
        hiddenId: 'meal_storage_instructions',
        scope: 'meal'
    });

    const welcomeRT = bindRichText({
        editorId: 'welcomeEditor',
        hiddenId: 'welcome_message',
        scope: 'welcome'
    });

    const subscriptionQrRT = bindRichText({
        editorId: 'subscriptionQrEditor',
        hiddenId: 'subscription_qr_message',
        scope: 'subscriptionqr'
    });

    const lastOrderRT = bindRichText({
        editorId: 'lastOrderEditor',
        hiddenId: 'last_order_subscription_message',
        scope: 'lastorder'
    });

    function applyDirection(lang) {
        const isRTL = lang === 'ar';

        [mealRT.editor, welcomeRT.editor].forEach(editor => {
            editor.style.direction = isRTL ? 'rtl' : 'ltr';
            editor.style.textAlign = isRTL ? 'right' : 'left';
        });
    }

    applyDirection(langSelect.value);

    langSelect.addEventListener('change', function () {
        applyDirection(this.value);
    });

    form.addEventListener('submit', function () {
        mealRT.sync();
        welcomeRT.sync();
        subscriptionQrRT.sync();
        lastOrderRT.sync();
    });
});
</script>

@endsection
