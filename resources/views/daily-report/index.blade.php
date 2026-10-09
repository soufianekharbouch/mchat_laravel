@extends('layouts.app')

@section('content')
@php
    $authenticatedUser = auth()->user();

    $canViewDailyReports = $authenticatedUser
        && $authenticatedUser->hasPermission('daily_reports.view');

    $canMarkPrepared = $authenticatedUser
        && $authenticatedUser->hasPermission('daily_reports.mark_prepared');

    $canGeneratePdf = $authenticatedUser
        && $authenticatedUser->hasPermission('daily_reports.generate_pdf');
@endphp

@if(!$canViewDailyReports)
    <div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        You do not have permission to view daily reports.
    </div>
@else
<div class="card card-sm shadow">
    <div class="card-header bg-mauve text-white py-2 center">
        <h4 class="mb-0 h5 center">Daily Preparation Report</h4>
    </div>

    <div class="card-body p-2">
        <form method="GET" action="{{ route('daily-report.index') }}" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label for="date" class="form-label small">Select Date</label>
                    <input type="date"
                           class="form-control form-control-sm"
                           id="date"
                           name="date"
                           value="{{ $date }}"
                           required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-mauve btn-sm w-100">
                        <i class="fas fa-search me-1"></i>Generate Report
                    </button>
                </div>
            </div>
        </form>

        @if($plannedOrders->count() > 0)
            <div class="card bg-light mt-2">
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-header bg-yellow text-dark py-1">
                            <h6 class="mb-0">
                                <i class="fas fa-truck me-1"></i>
                                Deliveries Summary
                            </h6>
                        </div>
                        <div class="card-body p-2">
                            <p class="mb-1 small">
                                <strong>Total subscriptions:</strong>
                                <span class="badge bg-mauve">{{ $subscriptions->count() }}</span>
                            </p>
                            <p class="mb-1 small">
                                <strong>Total planned orders:</strong>
                                <span class="badge bg-mauve">{{ $plannedOrders->count() }}</span>
                            </p>
                            <p class="mb-0 small">
                                <strong>Date:</strong>
                                {{ \Carbon\Carbon::parse($date)->format('M j, Y') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-light mt-2">
                <div class="card-header bg-yellow text-dark py-1">
                    <h6 class="mb-0">
                        <i class="fas fa-carrot me-1"></i>
                        Ingredients Required By Recipe Type (Raw quantity before preparation)
                    </h6>
                </div>

                <div class="card-body p-2">
                    @if(empty($ingredientPerRecipe))
                        <div class="alert alert-info small mb-0">
                            No ingredients for the selected date.
                        </div>
                    @else
                        @php
                            $recipeModels = collect(array_keys($ingredientPerRecipe))
                                ->map(function($rid) use ($recipes) {
                                    return $recipes[$rid] ?? null;
                                })
                                ->filter()
                                ->values();

                            $recipeModels = $recipeModels->sortBy(function($r){
                                return ($r->recipeType->name ?? 'Other') . '|' . ($r->name ?? '');
                            })->values();

                            $allIngredientsMap = [];
                            $matrix = [];

                            foreach ($recipeModels as $r) {
                                $rid = $r->id;
                                foreach (($ingredientPerRecipe[$rid] ?? []) as $row) {
                                    $ing = $row['ingredient'] ?? null;
                                    if (!$ing) continue;

                                    $iid = $ing->id;
                                    $qty = (float)($row['total_quantity'] ?? 0);

                                    $allIngredientsMap[$iid] = $ing;

                                    if (!isset($matrix[$iid])) $matrix[$iid] = [];
                                    if (!isset($matrix[$iid][$rid])) $matrix[$iid][$rid] = 0;

                                    $matrix[$iid][$rid] += $qty;
                                }
                            }

                            $ingredientsList = collect($allIngredientsMap)
                                ->sortBy(function($ing){ return $ing->name ?? ''; })
                                ->values();

                            $colTotals = [];
                            foreach ($recipeModels as $r) $colTotals[$r->id] = 0;

                            $grandTotal = 0;
                        @endphp

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered table-ingredients-inverted mb-0">
                                <thead>
                                    <tr>
                                        <th class="sticky-col col-ingredient-name">
                                            Ingredient
                                        </th>

                                        @foreach($recipeModels as $r)
                                            @php
                                                $neededQty = (int)($recipeCounts[$r->id] ?? 0);
                                            @endphp
                                            <th class="text-center col-recipe-name">
                                                <a href="{{ route('recipes.show', $r->id) }}"
                                                   target="_blank"
                                                   class="recipe-link"
                                                   title="View recipe in new tab"
                                                   data-stop-click="1">
                                                    {{ $r->name }}
                                                    <span class="badge bg-mauve ms-1" title="Required qty">
                                                        {{ $neededQty }}
                                                    </span>
                                                </a>

                                                <div class="mt-1">
                                                    @if(!$r->is_premium)
                                                        <small class="text-muted">
                                                            {{ $r->recipeType->name ?? 'Other' }}
                                                        </small>
                                                    @endif

                                                    @if(!empty($r->is_premium))
                                                        <span class="badge bg-warning text-dark ms-1">Premium</span>
                                                    @endif
                                                </div>
                                            </th>
                                        @endforeach

                                        <th class="text-end col-total-col">
                                            Total
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @forelse($ingredientsList as $ing)
                                        @php
                                            $iid = $ing->id;
                                            $rowTotal = 0;
                                        @endphp

                                        <tr class="ingredient-row">
                                            <td class="sticky-col small fw-semibold col-ingredient-name">
                                                {{ $ing->name }}
                                                <div class="text-muted small">
                                                    ({{ $ing->unit ?? '-' }})
                                                    @if($ing->yield_percentage !== null)
                                                        • Yield: {{ rtrim(rtrim(number_format($ing->yield_percentage, 2, '.', ''), '0'), '.') }}%
                                                    @endif
                                                </div>
                                            </td>

                                            @foreach($recipeModels as $r)
                                                @php
                                                    $rid = $r->id;
                                                    $val = (float)($matrix[$iid][$rid] ?? 0);

                                                    $rowTotal += $val;
                                                    $colTotals[$rid] += $val;
                                                @endphp

                                                <td class="small text-end">
                                                    {{ $val > 0 ? number_format($val, 2) : '-' }}
                                                </td>
                                            @endforeach

                                            @php $grandTotal += $rowTotal; @endphp

                                            <td class="small text-end fw-bold">
                                                {{ number_format($rowTotal, 2) }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ 2 + $recipeModels->count() }}" class="small text-muted">
                                                No ingredients found.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card bg-light mt-2">
                <div class="card-header bg-yellow text-dark py-1">
                    <h6 class="mb-0">
                        <i class="fas fa-route me-1"></i>
                        Deliveries
                    </h6>
                </div>

                <div class="card-body p-2">
                    @php
                        $groupedDeliveries = $subscriptions->groupBy(function($sub) {
                            $prov = trim((string)($sub->subscriber_province ?? ''));
                            return $prov !== '' ? $prov : 'Unknown province';
                        });

                        $groupedDeliveries = $groupedDeliveries->sortKeys();
                    @endphp

                    @if($subscriptions->isEmpty())
                        <div class="alert alert-info text-center py-2 mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            No deliveries for {{ \Carbon\Carbon::parse($date)->format('M j, Y') }}
                        </div>
                    @else
                        <div class="accordion" id="deliveriesAccordion">
                            @foreach($groupedDeliveries as $provName => $subsInProvince)
                                @php
                                    $collapseId = 'deliveries_' . md5($provName);
                                    $headingId = 'heading_' . md5($provName);

                                    $subsInProvince = $subsInProvince->values();
                                @endphp

                                <div class="accordion-item mb-2">
                                    <h2 class="accordion-header" id="{{ $headingId }}">
                                        <button class="accordion-button collapsed py-2" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#{{ $collapseId }}"
                                                aria-expanded="false"
                                                aria-controls="{{ $collapseId }}">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <span class="fw-semibold small">
                                                    <i class="fas fa-map-marked-alt me-1"></i>
                                                    {{ $provName }}
                                                </span>
                                                <span class="badge bg-mauve ms-2">
                                                    {{ $subsInProvince->count() }}
                                                </span>
                                            </div>
                                        </button>
                                    </h2>

                                    <div id="{{ $collapseId }}" class="accordion-collapse collapse"
                                         aria-labelledby="{{ $headingId }}">
                                        <div class="accordion-body p-2">

                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Subscriber</th>
                                                            <th>Address</th>
                                                            @if($canGeneratePdf)
                                                                <th class="text-end">PDF</th>
                                                            @endif
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($subsInProvince as $subscription)
                                                            @php
                                                                $ordersForSub = $plannedOrdersBySubscription[$subscription->id] ?? collect();

                                                                $hasTransitionForDate = false;
                                                                $hasPremiumForDate = false;
                                                                $hasLastOrderForDate = false;

                                                                $totalOrders = 0;
                                                                $preparedCount = 0;

                                                                foreach ($ordersForSub as $o) {
                                                                    $totalOrders++;

                                                                    $st = strtolower((string)($o->computed_status ?? $o->status ?? ''));
                                                                    if (in_array($st, ['prepared','shipped','delivered'], true)) {
                                                                        $preparedCount++;
                                                                    }

                                                                    if (!empty(optional($o->animal)->transition)) {
                                                                        $hasTransitionForDate = true;
                                                                    }

                                                                    if (!empty(optional($o->animal)->last_order)) {
                                                                        $hasLastOrderForDate = true;
                                                                    }

                                                                    foreach (($o->items ?? collect()) as $it) {
                                                                        if ($it->recipe && $it->recipe->is_premium) {
                                                                            $hasPremiumForDate = true;
                                                                            break;
                                                                        }
                                                                    }
                                                                }

                                                                $allPreparedForSub = ($totalOrders > 0 && $preparedCount === $totalOrders);

                                                                $zoneName = trim((string)($subscription->subscriber_zone ?? ''));
                                                                $zoneName = $zoneName !== '' ? $zoneName : 'Unknown zone';

                                                                $flags = $subscriptionFlags[$subscription->id] ?? ['is_first_order' => false, 'is_last_order' => false];

                                                                $firstOrderPdfPath = $settings->pdf_first_delivery ?? null;
                                                                $firstOrderPdfUrl = null;

                                                                if ($firstOrderPdfPath) {
                                                                    $firstOrderPdfUrl = asset('storage/app/public/'.$settings->pdf_first_delivery);
                                                                }
                                                            @endphp

                                                            @php
                                                                $details = [
                                                                    'subscription_id' => $subscription->id,
                                                                    'subscriber' => [
                                                                        'code'       => $subscription->code ?? null,
                                                                        'first_name' => $subscription->subscriber_first_name,
                                                                        'last_name'  => $subscription->subscriber_last_name,
                                                                        'phone'      => $subscription->subscriber_phone,
                                                                        'address'    => $subscription->subscriber_address,
                                                                        'province'   => $subscription->subscriber_province,
                                                                        'zone'       => $subscription->subscriber_zone,
                                                                        'note'       => $subscription->subscriber_note,
                                                                    ],
                                                                    'orders' => [],
                                                                ];

                                                                foreach ($ordersForSub as $o) {
                                                                    $animal = $o->animal;

                                                                    $meals = [];
                                                                    foreach (($o->items ?? collect()) as $it) {
                                                                        if (!$it->recipe) continue;
                                                                        $qty = (int)($it->quantity ?? 0);
                                                                        if ($qty <= 0) continue;

                                                                        $meals[] = [
                                                                            'recipe' => $it->recipe->name,
                                                                            'qty' => $qty,
                                                                        ];
                                                                    }

                                                                    if (!$animal || empty($meals)) {
                                                                        continue;
                                                                    }

                                                                    $details['orders'][] = [
                                                                        'order_id' => $o->id,
                                                                        'scheduled_for' => (string)$o->scheduled_for,
                                                                        'status' => (string)($o->computed_status ?? $o->status),
                                                                        'animal' => [
                                                                            'name' => $animal->name,
                                                                            'species' => $animal->species,
                                                                            'age' => $animal->age,
                                                                            'health_status' => $animal->health_status,
                                                                            'note' => $animal->note,
                                                                            'last_order' => (int) ($animal->last_order ?? 0),
                                                                        ],
                                                                        'meals' => $meals,
                                                                    ];
                                                                }
                                                            @endphp

                                                            <tr class="delivery-row delivery-clickable {{ $allPreparedForSub ? 'delivery-prepared-row' : '' }}"
                                                                role="button"
                                                                tabindex="0"
                                                                data-subscription-id="{{ $subscription->id }}"
                                                                data-delivery-details='@json($details, JSON_UNESCAPED_UNICODE)'
                                                                data-delivery-date="{{ $date }}">
                                                                <td class="small">
                                                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                                                        <span class="fw-semibold">
                                                                            {{ $subscription->subscriber_first_name }}
                                                                            {{ $subscription->subscriber_last_name }}
                                                                        </span>

                                                                        <span class="prep-status-pill {{ $allPreparedForSub ? 'is-prepared' : 'is-pending' }}"
                                                                              id="prepStatus_{{ $subscription->id }}">
                                                                            @if($allPreparedForSub)
                                                                                <i class="fas fa-check-circle me-1"></i>Prepared
                                                                            @else
                                                                                <i class="fas fa-hourglass-half me-1"></i>To prepare
                                                                            @endif
                                                                        </span>

                                                                        <span class="prep-row-check {{ $allPreparedForSub ? '' : 'd-none' }}"
                                                                              id="prepRowCheck_{{ $subscription->id }}">
                                                                            <i class="fas fa-check-circle"></i>
                                                                        </span>

                                                                        @if($hasTransitionForDate)
                                                                            <span class="badge bg-success">
                                                                                <i class="fas fa-exchange-alt me-1"></i>Transition
                                                                            </span>
                                                                        @endif

                                                                        @if(!empty($flags['is_last_order']) && $hasLastOrderForDate)
                                                                            <span class="badge bg-danger">
                                                                                <i class="fas fa-undo-alt me-1"></i>Last order
                                                                            </span>
                                                                        @endif
                                                                    </div>

                                                                    <div class="small text-muted mt-1">
                                                                        <span class="me-2">
                                                                            <strong>Phone:</strong> {{ $subscription->subscriber_phone ?? '-' }}
                                                                        </span>
                                                                        <span class="me-2">
                                                                            <strong>Delivery Time Slot:</strong> {{ $subscription->subscriber_delivery_slot ?? '-' }}
                                                                        </span>
                                                                        <span>
                                                                            <strong>Code:</strong> {{ $subscription->code ?? '-' }}
                                                                        </span>
                                                                    </div>
                                                                </td>

                                                                <td class="small">
                                                                    {{ \Illuminate\Support\Str::limit($subscription->subscriber_address, 60) }}
                                                                    <span class="ms-1 fw-bold">- {{ $zoneName }}</span>
                                                                </td>

                                                                @if($canGeneratePdf)
                                                                    <td class="small text-end">
                                                                        <div class="d-flex flex-column flex-md-row gap-1 justify-content-end">
                                                                            <a data-stop-click="1"
                                                                               href="{{ route('daily-report.delivery-pdf', ['subscription' => $subscription->id, 'date' => $date]) }}"
                                                                               class="btn btn-sm btn-outline-secondary">
                                                                                <i class="fas fa-file-pdf me-1"></i>Delivery
                                                                            </a>

                                                                            @if(!empty($flags['is_first_order']) && $firstOrderPdfUrl)
                                                                                <a data-stop-click="1"
                                                                                   href="{{ $firstOrderPdfUrl }}"
                                                                                   target="_blank"
                                                                                   class="btn btn-sm btn-outline-primary">
                                                                                    <i class="fas fa-file-pdf me-1"></i>First Order PDF
                                                                                </a>
                                                                            @endif

                                                                            @if(!empty($flags['is_last_order']) && $hasLastOrderForDate)
                                                                                <a data-stop-click="1"
                                                                                   href="{{ route('daily-report.last-order-pdf', ['subscription' => $subscription->id, 'date' => $date]) }}"
                                                                                   target="_blank"
                                                                                   class="btn btn-sm btn-outline-danger">
                                                                                    <i class="fas fa-file-pdf me-1"></i>Last Order
                                                                                </a>
                                                                            @endif

                                                                            @if($hasPremiumForDate)
                                                                                <a data-stop-click="1"
                                                                                   href="{{ route('daily-report.premium-pdf', ['subscription' => $subscription->id, 'date' => $date]) }}"
                                                                                   class="btn btn-sm btn-outline-warning">
                                                                                    <i class="fas fa-bolt me-1"></i>Premium
                                                                                </a>
                                                                            @endif

                                                                            @if($hasTransitionForDate)
                                                                                <a data-stop-click="1"
                                                                                   href="{{ route('daily-report.new-delivery-pdf', ['subscription' => $subscription->id, 'date' => $date]) }}"
                                                                                   class="btn btn-sm btn-outline-success">
                                                                                    <i class="fas fa-handshake me-1"></i>Welcome
                                                                                </a>
                                                                            @endif

                                                                            @if($hasTransitionForDate)
                                                                                <a data-stop-click="1"
                                                                                   href="{{ route('daily-report.subscription-qr-promo-pdf', ['subscription' => $subscription->id, 'date' => $date]) }}"
                                                                                   class="btn btn-sm btn-outline-info">
                                                                                    <i class="fas fa-qrcode me-1"></i>Subscription QR Promo
                                                                                </a>
                                                                            @endif
                                                                        </div>
                                                                    </td>
                                                                @endif
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @if($canGeneratePdf)
                <div class="d-flex justify-content-end mt-3">
                    <a target="_blank"
                       href="{{ route('daily-report.full-pdf', ['date' => $date]) }}"
                       class="btn btn-mauve btn-sm">
                        <i class="fas fa-file-pdf me-1"></i> Download Full Daily Report (PDF)
                    </a>
                </div>
            @endif

        @else
            <div class="alert alert-info text-center py-2">
                <i class="fas fa-info-circle me-1"></i>
                No deliveries for {{ \Carbon\Carbon::parse($date)->format('M j, Y') }}
            </div>
        @endif
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const CAN_MARK_PREPARED = @json($canMarkPrepared);
  const CSRF_TOKEN = '{{ csrf_token() }}';
  const MARK_PREPARED_URL_TEMPLATE = '{{ url('/daily-report/planned-orders') }}/__ID__/mark-prepared';

  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function normalizeStatus(s) {
    return String(s || '').trim().toLowerCase();
  }

  function isPreparedStatus(s) {
    const st = normalizeStatus(s);
    return st === 'prepared' || st === 'shipped' || st === 'delivered';
  }

  function updateSubscriberPreparedUI(subscriptionId, allPrepared) {
    const pill = document.getElementById('prepStatus_' + subscriptionId);
    const check = document.getElementById('prepRowCheck_' + subscriptionId);
    const row = document.querySelector('tr.delivery-row[data-subscription-id="' + subscriptionId + '"]');

    if (pill) {
      pill.classList.toggle('is-prepared', !!allPrepared);
      pill.classList.toggle('is-pending', !allPrepared);
      pill.innerHTML = allPrepared
        ? '<i class="fas fa-check-circle me-1"></i>Prepared'
        : '<i class="fas fa-hourglass-half me-1"></i>To prepare';
    }

    if (check) check.classList.toggle('d-none', !allPrepared);
    if (row) row.classList.toggle('delivery-prepared-row', !!allPrepared);
  }

  function computeAllPrepared(orders) {
    if (!Array.isArray(orders) || orders.length === 0) return false;
    for (const o of orders) {
      if (!isPreparedStatus(o.status)) return false;
    }
    return true;
  }

  function getRowData(row) {
    const raw = row.getAttribute('data-delivery-details') || '{}';
    let data = {};
    try { data = JSON.parse(raw); } catch(e) { data = {}; }
    return data;
  }

  const modalEl = document.getElementById('deliveryDetailsModal');
  const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

  const confirmModalEl = document.getElementById('confirmPreparedModal');
  const confirmModal = confirmModalEl ? new bootstrap.Modal(confirmModalEl) : null;

  let pendingConfirm = null;

  document.addEventListener('click', function(e){
    const stopEl = e.target.closest('[data-stop-click="1"]');
    if (stopEl) {
      e.stopPropagation();
      return;
    }

    const btn = e.target.closest('.mark-prepared-btn');
    if (btn) {
      e.preventDefault();
      e.stopPropagation();

      if (!CAN_MARK_PREPARED) {
        return;
      }

      confirmMarkPrepared(btn);
      return;
    }

    const row = e.target.closest('tr.delivery-row');
    if (row && modal) {
      openModalFromRow(row);
    }
  });

  function openModalFromRow(row) {
    const data = getRowData(row);
    const date = row.getAttribute('data-delivery-date') || '';
    const subscriptionId = data.subscription_id || row.getAttribute('data-subscription-id') || null;

    document.getElementById('modalDateBadge').textContent = date ? ('Date: ' + date) : '';

    const sub = data.subscriber || {};
    document.getElementById('subName').textContent = [sub.first_name, sub.last_name].filter(Boolean).join(' ');
    document.getElementById('subPhone').textContent = sub.phone || '-';
    document.getElementById('subCode').textContent = sub.code || '-';
    document.getElementById('subAddress').textContent = sub.address || '-';
    document.getElementById('subProvince').textContent = sub.province || '-';
    document.getElementById('subZone').textContent = sub.zone || '-';

    const noteWrap = document.getElementById('subNoteWrap');
    if (sub.note) {
      noteWrap.style.display = '';
      document.getElementById('subNote').textContent = sub.note;
    } else {
      noteWrap.style.display = 'none';
      document.getElementById('subNote').textContent = '';
    }

    const orders = Array.isArray(data.orders) ? data.orders : [];
    const container = document.getElementById('animalsContainer');
    container.innerHTML = '';

    if (!orders.length) {
      container.innerHTML = '<div class="alert alert-info small mb-0">No planned orders for this date.</div>';
      modal.show();
      return;
    }

    orders.forEach(function(o, idx){
      const animal = o.animal || {};
      const name = animal.name || ('Animal #' + (idx+1));
      const status = (o.status || '').toString();
      const prepared = isPreparedStatus(status);

      const metaParts = [];
      if (animal.species) metaParts.push('<strong>Species:</strong> ' + escapeHtml(animal.species));
      if (animal.age) metaParts.push('<strong>Age:</strong> ' + escapeHtml(String(animal.age)));
      if (animal.health_status) metaParts.push('<strong>Health:</strong> ' + escapeHtml(animal.health_status));
      if (animal.note) metaParts.push('<strong>Note:</strong> ' + escapeHtml(animal.note));
      if (parseInt(animal.last_order || 0) === 1) metaParts.push('<strong>Last order:</strong> Yes');

      const meals = Array.isArray(o.meals) ? o.meals : [];

      let mealsHtml = '';
      if (!meals.length) {
        mealsHtml = '<div class="text-muted small">No meals</div>';
      } else {
        mealsHtml += `
          <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
              <thead>
                <tr>
                  <th class="small">Recipe</th>
                  <th class="small text-end" style="width:90px;">Qty</th>
                </tr>
              </thead>
              <tbody>
                ${meals.map(m => `
                  <tr>
                    <td class="small">${escapeHtml(m.recipe || '')}</td>
                    <td class="small text-end fw-semibold">${escapeHtml(String(m.qty ?? 0))}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        `;
      }

      container.insertAdjacentHTML('beforeend', `
        <div class="border rounded p-2 mb-2" data-order-card="1" data-order-id="${escapeHtml(String(o.order_id ?? ''))}">
          <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
            <div class="fw-semibold small"><i class="fas fa-paw me-1 text-mauve"></i>${escapeHtml(name)}</div>
            <div class="d-flex gap-2 align-items-center">
              <span class="badge ${prepared ? 'bg-success' : 'bg-warning text-dark'}" data-order-status-badge="1">${escapeHtml(status.toUpperCase())}</span>
              <span class="badge bg-secondary">Order #${escapeHtml(String(o.order_id ?? ''))}</span>
              ${
                prepared
                  ? `<span class="badge bg-light text-dark border"><i class="fas fa-check me-1"></i>Prepared</span>`
                  : (
                      CAN_MARK_PREPARED
                        ? `<button type="button"
                                   class="btn btn-sm btn-outline-success mark-prepared-btn"
                                   data-order-id="${escapeHtml(String(o.order_id ?? ''))}"
                                   data-subscription-id="${escapeHtml(String(subscriptionId ?? ''))}">
                             <span class="btn-label"><i class="fas fa-check me-1"></i>Mark as prepared</span>
                             <span class="btn-loading d-none"><i class="fas fa-spinner fa-spin me-1"></i>Saving...</span>
                           </button>`
                        : `<span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>To prepare</span>`
                    )
              }
            </div>
          </div>
          ${metaParts.length ? `<div class="small text-muted mb-2">${metaParts.join(' &nbsp; | &nbsp; ')}</div>` : ''}
          ${mealsHtml}
        </div>
      `);
    });

    modal.show();
  }

  function confirmMarkPrepared(btn) {
    if (!confirmModal) {
      handleMarkPrepared(btn);
      return;
    }

    const card = btn.closest('[data-order-card="1"]');
    const animalNameEl = card ? card.querySelector('.fw-semibold.small') : null;
    const animalNameText = animalNameEl ? animalNameEl.textContent.trim() : '';

    const msgEl = document.getElementById('confirmPreparedMessage');
    if (msgEl) {
      const extra = animalNameText ? ('\n\n' + animalNameText) : '';
      msgEl.textContent = 'Are you sure you want to mark this order as prepared? This action is irreversible.' + extra;
    }

    pendingConfirm = btn;
    confirmModal.show();
  }

  const confirmContinueBtn = document.getElementById('confirmPreparedContinue');
  if (confirmContinueBtn) {
    confirmContinueBtn.addEventListener('click', function(){
      if (!pendingConfirm) return;
      const btn = pendingConfirm;
      pendingConfirm = null;
      if (confirmModal) confirmModal.hide();
      handleMarkPrepared(btn);
    });
  }

  const confirmCancelBtn = document.getElementById('confirmPreparedCancel');
  if (confirmCancelBtn) {
    confirmCancelBtn.addEventListener('click', function(){
      pendingConfirm = null;
      if (confirmModal) confirmModal.hide();
    });
  }

  if (confirmModalEl) {
    confirmModalEl.addEventListener('hidden.bs.modal', function(){
      pendingConfirm = null;
    });
  }

  async function handleMarkPrepared(btn) {
    if (!CAN_MARK_PREPARED) {
      return;
    }

    const orderId = btn.getAttribute('data-order-id');
    const subId = btn.getAttribute('data-subscription-id');

    if (!orderId) return;

    const label = btn.querySelector('.btn-label');
    const loading = btn.querySelector('.btn-loading');

    btn.disabled = true;
    if (label) label.classList.add('d-none');
    if (loading) loading.classList.remove('d-none');

    try {
      const url = MARK_PREPARED_URL_TEMPLATE.replace('__ID__', encodeURIComponent(orderId));
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': CSRF_TOKEN,
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      if (!res.ok) {
        const t = await res.text();
        throw new Error('HTTP ' + res.status + ' ' + t);
      }

      const card = btn.closest('[data-order-card="1"]');
      if (card) {
        const badge = card.querySelector('[data-order-status-badge="1"]');
        if (badge) {
          badge.className = 'badge bg-success';
          badge.textContent = 'PREPARED';
        }
      }

      btn.outerHTML = `<span class="badge bg-light text-dark border"><i class="fas fa-check me-1"></i>Prepared</span>`;

      const modalOpenRow = document.querySelector('tr.delivery-row[data-subscription-id="' + subId + '"]');
      if (modalOpenRow) {
        const raw = modalOpenRow.getAttribute('data-delivery-details') || '{}';
        let data = {};
        try { data = JSON.parse(raw); } catch(e) { data = {}; }

        if (Array.isArray(data.orders)) {
          data.orders = data.orders.map(function(o){
            if (String(o.order_id ?? '') === String(orderId)) {
              return Object.assign({}, o, { status: 'prepared' });
            }
            return o;
          });
        }

        modalOpenRow.setAttribute('data-delivery-details', JSON.stringify(data));
        updateSubscriberPreparedUI(subId, computeAllPrepared(data.orders || []));
      }

    } catch (err) {
      btn.disabled = false;
      if (label) label.classList.remove('d-none');
      if (loading) loading.classList.add('d-none');
      alert('Failed to update order status. Please check the route and server response.');
    }
  }
});
</script>

<style>
.delivery-clickable { cursor: pointer; }
.delivery-clickable:hover { background: #fff7d6 !important; }
.delivery-clickable:focus { outline: 2px solid #8B5FBF; outline-offset: -2px; }

.delivery-prepared-row { background: rgba(34,197,94,0.06) !important; }

.prep-status-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: .75rem;
  font-weight: 800;
  border: 1px solid rgba(0,0,0,0.10);
  white-space: nowrap;
}
.prep-status-pill.is-pending { background: rgba(245,158,11,0.12); color: #92400e; }
.prep-status-pill.is-prepared { background: rgba(34,197,94,0.12); color: #166534; }

.prep-row-check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #16a34a;
  font-size: 1rem;
}

.table-ingredients-inverted thead th {
  background-color: #f3e8ff;
  vertical-align: middle;
  min-width: 140px;
}

.table-ingredients-inverted .col-ingredient-name { min-width: 240px; }
.table-ingredients-inverted .col-recipe-name { min-width: 190px; }
.table-ingredients-inverted .col-total-col { min-width: 120px; background: #fff8e1; }

.table-ingredients-inverted .ingredient-row:hover td { background: #faf5ff; }

.recipe-link {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 6px;
  text-decoration: none;
  font-weight: 700;
  color: #1f2937;
}

.recipe-link:hover {
  background: #fff7d6;
  text-decoration: underline;
}

.sticky-col {
  position: sticky;
  left: 0;
  z-index: 2;
  background: #ffffff;
}

.table-ingredients-inverted thead .sticky-col {
  z-index: 3;
  background: #f3e8ff;
}

.confirm-modal-icon {
  width: 46px;
  height: 46px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(245, 158, 11, 0.14);
  border: 1px solid rgba(245, 158, 11, 0.35);
  color: #92400e;
  font-size: 20px;
}
</style>

<div class="modal fade" id="deliveryDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-mauve text-white py-2">
        <h5 class="modal-title mb-0">
          <i class="fas fa-truck me-2"></i>Delivery Details
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-2 p-md-3">
        <div class="mb-2">
          <span class="badge bg-mauve" id="modalDateBadge"></span>
        </div>

        <div class="card card-sm mb-2">
          <div class="card-header bg-yellow py-1">
            <strong>Subscriber</strong>
          </div>
          <div class="card-body p-2">
            <div class="row g-2 small">
              <div class="col-md-6"><strong>Name:</strong> <span id="subName"></span></div>
              <div class="col-md-6"><strong>Phone:</strong> <span id="subPhone"></span></div>
              <div class="col-md-6"><strong>Code:</strong> <span id="subCode"></span></div>
              <div class="col-12"><strong>Address:</strong> <span id="subAddress"></span></div>
              <div class="col-md-6"><strong>Province:</strong> <span id="subProvince"></span></div>
              <div class="col-md-6"><strong>Zone:</strong> <span id="subZone"></span></div>
              <div class="col-12" id="subNoteWrap" style="display:none;">
                <strong>Note:</strong> <span id="subNote"></span>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-sm">
          <div class="card-header bg-yellow py-1">
            <strong>Planned Orders</strong>
          </div>
          <div class="card-body p-2">
            <div id="animalsContainer"></div>
          </div>
        </div>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

@if($canMarkPrepared)
<div class="modal fade" id="confirmPreparedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title mb-0">Confirm action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="d-flex gap-3 align-items-start">
          <div class="confirm-modal-icon">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div>
            <div class="fw-semibold mb-1">Mark as prepared</div>
            <div class="text-muted" id="confirmPreparedMessage">
              Are you sure you want to mark this order as prepared? This action is irreversible.
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="confirmPreparedCancel">Cancel</button>
        <button type="button" class="btn btn-success btn-sm" id="confirmPreparedContinue">
          Continue
        </button>
      </div>
    </div>
  </div>
</div>
@endif

@endif
@endsection