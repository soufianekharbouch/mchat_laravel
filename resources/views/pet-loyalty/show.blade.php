@extends('layouts.app')

@section('title', 'Loyalty · ' . trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name))

@section('content')
@php
    $minPoints = (int) $settings->min_points_to_redeem;
    $customerName = trim($subscription->subscriber_first_name . ' ' . $subscription->subscriber_last_name) ?: '—';

    // Points of the next pack of a kind (rules of the settings)
    $nextPoints = function (string $kind, int $number) use ($rules): int {
        $rule = ($rules->get($kind) ?? collect())->first(fn ($rule) => $rule->matches($number));
        return $rule ? (int) $rule->points : 0;
    };
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a href="{{ route('pet-loyalty.index') }}" class="small text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Pet Loyalty</a>
        <h2 class="text-mauve mb-0">{{ $customerName }}</h2>
        <div class="small text-muted">
            <i class="fas fa-phone me-1"></i>{{ $subscription->subscriber_phone ?: '-' }}
            @if($subscription->code) · <i class="fas fa-hashtag me-1"></i>{{ $subscription->code }} @endif
        </div>
    </div>

    <div class="text-end">
        <div class="fs-3 fw-bold lh-1">{{ number_format($accounts->sum('balance')) }} <span class="fs-6 text-muted">pts</span></div>
        <div class="small text-muted">{{ number_format($settings->valueOf((int) $accounts->sum('balance')), 3) }} {{ $settings->currency }} · {{ $accounts->count() }} pet(s)</div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger py-2"><i class="fas fa-exclamation-circle me-1"></i>{{ $errors->first() }}</div>
@endif

