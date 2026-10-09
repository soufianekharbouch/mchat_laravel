@extends('layouts.guest')

@section('content')
<div class="login-card">
    <div class="card">
        <div class="card-header bg-mauve text-white text-center py-4">
            <h3 class="mb-0"><i class="fas fa-paw me-2"></i>Pet Nutrition App</h3>
            <p class="mb-0 mt-1 small opacity-75">Please login to continue</p>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fas fa-user text-mauve"></i>
                        </span>
                        <input type="text" class="form-control @error('username') is-invalid @enderror"
                               id="username" name="username" value="{{ old('username') }}"
                               placeholder="Enter your username" required autofocus>
                    </div>
                    @error('username')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fas fa-lock text-mauve"></i>
                        </span>
                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                               id="password" name="password"
                               placeholder="Enter your password" required>
                    </div>
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-mauve w-100 py-2 mb-3">
                    <i class="fas fa-sign-in-alt me-2"></i>Login
                </button>

                @if($errors->any())
                    <div class="alert alert-danger py-2">
                        <small class="d-block text-center">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Invalid credentials
                        </small>
                    </div>
                @endif
            </form>
        </div>
    </div>
</div>
@endsection
