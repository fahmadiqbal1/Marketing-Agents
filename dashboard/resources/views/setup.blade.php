@extends('layouts.app')

@section('title', 'Setup Your Business')

@section('content')
<div style="max-width: 720px; margin: 0 auto;">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2" style="text-align: center;">
        <h1 class="page-title h3 mb-0">Welcome! Let's set up your marketing hub</h1>
        <p class="page-subtitle text-secondary small mb-0">Complete these steps to get started — takes about 2 minutes</p>
    </div>

    {{-- Wizard Steps --}}
    <div class="wizard-steps">
        <div class="wizard-step active" id="ws-1">
            <div class="wizard-step-circle">1</div>
            <span class="wizard-step-label">Business</span>
        </div>
        <div class="wizard-connector" id="wc-1"></div>
        <div class="wizard-step" id="ws-2">
            <div class="wizard-step-circle">2</div>
            <span class="wizard-step-label">Brand</span>
        </div>
        <div class="wizard-connector" id="wc-2"></div>
        <div class="wizard-step" id="ws-3">
            <div class="wizard-step-circle">3</div>
            <span class="wizard-step-label">Platforms</span>
        </div>
    </div>

    {{-- Step 1: Business Info --}}
    <div class="card p-4 mb-4" style="padding: 2rem;" id="step-1">
        <h3 style="color: #fff; font-size: 1.1rem; margin: 0 0 1.5rem;">
            <i class="bi bi-building" style="color: #00d4ff;"></i> Business Information
        </h3>

        <form id="setup-form">
            @csrf
            <div class="form-group">
                <label class="form-label">Business Name *</label>
                <input type="text" name="name" class="form-control" placeholder="My Business" required>
            </div>

            <div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
                <div class="form-group">
                    <label class="form-label">Industry</label>
                    <select name="industry" class="form-control">
                        <option value="">Select…</option>
                        <option value="healthcare">Healthcare</option>
                        <option value="retail">Retail / E-commerce</option>
                        <option value="restaurant">Restaurant / Food</option>
                        <option value="realestate">Real Estate</option>
                        <option value="fitness">Fitness / Wellness</option>
                        <option value="education">Education</option>
                        <option value="tech">Technology / SaaS</option>
                        <option value="agency">Marketing Agency</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Timezone</label>
                    <select name="timezone" class="form-control">
                        <option value="UTC">UTC</option>
                        <option value="America/New_York">Eastern (US)</option>
                        <option value="America/Chicago">Central (US)</option>
                        <option value="America/Denver">Mountain (US)</option>
                        <option value="America/Los_Angeles">Pacific (US)</option>
                        <option value="Europe/London">London (GMT)</option>
                        <option value="Europe/Paris">Paris (CET)</option>
                        <option value="Asia/Dubai">Dubai (GST)</option>
                        <option value="Asia/Kolkata">India (IST)</option>
                        <option value="Asia/Shanghai">China (CST)</option>
                        <option value="Asia/Tokyo">Tokyo (JST)</option>
                    </select>
                </div>
            </div>

            <div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
                <div class="form-group">
                    <label class="form-label">Website</label>
                    <input type="url" name="website" class="form-control" placeholder="https://example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="+1 555 0123">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" placeholder="123 Main St, City, State">
            </div>

            <div style="text-align: right; margin-top: 1rem;">
                <button type="button" class="btn btn-primary" onclick="goToStep(2)">
                    Next: Brand Voice <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
    </div>

    {{-- Step 2: Brand Voice --}}
    <div class="card p-4 mb-4" style="padding: 2rem; display: none;" id="step-2">
        <h3 style="color: #fff; font-size: 1.1rem; margin: 0 0 1.5rem;">
            <i class="bi bi-palette" style="color: #7c3aed;"></i> Brand Voice & Style
        </h3>

        <div class="form-group">
            <label class="form-label">Describe your brand voice</label>
            <textarea name="brand_voice" class="form-control" rows="4" placeholder="E.g. Professional but friendly, like a trusted advisor. We use simple language and avoid jargon. Our tone is warm, empathetic, and encouraging."></textarea>
            <div style="font-size: .7rem; color: rgba(255,255,255,.3); margin-top: .4rem;">
                This helps the AI write captions that match your brand personality
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Key services / products (one per line)</label>
            <textarea name="services" class="form-control" rows="3" placeholder="Web Development&#10;Mobile Apps&#10;UI/UX Design"></textarea>
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 1rem;">
            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(1)">
                <i class="bi bi-arrow-left"></i> Back
            </button>
            <button type="button" class="btn btn-primary" onclick="goToStep(3)">
                Next: Connect Platforms <i class="bi bi-arrow-right"></i>
            </button>
        </div>
    </div>

    {{-- Step 3: Quick Platform Connect --}}
    <div class="card p-4 mb-4" style="padding: 2rem; display: none;" id="step-3">
        <h3 style="color: #fff; font-size: 1.1rem; margin: 0 0 .5rem;">
            <i class="bi bi-share-fill" style="color: #00d4ff;"></i> Connect Your Platforms
        </h3>
        <p style="color: rgba(255,255,255,.4); font-size: .85rem; margin-bottom: 1.5rem;">
            You can skip this and connect platforms later from the Platforms page
        </p>

        <div class="grid-3" style="margin-bottom: 1.5rem;">
            @php
                $platforms = [
                    ['key' => 'instagram', 'name' => 'Instagram', 'icon' => 'bi-instagram'],
                    ['key' => 'facebook', 'name' => 'Facebook', 'icon' => 'bi-facebook'],
                    ['key' => 'youtube', 'name' => 'YouTube', 'icon' => 'bi-youtube'],
                    ['key' => 'linkedin', 'name' => 'LinkedIn', 'icon' => 'bi-linkedin'],
                    ['key' => 'tiktok', 'name' => 'TikTok', 'icon' => 'bi-tiktok'],
                    ['key' => 'twitter', 'name' => 'Twitter/X', 'icon' => 'bi-twitter-x'],
                ];
            @endphp
            @foreach($platforms as $p)
                <div class="card p-3" style="padding: 1rem; text-align: center; cursor: pointer; transition: all .2s;" onclick="window.location='{{ route('dashboard.platforms') }}?connect={{ $p['key'] }}'">
                    <div class="badge rounded-2 p-2 platform-{{ $p['key'] }}" style="margin: 0 auto .5rem;">
                        <i class="bi {{ $p['icon'] }}"></i>
                    </div>
                    <div style="font-size: .8rem; color: rgba(255,255,255,.6);">{{ $p['name'] }}</div>
                </div>
            @endforeach
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 1rem;">
            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">
                <i class="bi bi-arrow-left"></i> Back
            </button>
            <button type="button" class="btn btn-primary" onclick="finishSetup()">
                <i class="bi bi-check-lg"></i> Finish Setup
            </button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    let currentStep = 1;

    function goToStep(step) {
        document.getElementById('step-' + currentStep).style.display = 'none';
        document.getElementById('step-' + step).style.display = 'block';

        // Update wizard indicators
        for (let i = 1; i <= 3; i++) {
            const ws = document.getElementById('ws-' + i);
            ws.classList.remove('active', 'completed');
            if (i < step) ws.classList.add('completed');
            else if (i === step) ws.classList.add('active');

            const wc = document.getElementById('wc-' + i);
            if (wc) {
                wc.classList.toggle('completed', i < step);
            }
        }

        currentStep = step;
    }

    async function finishSetup() {
        const form = document.getElementById('setup-form');
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        delete data._token;

        try {
            const token = document.querySelector('meta[name="csrf-token"]').content;
            const resp = await fetch('/setup', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(data),
            });

            if (resp.ok) {
                window.location.href = '/';
            } else {
                const err = await resp.json();
                alert(err.message || 'Setup failed');
            }
        } catch (e) {
            alert('Error: ' + e.message);
        }
    }
</script>
@endpush
@endsection
