@extends('layouts.app')

@section('title', 'AI Agents')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-robot text-info me-2"></i>AI Agents</h1>
        <p class="text-secondary small mb-0">Specialist agents that learn and improve from your content performance</p>
    </div>
    <a href="{{ route('dashboard.platforms') }}" class="btn btn-primary">
        <i class="bi bi-gear"></i> Configure AI Models
    </a>
</div>

{{-- ── Summary Stats ────────────────────── --}}
<div class="row row-cols-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">Platform Agents</div>
            <div class="fs-3 fw-bold text-info">11</div>
            <div class="text-secondary small">available</div>
        </div>
    </div>
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">Specialist Agents</div>
            <div class="fs-3 fw-bold text-warning">5</div>
            <div class="text-secondary small">SEO, HR, Caption, Strategy, Growth</div>
        </div>
    </div>
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">AI Engine</div>
            <div class="fs-3 fw-bold text-success">Active</div>
            <div class="text-secondary small">powered by your AI models</div>
        </div>
    </div>
    <div class="col">
        <div class="card p-3 h-100">
            <div class="text-secondary small mb-1">Learning</div>
            <div class="fs-3 fw-bold text-primary">RAG</div>
            <div class="text-secondary small">retrieval-augmented</div>
        </div>
    </div>
</div>

{{-- ── Platform Agents ──────────────────── --}}
<h5 class="mb-3"><i class="bi bi-share-fill text-info me-2"></i>Platform Agents</h5>
<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 mb-4">

    @php
    $platformAgents = [
        ['name' => 'Instagram Agent', 'icon' => 'bi-instagram', 'color' => '#e1306c', 'desc' => 'Crafts reels captions, stories, and carousel posts optimised for Instagram algorithm.'],
        ['name' => 'Facebook Agent', 'icon' => 'bi-facebook', 'color' => '#1877f2', 'desc' => 'Creates engaging posts, events, and ad copy tailored to Facebook audiences.'],
        ['name' => 'YouTube Agent', 'icon' => 'bi-youtube', 'color' => '#ff0000', 'desc' => 'Writes video titles, descriptions, tags, and community post scripts.'],
        ['name' => 'LinkedIn Agent', 'icon' => 'bi-linkedin', 'color' => '#0a66c2', 'desc' => 'Produces professional articles, thought-leadership posts, and job updates.'],
        ['name' => 'TikTok Agent', 'icon' => 'bi-tiktok', 'color' => '#ffffff', 'desc' => 'Generates trending hooks, captions, and hashtag strategies for short-form video.'],
        ['name' => 'Twitter / X Agent', 'icon' => 'bi-twitter-x', 'color' => '#ffffff', 'desc' => 'Writes threads, tweet copy, and reply suggestions optimised for engagement.'],
        ['name' => 'Pinterest Agent', 'icon' => 'bi-pinterest', 'color' => '#e60023', 'desc' => 'Creates pin titles, board descriptions, and keyword-rich captions.'],
        ['name' => 'Snapchat Agent', 'icon' => 'bi-snapchat', 'color' => '#fffc00', 'desc' => 'Designs story scripts and spotlight captions for the Snapchat audience.'],
        ['name' => 'Threads Agent', 'icon' => 'bi-threads', 'color' => '#ffffff', 'desc' => 'Generates conversation-starting posts for Meta Threads platform.'],
        ['name' => 'Google My Business Agent', 'icon' => 'bi-geo-alt-fill', 'color' => '#00d4ff', 'desc' => 'Writes local business posts, updates, and event listings for Google Maps.'],
        ['name' => 'Telegram Bot Agent', 'icon' => 'bi-telegram', 'color' => '#0088cc', 'desc' => 'Manages your Telegram bot, approves posts, and responds to commands.'],
    ];
    @endphp

    @foreach($platformAgents as $agent)
    <div class="col">
        <div class="card p-3 h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:42px;height:42px;background:rgba(255,255,255,0.07);">
                    <i class="bi {{ $agent['icon'] }} fs-5" style="color:{{ $agent['color'] }}"></i>
                </div>
                <div>
                    <div class="fw-semibold small">{{ $agent['name'] }}</div>
                    <span class="badge bg-success" style="font-size:.65rem;">Active</span>
                </div>
            </div>
            <p class="text-secondary small mb-0" style="line-height:1.5;">{{ $agent['desc'] }}</p>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Specialist Agents ────────────────── --}}
