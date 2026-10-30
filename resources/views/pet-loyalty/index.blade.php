@extends('layouts.app')

@section('title', 'Pet Loyalty')

@section('content')
@php
    $hasFilters = $filters['q'] !== '' || $filters['level'] !== '' || $filters['can_use'] !== '';
    $minPoints = (int) $settings->min_points_to_redeem;
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="text-mauve mb-0"><i class="fas fa-star me-2"></i>Pet Loyalty</h2>
        <div class="small text-muted">
            Points per pet ·
            usable from <b>{{ $minPoints }}</b> pts, <b>{{ $settings->max_points_per_purchase }}</b> max per pack ·
            1 pt = {{ number_format((float) $settings->point_value, 3) }} {{ $settings->currency }}
            @unless($settings->is_enabled)
                <span class="badge bg-secondary ms-1">Program off</span>
            @endunless
        </div>
    </div>

    @if($canRecalculate || $canSettings)
        <div class="d-flex gap-2">
            @if($canRecalculate)
                <form method="POST" action="{{ route('pet-loyalty.recalculate') }}" onsubmit="return confirm('Recalculate the points of all the pets?')">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-sync-alt me-1"></i>Recalculate
                    </button>
                </form>
            @endif

            @if($canSettings)
                <button type="button" class="btn btn-mauve btn-sm" data-bs-toggle="modal" data-bs-target="#loyaltySettingsModal">
                    <i class="fas fa-sliders-h me-1"></i>Settings
                </button>
            @endif
        </div>
    @endif
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- FIRST USE: nothing calculated yet --}}
@if($stats['pets'] === 0)
    <div class="alert alert-info d-flex align-items-center gap-2 py-2">
        <i class="fas fa-info-circle"></i>
        <span>No points calculated yet. Click <b>Recalculate</b> once: the points of all the pets are calculated from their purchases.</span>
    </div>
@endif

