@extends('layouts.app')

@section('title', 'Platforms & AI Agents')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white"><i class="bi bi-plug me-2"></i>Platforms &amp; AI Models</h1>
        <p class="text-secondary small mb-0">Connect social accounts and configure AI providers</p>
    </div>
</div>

@php
    $allPlatforms = [
        ['key' => 'instagram',  'name' => 'Instagram',    'icon' => 'bi-instagram',     'color' => '#E1306C',
         'fields' => [
             'client_id'    => ['label' => 'Meta App ID', 'type' => 'text'],
             'client_secret'=> ['label' => 'Meta App Secret', 'type' => 'password'],
             'access_token' => ['label' => 'Page Access Token', 'type' => 'password'],
             'instagram_business_account_id' => ['label' => 'Instagram Business Account ID', 'type' => 'text'],
         ],
         'guide' => 'developers.facebook.com → Create App → Add Instagram Graph API → Generate long-lived token'],
        ['key' => 'facebook',   'name' => 'Facebook',     'icon' => 'bi-facebook',      'color' => '#1877F2',
         'fields' => [
             'client_id'    => ['label' => 'Meta App ID', 'type' => 'text'],
             'client_secret'=> ['label' => 'Meta App Secret', 'type' => 'password'],
             'access_token' => ['label' => 'Page Access Token', 'type' => 'password'],
             'page_id'      => ['label' => 'Facebook Page ID', 'type' => 'text'],
         ],
         'guide' => 'developers.facebook.com → Your App → Graph API Explorer → Generate Page Access Token'],
        ['key' => 'youtube',    'name' => 'YouTube',      'icon' => 'bi-youtube',       'color' => '#FF0000',
         'fields' => [
             'client_id'    => ['label' => 'Google Client ID', 'type' => 'text'],
             'client_secret'=> ['label' => 'Google Client Secret', 'type' => 'password'],
             'refresh_token'=> ['label' => 'Refresh Token', 'type' => 'password'],
         ],
         'guide' => 'console.cloud.google.com → Enable YouTube Data API v3 → Create OAuth credentials'],
        ['key' => 'linkedin',   'name' => 'LinkedIn',     'icon' => 'bi-linkedin',      'color' => '#0A66C2',
         'fields' => [
             'client_id'    => ['label' => 'LinkedIn Client ID', 'type' => 'text'],
             'client_secret'=> ['label' => 'LinkedIn Client Secret', 'type' => 'password'],
             'access_token' => ['label' => 'Access Token', 'type' => 'password'],
             'organization_id' => ['label' => 'Organization (Company) ID', 'type' => 'text'],
         ],
         'guide' => 'linkedin.com/developers → Create App → Verify Company → Auth tab → Copy credentials'],
        ['key' => 'tiktok',     'name' => 'TikTok',       'icon' => 'bi-tiktok',        'color' => '#010101',
         'fields' => [
             'client_id'    => ['label' => 'TikTok Client Key', 'type' => 'text'],
             'client_secret'=> ['label' => 'TikTok Client Secret', 'type' => 'password'],
             'access_token' => ['label' => 'Access Token', 'type' => 'password'],
             'refresh_token'=> ['label' => 'Refresh Token (optional)', 'type' => 'password', 'required' => false],
         ],
         'guide' => 'developers.tiktok.com → Create App → Add Content Posting API → Generate tokens'],
        ['key' => 'twitter',    'name' => 'Twitter / X',  'icon' => 'bi-twitter-x',    'color' => '#1DA1F2',
         'fields' => [
             'client_id'    => ['label' => 'API Key (Consumer Key)', 'type' => 'text'],
             'client_secret'=> ['label' => 'API Secret (Consumer Secret)', 'type' => 'password'],
             'access_token' => ['label' => 'Access Token', 'type' => 'password'],
             'access_token_secret' => ['label' => 'Access Token Secret', 'type' => 'password'],
             'bearer_token' => ['label' => 'Bearer Token (optional)', 'type' => 'password', 'required' => false],
         ],
         'guide' => 'developer.twitter.com → Create Project → App → Generate Consumer Keys and Access Tokens'],
        ['key' => 'snapchat',   'name' => 'Snapchat',     'icon' => 'bi-snapchat',      'color' => '#FFFC00',
         'fields' => [], 'guide' => 'Snapchat does not support automated posting. We prepare optimized content for manual posting.'],
        ['key' => 'pinterest',  'name' => 'Pinterest',    'icon' => 'bi-pinterest',     'color' => '#E60023',
         'fields' => [
             'access_token' => ['label' => 'Access Token', 'type' => 'password'],
             'board_id'     => ['label' => 'Board ID', 'type' => 'text'],
         ],
         'guide' => 'developers.pinterest.com → Create App → Generate access token with pins:write scope'],
        ['key' => 'threads',    'name' => 'Threads',      'icon' => 'bi-threads',       'color' => '#000',
         'fields' => [
             'access_token' => ['label' => 'Threads Access Token', 'type' => 'password'],
             'user_id'      => ['label' => 'Threads User ID', 'type' => 'text'],
         ],
         'guide' => 'developers.facebook.com → Add Threads API → Generate token with threads_content_publish'],
    ];

    $connected = $platforms instanceof \Illuminate\Database\Eloquent\Collection ? $platforms->all() : (is_array($platforms) && !isset($platforms['error']) ? $platforms : []);
    $connectedKeys = collect($connected)->filter(fn($c) => is_array($c) ? ($c['connected'] ?? false) : ($c->connected ?? false))->map(fn($c) => is_array($c) ? $c['key'] : $c->key)->unique()->values()->all();
