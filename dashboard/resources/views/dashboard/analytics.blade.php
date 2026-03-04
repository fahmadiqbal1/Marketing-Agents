@extends('layouts.app')

@section('title', 'Analytics')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white">Analytics</h1>
        <p class="text-secondary small mb-0">Track your marketing performance across all platforms</p>
    </div>
    <div class="d-flex gap-2">
        @foreach([7, 14, 30] as $d)
            <a href="{{ route('dashboard.analytics', ['days' => $d]) }}"
               class="btn btn-sm {{ (int)$days === $d ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $d }}d</a>
        @endforeach
    </div>
</div>

{{-- Stat Cards --}}
<div class="row row-cols-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">Posts Published</div>
            <div class="stat-value">{{ $analytics['posts_published'] ?? 0 }}</div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">Total Reach</div>
            <div class="stat-value">{{ number_format($analytics['total_reach'] ?? 0) }}</div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">Total Engagement</div>
            <div class="stat-value">{{ number_format($analytics['total_engagement'] ?? 0) }}</div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">Avg. Engagement Rate</div>
            <div class="stat-value">{{ number_format($analytics['avg_engagement_rate'] ?? 0, 1) }}%</div>
        </div>
    </div>
</div>

{{-- Charts Row --}}
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <h6 class="mb-3 text-white"><i class="bi bi-graph-up text-info me-1"></i> Reach Over Time</h6>
            <div class="chart-container"><canvas id="reachChart"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <h6 class="mb-3 text-white"><i class="bi bi-pie-chart-fill me-1" style="color:#7c3aed;"></i> Engagement by Platform</h6>
            <div class="chart-container"><canvas id="platformChart"></canvas></div>
        </div>
    </div>
</div>

{{-- Content Pillar Balance --}}
<div class="card p-4 mb-4">
    <h6 class="mb-3 text-white"><i class="bi bi-columns-gap me-1" style="color:#10b981;"></i> Content Pillar Balance</h6>
    @php $pillars = is_array($pillarBalance) && !isset($pillarBalance['error']) ? $pillarBalance : []; @endphp
    @if(count($pillars) > 0)
        <div class="row row-cols-2 row-cols-xl-4 g-3">
            @foreach($pillars as $pillar => $data)
                @php
                    $pct = is_array($data) ? ($data['percentage'] ?? 0) : $data;
                    $target = is_array($data) ? ($data['target'] ?? 25) : 25;
                    $diff = $pct - $target;
                    $barColor = ($diff >= -5 && $diff <= 5) ? '#10b981' : ($diff < -5 ? '#f59e0b' : '#00d4ff');
                @endphp
                <div class="col">
                    <div class="card p-3 text-center h-100">
                        <div class="text-uppercase text-secondary mb-2" style="font-size:.72rem;letter-spacing:.06em;">{{ $pillar }}</div>
                        <div class="fs-4 fw-bold text-white">{{ number_format($pct, 0) }}%</div>
                        <div class="progress my-2" style="height:4px;background:rgba(255,255,255,.06);">
                            <div class="progress-bar" style="width:{{ min($pct,100) }}%;background:{{ $barColor }};"></div>
                        </div>
                        <div class="text-secondary" style="font-size:.7rem;">Target: {{ $target }}%</div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-4 text-secondary">
            <i class="bi bi-bar-chart fs-2 d-block mb-2"></i>
            Not enough data to display pillar balance yet
        </div>
    @endif
</div>

{{-- Top Performing Posts --}}
<div class="card p-4 mb-4">
    <h6 class="mb-3 text-white"><i class="bi bi-trophy-fill me-1" style="color:#f59e0b;"></i> Top Performing Posts</h6>
    @php $topPosts = $analytics['top_posts'] ?? []; @endphp
    @forelse($topPosts as $post)
        <div class="d-flex align-items-center gap-3 py-2 border-bottom border-secondary border-opacity-25">
            <div class="badge rounded-2 p-2 flex-shrink-0" style="background:rgba(255,255,255,.08);width:32px;height:32px;font-size:.75rem;">
                <i class="bi bi-{{ $post['platform'] ?? 'globe' }}"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="text-white small text-truncate">{{ Str::limit($post['caption'] ?? $post['title'] ?? 'Untitled', 60) }}</div>
                <div class="text-secondary" style="font-size:.7rem;">{{ $post['published_at'] ?? '' }}</div>
            </div>
            <div class="text-end flex-shrink-0">
                <div class="text-info small fw-semibold">{{ number_format($post['engagement'] ?? $post['likes'] ?? 0) }}</div>
                <div class="text-secondary" style="font-size:.65rem;">engagements</div>
            </div>
        </div>
    @empty
        <div class="text-center py-4 text-secondary">No performance data yet — publish some posts first!</div>
    @endforelse
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const reachCtx = document.getElementById('reachChart');
    if (reachCtx) {
        const reachData = @json($analytics['reach_timeline'] ?? []);
        new Chart(reachCtx, {
            type: 'bar',
            data: {
                labels: reachData.map(d => d.date || d.label || ''),
                datasets: [{ label: 'Reach', data: reachData.map(d => d.reach || d.value || 0),
                    backgroundColor: 'rgba(0,212,255,0.3)', borderColor: '#00d4ff', borderWidth: 1, borderRadius: 6 }]
            },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 11 } } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 11 } } }
                }
            }
        });
    }
    const platCtx = document.getElementById('platformChart');
    if (platCtx) {
        const platData = @json($analytics['platform_breakdown'] ?? []);
        new Chart(platCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(platData).map(l => l.charAt(0).toUpperCase() + l.slice(1)),
                datasets: [{ data: Object.values(platData),
                    backgroundColor: ['#E1306C','#4267B2','#FF0000','#0A66C2','#aaa','#1DA1F2','#FFFC00','#E60023'],
                    borderWidth: 0 }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '60%',
                plugins: { legend: { position: 'bottom', labels: { color: 'rgba(255,255,255,0.5)', font: { size: 11 }, padding: 14, usePointStyle: true } } }
            }
        });
    }
});
</script>
@endpush
@endsection
