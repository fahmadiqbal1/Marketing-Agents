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

{{-- ── Orchestrator Panel ──────────────────────────── --}}
<div class="card mb-4 border-0" style="background:linear-gradient(135deg,#1e0a3c,#16213e);border:1px solid rgba(124,58,237,.3) !important;">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:rgba(124,58,237,.2);">
                <i class="bi bi-cpu-fill fs-3" style="color:#a78bfa;"></i>
            </div>
            <div class="flex-grow-1">
                <h5 class="text-white mb-0">Marketing Orchestrator <span id="orchStatusBadge" class="badge bg-secondary ms-2" style="font-size:.65rem;">Loading…</span></h5>
                <p class="text-secondary small mb-0">The central brain — routes tasks to the best AI, learns marketing skills, and coordinates all sub-agents</p>
            </div>
            <button class="btn btn-sm" style="background:#7c3aed;color:#fff;" onclick="openConfigOrchModal()">
                <i class="bi bi-sliders me-1"></i>Configure
            </button>
        </div>

        <div class="row g-3 mb-3" id="orchStats">
            <div class="col-6 col-md-3">
                <div class="rounded-3 p-3 text-center" style="background:rgba(255,255,255,.05);">
                    <div class="fs-4 fw-bold text-white" id="orchModelName">—</div>
                    <div class="text-secondary small">Active Brain</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rounded-3 p-3 text-center" style="background:rgba(255,255,255,.05);">
                    <div class="fs-4 fw-bold text-info" id="orchModelsCount">0</div>
                    <div class="text-secondary small">Available Models</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rounded-3 p-3 text-center" style="background:rgba(255,255,255,.05);">
                    <div class="fs-4 fw-bold text-success" id="orchVerifiedCount">0</div>
                    <div class="text-secondary small">Verified</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rounded-3 p-3 text-center" style="background:rgba(255,255,255,.05);">
                    <div class="fs-4 fw-bold text-warning" id="orchInsightsCount">0</div>
                    <div class="text-secondary small">Skill Insights</div>
                </div>
            </div>
        </div>

        {{-- Orchestrator Plan Interface --}}
        <div class="mb-3">
            <label class="form-label text-secondary small fw-semibold">Ask the Orchestrator to Plan a Marketing Campaign</label>
            <div class="input-group">
                <input type="text" id="orchGoalInput" class="form-control" placeholder="e.g. Launch a new product on Instagram and TikTok targeting Gen-Z">
                <button class="btn" style="background:#7c3aed;color:#fff;" id="orchPlanBtn" onclick="runOrchestratorPlan()">
                    <i class="bi bi-send me-1"></i>Plan
                </button>
            </div>
        </div>
        <div id="orchPlanResult" class="d-none"></div>

        {{-- Skill Profile --}}
        <div class="mt-3">
            <button class="btn btn-sm btn-outline-secondary" onclick="loadOrchestratorSkills()">
                <i class="bi bi-brain me-1"></i>View Skill Profile
            </button>
        </div>
        <div id="orchSkillsPanel" class="mt-3 d-none"></div>
    </div>
</div>

