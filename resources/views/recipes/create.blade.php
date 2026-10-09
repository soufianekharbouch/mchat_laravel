@extends('layouts.app')

@section('title', 'Create Recipe')

@section('content')
<div class="card shadow">
    <div class="card-header bg-mauve text-white">
        <h4 class="mb-0">Create New Recipe</h4>
    </div>
    <div class="card-body">
        @include('recipes._form')
    </div>
</div>
@endsection
