@extends('layouts.app')

@section('title', 'Automatic notifications')

@section('content')
@php
    $open = request('open');
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a href="{{ route('notifications.index') }}" class="text-decoration-none small"><i class="fas fa-arrow-left me-1"></i>Notifications</a>
        <h2 class="text-mauve mb-0">Automatic notifications</h2>
        <div class="text-muted small">On / off, texts, timing and screen opened of each automatic notification.</div>
    </div>

    @if($canManage)
        <form method="POST" action="{{ route('notification-types.plan-reminders') }}">
            @csrf
            <button class="btn btn-outline-secondary"><i class="fas fa-sync me-1"></i>Plan the reminders now</button>
        </form>
    @endif
</div>

@foreach(['success' => 'check-circle', 'error' => 'exclamation-circle'] as $flash => $icon)
    @if(session($flash))
        <div class="alert alert-{{ $flash === 'error' ? 'danger' : 'success' }} alert-dismissible fade show">
            <i class="fas fa-{{ $icon }} me-1"></i>{{ session($flash) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
@endforeach

@if($errors->any())
    <div class="alert alert-danger">
        @foreach($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="alert alert-light border small">
    <i class="fas fa-info-circle me-1"></i>
    Words between braces are replaced for each customer:
    @foreach($vars as $var => $label)
        <code>{{ '{' . $var . '}' }}</code> {{ strtolower($label) }}@if(!$loop->last), @endif
    @endforeach
</div>

<div class="accordion" id="typesAccordion">
    @foreach($types as $item)
        @php
            $key = $item['key'];
            $definition = $item['definition'];
            $settings = $item['settings'];
            $reminder = $definition['kind'] === 'reminder';
            $isOpen = $open === $key || old('_type') === $key;
        @endphp

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button {{ $isOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#type_{{ $key }}">
                    <span class="me-2">
                        @if($settings->enabled)
                            <span class="badge bg-success">ON</span>
                        @else
                            <span class="badge bg-secondary">OFF</span>
                        @endif
                    </span>
                    <span class="fw-semibold me-2">{{ $definition['label'] }}</span>
                    <span class="small text-muted">
                        @if($reminder)
                            <i class="fas fa-bell me-1"></i>{{ $settings->days_before }} day(s) before, at {{ $settings->send_time }}
                        @else
                            <i class="fas fa-bolt me-1"></i>{{ $settings->delay_minutes > 0 ? $settings->delay_minutes . ' min after' : 'immediately' }}
                        @endif
                        · {{ $item['scheduled'] }} scheduled · {{ $item['sent_30'] }} sent (30 days)
                    </span>
                </button>
            </h2>

            <div id="type_{{ $key }}" class="accordion-collapse collapse {{ $isOpen ? 'show' : '' }}" data-bs-parent="#typesAccordion">
                <div class="accordion-body">
                    <p class="small text-muted mb-3">
                        {{ $definition['description'] }}
                        Possible words:
                        @foreach($definition['vars'] as $var)
                            <code>{{ '{' . $var . '}' }}</code>
                        @endforeach
                    </p>

                    <form method="POST" action="{{ route('notification-types.update', $key) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_type" value="{{ $key }}">

                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="enabled_{{ $key }}"
                                   @checked(old('_type') === $key ? old('enabled') : $settings->enabled) @disabled(!$canManage)>
                            <label class="form-check-label" for="enabled_{{ $key }}">Send this notification</label>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small">Title (English)</label>
                                <input type="text" name="title_en" class="form-control" maxlength="255" required
                                       value="{{ old('_type') === $key ? old('title_en') : $settings->title_en }}" @disabled(!$canManage)>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small d-block text-end">العنوان (عربي)</label>
                                <input type="text" name="title_ar" class="form-control" maxlength="255" dir="rtl" required
                                       value="{{ old('_type') === $key ? old('title_ar') : $settings->title_ar }}" @disabled(!$canManage)>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Message (English)</label>
                                <textarea name="body_en" rows="3" class="form-control" maxlength="2000" required @disabled(!$canManage)>{{ old('_type') === $key ? old('body_en') : $settings->body_en }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small d-block text-end">الرسالة (عربي)</label>
                                <textarea name="body_ar" rows="3" class="form-control" maxlength="2000" dir="rtl" required @disabled(!$canManage)>{{ old('_type') === $key ? old('body_ar') : $settings->body_ar }}</textarea>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small">Opens in the app</label>
                                <select name="destination" class="form-select" @disabled(!$canManage)>
                                    @foreach($item['destinations'] as $destinationKey => $destination)
                                        <option value="{{ $destinationKey }}" @selected((old('_type') === $key ? old('destination') : \App\Support\AppNotifications\NotificationTypes::destination($key)) === $destinationKey)>
                                            {{ $destination['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            @if($reminder)
                                <div class="col-md-4">
                                    <label class="form-label small">Days before the end of the pack</label>
                                    <input type="text" name="days_before" class="form-control" placeholder="5,1" required
                                           value="{{ old('_type') === $key ? old('days_before') : $settings->days_before }}" @disabled(!$canManage)>
                                    <div class="form-text">One reminder per number, e.g. <code>5,1</code>.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Time</label>
                                    <input type="time" name="send_time" class="form-control" required
                                           value="{{ old('_type') === $key ? old('send_time') : $settings->send_time }}" @disabled(!$canManage)>
                                </div>
                            @else
                                <div class="col-md-4">
                                    <label class="form-label small">Sent after (minutes)</label>
                                    <input type="number" name="delay_minutes" min="0" max="1440" class="form-control" required
                                           value="{{ old('_type') === $key ? old('delay_minutes') : $settings->delay_minutes }}" @disabled(!$canManage)>
                                    <div class="form-text">0 = immediately. With a delay, the team can still change / cancel it before.</div>
                                </div>
                            @endif
                        </div>

                        @if($canManage)
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                                <div class="form-check">
                                    <input type="hidden" name="apply_scheduled" value="0">
                                    <input class="form-check-input" type="checkbox" name="apply_scheduled" value="1" id="apply_{{ $key }}" checked>
                                    <label class="form-check-label small" for="apply_{{ $key }}">
                                        Also update the {{ $item['scheduled'] }} scheduled notification(s) of this type (not the ones edited by hand)
                                    </label>
                                </div>
                                <button class="btn btn-mauve"><i class="fas fa-save me-1"></i>Save</button>
                            </div>
                        @endif
                    </form>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection 
