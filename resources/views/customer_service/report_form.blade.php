@extends('layouts.app')

@section('content')
<div class="container-fluid">

    <h3 class="mb-3">
        {{ isset($report) ? 'Edit Report' : 'New Report' }}
    </h3>

    <form method="POST"
          action="{{ isset($report) ? route('customer-service.reports.update', $report) : route('customer-service.reports.store') }}">

        @csrf
        @if(isset($report))
            @method('PUT')
        @endif

        <div class="card shadow-sm">
            <div class="card-body">

                <div class="mb-3">
                    <label class="form-label">Customer</label>
                    <select name="subscription_id" class="form-select" required>
                        @foreach($subscriptions as $sub)
                            <option value="{{ $sub->id }}"
                                {{ (old('subscription_id', $report->subscription_id ?? $selectedSubscriptionId ?? '') == $sub->id) ? 'selected' : '' }}>
                                {{ $sub->code }} -
                                {{ $sub->subscriber_first_name }}
                                {{ $sub->subscriber_last_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="contact_date" class="form-control"
                               value="{{ old('contact_date', $report->contact_date ?? now()->format('Y-m-d')) }}" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Time</label>
                        <input type="time" name="contact_time" class="form-control"
                               value="{{ old('contact_time', $report->contact_time ?? now()->format('H:i')) }}" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Contact Channel</label>
                    <select name="channel" class="form-select" required>
                        <option value="call">Call</option>
                        <option value="whatsapp_message">WhatsApp Message</option>
                        <option value="whatsapp_call">WhatsApp Call</option>
                        <option value="email">Email</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control"
                           value="{{ old('subject', $report->subject ?? '') }}" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Summary</label>
                    <textarea name="summary" class="form-control" rows="4" required>{{ old('summary', $report->summary ?? '') }}</textarea>
                </div>

                <button type="submit" class="btn btn-mauve">
                    {{ isset($report) ? 'Update' : 'Save' }}
                </button>

            </div>
        </div>
    </form>

</div>
@endsection
