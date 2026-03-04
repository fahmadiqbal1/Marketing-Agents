@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white">Dashboard</h1>
        <p class="text-secondary small mb-0">Your marketing performance at a glance</p>
    </div>
    <a href="{{ route('dashboard.posts') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Post
    </a>
</div>

{{-- ── Stat Cards ──────────────────────── --}}
<div class="row row-cols-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Total Posts</div>
                    <div class="stat-value">{{ $growthReport['total_posts'] ?? 0 }}</div>
                    <div class="stat-change up"><i class="bi bi-arrow-up"></i> {{ $growthReport['posts_this_week'] ?? 0 }} this week</div>
                </div>
                <div class="stat-icon blue"><i class="bi bi-file-earmark-post"></i></div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Engagement</div>
                    <div class="stat-value">{{ number_format($growthReport['engagement_rate'] ?? 0, 1) }}%</div>
                    @php $engDelta = $growthReport['engagement_delta'] ?? 0; @endphp
                    <div class="stat-change {{ $engDelta >= 0 ? 'up' : 'down' }}">
                        <i class="bi bi-arrow-{{ $engDelta >= 0 ? 'up' : 'down' }}"></i> {{ abs($engDelta) }}% vs last week
                    </div>
                </div>
                <div class="stat-icon purple"><i class="bi bi-heart-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Followers</div>
                    <div class="stat-value">{{ number_format($growthReport['total_followers'] ?? 0) }}</div>
                    @php $fDelta = $growthReport['follower_growth'] ?? 0; @endphp
                    <div class="stat-change {{ $fDelta >= 0 ? 'up' : 'down' }}">
                        <i class="bi bi-arrow-{{ $fDelta >= 0 ? 'up' : 'down' }}"></i> {{ number_format(abs($fDelta)) }} new
                    </div>
                </div>
                <div class="stat-icon green"><i class="bi bi-people-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Platforms</div>
                    @php
                        $activePlatforms = $platforms instanceof \Illuminate\Database\Eloquent\Collection
                            ? $platforms->where('connected', true)->count()
                            : (is_array($platforms) ? count(array_filter($platforms, fn($p) => ($p['connected'] ?? false))) : 0);
                    @endphp
                    <div class="stat-value">{{ $activePlatforms }}</div>
                    <div class="stat-change" style="color:#00d4ff;">connected</div>
                </div>
                <div class="stat-icon amber"><i class="bi bi-share-fill"></i></div>
            </div>
        </div>
    </div>
</div>

{{-- ── Main Charts Row ──────────────────── --}}
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-7">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 text-white">Engagement Trend</h6>
                <span class="badge bg-info text-dark">7 days</span>
            </div>
            <div class="chart-container">
                <canvas id="engagementChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 text-white">Content Mix</h6>
                <span class="badge bg-secondary">by pillar</span>
            </div>
            <div class="chart-container">
                <canvas id="contentMixChart"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- ── Bottom Row ─────────────────────── --}}
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 text-white"><i class="bi bi-calendar3 text-info me-1"></i> Upcoming Posts</h6>
                <a href="{{ route('dashboard.posts') }}" class="btn btn-outline-secondary btn-sm">View All</a>
            </div>
            @php
                $upcoming = $schedule instanceof \Illuminate\Database\Eloquent\Collection
                    ? $schedule->take(5)
                    : (is_array($schedule) ? array_slice($schedule, 0, 5) : []);
            @endphp
            @forelse($upcoming as $post)
                <div class="d-flex align-items-center gap-2 py-2 border-bottom border-secondary border-opacity-25">
                    <div class="badge rounded-2 p-2 flex-shrink-0" style="background:rgba(255,255,255,.08);width:32px;height:32px;font-size:.8rem;">
                        <i class="bi bi-{{ $post['platform'] ?? ($post->platform ?? 'globe') }}"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="text-white small text-truncate">
                            {{ Str::limit($post['caption'] ?? $post['title'] ?? ($post->caption ?? $post->title ?? 'Untitled'), 50) }}
                        </div>
                        <div class="text-secondary" style="font-size:.7rem;">
                            {{ $post['scheduled_at'] ?? ($post->scheduled_at ?? 'Pending') }}
                        </div>
                    </div>
                    <span class="badge bg-secondary">{{ $post['status'] ?? ($post->status ?? 'pending') }}</span>
                </div>
            @empty
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>
                    No upcoming posts scheduled
                </div>
            @endforelse
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 text-white"><i class="bi bi-bar-chart-fill me-1" style="color:#7c3aed;"></i> Platform Performance</h6>
            </div>
            @php
                $platformList = $platforms instanceof \Illuminate\Database\Eloquent\Collection
                    ? $platforms
                    : (is_array($platforms) && !isset($platforms['error']) ? $platforms : []);
            @endphp
            @forelse($platformList as $p)
                <div class="d-flex align-items-center gap-2 py-2 border-bottom border-secondary border-opacity-25">
                    <div class="badge rounded-2 p-2 flex-shrink-0" style="background:rgba(255,255,255,.08);width:32px;height:32px;font-size:.8rem;">
                        <i class="bi bi-{{ $p['key'] ?? ($p->key ?? 'globe') }}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-white small">{{ ucfirst($p['key'] ?? ($p->key ?? $p->name ?? 'Unknown')) }}</div>
                        <div class="text-secondary" style="font-size:.7rem;">
                            {{ number_format($p['followers'] ?? 0) }} followers
                        </div>
                    </div>
                    @php $connected = $p['connected'] ?? ($p->connected ?? false); @endphp
                    <span class="badge {{ $connected ? 'bg-success' : 'bg-danger' }}">
                        {{ $connected ? 'Connected' : 'Offline' }}
                    </span>
                </div>
            @empty
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-share fs-2 d-block mb-2"></i>
                    No platforms connected yet<br>
                    <a href="{{ route('dashboard.platforms') }}" class="btn btn-primary btn-sm mt-3">Connect Platforms</a>
                </div>
            @endforelse
        </div>
    </div>
