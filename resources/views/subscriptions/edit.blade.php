@extends('layouts.app')

@section('title', 'Edit Subscription')

@section('content')
<div class="card shadow">
    <div class="card-header bg-mauve text-white">
        <h4 class="mb-0">Edit Subscription</h4>
    </div>
    <div class="card-body">
        @include('subscriptions._form', [
            'recipes' => $recipes,
            'provinces' => $provinces,
            'subscription' => $subscription
        ])
    </div>
</div>
@endsection
