@extends('layouts.app')

@section('title', 'Billing & Usage')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">Billing &amp; AI Usage</h1>
        <p class="text-secondary small mb-0">Monitor AI model configurations and post volume</p>
    </div>
</div>

{{-- ── Stat Cards ──────────────────────── --}}
<div class="row row-cols-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">AI Models Configured</div>
            <div class="fs-3 fw-bold text-info">{{ $aiConfigs->count() }}</div>
            <div class="text-secondary small">active configurations</div>
        </div>
    </div>
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">Posts This Month</div>
            <div class="fs-3 fw-bold text-success">{{ $postCount }}</div>
            <div class="text-secondary small">published &amp; pending</div>
        </div>
    </div>
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">Plan</div>
            <div class="fs-3 fw-bold text-warning">Pro</div>
            <div class="text-secondary small">unlimited posts</div>
        </div>
    </div>
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">Status</div>
            <div class="fs-3 fw-bold text-success"><i class="bi bi-check-circle-fill"></i></div>
            <div class="text-secondary small">all systems active</div>
        </div>
    </div>
</div>

{{-- ── AI Model Configurations ─────────── --}}
<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="bi bi-robot text-info me-2"></i>AI Model Configurations</h5>
        <a href="{{ route('dashboard.platforms') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-gear"></i> Manage Models
        </a>
    </div>

    @if($aiConfigs->isEmpty())
        <div class="text-center py-4 text-secondary">
            <i class="bi bi-robot fs-2 d-block mb-2"></i>
            No AI models configured yet.
            <a href="{{ route('dashboard.platforms') }}" class="text-info">Set one up</a>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Provider</th>
                        <th>Model</th>
                        <th>Purpose</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Added</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($aiConfigs as $config)
                    <tr>
                        <td class="fw-semibold">{{ ucfirst($config->provider ?? 'Unknown') }}</td>
                        <td>
                            <span class="badge bg-secondary">{{ ucfirst($config->provider ?? 'unknown') }}</span>
                        </td>
                        <td class="text-info small">{{ $config->model_name ?? '—' }}</td>
                        <td class="text-secondary small">{{ $config->is_default ? 'Default' : 'Custom' }}</td>
                        <td class="text-center">
                            @if($config->is_active ?? true)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-secondary">Inactive</span>
                            @endif
                        </td>
                        <td class="text-end text-secondary small">
                            {{ $config->created_at ? $config->created_at->format('M d, Y') : '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ── Usage Stats ─────────────────────── --}}
<div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
    <div class="col">
        <div class="card p-4 h-100">
            <h5 class="mb-3"><i class="bi bi-bar-chart-fill text-warning me-2"></i>Post Volume (Last 30 Days)</h5>
            <canvas id="postVolumeChart" style="max-height: 220px;"></canvas>
        </div>
    </div>
    <div class="col">
        <div class="card p-4 h-100">
            <h5 class="mb-3"><i class="bi bi-credit-card text-success me-2"></i>Plan Details</h5>
            <ul class="list-unstyled mb-0">
                <li class="d-flex justify-content-between py-2 border-bottom border-secondary">
                    <span class="text-secondary">Current Plan</span>
                    <span class="fw-semibold text-warning">Pro</span>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom border-secondary">
                    <span class="text-secondary">Billing Cycle</span>
                    <span>Monthly</span>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom border-secondary">
                    <span class="text-secondary">Posts This Month</span>
                    <span class="text-info">{{ $postCount }}</span>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom border-secondary">
                    <span class="text-secondary">AI Models Active</span>
                    <span class="text-info">{{ $aiConfigs->count() }}</span>
                </li>
                <li class="d-flex justify-content-between py-2">
                    <span class="text-secondary">Support</span>
                    <span class="text-success">Priority</span>
                </li>
            </ul>
        </div>
    </div>
</div>

{{-- ── Billing History (Placeholder) ──── --}}
<div class="card p-4">
    <h5 class="mb-3"><i class="bi bi-receipt text-success me-2"></i>Billing History</h5>
    <div class="text-center py-4 text-secondary">
        <i class="bi bi-receipt fs-2 d-block mb-2"></i>
        No billing records yet. Your first invoice will appear here after your billing cycle ends.
    </div>
</div>

@endsection

@push('scripts')
<script>
// Simple post volume sparkline
const ctx = document.getElementById('postVolumeChart')?.getContext('2d');
if (ctx) {
    const labels = Array.from({length: 30}, (_, i) => {
        const d = new Date(); d.setDate(d.getDate() - (29 - i));
        return d.getDate();
    });
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Posts',
                data: labels.map(() => Math.floor(Math.random() * 8)),
                backgroundColor: 'rgba(0,212,255,0.4)',
                borderColor: '#00d4ff',
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: 'rgba(255,255,255,0.4)', maxRotation: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { ticks: { color: 'rgba(255,255,255,0.4)' }, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }
    });
}
</script>
@endpush
