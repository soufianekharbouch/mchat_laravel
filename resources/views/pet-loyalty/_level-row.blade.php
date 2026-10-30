{{-- One level in the settings popup ($i = index, $level = array) --}}
@php
    $color = $level['color'] ?? '#8B5FBF';
    $icon = $level['icon'] ?? 'fa-medal';
    $iconClass = str_starts_with((string) $icon, 'fa-') ? $icon : 'fa-' . $icon;
@endphp

<div class="lp-level-row border rounded p-2 mb-2 bg-light">
    <input type="hidden" name="levels[{{ $i }}][id]" value="{{ $level['id'] ?? '' }}">

    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <span class="lp-level-preview d-inline-flex align-items-center justify-content-center rounded-circle text-white"
                  style="width:38px;height:38px;background:{{ $color }}">
                <i class="fas {{ $iconClass }}"></i>
            </span>
        </div>

        <div class="col-md-3">
            <label class="form-label small mb-1">Name (English)</label>
            <input type="text" name="levels[{{ $i }}][name_en]" value="{{ $level['name_en'] ?? '' }}" class="form-control form-control-sm" required maxlength="100">
        </div>

        <div class="col-md-3">
            <label class="form-label small mb-1">الاسم (Arabic)</label>
            <input type="text" name="levels[{{ $i }}][name_ar]" value="{{ $level['name_ar'] ?? '' }}" class="form-control form-control-sm" dir="rtl" required maxlength="100">
        </div>

        <div class="col-md-2">
            <label class="form-label small mb-1">From (points earned)</label>
            <input type="number" min="0" name="levels[{{ $i }}][min_points]" value="{{ $level['min_points'] ?? 0 }}" class="form-control form-control-sm" required>
        </div>

        <div class="col-md-1">
            <label class="form-label small mb-1">Color</label>
            <input type="color" name="levels[{{ $i }}][color]" value="{{ $color }}" class="form-control form-control-sm form-control-color w-100"
                   oninput="this.closest('.lp-level-row').querySelector('.lp-level-preview').style.background = this.value">
        </div>

        <div class="col-md-2">
            <label class="form-label small mb-1">Icon</label>
            <input type="text" name="levels[{{ $i }}][icon]" value="{{ $icon }}" class="form-control form-control-sm" maxlength="50"
                   oninput="this.closest('.lp-level-row').querySelector('.lp-level-preview i').className = 'fas ' + (this.value.startsWith('fa-') ? this.value : 'fa-' + this.value)">
        </div>

        <div class="col-md-6">
            <label class="form-label small mb-1">Description (English, optional)</label>
            <input type="text" name="levels[{{ $i }}][description_en]" value="{{ $level['description_en'] ?? '' }}" class="form-control form-control-sm" maxlength="500">
        </div> 

        <div class="col-md-5">
            <label class="form-label small mb-1">الوصف (Arabic, optional)</label>
            <input type="text" name="levels[{{ $i }}][description_ar]" value="{{ $level['description_ar'] ?? '' }}" class="form-control form-control-sm" dir="rtl" maxlength="500">
        </div>

        <div class="col-md-1 d-flex align-items-center gap-2 justify-content-end">
            <input type="hidden" name="levels[{{ $i }}][is_active]" value="0">
            <div class="form-check form-switch mb-0" title="Active">
                <input class="form-check-input" type="checkbox" name="levels[{{ $i }}][is_active]" value="1" @checked(!empty($level['is_active']))>
            </div>
            <button type="button" class="btn btn-sm btn-link text-danger p-0 lp-remove" title="Remove"><i class="fas fa-trash"></i></button>
        </div>
    </div>
</div>