<h5 class="mb-3"><i class="bi bi-stars text-warning me-2"></i>Specialist Agents</h5>
<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 mb-4">

    @php
    $specialistAgents = [
        ['name' => 'Caption Writer', 'icon' => 'bi-pencil-square', 'color' => '#7c3aed', 'desc' => 'Generates platform-specific captions, hooks, and calls-to-action from uploaded media.'],
        ['name' => 'Hashtag Researcher', 'icon' => 'bi-hash', 'color' => '#10b981', 'desc' => 'Finds trending and niche hashtags to maximise organic reach for each platform.'],
        ['name' => 'Content Strategist', 'icon' => 'bi-bar-chart-steps', 'color' => '#f59e0b', 'desc' => 'Builds content calendars, posting strategies, and growth plans based on analytics.'],
        ['name' => 'Growth Hacker', 'icon' => 'bi-graph-up-arrow', 'color' => '#00d4ff', 'desc' => 'Analyses post performance and suggests optimisations to accelerate follower growth.'],
        ['name' => 'Content Recycler', 'icon' => 'bi-recycle', 'color' => '#ec4899', 'desc' => 'Repurposes top-performing posts across platforms with format-appropriate adaptations.'],
    ];
    @endphp

    @foreach($specialistAgents as $agent)
    <div class="col">
        <div class="card p-3 h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:42px;height:42px;background:rgba(255,255,255,0.07);">
                    <i class="bi {{ $agent['icon'] }} fs-5" style="color:{{ $agent['color'] }}"></i>
                </div>
                <div>
                    <div class="fw-semibold small">{{ $agent['name'] }}</div>
                    <span class="badge bg-success" style="font-size:.65rem;">Active</span>
                </div>
            </div>
            <p class="text-secondary small mb-0" style="line-height:1.5;">{{ $agent['desc'] }}</p>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Train Agent from GitHub ──────────── --}}
<div class="card p-4 mb-4">
    <h5 class="mb-3"><i class="bi bi-github text-info me-2"></i>Train Agent from GitHub</h5>
    <p class="text-secondary small mb-3">Point an agent at a GitHub repository to learn custom workflows, brand guidelines, or product documentation.</p>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label small">GitHub Repository URL</label>
            <input type="url" id="trainRepoUrl" class="form-control" placeholder="https://github.com/owner/repo">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label small">Target Agent</label>
            <select id="trainPlatform" class="form-select">
                <option value="">Select agent...</option>
                <option value="instagram">Instagram Agent</option>
                <option value="facebook">Facebook Agent</option>
                <option value="youtube">YouTube Agent</option>
                <option value="linkedin">LinkedIn Agent</option>
                <option value="tiktok">TikTok Agent</option>
                <option value="twitter">Twitter / X Agent</option>
                <option value="pinterest">Pinterest Agent</option>
                <option value="snapchat">Snapchat Agent</option>
                <option value="threads">Threads Agent</option>
                <option value="seo">SEO Agent</option>
                <option value="hr">HR Agent</option>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100" id="trainBtn" onclick="trainFromRepo(this)">
                <i class="bi bi-rocket-takeoff"></i> Train
            </button>
        </div>
    </div>
    <div id="trainResult" class="mt-3"></div>
</div>

{{-- ── GitHub Train Modal ──────────────── --}}
<div class="modal fade" id="trainModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-github text-info me-2"></i>Training Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="trainModalBody">
                <div class="text-center py-3">
                    <div class="spinner-border text-info" role="status"></div>
                    <div class="mt-2 text-secondary">Training in progress...</div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
async function trainFromRepo(btn) {
    const repoUrl = document.getElementById('trainRepoUrl').value.trim();
    const platform = document.getElementById('trainPlatform').value;
    if (!repoUrl || !platform) {
        showToast('Please enter a repository URL and select an agent.', 'warning');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Training...';
    document.getElementById('trainResult').innerHTML = '';

    try {
        const data = await ajaxPost('/bot-training/train', { source_type: 'url', url: repoUrl, agent: platform }, btn);
        document.getElementById('trainResult').innerHTML = `
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill me-2"></i>
                Agent trained successfully! The <strong>${platform}</strong> agent has learned from the repository.
            </div>`;
        showToast('Agent training complete!', 'success');
    } catch(e) {
        document.getElementById('trainResult').innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Training failed. Please check the repository URL and try again.
            </div>`;
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-rocket-takeoff"></i> Train';
}
</script>
@endpush
