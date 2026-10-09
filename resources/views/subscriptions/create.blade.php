@extends('layouts.app')

@section('title', 'Create Subscription')

@section('content')
<div class="card shadow">
    <div class="card-header bg-mauve text-white">
        <h4 class="mb-0">Create New Subscription</h4>
    </div>
    <div class="card-body">
        @include('subscriptions._form', ['recipes' => $recipes])
    </div>
</div>
@endsection