@endphp

{{-- Summary Stats --}}
<div class="row row-cols-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">Connected</div>
            <div class="stat-value text-success" id="connected-count">{{ count($connectedKeys) }}</div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">Available</div>
            <div class="stat-value">{{ count($allPlatforms) }}</div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">AI Agents</div>
            <div class="stat-value text-info"><i class="bi bi-robot" style="font-size:.9em;"></i> {{ count($connectedKeys) }}</div>
        </div>
    </div>
    <div class="col">
        <div class="card stat-card h-100 p-3">
            <div class="stat-label">Status</div>
            <div class="stat-value">
                @if(count($connectedKeys) > 0)
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                @else
                    <span class="badge bg-warning text-dark">Setup needed</span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- AI Models Section --}}
<div class="d-flex align-items-center gap-3 mb-3 mt-4">
    <i class="bi bi-cpu fs-5" style="color:#7c3aed;"></i>
    <div>
        <h6 class="text-white mb-0">AI Models &amp; Providers</h6>
        <p class="text-secondary small mb-0">Configure the AI engines powering your content automation</p>
    </div>
</div>

<div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4" id="ai-models-grid">
    <div class="col-12">
        <div class="d-flex align-items-center gap-2 text-secondary p-3">
            <span class="spinner-border spinner-border-sm"></span> Loading AI model status…
        </div>
    </div>
</div>

{{-- Social Platforms --}}
<div class="d-flex align-items-center gap-3 mb-3 mt-2">
    <i class="bi bi-share-fill fs-5 text-info"></i>
    <div>
        <h6 class="text-white mb-0">Social Platforms</h6>
        <p class="text-secondary small mb-0">Connect your accounts for automated posting</p>
    </div>
</div>

