@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="text-mauve mb-0">Edit Product</h2>
        <div class="text-muted small">{{ $product->code }} · {{ $product->name }}</div>
    </div>
    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
</div>

<form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('products.form')
</form>
@endsection
