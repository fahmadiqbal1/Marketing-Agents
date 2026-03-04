@extends('layouts.app')

@section('title', 'SEO')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <h1 class="page-title h3 mb-0"><i class="bi bi-search" style="color: #00d4ff;"></i> SEO Center</h1>
    <p class="page-subtitle text-secondary small mb-0">AI-powered search engine optimization for your business</p>
</div>

{{-- ── Tab Navigation ───────────────────── --}}
<div class="tab-nav">
    <button class="tab-btn active" onclick="switchTab('keywords')"><i class="bi bi-tags"></i> Keyword Research</button>
    <button class="tab-btn" onclick="switchTab('audit')"><i class="bi bi-clipboard-check"></i> Content Audit</button>
    <button class="tab-btn" onclick="switchTab('gmb')"><i class="bi bi-geo-alt-fill"></i> Google My Business</button>
</div>

{{-- ── Keywords Tab ─────────────────────── --}}
<div class="tab-content active" id="tab-keywords">
    <div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
        <div class="glass hover-lift" style="padding: 1.5rem;">
            <h3 class="section-header" style="color: #fff; font-size: 1rem; margin: 0 0 1.25rem;">Generate Keywords</h3>
            <div class="form-group">
                <label class="form-label">Business Topic / Niche</label>
                <input type="text" id="kw-topic" class="form-control" placeholder="e.g., digital marketing agency">
            </div>
            <div class="form-group">
                <label class="form-label">Number of Keywords</label>
                <select id="kw-count" class="form-select">
                    <option value="10">10 keywords</option>
                    <option value="20" selected>20 keywords</option>
                    <option value="30">30 keywords</option>
                    <option value="50">50 keywords</option>
                </select>
            </div>
            <button class="btn btn-primary" onclick="generateKeywords()" id="kw-btn">
                <i class="bi bi-magic"></i> Generate Keywords
            </button>
        </div>
        <div class="card p-4 mb-4" id="kw-results">
            <h3 class="section-header" style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">Results</h3>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-tags"></i></div>
                <div class="empty-state-text">No keywords generated yet</div>
                <div class="empty-state-sub">Enter a topic and click Generate</div>
            </div>
        </div>
    </div>
</div>

{{-- ── Audit Tab ────────────────────────── --}}
<div class="tab-content" id="tab-audit">
    <div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
        <div class="glass hover-lift" style="padding: 1.5rem;">
            <h3 class="section-header" style="color: #fff; font-size: 1rem; margin: 0 0 1.25rem;">Content SEO Audit</h3>
            <div class="form-group">
                <label class="form-label">Content Text to Audit</label>
                <textarea id="audit-content" class="form-control" rows="6" placeholder="Paste your content here..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Content Category</label>
                <input type="text" id="audit-category" class="form-control" placeholder="e.g., blog post, product page">
            </div>
            <button class="btn btn-primary" onclick="runAudit()" id="audit-btn">
                <i class="bi bi-clipboard-check"></i> Run Audit
            </button>
        </div>
        <div class="card p-4 mb-4" id="audit-results">
            <h3 class="section-header" style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">Audit Report</h3>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-clipboard-check"></i></div>
                <div class="empty-state-text">No audit results yet</div>
                <div class="empty-state-sub">Paste content and run an audit</div>
            </div>
        </div>
    </div>
</div>

{{-- ── GMB Tab ──────────────────────────── --}}
<div class="tab-content" id="tab-gmb">
    <div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
        <div class="glass hover-lift" style="padding: 1.5rem;">
            <h3 class="section-header" style="color: #fff; font-size: 1rem; margin: 0 0 1.25rem;">Generate GMB Post</h3>
            <div class="form-group">
                <label class="form-label">Content Description</label>
                <textarea id="gmb-description" class="form-control" rows="4" placeholder="Describe the content or promotion..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Category</label>
                <input type="text" id="gmb-category" class="form-control" placeholder="e.g., service, offer, event">
            </div>
            <button class="btn btn-primary" onclick="generateGMB()" id="gmb-btn">
                <i class="bi bi-geo-alt-fill"></i> Generate Post
            </button>
        </div>
        <div class="card p-4 mb-4" id="gmb-results">
            <h3 class="section-header" style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">GMB Post Preview</h3>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-geo-alt-fill"></i></div>
                <div class="empty-state-text">No GMB post generated yet</div>
                <div class="empty-state-sub">Describe your content and generate a post</div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.target.closest('.tab-btn').classList.add('active');
}