<div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
    @foreach($allPlatforms as $p)
        @php
            $isConnected = in_array($p['key'], $connectedKeys);
            $platformData = collect($connected)->first(fn($c) => (is_array($c) ? ($c['key'] ?? '') : ($c->key ?? '')) === $p['key']);
            $testStatus = null;
            $testMessage = null;
            $testedAt = null;
            if ($platformData) {
                $testStatus = is_array($platformData) ? ($platformData['last_test_status'] ?? null) : ($platformData->last_test_status ?? null);
                $testMessage = is_array($platformData) ? ($platformData['last_test_message'] ?? null) : ($platformData->last_test_message ?? null);
                $testedAt = is_array($platformData) ? ($platformData['last_tested_at'] ?? null) : ($platformData->last_tested_at ?? null);
            }
            $isVerified = $isConnected && $testStatus === 'ok';
            $hasError = $isConnected && $testStatus === 'error';
        @endphp
        <div class="col" id="platform-{{ $p['key'] }}">
            <div class="card p-4 h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;background:{{ $p['color'] }}22;">
                        <i class="bi {{ $p['icon'] }}" style="color:{{ $p['color'] }};font-size:1.2rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-white fw-semibold">{{ $p['name'] }}</div>
                        <div class="text-secondary small" id="status-text-{{ $p['key'] }}">
                            @if($isVerified)
                                <span class="badge bg-success bg-opacity-75 me-1" style="font-size:.6rem;">●</span>Connected — verified
                            @elseif($hasError)
                                <span class="badge bg-danger bg-opacity-75 me-1" style="font-size:.6rem;">●</span>Connected — verification failed
                            @elseif($isConnected)
                                <span class="badge bg-warning bg-opacity-75 me-1" style="font-size:.6rem;">●</span>Connected — not verified
                            @else
                                <span class="text-muted"><i class="bi bi-dash-circle me-1"></i>Disconnected</span>
                            @endif
                        </div>
                    </div>
                    <span class="badge {{ $isVerified ? 'bg-success' : ($hasError ? 'bg-danger' : ($isConnected ? 'bg-warning text-dark' : 'bg-secondary')) }}" id="badge-{{ $p['key'] }}">
                        {{ $isVerified ? 'Live' : ($hasError ? 'Error' : ($isConnected ? 'Unverified' : 'Off')) }}
                    </span>
                </div>

                @if($isConnected && $testMessage)
                    <div class="mb-2 {{ $isVerified ? 'text-success' : 'text-danger' }}" style="font-size:.75rem;">
                        <i class="bi {{ $isVerified ? 'bi-check-circle' : 'bi-exclamation-triangle' }} me-1"></i>{{ $testMessage }}
                    </div>
                @endif

                @if($isConnected && $testedAt)
                    <div class="text-secondary mb-2" style="font-size:.65rem;">
                        Last tested: {{ $testedAt instanceof \Carbon\Carbon ? $testedAt->diffForHumans() : $testedAt }}
                    </div>
                @endif

                @if($isConnected)
                    <div class="d-flex gap-2 flex-wrap" id="actions-{{ $p['key'] }}">
                        <button class="btn btn-outline-secondary btn-sm" onclick="testPlatform('{{ $p['key'] }}', this)">
                            <i class="bi bi-arrow-repeat me-1"></i>Test Connection
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="disconnectPlatform('{{ $p['key'] }}', '{{ $p['name'] }}', this)">
                            <i class="bi bi-x-circle me-1"></i>Disconnect
                        </button>
                    </div>
                @elseif(count($p['fields']) > 0)
                    <div class="border-top border-secondary border-opacity-25 pt-3" id="connect-form-{{ $p['key'] }}">
                        <p class="text-secondary small mb-3">
                            <i class="bi bi-info-circle text-info me-1"></i>{{ $p['guide'] }}
                        </p>
                        @foreach($p['fields'] as $fieldKey => $fieldInfo)
                            <div class="mb-2">
                                <label class="form-label text-secondary small">{{ $fieldInfo['label'] }}</label>
                                <input type="{{ $fieldInfo['type'] ?? 'text' }}"
                                       class="form-control form-control-sm platform-field-{{ $p['key'] }}"
                                       data-field="{{ $fieldKey }}"
                                       placeholder="{{ $fieldInfo['label'] }}"
                                       {{ ($fieldInfo['required'] ?? true) ? 'required' : '' }}>
                            </div>
                        @endforeach
                        <button type="button" class="btn btn-primary btn-sm w-100 mt-2"
                                onclick="connectPlatform('{{ $p['key'] }}', this)">
                            <i class="bi bi-link-45deg me-1"></i>Connect {{ $p['name'] }}
                        </button>
                    </div>
                @else
                    <div class="border-top border-secondary border-opacity-25 pt-3">
                        <p class="text-secondary small mb-3">
                            <i class="bi bi-info-circle text-warning me-1"></i>{{ $p['guide'] }}
                        </p>
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100"
                                onclick="connectPlatform('{{ $p['key'] }}', this)">
                            <i class="bi bi-check-lg me-1"></i>Enable {{ $p['name'] }} Content Prep
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

