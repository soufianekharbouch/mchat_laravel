@extends('layouts.app')

@section('title', 'Edit Recipe Type')

@section('content')
<div class="card shadow card-sm">
    <div class="card-header bg-mauve text-white">
        <h4 class="mb-0 h5">
            <i class="fas fa-tag me-2"></i>Edit Recipe Type – {{ $recipeType->name }}
        </h4>
    </div>
    <div class="card-body">
        @include('recipe-types._form', ['recipeType' => $recipeType])
    </div>
</div>
@endsection
