@extends('layouts.app')

@section('title', 'Edit Recipe')

@section('content')
<div class="card shadow">
    <div class="card-header bg-mauve text-white">
        <h4 class="mb-0">Edit Recipe</h4>
    </div>
    <div class="card-body">
        @include('recipes._form')
    </div>
</div>
@endsection