{{-- Telegram Bot Config --}}
<div class="card p-4 mb-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:44px;height:44px;background:rgba(0,136,204,.15);">
            <i class="bi bi-telegram" style="color:#0088cc;font-size:1.25rem;"></i>
        </div>
        <div class="flex-grow-1">
            <div class="text-white fw-semibold">Telegram Bot</div>
            <div class="text-secondary small">Submit content and get notifications via Telegram</div>
        </div>
        <span class="badge d-none" id="telegram-status-badge"></span>
    </div>

    <div class="alert alert-dark border-secondary p-3 mb-4 small">
        <div class="mb-2"><span class="badge bg-info text-dark me-2">1</span>Open <a href="https://t.me/BotFather" target="_blank" class="text-info">@BotFather</a> on Telegram and send <code>/newbot</code></div>
        <div><span class="badge bg-info text-dark me-2">2</span>Copy the bot token and paste it below</div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6">
            <label class="form-label text-secondary small">Bot Token</label>
            <input type="password" id="telegram_bot_token" class="form-control" placeholder="123456:ABC-DEF..."
                   onfocus="this.type='text'" onblur="if(!this.value)this.type='password'">
        </div>
        <div class="col-12 col-sm-6">
            <label class="form-label text-secondary small">Your Chat ID</label>
            <input type="text" id="telegram_admin_chat_id" class="form-control" placeholder="Send /start to @userinfobot">
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm" onclick="saveTelegramConfig(this)">
            <i class="bi bi-check-lg me-1"></i>Save Config
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="testTelegramToken(this)">
            <i class="bi bi-arrow-repeat me-1"></i>Test Token
        </button>
    </div>
</div>

{{-- AI Model Modal (Bootstrap) --}}
<div class="modal fade" id="aiModelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#16213e;border:1px solid rgba(255,255,255,.1);">
            <div class="modal-header border-0">
                <h5 class="modal-title text-white"><i class="bi bi-cpu me-2" style="color:#7c3aed;"></i>Configure <span id="aiModelProviderName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="aiModelProviderKey">
                <div class="mb-3">
                    <label class="form-label text-secondary small">API Key</label>
                    <input type="password" id="aiModelApiKey" class="form-control" placeholder="Paste your API key"
                           onfocus="this.type='text'" onblur="if(!this.value)this.type='password'">
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary small">Model Name (optional, uses default if blank)</label>
                    <input type="text" id="aiModelName" class="form-control">
                    <div class="form-text text-secondary" id="aiModelHint"></div>
                </div>
                <div class="mb-3" id="aiModelBaseUrlGroup" style="display:none;">
                    <label class="form-label text-secondary small">Base URL</label>
                    <input type="url" id="aiModelBaseUrl" class="form-control" placeholder="http://localhost:11434">
                    <div class="form-text text-secondary">Server URL for local/custom AI endpoints</div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveAiModel(this)">
                    <i class="bi bi-check-lg me-1"></i>Save &amp; Connect
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const AI_PROVIDERS = [
    { key: 'openai',        name: 'OpenAI',           icon: 'bi-stars',              color: '#10a37f', defaultModel: 'gpt-4o-mini',               hint: 'gpt-4o, gpt-4o-mini, gpt-4-turbo, o1-mini' },
    { key: 'google_gemini', name: 'Google Gemini',    icon: 'bi-google',             color: '#4285F4', defaultModel: 'gemini-2.0-flash',           hint: 'gemini-2.0-flash, gemini-1.5-pro, gemini-1.5-flash' },
    { key: 'anthropic',     name: 'Anthropic Claude', icon: 'bi-chat-square-dots',   color: '#d97706', defaultModel: 'claude-sonnet-4-20250514',    hint: 'claude-sonnet-4-20250514, claude-3-5-sonnet, claude-3-haiku' },
    { key: 'mistral',       name: 'Mistral AI',       icon: 'bi-wind',               color: '#ff7000', defaultModel: 'mistral-large-latest',        hint: 'mistral-large-latest, mistral-medium, mistral-small' },
    { key: 'deepseek',      name: 'DeepSeek',         icon: 'bi-search-heart',       color: '#0ea5e9', defaultModel: 'deepseek-chat',              hint: 'deepseek-chat, deepseek-coder' },
    { key: 'groq',          name: 'Groq',             icon: 'bi-lightning-charge',   color: '#f97316', defaultModel: 'llama-3.1-70b-versatile',    hint: 'llama-3.1-70b-versatile, mixtral-8x7b-32768' },
    { key: 'ollama',        name: 'Ollama (Local)',    icon: 'bi-pc-display',        color: '#6366f1', defaultModel: 'llama3',                     hint: 'llama3, mistral, codellama, phi3 — runs on your machine', needsBaseUrl: true },
    { key: 'openai_compatible', name: 'Custom Endpoint', icon: 'bi-plug',            color: '#8b5cf6', defaultModel: 'default',                    hint: 'Any OpenAI-compatible API (LM Studio, vLLM, text-generation-webui)', needsBaseUrl: true },
];

