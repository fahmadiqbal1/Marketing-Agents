@extends('layouts.auth')
@section('title', 'Create Account')
@section('content')
<div class="d-flex justify-content-center">
    <div class="auth-card" style="max-width:480px;">
        <div class="auth-logo">
            <span class="auth-logo-icon">🚀</span>
            <h2 class="h4 fw-bold text-white mb-1">Create your account</h2>
            <p class="text-secondary small mb-0">Start growing your brand with AI marketing</p>
        </div>

        <form method="POST" action="{{ route('register') }}">
            @csrf
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Your Name</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" placeholder="John Smith" value="{{ old('name') }}" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" placeholder="you@company.com" value="{{ old('email') }}" required>
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label">Business Name</label>
                    <input type="text" name="business_name" class="form-control @error('business_name') is-invalid @enderror" placeholder="Acme Marketing Co." value="{{ old('business_name') }}" required>
                    @error('business_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label">Industry <span class="text-secondary">(optional)</span></label>
                    <select name="industry" class="form-select">
                        <option value="">Select industry…</option>
                        <option value="retail" {{ old('industry')=='retail'?'selected':'' }}>Retail</option>
                        <option value="food_beverage" {{ old('industry')=='food_beverage'?'selected':'' }}>Food & Beverage</option>
                        <option value="health_wellness" {{ old('industry')=='health_wellness'?'selected':'' }}>Health & Wellness</option>
                        <option value="real_estate" {{ old('industry')=='real_estate'?'selected':'' }}>Real Estate</option>
                        <option value="technology" {{ old('industry')=='technology'?'selected':'' }}>Technology</option>
                        <option value="education" {{ old('industry')=='education'?'selected':'' }}>Education</option>
                        <option value="entertainment" {{ old('industry')=='entertainment'?'selected':'' }}>Entertainment</option>
                        <option value="other" {{ old('industry')=='other'?'selected':'' }}>Other</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" placeholder="At least 6 characters" required>
                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="password_confirmation" class="form-control" placeholder="Repeat password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 fw-semibold mt-4">
                <i class="bi bi-rocket-takeoff me-2"></i>Create Account
            </button>
        </form>

        <hr class="my-4" style="border-color:rgba(255,255,255,.08);">
        <p class="text-center small text-secondary mb-0">
            Already have an account? <a href="{{ route('login') }}">Sign in</a>
        </p>
    </div>
</div>
@endsection
