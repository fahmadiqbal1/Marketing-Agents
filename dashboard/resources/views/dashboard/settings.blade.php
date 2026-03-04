@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white">Settings</h1>
        <p class="text-secondary small mb-0">Manage your business profile and preferences</p>
    </div>
</div>

@php
    $prof = is_array($profile) && !isset($profile['error']) ? $profile : [];
    $user = auth()->user();
    $business = $user->business;
@endphp

<div class="row g-4">
    {{-- Left Column: Business Profile --}}
    <div class="col-12 col-lg-7">
        <div class="card p-4">
            <h6 class="text-white mb-4"><i class="bi bi-building text-info me-2"></i>Business Profile</h6>

            <form method="POST" action="{{ route('dashboard.settings.update') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label text-secondary small">Business Name *</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $business->name ?? $prof['name'] ?? '') }}" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label text-secondary small">Industry</label>
                        <select name="industry" class="form-select">
                            <option value="">Select…</option>
                            @php $ind = old('industry', $business->industry ?? $prof['industry'] ?? ''); @endphp
                            @foreach(['healthcare' => 'Healthcare', 'retail' => 'Retail / E-commerce', 'restaurant' => 'Restaurant / Food', 'realestate' => 'Real Estate', 'fitness' => 'Fitness / Wellness', 'education' => 'Education', 'tech' => 'Technology / SaaS', 'agency' => 'Marketing Agency', 'other' => 'Other'] as $v => $l)
                                <option value="{{ $v }}" {{ $ind === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label text-secondary small">Timezone</label>
                        <select name="timezone" class="form-select">
                            @php $tz = old('timezone', $business->timezone ?? $prof['timezone'] ?? 'UTC'); @endphp
                            @foreach(['UTC' => 'UTC', 'America/New_York' => 'Eastern (US)', 'America/Chicago' => 'Central (US)', 'America/Denver' => 'Mountain (US)', 'America/Los_Angeles' => 'Pacific (US)', 'Europe/London' => 'London (GMT)', 'Europe/Paris' => 'Paris (CET)', 'Asia/Dubai' => 'Dubai (GST)', 'Asia/Kolkata' => 'India (IST)', 'Asia/Shanghai' => 'China (CST)', 'Asia/Tokyo' => 'Tokyo (JST)'] as $v => $l)
                                <option value="{{ $v }}" {{ $tz === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label text-secondary small">Website</label>
                        <input type="url" name="website" class="form-control" placeholder="https://example.com"
                               value="{{ old('website', $business->website ?? $prof['website'] ?? '') }}">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label text-secondary small">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="+1 555 0123"
                               value="{{ old('phone', $business->phone ?? $prof['phone'] ?? '') }}">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="123 Main St, City, State"
                           value="{{ old('address', $business->address ?? $prof['address'] ?? '') }}">
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small">Brand Voice</label>
                    <textarea name="brand_voice" class="form-control" rows="4"
                              placeholder="Describe your brand tone and personality…">{{ old('brand_voice', $business->brand_voice ?? $prof['brand_voice'] ?? '') }}</textarea>
                    <div class="form-text text-secondary">Helps the AI write captions that match your style</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Save Changes
                </button>
            </form>
        </div>
    </div>

    {{-- Right Column --}}
    <div class="col-12 col-lg-5">
        {{-- Account Info --}}
        <div class="card p-4 mb-4">
            <h6 class="text-white mb-3"><i class="bi bi-person-circle me-2" style="color:#7c3aed;"></i>Account</h6>
            <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:rgba(255,255,255,.04);">
                <div class="rounded-3 d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0"
                     style="width:44px;height:44px;background:linear-gradient(135deg,#00d4ff,#7c3aed);font-size:1.1rem;">
                    {{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}
                </div>
                <div class="flex-grow-1">
                    <div class="text-white small fw-semibold">{{ $user->name }}</div>
                    <div class="text-secondary" style="font-size:.75rem;">{{ $user->email }}</div>
                </div>
                <span class="badge bg-info text-dark">{{ ucfirst($user->role ?? 'owner') }}</span>
            </div>
            <div class="mt-3 text-secondary small">
                Business ID: <code class="text-info">{{ $business->id ?? '—' }}</code>
            </div>
        </div>

        {{-- Change Password --}}
        <div class="card p-4 mb-4">
            <h6 class="text-white mb-3"><i class="bi bi-shield-lock me-2 text-warning"></i>Change Password</h6>
            <form method="POST" action="{{ route('dashboard.settings.update') }}">
                @csrf
                <input type="hidden" name="_action" value="password">
                <div class="mb-3">
                    <label class="form-label text-secondary small">Current Password</label>
                    <input type="password" name="current_password" class="form-control @error('current_password') is-invalid @enderror">
                    @error('current_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary small">New Password</label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror">
                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary small">Confirm New Password</label>
                    <input type="password" name="password_confirmation" class="form-control">
                </div>
                <button type="submit" class="btn btn-warning btn-sm">Update Password</button>
            </form>
        </div>

        {{-- App Info --}}
        <div class="card p-4 mb-4">
            <h6 class="text-white mb-3"><i class="bi bi-info-circle me-2 text-secondary"></i>App Info</h6>
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-secondary border-opacity-25">
                <span class="text-secondary small">App URL</span>
                <code class="text-info small">{{ config('app.url') }}</code>
            </div>
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-secondary border-opacity-25">
                <span class="text-secondary small">Environment</span>
                <span class="badge {{ config('app.env') === 'production' ? 'bg-success' : 'bg-warning text-dark' }}">{{ config('app.env') }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center py-2">
                <span class="text-secondary small">Laravel Version</span>
                <code class="text-secondary small">{{ app()->version() }}</code>
            </div>
        </div>

        {{-- Logout --}}
        <div class="card p-4">
            <h6 class="text-white mb-3"><i class="bi bi-box-arrow-right me-2 text-danger"></i>Session</h6>
            <p class="text-secondary small mb-3">Sign out of your account on this device.</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i> Logout
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