function statusBadge(status) {
    const map = {
        active:     { cls: 'bg-success',              icon: 'bi-check-circle-fill', text: 'Connected' },
        configured: { cls: 'bg-warning text-dark',    icon: 'bi-exclamation-circle', text: 'Not Verified' },
        error:      { cls: 'bg-danger',               icon: 'bi-x-circle-fill',     text: 'Error' },
        inactive:   { cls: 'bg-secondary',            icon: 'bi-dash-circle',       text: 'Inactive' },
    };
    const s = map[status] || map.inactive;
    return `<span class="badge ${s.cls}"><i class="bi ${s.icon} me-1"></i>${s.text}</span>`;
}

function statusDot(status) {
    const colors = { active: '#10b981', configured: '#f59e0b', error: '#ef4444', inactive: 'rgba(255,255,255,.2)' };
    const labels = { active: 'Connected', configured: 'Not verified', error: 'Connection error', inactive: 'Not configured' };
    const c = colors[status] || colors.inactive;
    const l = labels[status] || 'Not configured';
    return `<span style="color:${c};">●</span> ${l}`;
}

async function loadAiModels() {
    const grid = document.getElementById('ai-models-grid');
    try {
        const data = await ajaxGet('/ai-models');
        const models = data.models || [];
        const modelMap = {};
        models.forEach(m => { modelMap[m.provider] = m; });

        let html = '';
        AI_PROVIDERS.forEach(p => {
            const cfg = modelMap[p.key];
            const status = cfg ? (cfg.status || 'configured') : 'inactive';

            html += `<div class="col"><div class="card p-3 h-100" id="ai-model-${p.key}">`;
            html += `<div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:40px;height:40px;background:${p.color}22;">
                     <i class="bi ${p.icon}" style="color:${p.color};font-size:1.1rem;"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="text-white small fw-semibold">${p.name}</div>
                    <div class="text-secondary" style="font-size:.7rem;">
                        ${statusDot(status)}
                        ${cfg && cfg.model_name ? ' · ' + cfg.model_name : ''}
                    </div>
                </div>
                <div>${cfg ? statusBadge(status) : ''}</div>
            </div>`;

            if (cfg) {
                html += `<div class="mb-1 text-secondary" style="font-size:.7rem;">Key: ${cfg.masked_key || '••••••'}</div>`;
                if (cfg.last_test_message) {
                    const msgColor = status === 'active' ? 'text-success' : (status === 'error' ? 'text-danger' : 'text-warning');
                    html += `<div class="${msgColor} mb-2" style="font-size:.7rem;"><i class="bi ${status === 'active' ? 'bi-check-circle' : 'bi-exclamation-triangle'} me-1"></i>${cfg.last_test_message}</div>`;
                }
                if (cfg.last_tested_at) {
                    html += `<div class="text-secondary mb-2" style="font-size:.65rem;">Last tested: ${new Date(cfg.last_tested_at).toLocaleString()}</div>`;
                }
                html += `<div class="d-flex gap-1 flex-wrap">
                    <button class="btn btn-outline-secondary btn-sm" id="test-btn-${p.key}" onclick="testAiModel('${p.key}', this)"><i class="bi bi-arrow-repeat me-1"></i>Test</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="openAiModelModal('${p.key}','${p.name}','${p.defaultModel}','${p.hint}')"><i class="bi bi-pencil me-1"></i>Edit</button>
                    <button class="btn btn-outline-danger btn-sm" onclick="deleteAiModel('${p.key}', this)"><i class="bi bi-trash"></i></button>
                </div>`;
            } else {
                html += `<button class="btn btn-primary btn-sm w-100" onclick="openAiModelModal('${p.key}','${p.name}','${p.defaultModel}','${p.hint}')"><i class="bi bi-plus-lg me-1"></i>Configure</button>`;
            }
            html += '</div></div>';
        });
        grid.innerHTML = html;
    } catch (e) {
        grid.innerHTML = '<div class="col-12"><div class="alert alert-danger">Failed to load AI models.</div></div>';
    }
}