{{-- Orchestrator Configure Modal --}}
<div class="modal fade" id="orchConfigModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-cpu-fill me-2" style="color:#a78bfa;"></i>Configure Orchestrator</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small">Select the AI model that will act as the Orchestrator — the brain that routes tasks to all other sub-agents. <strong>Ollama (local)</strong> is recommended for full privacy and free operation.</p>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Select Orchestrator Model</label>
                    <select id="orchModelSelect" class="form-select">
                        <option value="">Loading models…</option>
                    </select>
                    <div class="form-text text-secondary">Tip: Use a fast, free model (Ollama llama3, Groq, Gemini Flash) or your best available model for smartest routing.</div>
                </div>
                <div class="alert alert-info small py-2">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>How Ollama works as Orchestrator:</strong> Run <code>ollama pull llama3</code> locally, configure it as a model on the Platforms page, then select it here. Your data never leaves your machine.
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="setOrchestrator(this)" style="background:#7c3aed;border-color:#7c3aed;">
                    <i class="bi bi-check-lg me-1"></i>Set as Orchestrator
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Agent Skill Preview Modal --}}
<div class="modal fade" id="agentSkillModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-lightning-charge-fill me-2" style="color:#f59e0b;"></i>Skills for <span id="agentSkillModalTitle"></span> Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="agentSkillModalBody">
                <div class="text-center py-4"><span class="spinner-border text-warning"></span></div>
            </div>
            <div class="modal-footer border-secondary justify-content-between">
                <button type="button" class="btn btn-outline-danger btn-sm" id="agentSkillClearBtn" onclick="clearAgentSkills()">
                    <i class="bi bi-trash me-1"></i>Clear All Skills
                </button>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-sm ms-2" style="background:#7c3aed;color:#fff;" id="agentSkillTransferBtn" onclick="transferToCurrentAgent()">
                        <i class="bi bi-arrow-down-circle me-1"></i>Inject Skills
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Knowledge Transfer Panel ──────────────────── --}}
<div class="card mb-4" style="border:1px solid rgba(245,158,11,.2);">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:48px;height:48px;background:rgba(245,158,11,.15);">
                <i class="bi bi-arrow-down-circle-fill fs-3" style="color:#f59e0b;"></i>
            </div>
            <div class="flex-grow-1">
                <h6 class="text-white mb-0">Knowledge Transfer</h6>
                <p class="text-secondary small mb-0">Push selected Orchestrator capabilities into each platform sub-agent so they know <em>what to do</em> for their specific channel</p>
            </div>
            <button class="btn btn-sm" style="background:#f59e0b;color:#000;font-weight:600;" onclick="transferAllSkills(this)">
                <i class="bi bi-lightning-charge-fill me-1"></i>Transfer All
            </button>
        </div>

        <div class="row row-cols-2 row-cols-sm-3 row-cols-xl-4 g-2" id="agentTransferGrid">
            <div class="col-12 text-secondary small py-2">
                <span class="spinner-border spinner-border-sm me-2"></span>Loading agent skill status…
            </div>
        </div>

        <div id="transferAllResult" class="mt-3 d-none"></div>
    </div>
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
        ['name' => 'Instagram Agent',       'key' => 'instagram',        'icon' => 'bi-instagram',     'color' => '#e1306c', 'desc' => 'Crafts reels captions, stories, and carousel posts optimised for Instagram algorithm.'],
        ['name' => 'Facebook Agent',        'key' => 'facebook',         'icon' => 'bi-facebook',      'color' => '#1877f2', 'desc' => 'Creates engaging posts, events, and ad copy tailored to Facebook audiences.'],
        ['name' => 'YouTube Agent',         'key' => 'youtube',          'icon' => 'bi-youtube',       'color' => '#ff0000', 'desc' => 'Writes video titles, descriptions, tags, and community post scripts.'],
        ['name' => 'LinkedIn Agent',        'key' => 'linkedin',         'icon' => 'bi-linkedin',      'color' => '#0a66c2', 'desc' => 'Produces professional articles, thought-leadership posts, and job updates.'],
        ['name' => 'TikTok Agent',          'key' => 'tiktok',           'icon' => 'bi-tiktok',        'color' => '#ffffff', 'desc' => 'Generates trending hooks, captions, and hashtag strategies for short-form video.'],
        ['name' => 'Twitter / X Agent',     'key' => 'twitter',          'icon' => 'bi-twitter-x',     'color' => '#ffffff', 'desc' => 'Writes threads, tweet copy, and reply suggestions optimised for engagement.'],
        ['name' => 'Pinterest Agent',       'key' => 'pinterest',        'icon' => 'bi-pinterest',     'color' => '#e60023', 'desc' => 'Creates pin titles, board descriptions, and keyword-rich captions.'],
        ['name' => 'Snapchat Agent',        'key' => 'snapchat',         'icon' => 'bi-snapchat',      'color' => '#fffc00', 'desc' => 'Designs story scripts and spotlight captions for the Snapchat audience.'],
        ['name' => 'Threads Agent',         'key' => 'threads',          'icon' => 'bi-threads',       'color' => '#ffffff', 'desc' => 'Generates conversation-starting posts for Meta Threads platform.'],
        ['name' => 'Google My Business',    'key' => 'google_my_business','icon' => 'bi-geo-alt-fill', 'color' => '#00d4ff', 'desc' => 'Writes local business posts, updates, and event listings for Google Maps.'],
        ['name' => 'Telegram Bot Agent',    'key' => 'telegram',         'icon' => 'bi-telegram',      'color' => '#0088cc', 'desc' => 'Manages your Telegram bot, approves posts, and responds to commands.'],
    ];
    @endphp

    @foreach($platformAgents as $agent)
    <div class="col">
        <div class="card p-3 h-100 d-flex flex-column">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:42px;height:42px;background:rgba(255,255,255,0.07);">
                    <i class="bi {{ $agent['icon'] }} fs-5" style="color:{{ $agent['color'] }}"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold small">{{ $agent['name'] }}</div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success" style="font-size:.65rem;">Active</span>
                        <span class="badge bg-secondary skill-count-badge" id="skill-count-{{ $agent['key'] }}" style="font-size:.65rem;">0 skills</span>
                    </div>
                </div>
            </div>
            <p class="text-secondary small flex-grow-1" style="line-height:1.5;">{{ $agent['desc'] }}</p>
            <div class="d-flex gap-1 mt-2 pt-2" style="border-top:1px solid rgba(255,255,255,.07);">
                <button class="btn btn-outline-warning btn-sm flex-grow-1"
                        onclick="transferToAgent('{{ $agent['key'] }}', this)"
                        title="Inject Orchestrator capabilities into this agent">
                    <i class="bi bi-arrow-down-circle me-1"></i>Transfer Skills
                </button>
                <button class="btn btn-outline-secondary btn-sm"
                        onclick="viewAgentSkills('{{ $agent['key'] }}', '{{ $agent['name'] }}')"
                        title="View skills injected into this agent">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
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
                <option value="orchestrator">Orchestrator (Brain)</option>
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
// Domain colours — sourced from OrchestratorService::DOMAIN_COLORS (single source of truth)
const DOMAIN_COLORS = @json(\App\Services\OrchestratorService::DOMAIN_COLORS);

