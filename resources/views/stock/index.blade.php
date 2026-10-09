@extends('layouts.app')

@section('title', 'Stock Management')

@section('content')
<div class="card card-sm shadow">
    <div class="card-header bg-mauve text-white py-2 center">
        <h4 class="mb-0 h5 center">Stock Management</h4>
    </div>

    <div class="card-body p-2">

        {{-- Date selector (always visible) --}}
        <form method="GET" action="{{ route('stock.index') }}" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label for="date" class="form-label small">
                        Select Date
                        <span class="text-muted">(Edit stock OR End date for status)</span>
                    </label>
                    <input type="date"
                           class="form-control form-control-sm"
                           id="date"
                           name="date"
                           value="{{ $selectedDate ?? '' }}">
                </div>

                <div class="col-md-6 d-flex gap-2">
                    <button type="submit"
                            name="action"
                            value="edit"
                            class="btn btn-mauve btn-sm flex-grow-1">
                        <i class="fas fa-pen-to-square me-1"></i>Open Stock Form
                    </button>

                    <button type="submit"
                            name="action"
                            value="status"
                            class="btn btn-outline-secondary btn-sm flex-grow-1">
                        <i class="fas fa-chart-line me-1"></i>Status
                    </button>
                </div>
            </div>
        </form>

        {{-- MODE: edit --}}
        @if($mode === 'edit')
            <div class="alert alert-info small mb-2">
                Enter stock for date: <strong>{{ $stockDate }}</strong>
            </div>

            <form method="POST" action="{{ route('stock.store') }}">
                @csrf
                <input type="hidden" name="stock_date" value="{{ $stockDate }}">

                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="bg-yellow">
                            <tr>
                                <th style="min-width:180px;">Ingredient</th>
                                <th style="width:120px;" class="text-center">Unit</th>
                                <th style="width:180px;" class="text-end">Quantity (Raw)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ingredients as $ing)
                                @php
                                    $val = old('quantities.'.$ing->id);
                                    if ($val === null) {
                                        $val = $existing[$ing->id]->quantity ?? 0;
                                    }
                                @endphp
                                <tr>
                                    <td class="small fw-semibold">{{ $ing->name }}</td>
                                    <td class="small text-center">{{ $ing->unit }}</td>
                                    <td class="text-end">
                                        <input type="number"
                                               step="0.001"
                                               min="0"
                                               name="quantities[{{ $ing->id }}]"
                                               class="form-control form-control-sm text-end @error('quantities.'.$ing->id) is-invalid @enderror"
                                               value="{{ $val }}">
                                        @error('quantities.'.$ing->id)
                                            <div class="invalid-feedback small">{{ $message }}</div>
                                        @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-mauve btn-sm">
                        <i class="fas fa-save me-1"></i>Save Stock
                    </button>
                </div>
            </form>
        @endif

        {{-- MODE: status --}}
        @if($mode === 'status')
            @if(!$latestStockDate)
                <div class="alert alert-warning small">
                    No stock has been recorded yet. Please select a date and enter stock.
                </div>
            @else
                <div class="alert alert-light small mb-2">
                    Latest stock date: <strong>{{ $latestStockDate }}</strong><br>
                    Status computed from <strong>{{ \Carbon\Carbon::parse($latestStockDate)->addDay()->toDateString() }}</strong>
                    to <strong>{{ $endDate }}</strong>
                    (same rules as Daily Report + Yield).
                </div>
            @endif

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="bg-yellow">
                        <tr>
                            <th style="min-width:180px;">Ingredient</th>
                            <th style="width:120px;" class="text-center">Unit</th>
                            <th style="width:160px;" class="text-end">Last Stock</th>
                            <th style="width:160px;" class="text-end">Consumed</th>
                            <th style="width:160px;" class="text-end">Remaining</th>
                            <th style="width:220px;" class="text-center">Exhaustion</th>
                            <th style="width:170px;" class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statusRows as $row)
                            @php
                                $ing = $row['ingredient'];
                                $status = $row['status'];

                                $badgeClass = 'bg-secondary';
                                $label = 'No stock';

                                if ($status === 'ok') { $badgeClass = 'bg-success'; $label = 'Stock sufficiently available'; }
                                elseif ($status === 'almost') { $badgeClass = 'bg-warning text-dark'; $label = 'Stock almost finished'; }
                                elseif ($status === 'stock_out') { $badgeClass = 'bg-danger'; $label = 'Stock out'; }
                                elseif ($status === 'no_stock') { $badgeClass = 'bg-secondary'; $label = 'No stock'; }

                                $exhaustion = $row['exhaustion_date'] ?? null;
                                $exhaustionLabel = $row['exhaustion_label'] ?? '-';
                            @endphp
                            <tr>
                                <td class="small fw-semibold">{{ $ing->name }}</td>
                                <td class="small text-center">{{ $ing->unit }}</td>
                                <td class="small text-end">{{ number_format((float)$row['last_qty'], 3) }}</td>
                                <td class="small text-end">{{ number_format((float)$row['consumed'], 3) }}</td>
                                <td class="small text-end fw-bold">{{ number_format((float)$row['remaining'], 3) }}</td>

                                <td class="small text-center">
                                    @if($exhaustion)
                                        <span class="badge bg-light text-dark border">
                                            Sufficient until {{ $exhaustion }}
                                        </span>
                                    @else
                                        <span class="text-muted">{{ $exhaustionLabel }}</span>
                                    @endif
                                </td>

                                <td class="text-center">
                                    <span class="badge {{ $badgeClass }}">{{ $label }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</div>
@endsection
