<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — {{ auth()->user()->business->name ?? 'Marketing Hub' }}</title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- DataTables Bootstrap 5 -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Flatpickr (date picker) -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 250px;
            --sidebar-bg: #0f1117;
            --sidebar-border: rgba(255,255,255,.07);
            --accent: #0d6efd;
        }
        body { font-family: 'Inter', sans-serif; background: #0a0d14; }

        /* ── Sidebar ── */
        #sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--sidebar-border);
            position: fixed;
            top: 0; left: 0;
            display: flex; flex-direction: column;
            z-index: 1000;
            overflow-y: auto;
            transition: transform .25s ease;
        }
        .sidebar-brand {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--sidebar-border);
            display: flex; align-items: center; gap: .6rem;
        }
        .sidebar-brand-icon { font-size: 1.5rem; line-height:1; }
        .sidebar-brand-name { font-weight: 700; font-size: .95rem; color: #fff; line-height:1.2; }
        .sidebar-brand-sub  { font-size: .65rem; color: rgba(255,255,255,.35); }

        .sidebar-section {
            font-size: .65rem; font-weight: 600; letter-spacing: .08em;
            text-transform: uppercase; color: rgba(255,255,255,.28);
            padding: .9rem 1rem .3rem;
        }
        .sidebar-link {
            display: flex; align-items: center; gap: .6rem;
            padding: .45rem 1rem; margin: 1px .5rem;
            border-radius: .4rem;
            font-size: .83rem; color: rgba(255,255,255,.55);
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .sidebar-link:hover { background: rgba(255,255,255,.06); color: #fff; }
        .sidebar-link.active { background: rgba(13,110,253,.18); color: #3d8bfd; font-weight: 600; }
        .sidebar-link i { font-size: 1rem; width: 18px; text-align: center; }
        .sidebar-footer { margin-top: auto; padding: 1rem; border-top: 1px solid var(--sidebar-border); }

        /* ── Main Content ── */
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; background: #0a0d14; }
        .topbar {
            height: 56px;
            background: #0f1117;
            border-bottom: 1px solid var(--sidebar-border);
            display: flex; align-items: center;
            padding: 0 1.5rem;
            gap: .75rem;
            position: sticky; top: 0; z-index: 100;
        }
        .page-body { padding: 1.5rem; }

        /* ── Cards ── */
        .card { border: 1px solid rgba(255,255,255,.08); background: #111520; }
        .card-header { border-bottom: 1px solid rgba(255,255,255,.07); background: rgba(255,255,255,.02); }

        /* ── Stat Cards ── */
        .stat-card .stat-label { font-size: .75rem; color: rgba(255,255,255,.45); text-transform: uppercase; letter-spacing: .06em; margin-bottom: .3rem; }
        .stat-card .stat-value { font-size: 1.8rem; font-weight: 700; color: #fff; line-height: 1; }
        .stat-card .stat-icon  { width: 42px; height: 42px; border-radius: .5rem; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }

        /* ── Tables ── */
        .table { --bs-table-bg: transparent; }
        .table thead th { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: rgba(255,255,255,.4); border-color: rgba(255,255,255,.07); }
        .table td, .table th { border-color: rgba(255,255,255,.06); vertical-align: middle; }

        /* ── Forms ── */
        .form-control, .form-select { background: #1a1f2e; border-color: rgba(255,255,255,.12); color: #e2e8f0; }
        .form-control:focus, .form-select:focus { background: #1a1f2e; border-color: #0d6efd; color: #e2e8f0; box-shadow: 0 0 0 .2rem rgba(13,110,253,.2); }
        .form-control::placeholder { color: rgba(255,255,255,.25); }
        .form-label { font-size: .85rem; color: rgba(255,255,255,.65); font-weight: 500; }

        /* ── Page Header ── */
        .page-title { font-size: 1.4rem; font-weight: 700; color: #fff; margin: 0; }
        .page-subtitle { font-size: .85rem; color: rgba(255,255,255,.4); margin: .2rem 0 0; }

        /* ── Charts ── */
        .chart-container { position: relative; height: 220px; }

        /* ── Badges override ── */
        .badge { font-weight: 500; font-size: .72rem; }

        /* ── AI Assistant FAB ── */
        .ai-fab {
            position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500;
            width: 54px; height: 54px; border-radius: 50%;
            background: linear-gradient(135deg,#0d6efd,#6f42c1);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; color: #fff; cursor: pointer;
            box-shadow: 0 4px 20px rgba(13,110,253,.4);
            border: none;
            transition: transform .2s;
        }
        .ai-fab:hover { transform: scale(1.08); }
        .ai-panel {
            position: fixed; bottom: 5.5rem; right: 1.5rem; z-index: 1500;
            width: 340px; border-radius: .75rem;
            background: #111520; border: 1px solid rgba(255,255,255,.1);
            box-shadow: 0 8px 32px rgba(0,0,0,.5);
        }
        .ai-panel-header { padding: .85rem 1rem; border-bottom: 1px solid rgba(255,255,255,.07); display: flex; justify-content: space-between; align-items: center; }
        .ai-panel-messages { height: 220px; overflow-y: auto; padding: .75rem; display: flex; flex-direction: column; gap: .5rem; }
        .ai-msg-bot .ai-msg-content { background: rgba(255,255,255,.05); border-radius: .5rem; padding: .6rem .8rem; font-size: .82rem; color: rgba(255,255,255,.8); }
        .ai-msg-user .ai-msg-content { background: rgba(13,110,253,.2); border-radius: .5rem; padding: .6rem .8rem; font-size: .82rem; color: #fff; align-self: flex-end; }
        .ai-panel-input { padding: .75rem; border-top: 1px solid rgba(255,255,255,.07); }
        .ai-quick-btn { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); color: rgba(255,255,255,.6); border-radius: 20px; font-size: .72rem; padding: .2rem .65rem; cursor: pointer; }
        .ai-quick-btn:hover { background: rgba(255,255,255,.1); color: #fff; }

        /* ── Mobile ── */
        @media (max-width: 768px) {
            #sidebar { transform: translateX(-100%); }
            #sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }

        /* ── Business Switcher ── */
        #businessSwitcher { display: none; padding: 0 .5rem .5rem; }
        #businessSwitcher .switcher-item { display: flex; align-items: center; gap: .5rem; padding: .35rem .5rem; border-radius: .35rem; cursor: pointer; font-size: .8rem; color: rgba(255,255,255,.6); }
        #businessSwitcher .switcher-item:hover { background: rgba(255,255,255,.06); color: #fff; }
    </style>

    @stack('styles')
</head>
<body>

{{-- ── Sidebar ── --}}
<nav id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">🚀</div>
        <div style="flex:1">
            <div class="sidebar-brand-name">{{ auth()->user()->business->name ?? 'Marketing Hub' }}</div>
            <div class="sidebar-brand-sub">AI Marketing Platform</div>
        </div>
        <button onclick="toggleBusinessSwitcher()" class="btn btn-sm p-0" style="background:none;border:none;color:rgba(255,255,255,.3);" title="Switch Business">
            <i class="bi bi-chevron-expand"></i>
        </button>
    </div>

    {{-- Business Switcher --}}
    <div id="businessSwitcher">
        <div id="businessList" style="max-height:110px;overflow-y:auto;padding:.35rem .25rem;">
            <div style="color:rgba(255,255,255,.3);font-size:.75rem;padding:.25rem .5rem;">Loading...</div>
        </div>
        <div style="padding:.35rem .25rem 0;">
            <button onclick="showNewBusinessForm()" class="btn btn-sm w-100" style="background:rgba(255,255,255,.05);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);font-size:.75rem;">
                <i class="bi bi-plus-lg me-1"></i>New Business
            </button>
        </div>
        <div id="newBusinessForm" style="display:none;padding:.35rem .25rem 0;">
            <input type="text" id="newBusinessName" class="form-control form-control-sm mb-1" placeholder="Business name">
            <button onclick="createBusiness(this)" class="btn btn-primary btn-sm w-100" style="font-size:.75rem;"><i class="bi bi-check-lg me-1"></i>Create</button>
        </div>
    </div>

    <div class="py-2" style="flex:1">
        <div class="sidebar-section">Main</div>
        <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.upload') ? 'active' : '' }}" href="{{ route('dashboard.upload') }}">
            <i class="bi bi-cloud-arrow-up-fill"></i> Upload & Create
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.posts') ? 'active' : '' }}" href="{{ route('dashboard.posts') }}">
            <i class="bi bi-file-earmark-post"></i> Posts
        </a>

        <div class="sidebar-section">Insights</div>
        <a class="sidebar-link {{ request()->routeIs('dashboard.analytics') ? 'active' : '' }}" href="{{ route('dashboard.analytics') }}">
            <i class="bi bi-bar-chart-line-fill"></i> Analytics
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.calendar') ? 'active' : '' }}" href="{{ route('dashboard.calendar') }}">
            <i class="bi bi-calendar3"></i> Calendar
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.strategy') ? 'active' : '' }}" href="{{ route('dashboard.strategy') }}">
            <i class="bi bi-lightbulb-fill"></i> Strategy
        </a>

        <div class="sidebar-section">AI & Tools</div>
        <a class="sidebar-link {{ request()->routeIs('dashboard.agents') ? 'active' : '' }}" href="{{ route('dashboard.agents') }}">
            <i class="bi bi-robot"></i> AI Agents
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.seo') ? 'active' : '' }}" href="{{ route('dashboard.seo') }}">
            <i class="bi bi-search"></i> SEO Center
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.bot-training') ? 'active' : '' }}" href="{{ route('dashboard.bot-training') }}">
            <i class="bi bi-chat-heart"></i> Bot Training
        </a>

        <div class="sidebar-section">Business</div>
        <a class="sidebar-link {{ request()->routeIs('dashboard.hr') ? 'active' : '' }}" href="{{ route('dashboard.hr') }}">
            <i class="bi bi-person-badge-fill"></i> HR Center
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.jobs') ? 'active' : '' }}" href="{{ route('dashboard.jobs') }}">
            <i class="bi bi-briefcase-fill"></i> Jobs
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.billing') ? 'active' : '' }}" href="{{ route('dashboard.billing') }}">
            <i class="bi bi-credit-card"></i> Billing & Usage
        </a>

        <div class="sidebar-section">Setup</div>
        <a class="sidebar-link {{ request()->routeIs('dashboard.platforms') ? 'active' : '' }}" href="{{ route('dashboard.platforms') }}">
            <i class="bi bi-share-fill"></i> Platforms
        </a>
        <a class="sidebar-link {{ request()->routeIs('dashboard.settings') ? 'active' : '' }}" href="{{ route('dashboard.settings') }}">
            <i class="bi bi-gear-fill"></i> Settings
        </a>
    </div>

    <div class="sidebar-footer">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-2 d-flex align-items-center justify-content-center" style="width:32px;height:32px;background:rgba(255,255,255,.06);">
                <i class="bi bi-person-fill" style="color:rgba(255,255,255,.5);"></i>
            </div>
            <div style="min-width:0;flex:1;">
                <div style="font-size:.8rem;color:#fff;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ auth()->user()->name }}</div>
                <div style="font-size:.65rem;color:rgba(255,255,255,.35);">{{ ucfirst(auth()->user()->role ?? 'User') }}</div>
            </div>
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" title="Logout" class="btn p-0" style="color:rgba(255,255,255,.3);background:none;border:none;">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</nav>

{{-- ── Main Wrapper ── --}}
<div class="main-content">
    {{-- Top Bar --}}
    <div class="topbar">
        <button class="btn btn-sm d-md-none me-1" onclick="document.getElementById('sidebar').classList.toggle('show')">
            <i class="bi bi-list fs-5"></i>
        </button>
        <span style="color:rgba(255,255,255,.3);font-size:.85rem;" class="d-none d-md-inline">
            @yield('breadcrumb', '')
        </span>
        <div class="ms-auto d-flex align-items-center gap-2">
            {{-- Notification bell --}}
            <button class="btn btn-sm position-relative" id="notif-btn" style="color:rgba(255,255,255,.5);">
                <i class="bi bi-bell"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notif-badge" style="display:none;font-size:.6rem;">0</span>
            </button>
            <span style="font-size:.82rem;color:rgba(255,255,255,.5);">{{ auth()->user()->name }}</span>
        </div>
    </div>

    {{-- Flash Messages --}}
    <div class="px-4 pt-3" style="position:sticky;top:56px;z-index:99;">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i>{{ session('info') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                @foreach($errors->all() as $error) {{ $error }}<br> @endforeach
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        @endif
    </div>

    {{-- Page Content --}}
    <div class="page-body">
        @yield('content')
    </div>
</div>

{{-- ── AI Assistant FAB ── --}}
<button class="ai-fab" onclick="toggleAiAssistant()" title="AI Assistant">
    <i class="bi bi-robot"></i>
</button>

<div id="ai-assistant-panel" class="ai-panel" style="display:none;">
    <div class="ai-panel-header">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-2 d-flex align-items-center justify-content-center" style="width:28px;height:28px;background:linear-gradient(135deg,#0d6efd,#6f42c1);font-size:.75rem;">🤖</div>
            <div>
                <div style="font-size:.85rem;color:#fff;font-weight:600;">AI Assistant</div>
                <div style="font-size:.65rem;color:rgba(255,255,255,.4);">Marketing help, anytime</div>
            </div>
        </div>
        <button onclick="toggleAiAssistant()" class="btn btn-sm p-0" style="color:rgba(255,255,255,.4);background:none;border:none;font-size:1rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="ai-panel-messages" id="ai-messages">
        <div class="ai-msg ai-msg-bot">
            <div class="ai-msg-content">👋 Hi! I can help with platform setup, content strategy, hashtags, and more. What do you need?</div>
        </div>
    </div>
    <div class="ai-panel-input">
        <div class="d-flex gap-2">
            <input type="text" id="ai-input" class="form-control form-control-sm" placeholder="Ask a question…" onkeydown="if(event.key==='Enter')sendAiMessage()">
            <button onclick="sendAiMessage()" class="btn btn-primary btn-sm" id="ai-send-btn"><i class="bi bi-send"></i></button>
        </div>
        <div class="d-flex flex-wrap gap-1 mt-2">
            <button class="ai-quick-btn" onclick="askQuick('How do I connect Instagram?')">Instagram</button>
            <button class="ai-quick-btn" onclick="askQuick('What hashtags should I use?')">Hashtags</button>
            <button class="ai-quick-btn" onclick="askQuick('Best time to post?')">Best times</button>
        </div>
    </div>
</div>

{{-- Toast container --}}
<div id="toast-container" style="position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;width:320px;"></div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery (for DataTables) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Flatpickr -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

// ── AJAX Helpers ──────────────────────────────────────────────
async function ajaxGet(path) {
    const r = await fetch(path, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken } });
    const d = await r.json();
    if (!r.ok) throw new Error(d.message || 'Request failed');
    return d;
}
async function ajaxPost(path, body = {}, btn = null) {
    if (btn) btn.disabled = true;
    try {
        const r = await fetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify(body),
        });
        const d = await r.json();
        if (d.success) showToast(d.message || 'Done', 'success');
        else if (d.message) showToast(d.message, 'error');
        return d;
    } catch (e) {
        showToast(e.message || 'Request failed', 'error');
        return { success: false };
    } finally {
        if (btn) btn.disabled = false;
    }
}
async function ajaxDelete(path, btn = null) {
    if (btn) btn.disabled = true;
    try {
        const r = await fetch(path, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        });
        const d = await r.json();
        if (d.success) showToast(d.message || 'Deleted', 'success');
        else if (d.message) showToast(d.message, 'error');
        return d;
    } catch (e) {
        showToast(e.message || 'Request failed', 'error');
        return { success: false };
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ── Toast Helper ──────────────────────────────────────────────
function showToast(message, type = 'info') {
    const colors = { success: '#198754', error: '#dc3545', info: '#0dcaf0', warning: '#ffc107' };
    const icons  = { success: 'check-circle-fill', error: 'exclamation-triangle-fill', info: 'info-circle-fill', warning: 'exclamation-circle-fill' };
    const el = document.createElement('div');
    el.className = 'toast show';
    el.style.cssText = `background:#111520;border:1px solid rgba(255,255,255,.1);border-left:3px solid ${colors[type]};border-radius:.5rem;padding:.75rem 1rem;display:flex;align-items:center;gap:.6rem;color:#e2e8f0;font-size:.83rem;box-shadow:0 4px 16px rgba(0,0,0,.4);`;
    el.innerHTML = `<i class="bi bi-${icons[type]}" style="color:${colors[type]};font-size:1rem;"></i><span style="flex:1;">${message}</span><button onclick="this.parentElement.remove()" style="background:none;border:none;color:rgba(255,255,255,.4);cursor:pointer;font-size:.9rem;padding:0;"><i class="bi bi-x"></i></button>`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 5000);
}

// ── Sidebar Business Switcher ─────────────────────────────────
function toggleBusinessSwitcher() {
    const sw = document.getElementById('businessSwitcher');
    if (sw.style.display === 'none') {
        sw.style.display = 'block';
        loadBusinesses();
    } else { sw.style.display = 'none'; }
}
async function loadBusinesses() {
    try {
        const r = await fetch('/businesses', { headers: { 'Accept': 'application/json' } });
        const d = await r.json();
        const list = document.getElementById('businessList');
        if (d.businesses?.length) {
            list.innerHTML = d.businesses.map(b =>
                `<div class="switcher-item" onclick="doSwitchBusiness(${b.id},'${b.name.replace(/'/g,"\\'")}')"><i class="bi bi-building me-1"></i>${b.name}</div>`
            ).join('');
        } else { list.innerHTML = '<div style="color:rgba(255,255,255,.3);font-size:.75rem;padding:.25rem .5rem;">No other businesses.</div>'; }
    } catch(e) {}
}
async function doSwitchBusiness(id, name) {
    const r = await fetch(`/businesses/${id}/switch`, { method:'POST', headers:{'X-CSRF-TOKEN':csrfToken,'Content-Type':'application/json'}, body:'{}' });
    const d = await r.json();
    if (d.success) { showToast(`Switched to ${name}`, 'success'); setTimeout(()=>location.reload(), 800); }
}
function showNewBusinessForm() { document.getElementById('newBusinessForm').style.display='block'; }
async function createBusiness(btn) {
    const name = document.getElementById('newBusinessName').value.trim();
    if (!name) return;
    btn.disabled = true;
    const r = await fetch('/businesses', { method:'POST', headers:{'X-CSRF-TOKEN':csrfToken,'Content-Type':'application/json'}, body: JSON.stringify({name}) });
    const d = await r.json();
    if (d.success) { showToast('Business created!','success'); loadBusinesses(); document.getElementById('newBusinessForm').style.display='none'; document.getElementById('newBusinessName').value=''; }
    else showToast(d.message||'Failed','error');
    btn.disabled = false;
}

// ── AI Assistant ──────────────────────────────────────────────
let aiPanelOpen = false;
function toggleAiAssistant() {
    aiPanelOpen = !aiPanelOpen;
    document.getElementById('ai-assistant-panel').style.display = aiPanelOpen ? 'block' : 'none';
}
function askQuick(q) { document.getElementById('ai-input').value = q; sendAiMessage(); }
async function sendAiMessage() {
    const inp = document.getElementById('ai-input');
    const msg = inp.value.trim();
    if (!msg) return;
    const msgs = document.getElementById('ai-messages');
    msgs.innerHTML += `<div class="ai-msg ai-msg-user"><div class="ai-msg-content">${msg}</div></div>`;
    inp.value = '';
    msgs.scrollTop = msgs.scrollHeight;
    try {
        const r = await fetch('/ai-assistant', { method:'POST', headers:{'X-CSRF-TOKEN':csrfToken,'Content-Type':'application/json','Accept':'application/json'}, body: JSON.stringify({message:msg}) });
        const d = await r.json();
        msgs.innerHTML += `<div class="ai-msg ai-msg-bot"><div class="ai-msg-content">${d.response||d.message||'...'}</div></div>`;
    } catch(e) { msgs.innerHTML += `<div class="ai-msg ai-msg-bot"><div class="ai-msg-content">Sorry, I couldn't reach the server.</div></div>`; }
    msgs.scrollTop = msgs.scrollHeight;
}

// ── Notifications ─────────────────────────────────────────────
async function loadNotifs() {
    try {
        const r = await fetch('/insights', { headers:{'Accept':'application/json'} });
        const d = await r.json();
        if (d.insights?.length) {
            const badge = document.getElementById('notif-badge');
            badge.textContent = d.insights.length; badge.style.display='';
        }
    } catch(e) {}
}
document.addEventListener('DOMContentLoaded', () => { loadNotifs(); });
</script>

@stack('scripts')
</body>
</html>