@forelse($accounts as $account)
    @php
        $animal = $account->animal;
        $level = $account->level;
        $nextLevel = $levels->first(fn ($l) => $l->min_points > $account->lifetime_earned);
        $usable = $account->usablePoints($settings);
        $daysLeft = $account->expires_on ? (int) now()->startOfDay()->diffInDays($account->expires_on, false) : null;
        $trial = \App\Models\PetLoyaltyAccount::TRIAL_STATUSES[$account->trial_status] ?? null;

        // Progress to the next level
        $from = $level?->min_points ?? 0;
        $to = $nextLevel?->min_points;
        $progress = $to ? max(0, min(100, (int) round(($account->lifetime_earned - $from) * 100 / max(1, $to - $from)))) : 100;
    @endphp

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white py-2 d-flex flex-wrap align-items-center gap-2">
            <span class="lp-level-badge" style="background: {{ $level?->color ?? '#9CA3AF' }}">
                <i class="fas {{ $level?->iconClass() ?? 'fa-paw' }}"></i>
            </span>
            <div class="me-auto">
                <div class="fw-bold">
                    {{ $animal?->name ?? 'Pet #' . $account->animal_id }}
                    <span class="small text-muted fw-normal">{{ $animal?->species }}</span>
                </div>
                <div class="small">
                    @if($level)
                        <span style="color: {{ $level->color }}" class="fw-semibold">{{ $level->name_en }}</span>
                        <span class="text-muted">· {{ $level->name_ar }}</span>
                    @else
                        <span class="text-muted">No level yet</span>
                    @endif
                </div>
            </div>

            @if($canAdjust && $animal)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#lpAdjust{{ $account->animal_id }}">
                    <i class="fas fa-plus-minus me-1"></i>Adjust points
                </button>
            @endif
        </div>

        <div class="card-body p-3">
            <div class="row g-3">
                {{-- BALANCE --}}
                <div class="col-md-3">
                    <div class="lp-box {{ $usable > 0 ? 'lp-box-ok' : '' }}">
                        <div class="small text-muted">Points available</div>
                        <div class="fs-2 fw-bold lh-1">{{ number_format($account->balance) }}</div>
                        <div class="small mt-1">
                            @if($usable > 0)
                                <span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>{{ $usable }} pts usable = {{ number_format($settings->valueOf($usable), 3) }} {{ $settings->currency }}</span>
                            @else
                                <span class="text-muted">{{ max(0, $minPoints - $account->balance) }} pts missing to use them</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- DEADLINE --}}
                <div class="col-md-3">
                    <div class="lp-box {{ $daysLeft !== null && $daysLeft <= 7 ? 'lp-box-danger' : '' }}">
                        <div class="small text-muted">Points lost after</div>
                        @if($account->expires_on && $account->balance > 0)
                            <div class="fw-bold">{{ $account->expires_on->format('M j, Y') }}</div>
                            <div class="small {{ $daysLeft <= 7 ? 'text-danger fw-semibold' : 'text-muted' }}">
                                {{ $daysLeft >= 0 ? $daysLeft . ' day(s) left to subscribe again' : 'Deadline passed' }}
                            </div>
                        @else
                            <div class="fw-bold">—</div>
                            <div class="small text-muted">No points to lose</div>
                        @endif

                        @if($account->trial_status === 'pending' && $account->trial_deadline)
                            <div class="small mt-1 text-warning fw-semibold">
                                <i class="fas fa-gift me-1"></i>Test pack points kept if subscribed by {{ $account->trial_deadline->format('M j') }}
                            </div>
                        @elseif($trial && $account->trial_status !== 'none')
                            <div class="small mt-1 text-{{ $trial['color'] }}"><i class="fas fa-gift me-1"></i>{{ $trial['label'] }}</div>
                        @endif
                    </div>
                </div>

                {{-- COUNTERS --}}
                <div class="col-md-3">
                    <div class="lp-box">
                        <div class="small text-muted">Subscriptions bought</div>
                        <div class="fw-bold">{{ $account->subscriptions_total }}</div>
                        <div class="small text-muted">
                            Current cycle: {{ $account->regular_count }} regular · {{ $account->premium_count }} premium
                        </div>
                        <div class="small mt-1">
                            Next: <b>+{{ $nextPoints('regular', $account->regular_count + 1) }}</b> (regular #{{ $account->regular_count + 1 }})
                            · <b>+{{ $nextPoints('premium', $account->premium_count + 1) }}</b> (premium #{{ $account->premium_count + 1 }})
                        </div>
                    </div>
                </div>

                {{-- LEVEL PROGRESS --}}
                <div class="col-md-3">
                    <div class="lp-box">
                        <div class="small text-muted">Points earned since the start</div>
                        <div class="fw-bold">{{ number_format($account->lifetime_earned) }} <span class="small text-muted fw-normal">· {{ number_format($account->lifetime_redeemed) }} used</span></div>
                        <div class="progress mt-2" style="height:8px">
                            <div class="progress-bar" style="width: {{ $progress }}%; background: {{ $nextLevel?->color ?? $level?->color ?? '#8B5FBF' }}"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            @if($nextLevel)
                                {{ $nextLevel->min_points - $account->lifetime_earned }} pts to <b style="color: {{ $nextLevel->color }}">{{ $nextLevel->name_en }}</b>
                            @else
                                Highest level reached
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- HISTORY --}}
            <h6 class="mt-3 mb-2 fw-bold"><i class="fas fa-history me-1 text-mauve"></i>History</h6>

            @if($account->transactions->isEmpty())
                <div class="small text-muted">No points yet.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr class="small">
                                <th style="width:140px">Date</th>
                                <th>Event</th>
                                <th>Purchase</th>
                                <th>Note</th>
                                <th class="text-end" style="width:90px">Points</th>
                                <th class="text-end" style="width:90px">Balance</th>
                                <th style="width:40px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($account->transactions as $row)
                                <tr class="small">
                                    <td>{{ $row->happened_at?->format('M j, Y H:i') }}</td>
                                    <td>
                                        <span class="badge bg-{{ $row->typeColor() }} {{ $row->typeColor() === 'warning' ? 'text-dark' : '' }}">
                                            <i class="fas {{ $row->typeIcon() }} me-1"></i>{{ $row->typeLabel() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($row->purchase)
                                            <a href="{{ route('purchases.show', $row->purchase_id) }}" target="_blank" class="text-decoration-none">
                                                {{ $row->purchase->reference ?? '#' . $row->purchase_id }}
                                            </a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-muted">
                                        {{ $row->note }}
                                        @if(!$row->is_auto && $row->creator)
                                            <span class="d-block">by {{ $row->creator->name }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-bold {{ $row->points >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $row->points >= 0 ? '+' : '' }}{{ $row->points }}
                                    </td>
                                    <td class="text-end">{{ $row->balance_after }}</td>
                                    <td class="text-end">
                                        @if($canAdjust && !$row->is_auto)
                                            <form method="POST" action="{{ route('pet-loyalty.adjust.destroy', $row->id) }}" onsubmit="return confirm('Delete this adjustment?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Delete"><i class="fas fa-trash"></i></button>
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

    {{-- ADJUST POPUP --}}
    @if($canAdjust && $animal)
        <div class="modal fade" id="lpAdjust{{ $account->animal_id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="{{ route('pet-loyalty.adjust', $account->animal_id) }}" class="modal-content">
                    @csrf
                    <div class="modal-header py-2">
                        <h5 class="modal-title mb-0">Adjust the points of {{ $animal->name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="btn-group w-100 mb-3" role="group">
                            <input type="radio" class="btn-check" name="direction" id="lpAdd{{ $account->animal_id }}" value="add" checked>
                            <label class="btn btn-outline-success btn-sm" for="lpAdd{{ $account->animal_id }}"><i class="fas fa-plus me-1"></i>Add</label>
                            <input type="radio" class="btn-check" name="direction" id="lpRemove{{ $account->animal_id }}" value="remove">
                            <label class="btn btn-outline-danger btn-sm" for="lpRemove{{ $account->animal_id }}"><i class="fas fa-minus me-1"></i>Remove</label>
                        </div>

                        <label class="form-label small fw-semibold">Points</label>
                        <input type="number" min="1" name="points" class="form-control form-control-sm mb-3" required>

                        <label class="form-label small fw-semibold">Reason (shown in the history)</label>
                        <input type="text" name="note" maxlength="500" class="form-control form-control-sm" placeholder="Gift, correction, compensation..." required>

                        <div class="form-text">Current balance: {{ $account->balance }} pts. The balance never goes below 0.</div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-mauve btn-sm"><i class="fas fa-save me-1"></i>Save</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@empty
    <div class="alert alert-info"><i class="fas fa-info-circle me-1"></i>This customer has no pet yet.</div>
@endforelse
@endsection

@push('styles')
<style>
.lp-level-badge { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; }
.lp-box { height: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fafafa; }
.lp-box-ok { border-color: rgba(34,197,94,0.4); background: rgba(34,197,94,0.06); }
.lp-box-danger { border-color: rgba(220,38,38,0.35); background: rgba(220,38,38,0.05); }
</style>
@endpush