// ── Orchestrator ──────────────────────────────────────────────────
async function loadOrchestrator() {
    try {
        const data = await ajaxGet('/orchestrator');
        const o = data.orchestrator || {};
        const badge = document.getElementById('orchStatusBadge');
        if (o.has_orchestrator) {
            badge.className = 'badge bg-success ms-2';
            badge.textContent = 'Active';
        } else {
            badge.className = 'badge bg-warning text-dark ms-2';
            badge.textContent = 'Not configured';
        }
        document.getElementById('orchModelName').textContent = o.orchestrator_display || '—';
        document.getElementById('orchModelsCount').textContent = o.total_models ?? '0';
        document.getElementById('orchVerifiedCount').textContent = o.verified_models ?? '0';
        document.getElementById('orchInsightsCount').textContent = o.total_skill_insights ?? '0';
    } catch(e) {
        console.warn('Could not load orchestrator status', e);
    }
}

async function openConfigOrchModal() {
    // Populate model select
    try {
        const data = await ajaxGet('/ai-models');
        const models = data.models || [];
        const sel = document.getElementById('orchModelSelect');
        sel.innerHTML = '<option value="">— select AI model —</option>';
        models.forEach(m => {
            const label = (m.display_name || m.provider) + (m.model_name ? ' · ' + m.model_name : '') + (m.is_orchestrator ? ' ★' : '');
            sel.innerHTML += `<option value="${m.id}" ${m.is_orchestrator ? 'selected' : ''}>${label}</option>`;
        });
        if (models.length === 0) {
            sel.innerHTML = '<option value="">No AI models configured — add one on Platforms page</option>';
        }
    } catch(e) {
        document.getElementById('orchModelSelect').innerHTML = '<option value="">Failed to load models</option>';
    }
    new bootstrap.Modal(document.getElementById('orchConfigModal')).show();
}

