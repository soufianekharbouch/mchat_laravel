@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="text-mauve mb-0">Add Product</h2>
    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
</div>

<form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data">
    @csrf
    @include('products.form')
</form>
@endsection