async function generateKeywords() {
    const topic = document.getElementById('kw-topic').value.trim();
    if (!topic) { showToast('Please enter a topic.', 'warning'); return; }

    const btn = document.getElementById('kw-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Generating...';
    document.getElementById('kw-results').innerHTML = '<h5 class="mb-3">Results</h5><div class="text-center py-3"><div class="spinner-border text-info"></div></div>';

    try {
        const data = await ajaxPost('/ai-assistant', {
            task: 'seo_keywords',
            topic,
            count: parseInt(document.getElementById('kw-count').value),
        }, btn);

        const keywords = data.keywords || (typeof data.result === 'string' ?
            data.result.split('\n').filter(k => k.trim()) : []);
        document.getElementById('kw-results').innerHTML = `
            <h5 class="mb-3">Results (${keywords.length})</h5>
            <div class="d-flex flex-wrap gap-2 mb-2">
                ${keywords.map(kw => `<span class="badge bg-secondary" style="cursor:pointer;font-size:.8rem;" onclick="navigator.clipboard.writeText('${kw.replace(/'/g,"\\'")}');showToast('Copied!','success')">${kw}</span>`).join('')}
            </div>
            <p class="text-secondary small"><i class="bi bi-info-circle me-1"></i>Click any keyword to copy</p>
        `;
        showToast('Keywords generated!', 'success');
    } catch (e) {
        document.getElementById('kw-results').innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Failed to generate keywords.</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-magic me-1"></i>Generate Keywords';
}

async function runAudit() {
    const content = document.getElementById('audit-content').value.trim();
    const category = document.getElementById('audit-category').value.trim();
    if (!content) { showToast('Please paste content to audit.', 'warning'); return; }

    const btn = document.getElementById('audit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Auditing...';
    document.getElementById('audit-results').innerHTML = '<h5 class="mb-3">Audit Report</h5><div class="text-center py-3"><div class="spinner-border text-info"></div></div>';

    try {
        const data = await ajaxPost('/ai-assistant', {
            task: 'seo_audit',
            content,
            category: category || 'general',
        }, btn);
        const audit = data.audit || data;
        const score = audit.score || data.score;
        const recommendations = audit.recommendations || data.result || data.content || JSON.stringify(audit, null, 2);
        let html = '<h5 class="mb-3">Audit Report</h5>';
        if (score !== undefined) {
            const cls = score >= 70 ? 'success' : score >= 40 ? 'warning' : 'danger';
            html += `<div class="text-center mb-3"><span class="badge bg-${cls} fs-4 py-2 px-3">${score}/100 SEO Score</span></div>`;
        }
        html += `<div class="bg-dark rounded p-3 mb-2" style="font-size:.85rem;color:#e2e8f0;line-height:1.7;white-space:pre-wrap;max-height:350px;overflow-y:auto;">${typeof recommendations === 'string' ? recommendations : JSON.stringify(recommendations, null, 2)}</div>`;
        document.getElementById('audit-results').innerHTML = html;
        showToast('Audit complete!', 'success');
    } catch (e) {
        document.getElementById('audit-results').innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Audit failed.</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-clipboard-check me-1"></i>Run Audit';
}

async function generateGMB() {
    const description = document.getElementById('gmb-description').value.trim();
    const category = document.getElementById('gmb-category').value.trim();
    if (!description) { showToast('Please describe the content.', 'warning'); return; }

    const btn = document.getElementById('gmb-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Generating...';
    document.getElementById('gmb-results').innerHTML = '<h5 class="mb-3">GMB Post Preview</h5><div class="text-center py-3"><div class="spinner-border text-info"></div></div>';

    try {
        const data = await ajaxPost('/ai-assistant', {
            task: 'seo_gmb_post',
            content_description: description,
            content_category: category || 'general',
        }, btn);
        const post = data.post || data.result || data.content || data.text || JSON.stringify(data, null, 2);
        document.getElementById('gmb-results').innerHTML = `
            <h5 class="mb-3">GMB Post Preview</h5>
            <div class="d-flex align-items-center gap-2 mb-3">
                <div class="rounded d-flex align-items-center justify-content-center" style="width:32px;height:32px;background:rgba(66,133,244,.2);">
                    <i class="bi bi-geo-alt-fill text-info"></i>
                </div>
                <div class="fw-semibold small">Google My Business Post</div>
            </div>
            <div class="bg-dark rounded p-3 mb-2" style="font-size:.85rem;color:#e2e8f0;line-height:1.7;white-space:pre-wrap;max-height:350px;overflow-y:auto;">${post}</div>
            <button class="btn btn-outline-secondary btn-sm" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent);showToast('Copied!','success')">
                <i class="bi bi-clipboard me-1"></i>Copy to Clipboard
            </button>
        `;
        showToast('GMB post generated!', 'success');
    } catch (e) {
        document.getElementById('gmb-results').innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>GMB generation failed.</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-geo-alt-fill me-1"></i>Generate Post';
}
</script>
@endpush