function openAiModelModal(key, name, defaultModel, hint) {
    document.getElementById('aiModelProviderKey').value = key;
    document.getElementById('aiModelProviderName').textContent = name;
    document.getElementById('aiModelApiKey').value = '';
    document.getElementById('aiModelName').value = '';
    document.getElementById('aiModelName').placeholder = defaultModel;
    document.getElementById('aiModelHint').textContent = 'Available models: ' + hint;
    document.getElementById('aiModelBaseUrl').value = '';
    const provider = AI_PROVIDERS.find(p => p.key === key);
    document.getElementById('aiModelBaseUrlGroup').style.display = (provider && provider.needsBaseUrl) ? 'block' : 'none';
    new bootstrap.Modal(document.getElementById('aiModelModal')).show();
}

async function saveAiModel(btn) {
    const provider  = document.getElementById('aiModelProviderKey').value;
    const apiKey    = document.getElementById('aiModelApiKey').value.trim();
    const modelName = document.getElementById('aiModelName').value.trim();
    const baseUrl   = document.getElementById('aiModelBaseUrl').value.trim();
    if (!apiKey && !['ollama'].includes(provider)) { showToast('Please enter an API key', 'warning'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Connecting…';
    try {
        const r = await fetch('/ai-models', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ provider, api_key: apiKey || 'local', model_name: modelName || null, base_url: baseUrl || null }),
        });
        const result = await r.json();
        if (result.connected) {
            showToast(result.message || 'Connected!', 'success');
        } else if (result.success) {
            showToast(result.message || 'Saved but not verified.', 'warning');
        } else {
            showToast(result.message || 'Failed', 'error');
        }
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('aiModelModal')).hide();
            loadAiModels();
        }
    } catch(e) {
        showToast('Request failed: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save & Connect';
    }
}
async function testAiModel(provider, btn) {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
    try {
        const r = await fetch('/ai-models/' + provider + '/test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: '{}',
        });
        const d = await r.json();
        showToast(d.message || (d.success ? 'Connected!' : 'Failed'), d.success ? 'success' : 'error');
        loadAiModels();
    } catch(e) {
        showToast('Test failed: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
async function deleteAiModel(provider, btn) {
    if (!confirm('Remove this AI model configuration?')) return;
    const result = await ajaxDelete('/ai-models/' + provider, btn);
    if (result.success) loadAiModels();
}

// Platform Ops
async function connectPlatform(platform, btn) {
    const fields = document.querySelectorAll('.platform-field-' + platform);
    const credentials = {};
    fields.forEach(f => { if (f.value.trim()) credentials[f.dataset.field] = f.value.trim(); });

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Connecting…';
    try {
        const r = await fetch('/platforms/' + platform + '/connect', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify(credentials),
        });
        const result = await r.json();
        if (result.verified) {
            showToast(result.message || 'Connected and verified!', 'success');
        } else if (result.success) {
            showToast(result.message || 'Saved but verification failed.', 'warning');
        } else {
            showToast(result.message || 'Failed', 'error');
        }
        if (result.success) setTimeout(() => location.reload(), 1200);
    } catch(e) {
        showToast('Connection failed: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
async function testPlatform(platform, btn) {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';

    const statusEl = document.getElementById('status-text-' + platform);
    if (statusEl) statusEl.innerHTML = '<span class="text-info"><span class="spinner-border spinner-border-sm me-1" style="width:.6rem;height:.6rem;"></span>Verifying connection…</span>';

    try {
        const r = await fetch('/platforms/' + platform + '/test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: '{}',
        });
        const d = await r.json();
        showToast(d.message || (d.success ? 'Connected!' : 'Failed'), d.success ? 'success' : 'error');

        // Update status in-place
        if (statusEl) {
            if (d.success) {
                statusEl.innerHTML = '<span class="badge bg-success bg-opacity-75 me-1" style="font-size:.6rem;">●</span>Connected — verified';
            } else {
                statusEl.innerHTML = '<span class="badge bg-danger bg-opacity-75 me-1" style="font-size:.6rem;">●</span>Error: ' + (d.message || 'Test failed');
            }
        }
        const badgeEl = document.getElementById('badge-' + platform);
        if (badgeEl) {
            badgeEl.className = d.success ? 'badge bg-success' : 'badge bg-danger';
            badgeEl.textContent = d.success ? 'Live' : 'Error';
        }
    } catch(e) {
        showToast('Test failed: ' + e.message, 'error');
        if (statusEl) statusEl.innerHTML = '<span class="badge bg-danger bg-opacity-75 me-1" style="font-size:.6rem;">●</span>Test error';
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
async function disconnectPlatform(platform, name, btn) {
    if (!confirm('Disconnect ' + name + '? You can reconnect later.')) return;
    const result = await ajaxPost('/platforms/' + platform + '/disconnect', {}, btn);
    if (result.success) setTimeout(() => location.reload(), 800);
}

// Telegram
async function saveTelegramConfig(btn) {
    const token  = document.getElementById('telegram_bot_token').value.trim();
    const chatId = document.getElementById('telegram_admin_chat_id').value.trim();
    if (!token) { showToast('Please enter a bot token', 'warning'); return; }

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Connecting…';

    try {
        const r = await fetch('/platforms/telegram/configure', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ telegram_bot_token: token, telegram_admin_chat_id: chatId }),
        });
        const result = await r.json();
        const badge = document.getElementById('telegram-status-badge');
        if (result.verified) {
            badge.className = 'badge bg-success';
            badge.innerHTML = '<i class="bi bi-check-circle me-1"></i>Connected';
            showToast(result.message || 'Telegram connected!', 'success');
        } else {
            badge.className = 'badge bg-warning text-dark';
            badge.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Not Verified';
            showToast(result.message || 'Saved but not verified.', 'warning');
        }
        badge.classList.remove('d-none');
    } catch(e) {
        showToast('Failed: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
async function testTelegramToken(btn) {
    const token = document.getElementById('telegram_bot_token').value.trim();
    if (!token) { showToast('Enter a token first', 'warning'); return; }

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';

    try {
        const r = await fetch('/platforms/telegram/test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ telegram_bot_token: token }),
        });
        const result = await r.json();
        const badge = document.getElementById('telegram-status-badge');
        badge.className = result.success ? 'badge bg-success' : 'badge bg-danger';
        badge.innerHTML = result.success
            ? '<i class="bi bi-check-circle me-1"></i>' + (result.message || 'Valid')
            : '<i class="bi bi-x-circle me-1"></i>' + (result.message || 'Invalid');
        badge.classList.remove('d-none');
        showToast(result.message || (result.success ? 'Token valid!' : 'Invalid token'), result.success ? 'success' : 'error');
    } catch(e) {
        showToast('Test failed: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

document.addEventListener('DOMContentLoaded', loadAiModels);
</script>

<style>
.status-dot { display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:4px; }
.status-dot.green  { background:#10b981; box-shadow:0 0 6px rgba(16,185,129,.5); }
.status-dot.yellow { background:#f59e0b; }
.status-dot.gray   { background:rgba(255,255,255,.2); }
.status-dot.red    { background:#ef4444; box-shadow:0 0 6px rgba(239,68,68,.4); }
</style>
@endsection
