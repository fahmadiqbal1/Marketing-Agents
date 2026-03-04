@extends('layouts.auth')
@section('title', 'Sign In')
@section('content')
<div class="d-flex justify-content-center">
    <div class="auth-card">
        <div class="auth-logo">
            <span class="auth-logo-icon">🚀</span>
            <h2 class="h4 fw-bold text-white mb-1">Welcome back</h2>
            <p class="text-secondary small mb-0">Sign in to your marketing dashboard</p>
        </div>

        @if(session('info'))
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-2"></i>{{ session('info') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Email address</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:#1a1f2e;border-color:rgba(255,255,255,.12);color:rgba(255,255,255,.4);">
                        <i class="bi bi-envelope"></i>
                    </span>
                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           placeholder="you@company.com" value="{{ old('email') }}" required autofocus>
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:#1a1f2e;border-color:rgba(255,255,255,.12);color:rgba(255,255,255,.4);">
                        <i class="bi bi-lock"></i>
                    </span>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           placeholder="••••••••" required>
                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                <label class="form-check-label small text-secondary" for="remember">Remember me</label>
            </div>

            <button type="submit" class="btn btn-primary w-100 fw-semibold">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

        <hr class="my-4" style="border-color:rgba(255,255,255,.08);">
        <p class="text-center small text-secondary mb-0">
            Don't have an account? <a href="{{ route('register') }}">Create one free</a>
        </p>
    </div>
</div>
@endsection