{{-- STATS --}}
<div class="row g-2 mb-3">
    @foreach([
        ['label' => 'Customers', 'value' => number_format($stats['customers']), 'icon' => 'fa-users', 'color' => 'mauve'],
        ['label' => 'Pets', 'value' => number_format($stats['pets']), 'icon' => 'fa-paw', 'color' => 'mauve'],
        ['label' => 'Points available', 'value' => number_format($stats['points']), 'icon' => 'fa-coins', 'color' => 'warning'],
        ['label' => 'Points used', 'value' => number_format($stats['redeemed']), 'icon' => 'fa-tag', 'color' => 'success'],
        ['label' => "Pets with {$minPoints}+ pts", 'value' => number_format($stats['can_use']), 'icon' => 'fa-check-circle', 'color' => 'success'],
        ['label' => 'Points lost within 7 days', 'value' => number_format($stats['expiring']), 'icon' => 'fa-hourglass-half', 'color' => 'danger'],
    ] as $card)
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card shadow-sm h-100">
                <div class="card-body p-2 d-flex align-items-center gap-2">
                    <span class="lp-stat-icon text-{{ $card['color'] }}"><i class="fas {{ $card['icon'] }}"></i></span>
                    <div>
                        <div class="fw-bold fs-5 lh-1">{{ $card['value'] }}</div>
                        <div class="small text-muted">{{ $card['label'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- LEVELS --}}
@if($levels->where('is_active', true)->isNotEmpty())
    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach($levels->where('is_active', true) as $level)
            <a href="{{ route('pet-loyalty.index', array_merge(array_filter($filters, fn ($v) => $v !== ''), ['level' => $filters['level'] == $level->id ? '' : $level->id])) }}"
               class="lp-level-chip text-decoration-none {{ $filters['level'] == $level->id ? 'active' : '' }}"
               style="--lp-color: {{ $level->color }}">
                <i class="fas {{ $level->iconClass() }} me-1"></i>{{ $level->name_en }}
                <span class="text-muted small">· {{ $level->name_ar }}</span>
                <span class="small ms-1 opacity-75">{{ number_format($level->min_points) }}+</span>
            </a>
        @endforeach
    </div>
@endif

{{-- FILTERS --}}
<form method="GET" action="{{ route('pet-loyalty.index') }}" class="card shadow-sm mb-3">
    <div class="card-body p-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Customer, phone, code or pet name">
            </div>

            <div class="col-md-2">
                <label class="form-label small mb-1">Level</label>
                <select name="level" class="form-select form-select-sm">
                    <option value="">All levels</option>
                    @foreach($levels as $level)
                        <option value="{{ $level->id }}" @selected($filters['level'] == $level->id)>{{ $level->name_en }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small mb-1">Sort by</label>
                <select name="sort" class="form-select form-select-sm">
                    <option value="points" @selected($filters['sort'] === 'points')>Points available</option>
                    <option value="earned" @selected($filters['sort'] === 'earned')>Points earned</option>
                    <option value="subscriptions" @selected($filters['sort'] === 'subscriptions')>Subscriptions</option>
                    <option value="name" @selected($filters['sort'] === 'name')>Name</option>
                </select>
            </div>

            <div class="col-md-1">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" name="can_use" value="1" id="lpCanUse" @checked($filters['can_use'] === '1')>
                    <label class="form-check-label small" for="lpCanUse">{{ $minPoints }}+ pts</label>
                </div>
            </div>

            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-mauve btn-sm w-100"><i class="fas fa-search me-1"></i>Filter</button>
                @if($hasFilters)
                    <a href="{{ route('pet-loyalty.index') }}" class="btn btn-outline-secondary btn-sm" title="Clear"><i class="fas fa-times"></i></a>
                @endif
            </div>
        </div>
    </div>
</form>

{{-- CUSTOMERS --}}
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr class="small">
                    <th>Customer</th>
                    <th>Pets &amp; points</th>
                    <th class="text-center">Subscriptions</th>
                    <th class="text-end">Points</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    @php
                        $accounts = $accountsByCustomer[$customer->id] ?? collect();
                        $url = route('pet-loyalty.show', $customer->id);
                    @endphp

                    <tr class="lp-row" onclick="window.location='{{ $url }}'">
                        <td>
                            <div class="fw-semibold">{{ trim($customer->subscriber_first_name . ' ' . $customer->subscriber_last_name) ?: '—' }}</div>
                            <div class="small text-muted">
                                {{ $customer->subscriber_phone ?: '-' }}
                                @if($customer->code) · {{ $customer->code }} @endif
                            </div>
                        </td>

                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                @foreach($accounts as $account)
                                    @php
                                        $level = $account->level;
                                        $usable = $account->balance >= $minPoints;
                                    @endphp
                                    <span class="lp-pet-chip {{ $usable ? 'usable' : '' }}"
                                          title="{{ $level ? $level->name_en . ' · ' : '' }}{{ $account->balance }} pts{{ $account->expires_on ? ' · lost after ' . $account->expires_on->format('M j, Y') : '' }}">
                                        @if($level)
                                            <i class="fas {{ $level->iconClass() }}" style="color: {{ $level->color }}"></i>
                                        @else
                                            <i class="fas fa-paw text-muted"></i>
                                        @endif
                                        {{ $account->animal?->name ?? 'Pet #' . $account->animal_id }}
                                        <b>{{ $account->balance }}</b>
                                    </span>
                                @endforeach
                            </div>
                        </td>

                        <td class="text-center">
                            <span class="badge bg-light text-dark border">{{ (int) $customer->subscriptions_count }}</span>
                        </td>

                        <td class="text-end">
                            <div class="fw-bold">{{ number_format((int) $customer->points) }}</div>
                            <div class="small text-muted">{{ number_format($settings->valueOf((int) $customer->points), 3) }} {{ $settings->currency }}</div>
                        </td>

                        <td class="text-end">
                            <a href="{{ $url }}" class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation()">
                                <i class="fas fa-history me-1"></i>History
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="fas fa-star me-1"></i>No customer found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($customers->hasPages())
        <div class="card-footer bg-white py-2">
            {{ $customers->links() }}
        </div>
    @endif
</div>

@if($canSettings)
    @include('pet-loyalty._settings')
@endif
@endsection

@push('styles')
<style>
.lp-stat-icon { width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #f6f1fb; }
.lp-row { cursor: pointer; }
.lp-pet-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 9px; border-radius: 999px; font-size: .8rem;
    background: #f3f4f6; border: 1px solid #e5e7eb;
}
.lp-pet-chip.usable { background: rgba(34,197,94,0.10); border-color: rgba(34,197,94,0.35); }
.lp-level-chip {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: 999px; font-size: .85rem; font-weight: 600;
    color: var(--lp-color); border: 1px solid var(--lp-color); background: #fff;
}
.lp-level-chip.active { background: var(--lp-color); color: #fff; }
.lp-level-chip.active .text-muted { color: rgba(255,255,255,.85) !important; }
</style>
@endpush
