{{--
|--------------------------------------------------------------------------
| SETTINGS POPUP: values, earning rules, levels (Arabic / English)
|--------------------------------------------------------------------------
--}}
@php
    $oldRules = old('rules', $rules->map(fn ($rule) => [
        'kind' => $rule->kind,
        'from_number' => $rule->from_number,
        'to_number' => $rule->to_number,
        'points' => $rule->points,
    ])->values()->all());

    $oldLevels = old('levels', $levels->map(fn ($level) => [
        'id' => $level->id,
        'name_en' => $level->name_en,
        'name_ar' => $level->name_ar,
        'description_en' => $level->description_en,
        'description_ar' => $level->description_ar,
        'min_points' => $level->min_points,
        'color' => $level->color,
        'icon' => $level->icon,
        'is_active' => $level->is_active ? 1 : 0,
    ])->values()->all());

    $kinds = \App\Models\LoyaltyEarningRule::KINDS;
@endphp

<div class="modal fade" id="loyaltySettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="{{ route('pet-loyalty.settings') }}" class="modal-content">
            @csrf

            <div class="modal-header bg-mauve text-white py-2">
                <h5 class="modal-title mb-0"><i class="fas fa-sliders-h me-2"></i>Loyalty program settings</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                @if($errors->any())
                    <div class="alert alert-danger small py-2">
                        <i class="fas fa-exclamation-circle me-1"></i>{{ $errors->first() }}
                    </div>
                @endif

                {{-- 1. GENERAL --}}
                <h6 class="text-mauve fw-bold mb-2"><i class="fas fa-cog me-1"></i>General</h6>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <input type="hidden" name="is_enabled" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="lpEnabled" name="is_enabled" value="1"
                                   @checked(old('is_enabled', $settings->is_enabled ? 1 : 0) == 1)>
                            <label class="form-check-label fw-semibold" for="lpEnabled">
                                Program active <span class="text-muted fw-normal small">(off: points are still counted, but cannot be used)</span>
                            </label>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Value of 1 point</label>
                        <div class="input-group input-group-sm">
                            <input type="number" step="0.001" min="0" class="form-control" name="point_value" id="lpPointValue"
                                   value="{{ old('point_value', number_format((float) $settings->point_value, 3, '.', '')) }}" required>
                            <input type="text" class="form-control" name="currency" style="max-width:70px"
                                   value="{{ old('currency', $settings->currency) }}" required>
                        </div>
                        <div class="form-text" id="lpValueExample"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Minimum points to use</label>
                        <input type="number" min="0" class="form-control form-control-sm" name="min_points_to_redeem" id="lpMin"
                               value="{{ old('min_points_to_redeem', $settings->min_points_to_redeem) }}" required>
                        <div class="form-text">Per pet. Below: points kept, not usable yet.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Maximum points per pack</label>
                        <input type="number" min="1" class="form-control form-control-sm" name="max_points_per_purchase" id="lpMax"
                               value="{{ old('max_points_per_purchase', $settings->max_points_per_purchase) }}" required>
                        <div class="form-text">Even with more points.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Days per meal</label>
                        <input type="number" step="0.01" min="0.1" class="form-control form-control-sm" name="days_per_meal"
                               value="{{ old('days_per_meal', number_format((float) $settings->days_per_meal, 2, '.', '')) }}" required>
                        <div class="form-text">Pack of 30 meals = 30 days, from the 1st delivery.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Test pack: days to subscribe</label>
                        <div class="input-group input-group-sm">
                            <input type="number" min="0" class="form-control" name="trial_bonus_days"
                                   value="{{ old('trial_bonus_days', $settings->trial_bonus_days) }}" required>
                            <span class="input-group-text">days</span>
                        </div>
                        <div class="form-text">After the end of the test pack. Later: its points are lost.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Days before all points are lost</label>
                        <div class="input-group input-group-sm">
                            <input type="number" min="0" class="form-control" name="grace_days"
                                   value="{{ old('grace_days', $settings->grace_days) }}" required>
                            <span class="input-group-text">days</span>
                        </div>
                        <div class="form-text">After the end of the last pack, without a new subscription.</div>
                    </div>

                    <div class="col-md-6 d-flex align-items-center">
                        <div>
                            <input type="hidden" name="reset_counters_on_expiry" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="lpReset" name="reset_counters_on_expiry" value="1"
                                       @checked(old('reset_counters_on_expiry', $settings->reset_counters_on_expiry ? 1 : 0) == 1)>
                                <label class="form-check-label small fw-semibold" for="lpReset">
                                    Points lost: the next subscription counts as #1 again
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- REMINDERS + CHECKOUT --}}
                <h6 class="text-mauve fw-bold mb-2"><i class="fas fa-bell me-1"></i>Reminders &amp; checkout</h6>

                <div class="row g-3 mb-4">
                    <div class="col-md-3 d-flex align-items-center">
                        <div>
                            <input type="hidden" name="reminders_enabled" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="lpReminders" name="reminders_enabled" value="1"
                                       @checked(old('reminders_enabled', $settings->reminders_enabled ? 1 : 0) == 1)>
                                <label class="form-check-label small fw-semibold" for="lpReminders">Push reminders</label>
                            </div>
                            <div class="form-text">Every morning, max 1 per pet per day.</div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">"Use your points" before the end of the pack</label>
                        <div class="input-group input-group-sm">
                            <input type="number" min="0" max="60" class="form-control" name="remind_days_before_end"
                                   value="{{ old('remind_days_before_end', $settings->remind_days_before_end) }}" required>
                            <span class="input-group-text">days</span>
                        </div>
                        <div class="form-text">Only when the pet has enough points.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">"Points lost soon" before the deadline</label>
                        <div class="input-group input-group-sm">
                            <input type="number" min="0" max="60" class="form-control" name="remind_days_before_expiry"
                                   value="{{ old('remind_days_before_expiry', $settings->remind_days_before_expiry) }}" required>
                            <span class="input-group-text">days</span>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Points kept on an unpaid order</label>
                        <div class="input-group input-group-sm">
                            <input type="number" min="1" max="720" class="form-control" name="reservation_hours"
                                   value="{{ old('reservation_hours', $settings->reservation_hours) }}" required>
                            <span class="input-group-text">hours</span>
                        </div>
                        <div class="form-text">Then the points are free again.</div>
                    </div>
                </div>

                {{-- 2. EARNING RULES --}}
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="text-mauve fw-bold mb-0"><i class="fas fa-coins me-1"></i>Points earned per pack</h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="lpAddRule">
                        <i class="fas fa-plus me-1"></i>Add a rule
                    </button>
                </div>
                <p class="small text-muted mb-2">
                    Number of the pack of the pet: #1 = its first subscription. Empty "to" = this one and all the next ones.
                    Test pack: once per pet.
                </p>

                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small">
                                <th style="min-width:200px">Pack</th>
                                <th style="width:120px">From #</th>
                                <th style="width:120px">To #</th>
                                <th style="width:130px">Points</th>
                                <th style="width:50px"></th>
                            </tr>
                        </thead>
                        <tbody id="lpRules">
                            @foreach($oldRules as $i => $rule)
                                <tr>
                                    <td>
                                        <select name="rules[{{ $i }}][kind]" class="form-select form-select-sm" required>
                                            @foreach($kinds as $kind => $config)
                                                <option value="{{ $kind }}" @selected(($rule['kind'] ?? '') === $kind)>{{ $config['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" min="1" name="rules[{{ $i }}][from_number]" value="{{ $rule['from_number'] ?? 1 }}" class="form-control form-control-sm" required></td>
                                    <td><input type="number" min="1" name="rules[{{ $i }}][to_number]" value="{{ $rule['to_number'] ?? '' }}" class="form-control form-control-sm" placeholder="and after"></td>
                                    <td><input type="number" min="0" name="rules[{{ $i }}][points]" value="{{ $rule['points'] ?? 0 }}" class="form-control form-control-sm" required></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 lp-remove" title="Remove"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- 3. LEVELS --}}
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="text-mauve fw-bold mb-0"><i class="fas fa-trophy me-1"></i>Levels</h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="lpAddLevel">
                        <i class="fas fa-plus me-1"></i>Add a level
                    </button>
                </div>
                <p class="small text-muted mb-2">
                    Reached with all the points EARNED by the pet (points used or lost do not lower the level).
                    Icon: Font Awesome name, e.g. fa-medal, fa-crown, fa-gem, fa-star, fa-paw.
                </p>

                <div id="lpLevels">
                    @foreach($oldLevels as $i => $level)
                        @include('pet-loyalty._level-row', ['i' => $i, 'level' => $level])
                    @endforeach
                </div>
            </div>

            <div class="modal-footer py-2">
                <span class="small text-muted me-auto"><i class="fas fa-info-circle me-1"></i>Saving recalculates the points of all the pets.</span>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-mauve btn-sm"><i class="fas fa-save me-1"></i>Save settings</button>
            </div>
        </form>
    </div>
</div>

{{-- Templates for the new rows --}}
<template id="lpRuleTemplate">
    <tr>
        <td>
            <select name="rules[__I__][kind]" class="form-select form-select-sm" required>
                @foreach($kinds as $kind => $config)
                    <option value="{{ $kind }}">{{ $config['label'] }}</option>
                @endforeach
            </select>
        </td>
        <td><input type="number" min="1" name="rules[__I__][from_number]" value="1" class="form-control form-control-sm" required></td>
        <td><input type="number" min="1" name="rules[__I__][to_number]" value="" class="form-control form-control-sm" placeholder="and after"></td>
        <td><input type="number" min="0" name="rules[__I__][points]" value="0" class="form-control form-control-sm" required></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0 lp-remove" title="Remove"><i class="fas fa-trash"></i></button>
        </td>
    </tr>
</template>

<template id="lpLevelTemplate">
    @include('pet-loyalty._level-row', ['i' => '__I__', 'level' => ['is_active' => 1, 'color' => '#8B5FBF', 'icon' => 'fa-medal', 'min_points' => 0]])
</template>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
  let counter = 1000;

  function addFromTemplate(templateId, containerId) {
    const html = document.getElementById(templateId).innerHTML.replaceAll('__I__', String(counter++));
    document.getElementById(containerId).insertAdjacentHTML('beforeend', html);
  }

  document.getElementById('lpAddRule')?.addEventListener('click', () => addFromTemplate('lpRuleTemplate', 'lpRules'));
  document.getElementById('lpAddLevel')?.addEventListener('click', () => addFromTemplate('lpLevelTemplate', 'lpLevels'));

  document.getElementById('loyaltySettingsModal')?.addEventListener('click', function (e) {
    const remove = e.target.closest('.lp-remove');
    if (remove) {
      (remove.closest('tr') || remove.closest('.lp-level-row'))?.remove();
    }
  });

  // Live example: value of the minimum / maximum points
  const value = document.getElementById('lpPointValue');
  const min = document.getElementById('lpMin');
  const max = document.getElementById('lpMax');
  const example = document.getElementById('lpValueExample');

  function refreshExample() {
    const v = parseFloat(value.value || '0');
    const currency = document.querySelector('[name="currency"]').value || '';
    example.textContent = `${min.value} pts = ${(v * parseInt(min.value || '0', 10)).toFixed(3)} ${currency} · ${max.value} pts = ${(v * parseInt(max.value || '0', 10)).toFixed(3)} ${currency}`;
  }

  [value, min, max].forEach(input => input?.addEventListener('input', refreshExample));
  if (value && example) refreshExample();

  // Errors after saving: the popup opens again
  @if($errors->any())
    new bootstrap.Modal(document.getElementById('loyaltySettingsModal')).show();
  @endif
});
</script>
@endpush