async function setOrchestrator(btn) {
    const modelId = document.getElementById('orchModelSelect').value;
    if (!modelId) { showToast('Please select a model', 'warning'); return; }
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    try {
        const r = await fetch('/orchestrator/configure', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ model_id: parseInt(modelId) }),
        });
        const result = await r.json();
        if (result.success) {
            showToast('Orchestrator configured!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('orchConfigModal')).hide();
            loadOrchestrator();
        } else {
            showToast(result.message || 'Failed', 'error');
        }
    } catch(e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

async function runOrchestratorPlan() {
    const goal = document.getElementById('orchGoalInput').value.trim();
    if (!goal) { showToast('Please enter a marketing goal', 'warning'); return; }
    const btn = document.getElementById('orchPlanBtn');
    const resultEl = document.getElementById('orchPlanResult');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Planning…';
    resultEl.classList.remove('d-none');
    resultEl.innerHTML = '<div class="d-flex align-items-center gap-2 text-secondary"><span class="spinner-border spinner-border-sm"></span> Orchestrator is thinking…</div>';

    try {
        const r = await fetch('/orchestrator/plan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ goal }),
        });
        const result = await r.json();
        if (result.success && result.plan) {
            const modelLabel = result.model_used ? `<span class="badge bg-secondary ms-2">${result.model_used}</span>` : '';
            resultEl.innerHTML = `<div class="card p-3" style="background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.3);">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-cpu-fill" style="color:#a78bfa;"></i>
                    <span class="text-white small fw-semibold">Orchestrator Plan</span>${modelLabel}
                </div>
                <div class="text-secondary small" style="white-space:pre-wrap;">${result.plan}</div>
            </div>`;
        } else {
            resultEl.innerHTML = `<div class="alert alert-warning small py-2">${result.message || 'No plan generated. Configure the Orchestrator first.'}</div>`;
        }
    } catch(e) {
        resultEl.innerHTML = `<div class="alert alert-danger small py-2">Error: ${e.message}</div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

async function loadOrchestratorSkills() {
    const panel = document.getElementById('orchSkillsPanel');
    panel.classList.remove('d-none');
    panel.innerHTML = '<div class="text-secondary small"><span class="spinner-border spinner-border-sm me-2"></span>Loading skill profile…</div>';
    try {
        const data = await ajaxGet('/orchestrator/skills');
        const skills = data.skills || {};
        const domains = data.domains || {};
        let html = '<div class="row g-2">';
        for (const [key, domain] of Object.entries(domains)) {
            const s = skills[key] || { insight_count: 0, avg_confidence: 0 };
            const pct = s.avg_confidence || 0;
            html += `<div class="col-12 col-md-6">
                <div class="rounded p-2" style="background:rgba(255,255,255,.05);">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-white small fw-semibold text-capitalize">${key.replace('_', ' ')}</span>
                        <span class="text-secondary" style="font-size:.7rem;">${s.insight_count} insights · ${pct}% confidence</span>
                    </div>
                    <div class="progress" style="height:4px;">
                        <div class="progress-bar" style="width:${pct}%;background:#7c3aed;"></div>
                    </div>
                    ${s.top_insights && s.top_insights.length ? `<div class="text-secondary mt-1" style="font-size:.65rem;">${s.top_insights[0]}</div>` : ''}
                </div>
            </div>`;
        }
        html += '</div>';
        panel.innerHTML = html;
    } catch(e) {
        panel.innerHTML = `<div class="text-danger small">Failed to load skills: ${e.message}</div>`;
    }
}

// ── Train from Repo ──────────────────────────────────────────────
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
        if (platform === 'orchestrator') loadOrchestrator();
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

// ── Knowledge Transfer ──────────────────────────────────────────
let _currentTransferPlatform = null;
let _currentTransferAgentName = null;

/** Load the transfer grid showing skill counts per agent */
async function loadAgentSkillCounts() {
    try {
        const data = await ajaxGet('/orchestrator');
        const counts = data.orchestrator?.agent_skill_counts || {};
        for (const [platform, count] of Object.entries(counts)) {
            const el = document.getElementById('skill-count-' + platform);
            if (el) {
                el.textContent = count + ' skill' + (count !== 1 ? 's' : '');
                el.className = count > 0
                    ? 'badge bg-warning text-dark skill-count-badge'
                    : 'badge bg-secondary skill-count-badge';
                el.style.fontSize = '.65rem';
            }
        }
        renderTransferGrid(counts);
    } catch(e) {
        console.warn('Could not load agent skill counts', e);
    }
}

function renderTransferGrid(counts) {
    const grid = document.getElementById('agentTransferGrid');
    if (!grid) return;

    const platforms = [
        { key: 'instagram',        name: 'Instagram',    icon: 'bi-instagram',     color: '#e1306c' },
        { key: 'tiktok',           name: 'TikTok',       icon: 'bi-tiktok',        color: '#ffffff' },
        { key: 'youtube',          name: 'YouTube',      icon: 'bi-youtube',       color: '#ff0000' },
        { key: 'facebook',         name: 'Facebook',     icon: 'bi-facebook',      color: '#1877f2' },
        { key: 'linkedin',         name: 'LinkedIn',     icon: 'bi-linkedin',      color: '#0a66c2' },
        { key: 'twitter',          name: 'Twitter / X',  icon: 'bi-twitter-x',     color: '#ffffff' },
        { key: 'pinterest',        name: 'Pinterest',    icon: 'bi-pinterest',     color: '#e60023' },
        { key: 'snapchat',         name: 'Snapchat',     icon: 'bi-snapchat',      color: '#fffc00' },
        { key: 'threads',          name: 'Threads',      icon: 'bi-threads',       color: '#ffffff' },
        { key: 'google_my_business', name: 'Google My Biz', icon: 'bi-geo-alt-fill', color: '#00d4ff' },
        { key: 'telegram',         name: 'Telegram',     icon: 'bi-telegram',      color: '#0088cc' },
    ];

    let html = '';
    for (const p of platforms) {
        const count = counts[p.key] ?? 0;
        const badgeCls = count > 0 ? 'bg-warning text-dark' : 'bg-secondary';
        html += `<div class="col">
            <div class="rounded-3 p-2 d-flex flex-column align-items-center gap-1 text-center"
                 style="background:rgba(255,255,255,.04);cursor:pointer;"
                 onclick="transferToAgent('${p.key}', this)">
                <i class="bi ${p.icon} fs-5" style="color:${p.color};"></i>
                <div class="text-white" style="font-size:.7rem;line-height:1.2;">${p.name}</div>
                <span class="badge ${badgeCls}" style="font-size:.6rem;" id="grid-count-${p.key}">${count} skill${count !== 1 ? 's' : ''}</span>
            </div>
        </div>`;
    }
    grid.innerHTML = html;
}

async function transferToAgent(platform, btn) {
    const origHtml = btn ? btn.innerHTML : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
    try {
        const r = await fetch('/orchestrator/transfer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ platform }),
        });
        const result = await r.json();
        showToast(result.message || (result.success ? 'Skills transferred!' : 'Transfer failed'), result.success ? 'success' : 'error');
        if (result.success) {
            loadAgentSkillCounts();
        }
    } catch(e) {
        showToast('Transfer failed: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; if (origHtml) btn.innerHTML = origHtml; }
    }
}

async function transferAllSkills(btn) {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Transferring…';
    const resultEl = document.getElementById('transferAllResult');
    resultEl.classList.remove('d-none');
    resultEl.innerHTML = '<div class="text-secondary small"><span class="spinner-border spinner-border-sm me-2"></span>Transferring skills to all agents…</div>';
    try {
        const r = await fetch('/orchestrator/transfer-all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: '{}',
        });
        const result = await r.json();
        if (result.success) {
            const rows = Object.entries(result.results || {})
                .map(([p, r]) => `<span class="badge bg-secondary me-1">${p}: +${r.skills_injected}</span>`)
                .join('');
            resultEl.innerHTML = `<div class="alert py-2 mb-0" style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);">
                <i class="bi bi-check-circle-fill text-warning me-2"></i>
                <strong>${result.message}</strong><br>
                <div class="mt-2">${rows}</div>
            </div>`;
            loadAgentSkillCounts();
        } else {
            resultEl.innerHTML = `<div class="alert alert-danger py-2 mb-0 small">${result.message || 'Transfer failed'}</div>`;
        }
    } catch(e) {
        resultEl.innerHTML = `<div class="alert alert-danger py-2 mb-0 small">Error: ${e.message}</div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

async function viewAgentSkills(platform, name) {
    _currentTransferPlatform = platform;
    _currentTransferAgentName = name;
    document.getElementById('agentSkillModalTitle').textContent = name;
    document.getElementById('agentSkillModalBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-warning"></span></div>';
    new bootstrap.Modal(document.getElementById('agentSkillModal')).show();

    try {
        const data = await ajaxGet('/orchestrator/agents/' + platform + '/skills');
        const injected  = data.injected_skills || [];
        const available = data.available_skills || [];

        let html = '';

        if (injected.length > 0) {
            html += `<h6 class="text-warning mb-2"><i class="bi bi-check-circle-fill me-2"></i>Injected (${injected.length})</h6>`;
            html += '<div class="row g-2 mb-3">';
            for (const s of injected) {
                const domainColor = DOMAIN_COLORS[s.domain] || '#aaa';
                html += `<div class="col-12 col-md-6">
                    <div class="rounded p-2" style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="fw-semibold small text-white">${s.title}</div>
                            <span class="badge" style="background:${domainColor}22;color:${domainColor};font-size:.6rem;white-space:nowrap;">${s.domain}</span>
                        </div>
                        <div class="text-secondary mt-1" style="font-size:.72rem;">${s.description}</div>
                        <div class="text-secondary mt-1" style="font-size:.65rem;">Confidence: ${s.confidence}%</div>
                    </div>
                </div>`;
            }
            html += '</div>';
        } else {
            html += `<div class="alert alert-secondary small py-2 mb-3">
                No skills injected yet. Click <strong>Inject Skills</strong> to push Orchestrator knowledge into this agent.
            </div>`;
        }

        if (available.length > injected.length) {
            const pending = available.filter(a => !injected.some(i => i.title === a.title));
            if (pending.length > 0) {
                html += `<h6 class="text-secondary mb-2"><i class="bi bi-clock-history me-2"></i>Available to inject (${pending.length})</h6>`;
                html += '<div class="row g-2">';
                for (const s of pending.slice(0, 8)) {
                    html += `<div class="col-12 col-md-6">
                        <div class="rounded p-2" style="background:rgba(255,255,255,.04);">
                            <div class="fw-semibold small text-secondary">${s.title}</div>
                            <div class="text-secondary mt-1" style="font-size:.72rem;">${s.description}</div>
                        </div>
                    </div>`;
                }
                if (pending.length > 8) html += `<div class="col-12 text-secondary small">…and ${pending.length - 8} more</div>`;
                html += '</div>';
            }
        }

        document.getElementById('agentSkillModalBody').innerHTML = html;
    } catch(e) {
        document.getElementById('agentSkillModalBody').innerHTML = `<div class="alert alert-danger small">${e.message}</div>`;
    }
}

async function transferToCurrentAgent() {
    if (!_currentTransferPlatform) return;
    const btn = document.getElementById('agentSkillTransferBtn');
    await transferToAgent(_currentTransferPlatform, btn);
    // Refresh modal content using the stored agent name
    await viewAgentSkills(_currentTransferPlatform, _currentTransferAgentName);
}

async function clearAgentSkills() {
    if (!_currentTransferPlatform) return;
    if (!confirm('Clear all injected skills from this agent?')) return;
    try {
        const r = await fetch('/orchestrator/agents/' + _currentTransferPlatform + '/skills', {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        });
        const result = await r.json();
        showToast(result.message || 'Cleared', result.success ? 'success' : 'error');
        if (result.success) {
            await viewAgentSkills(_currentTransferPlatform, _currentTransferAgentName);
            loadAgentSkillCounts();
        }
    } catch(e) {
        showToast('Error: ' + e.message, 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadOrchestrator();
    loadAgentSkillCounts();
});
</script>
@endpush
