@extends('layouts.app')

@section('title', 'Enter Stock')

@section('content')
<div class="card card-sm shadow">
    <div class="card-header bg-mauve text-white py-2 center">
        <h4 class="mb-0 h5 center"><i class="fas fa-warehouse me-2"></i>Enter Stock</h4>
    </div>

    <div class="card-body p-2">

        <form method="POST" action="{{ route('stock.store') }}">
            @csrf

            <div class="row g-2 align-items-end mb-3">
                <div class="col-md-6">
                    <label class="form-label small">Stock Date</label>
                    <input type="date"
                           class="form-control form-control-sm"
                           name="stock_date"
                           value="{{ $date }}"
                           required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-mauve btn-sm w-100">
                        <i class="fas fa-save me-1"></i>Save Stock
                    </button>
                </div>
            </div>

            <div class="alert alert-light small">
                Enter the stock quantity for each ingredient for <strong>{{ $date }}</strong>.
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="bg-yellow">
                        <tr>
                            <th>Ingredient</th>
                            <th style="width:180px;" class="text-end">Quantity</th>
                            <th style="width:120px;" class="text-end">Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ingredients as $ing)
                            @php
                                $existing = $stocks->get($ing->id);
                                $val = $existing ? $existing->quantity : '';
                            @endphp
                            <tr>
                                <td class="small fw-semibold">{{ $ing->name }}</td>
                                <td class="text-end">
                                    <input type="number"
                                           step="0.01"
                                           min="0"
                                           class="form-control form-control-sm text-end"
                                           name="quantities[{{ $ing->id }}]"
                                           value="{{ old('quantities.'.$ing->id, $val) }}"
                                           placeholder="0.00">
                                </td>
                                <td class="small text-end">{{ $ing->unit }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($errors->any())
                <div class="alert alert-danger mt-3 mb-0">
                    <ul class="mb-0 small">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

        </form>
    </div>
</div>
@endsection