</div>

{{-- ── AI Insights ────────────────────── --}}
<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-white"><i class="bi bi-stars me-1" style="color:#f59e0b;"></i> AI Insights</h6>
        <button class="btn btn-outline-secondary btn-sm" onclick="loadInsights()" id="insights-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
    <div id="insights-container" class="d-flex flex-wrap gap-3">
        <div class="text-center py-4 text-secondary w-100">
            <i class="bi bi-lightbulb fs-4 d-block mb-2"></i>
            Click "Refresh" to generate insights based on your data
        </div>
    </div>
</div>

@push('scripts')
<script>
async function loadInsights() {
    const container = document.getElementById('insights-container');
    const btn = document.getElementById('insights-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';
    container.innerHTML = '<div class="text-center py-3 text-secondary w-100">Analyzing your data...</div>';
    try {
        const resp = await fetch('/insights?days=7', { headers: {'Accept': 'application/json'} });
        const data = await resp.json();
        let html = '';
        if (data.summary) {
            html += `<div class="card p-3 flex-fill" style="min-width:180px;">
                <div class="text-secondary small mb-1">This Week</div>
                <div class="fs-4 fw-bold text-white">${data.summary.total_posts || 0} posts</div>
                <div class="text-secondary small">${data.summary.total_likes || 0} likes</div>
            </div>`;
        }
        if (data.recommendations && data.recommendations.length > 0) {
            const typeColors = { success: '#10b981', warning: '#f59e0b', danger: '#ef4444', info: '#6366f1' };
            const typeIcons = { success: 'check-circle-fill', warning: 'exclamation-triangle-fill', danger: 'x-circle-fill', info: 'info-circle-fill' };
            data.recommendations.forEach(rec => {
                const c = typeColors[rec.type] || typeColors.info;
                const ic = typeIcons[rec.type] || typeIcons.info;
                html += `<div class="card p-3 flex-fill" style="min-width:250px;border-left:3px solid ${c};">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-${ic}" style="color:${c};"></i>
                        <span class="small fw-semibold text-white">${rec.title}</span>
                    </div>
                    <div class="text-secondary" style="font-size:.78rem;">${rec.message}</div>
                </div>`;
            });
        }
        container.innerHTML = html || '<div class="text-secondary py-2">No insights yet. Start publishing content!</div>';
    } catch (e) {
        container.innerHTML = '<div class="text-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> Could not load insights.</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Engagement Trend chart
    const engCtx = document.getElementById('engagementChart');
    if (engCtx) {
        new Chart(engCtx, {
            type: 'line',
            data: {
                labels: {!! json_encode($growthReport['chart_labels'] ?? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']) !!},
                datasets: [{
                    label: 'Engagement',
                    data: {!! json_encode($growthReport['chart_data'] ?? [0,0,0,0,0,0,0]) !!},
                    borderColor: '#00d4ff',
                    backgroundColor: 'rgba(0,212,255,0.08)',
                    borderWidth: 2, fill: true, tension: 0.4,
                    pointBackgroundColor: '#00d4ff', pointRadius: 3, pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 11 } } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 11 } } }
                }
            }
        });
    }

    // Content Mix doughnut chart
    const mixCtx = document.getElementById('contentMixChart');
    if (mixCtx) {
        new Chart(mixCtx, {
            type: 'doughnut',
            data: {
                labels: {!! json_encode(array_keys($growthReport['pillar_breakdown'] ?? ['Education' => 1, 'Promotion' => 1, 'Engagement' => 1, 'Culture' => 1])) !!},
                datasets: [{
                    data: {!! json_encode(array_values($growthReport['pillar_breakdown'] ?? [25, 25, 25, 25])) !!},
                    backgroundColor: ['#00d4ff', '#7c3aed', '#10b981', '#f59e0b', '#ef4444', '#ec4899'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: 'rgba(255,255,255,0.5)', font: { size: 11 }, padding: 16, usePointStyle: true, pointStyleWidth: 8 }
                    }
                }
            }
        });
    }
});
</script>
@endpush
@endsection
