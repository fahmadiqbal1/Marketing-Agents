@extends('layouts.app')

@section('title', 'Bot Training')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <h1 class="page-title h3 mb-0"><i class="bi bi-mortarboard"></i> Bot Training & Knowledge</h1>
    <p class="page-subtitle text-secondary small mb-0">Train your AI assistant with files, URLs, Q&A examples, and personality settings</p>
</div>

{{-- Tab Bar --}}
<div class="fade-in" style="display: flex; gap: 0; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,.08);">
    <button class="tab-btn active" onclick="switchTab('knowledge', this)"><i class="bi bi-database"></i> Knowledge Base</button>
    <button class="tab-btn" onclick="switchTab('personality', this)"><i class="bi bi-person-gear"></i> Personality</button>
    <button class="tab-btn" onclick="switchTab('qa', this)"><i class="bi bi-chat-left-quote"></i> Q&A Training</button>
    <button class="tab-btn" onclick="switchTab('test', this)"><i class="bi bi-chat-dots"></i> Test Chat</button>
</div>

{{-- ═══ Knowledge Base Tab ═══ --}}
<div id="tab-knowledge" class="tab-panel fade-in">
    <div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
        <div class="col"><div class="card p-4 h-100">
            {{-- File Upload --}}
            <h3 style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">
                <i class="bi bi-file-earmark-zip" style="color: #7c3aed;"></i> Upload Training Files
            </h3>
            <div id="dropZone" style="border: 2px dashed rgba(255,255,255,.15); border-radius: 12px; padding: 2rem; text-align: center; cursor: pointer; transition: border-color .2s;"
                 onclick="document.getElementById('fileInput').click()"
                 ondragover="event.preventDefault(); this.style.borderColor='#00d4ff';"
                 ondragleave="this.style.borderColor='rgba(255,255,255,.15)';"
                 ondrop="event.preventDefault(); this.style.borderColor='rgba(255,255,255,.15)'; handleFileDrop(event);">
                <i class="bi bi-cloud-arrow-up" style="font-size: 2rem; color: rgba(255,255,255,.3);"></i>
                <p style="color: rgba(255,255,255,.5); margin: .5rem 0 0; font-size: .85rem;">
                    Drop files here or click to browse
                </p>
                <p style="color: rgba(255,255,255,.25); margin: .25rem 0 0; font-size: .7rem;">
                    Supports: ZIP, TXT, MD, PDF, CSV, JSON, HTML, DOCX
                </p>
            </div>
            <input type="file" id="fileInput" style="display:none;" accept=".zip,.txt,.md,.pdf,.csv,.json,.html,.docx" onchange="uploadFile(this)">
            <div id="uploadStatus" style="margin-top: .75rem; display: none;"></div>
        </div>

        </div></div>
        <div class="col"><div class="card p-4 h-100">
            {{-- URL Training --}}
            <h3 style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">
                <i class="bi bi-link-45deg" style="color: #00d4ff;"></i> Train from URL
            </h3>
            <p style="font-size: .8rem; color: rgba(255,255,255,.4); margin-bottom: 1rem;">
                Paste any webpage URL and the AI will extract and learn from its content.
            </p>
            <div class="form-group" style="margin-bottom: .75rem;">
                <label class="form-label">Website URL</label>
                <input type="url" id="trainUrl" class="form-control" placeholder="https://example.com/about">
            </div>
            <button class="btn btn-primary btn-sm" style="width: 100%; justify-content: center;" onclick="trainFromUrl(this)">
                <i class="bi bi-download"></i> Extract & Train
            </button>
            <div id="urlStatus" style="margin-top: .75rem; display: none;"></div>
        </div></div>
    </div>

    {{-- Knowledge Sources List --}}
    <div class="card p-4 mb-4">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="color: #fff; font-size: 1rem; margin: 0;">
                <i class="bi bi-journal-text" style="color: #10b981;"></i> Stored Knowledge Sources
            </h3>
            <span id="totalChunks" style="color: rgba(255,255,255,.4); font-size: .8rem;">Loading...</span>
        </div>
        <div id="knowledgeList">
            <div style="text-align: center; padding: 1rem; color: rgba(255,255,255,.4);">
                <i class="bi bi-hourglass-split"></i> Loading knowledge base...
            </div>
        </div>
    </div>
</div>

{{-- ═══ Personality Tab ═══ --}}
<div id="tab-personality" class="tab-panel" style="display:none;">
    <div class="card p-4 mb-4" style="padding: 1.5rem; max-width: 700px;">
        <h3 style="color: #fff; font-size: 1rem; margin: 0 0 1.25rem;">
            <i class="bi bi-person-gear" style="color: #7c3aed;"></i> Bot Personality Settings
        </h3>

        <div class="form-group" style="margin-bottom: 1rem;">
            <label class="form-label">Persona Name</label>
            <input type="text" id="personaName" class="form-control" placeholder="Marketing Assistant">
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
            <label class="form-label">Tone</label>
            <div style="display: flex; gap: .5rem; flex-wrap: wrap;">
                <button type="button" class="tone-btn btn btn-primary btn-sm" data-tone="professional" onclick="selectTone('professional')">Professional</button>
                <button type="button" class="tone-btn btn btn-outline-secondary btn-sm" data-tone="friendly" onclick="selectTone('friendly')">Friendly</button>
                <button type="button" class="tone-btn btn btn-outline-secondary btn-sm" data-tone="casual" onclick="selectTone('casual')">Casual</button>
                <button type="button" class="tone-btn btn btn-outline-secondary btn-sm" data-tone="witty" onclick="selectTone('witty')">Witty</button>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
            <label class="form-label">Response Style</label>
            <div style="display: flex; gap: .5rem; flex-wrap: wrap;">
                <button type="button" class="style-btn btn btn-primary btn-sm" data-style="detailed" onclick="selectStyle('detailed')">Detailed</button>
                <button type="button" class="style-btn btn btn-outline-secondary btn-sm" data-style="concise" onclick="selectStyle('concise')">Concise</button>
                <button type="button" class="style-btn btn btn-outline-secondary btn-sm" data-style="bullet" onclick="selectStyle('bullet')">Bullet Points</button>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
            <label class="form-label">Industry Context</label>
            <input type="text" id="industryContext" class="form-control" placeholder="e.g., Healthcare, Real Estate, Tech...">
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
            <label class="form-label">Custom System Prompt (optional)</label>
            <textarea id="systemPrompt" class="form-control" rows="4" placeholder="Override the default system prompt..."></textarea>
            <div style="font-size: .7rem; color: rgba(255,255,255,.25); margin-top: .3rem;">Leave empty to use the default AI personality</div>
        </div>

        <button class="btn btn-primary" style="width: 100%; justify-content: center;" onclick="savePersonality(this)">
            <i class="bi bi-check-lg"></i> Save Personality
        </button>
    </div>
</div>

{{-- ═══ Q&A Training Tab ═══ --}}
<div id="tab-qa" class="tab-panel" style="display:none;">
    <div class="card p-4 mb-4" style="padding: 1.5rem; margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="color: #fff; font-size: 1rem; margin: 0;">
                <i class="bi bi-chat-left-quote" style="color: #f59e0b;"></i> Training Examples
            </h3>
            <span id="exampleCount" style="color: rgba(255,255,255,.4); font-size: .85rem;">0 examples</span>
        </div>

        <div id="trainingExamples" style="margin-bottom: 1.5rem;">
            <div style="text-align: center; padding: 1rem; color: rgba(255,255,255,.4);">Loading...</div>
        </div>

        <div style="border-top: 1px solid rgba(255,255,255,.08); padding-top: 1rem;">
            <h4 style="color: #fff; font-size: .95rem; margin: 0 0 .75rem;">Add New Example</h4>
            <div class="form-group" style="margin-bottom: .75rem;">
                <label class="form-label">Customer Question</label>
                <input type="text" id="trainQuestion" class="form-control" placeholder="What question might a customer ask?">
            </div>
            <div class="form-group" style="margin-bottom: .75rem;">
                <label class="form-label">Ideal Answer</label>
                <textarea id="trainAnswer" class="form-control" rows="3" placeholder="How should the bot respond?"></textarea>
            </div>
            <button class="btn btn-primary btn-sm" onclick="addTrainingExample(this)">
                <i class="bi bi-plus-circle"></i> Add Example
            </button>
        </div>
    </div>
</div>

{{-- ═══ Test Chat Tab ═══ --}}
<div id="tab-test" class="tab-panel" style="display:none;">
    <div class="card p-4 mb-4" style="padding: 1.5rem; max-width: 700px;">
        <h3 style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">
            <i class="bi bi-chat-dots" style="color: #00d4ff;"></i> Test Your Bot
        </h3>
        <div id="chatMessages" style="min-height: 300px; max-height: 500px; overflow-y: auto; margin-bottom: 1rem; padding: .5rem;">
            <div style="color: rgba(255,255,255,.4); text-align: center; padding: 2rem;">
                Type a message to test your bot's personality and knowledge
            </div>
        </div>
        <div style="display: flex; gap: .5rem;">
            <input type="text" id="chatInput" class="form-control" placeholder="Type a test message..." style="flex: 1;"
                   onkeypress="if(event.key==='Enter'){sendTestChat(document.querySelector('#chatInput').nextElementSibling.nextElementSibling); event.preventDefault();}">
            <button class="btn btn-primary btn-sm" onclick="sendTestChat(this)">
                <i class="bi bi-send"></i> Send
            </button>
        </div>
    </div>
</div>

<script>
// Tab management
function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.style.display = 'none'; });
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('tab-' + tabId).style.display = 'block';
    btn.classList.add('active');
}

// Tone & Style selection
var selectedTone = 'professional';
var selectedStyle = 'detailed';

function selectTone(tone) {
    selectedTone = tone;
    document.querySelectorAll('.tone-btn').forEach(function(b) {
        b.classList.toggle('btn-primary', b.dataset.tone === tone);
        b.classList.toggle('btn-outline-secondary', b.dataset.tone !== tone);
    });
}

function selectStyle(style) {
    selectedStyle = style;
    document.querySelectorAll('.style-btn').forEach(function(b) {
        b.classList.toggle('btn-primary', b.dataset.style === style);
        b.classList.toggle('btn-outline-secondary', b.dataset.style !== style);
    });
}

// Knowledge Base - File Upload
function handleFileDrop(event) {
    var files = event.dataTransfer.files;
    if (files.length > 0) uploadFileObj(files[0]);
}

function uploadFile(input) {
    if (input.files.length > 0) uploadFileObj(input.files[0]);
}

async function uploadFileObj(file) {
    var statusDiv = document.getElementById('uploadStatus');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<div style="color: #00d4ff; font-size: .85rem;"><i class="bi bi-hourglass-split"></i> Uploading and processing ' + escapeHtml(file.name) + '...</div>';

    var formData = new FormData();
    formData.append('file', file);

    try {
        var resp = await fetch('/bot-training/upload', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });
        var data = await resp.json();
        if (data.success) {
            statusDiv.innerHTML = '<div class="inline-alert alert-success"><i class="bi bi-check-circle"></i> ' + (data.message || 'File processed') + '</div>';
            loadKnowledge();
        } else {
            statusDiv.innerHTML = '<div class="inline-alert alert-error"><i class="bi bi-x-circle"></i> ' + (data.message || data.error || 'Upload failed') + '</div>';
        }
    } catch (e) {
        statusDiv.innerHTML = '<div class="inline-alert alert-error"><i class="bi bi-x-circle"></i> Upload failed: ' + e.message + '</div>';
    }
}

// Knowledge Base - URL Training
async function trainFromUrl(btn) {
    var url = document.getElementById('trainUrl').value.trim();
    if (!url) { showToast('warning', 'Missing URL', 'Please enter a URL'); return; }
    var statusDiv = document.getElementById('urlStatus');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<div style="color: #00d4ff; font-size: .85rem;"><i class="bi bi-hourglass-split"></i> Fetching and processing...</div>';

    var result = await ajaxPost('/bot-training/train-url', { url: url }, btn);
    if (result.success) {
        statusDiv.innerHTML = '<div class="inline-alert alert-success"><i class="bi bi-check-circle"></i> ' + (result.message || 'URL processed') + '</div>';
        loadKnowledge();
    } else {
        statusDiv.innerHTML = '<div class="inline-alert alert-error"><i class="bi bi-x-circle"></i> ' + (result.message || result.error || 'Failed') + '</div>';
    }
}

// Knowledge Base - List & Delete
async function loadKnowledge() {
    var listDiv = document.getElementById('knowledgeList');
    var totalSpan = document.getElementById('totalChunks');
    try {
        var data = await ajaxGet('/bot-training/knowledge');
        var sources = data.sources || [];
        totalSpan.textContent = (data.total_chunks || 0) + ' total chunks';

        if (sources.length === 0) {
            listDiv.innerHTML = '<div style="text-align:center; padding:1.5rem; color:rgba(255,255,255,.4);"><i class="bi bi-inbox"></i> No knowledge sources yet. Upload files or paste URLs above.</div>';
            return;
        }

        var html = '';
        sources.forEach(function(s) {
            html += '<div class="card p-3" style="padding:.75rem 1rem; margin-bottom:.5rem; display:flex; align-items:center; gap:.75rem;">';
            html += '<i class="bi bi-file-text" style="color:#00d4ff;"></i>';
            html += '<div style="flex:1;">';
            html += '<div style="font-size:.85rem; color:#fff;">' + escapeHtml(s.source) + '</div>';
            html += '<div style="font-size:.7rem; color:rgba(255,255,255,.35);">' + s.chunks + ' chunks' + (s.added_at ? ' &middot; ' + s.added_at.substring(0, 10) : '') + '</div>';
            html += '</div>';
            html += '<button class="btn btn-danger btn-sm" onclick="deleteKnowledge(\'' + encodeURIComponent(s.source) + '\', this)"><i class="bi bi-trash"></i></button>'
            html += '</div>';
        });
        listDiv.innerHTML = html;
    } catch (e) {
        listDiv.innerHTML = '<div style="color:#f87171; text-align:center; padding:1rem;">Failed to load knowledge base</div>';
    }
}

async function deleteKnowledge(sourceId, btn) {
    if (!confirm('Delete this knowledge source?')) return;
    var result = await ajaxDelete('/bot-training/knowledge/' + sourceId, btn);
    if (result.success) loadKnowledge();
}

// Personality
async function loadPersonality() {
    try {
        var data = await ajaxGet('/bot-training/personality');
        if (data.personality) {
            var p = data.personality;
            document.getElementById('personaName').value = p.persona_name || 'Marketing Assistant';
            document.getElementById('industryContext').value = p.industry_context || '';
            document.getElementById('systemPrompt').value = p.system_prompt_override || '';
            selectTone(p.tone || 'professional');
            selectStyle(p.response_style || 'detailed');

            var examples = [];
            try { examples = JSON.parse(p.trained_examples_json || '[]'); } catch(e) {}
            renderExamples(examples);
        }
    } catch (e) {
        console.error('Load personality error:', e);
    }
}

async function savePersonality(btn) {
    var result = await ajaxPost('/bot-training/personality', {
        persona_name: document.getElementById('personaName').value,
        tone: selectedTone,
        response_style: selectedStyle,
        industry_context: document.getElementById('industryContext').value || null,
        system_prompt_override: document.getElementById('systemPrompt').value || null,
    }, btn);
}

// Q&A Training
function renderExamples(examples) {
    var container = document.getElementById('trainingExamples');
    document.getElementById('exampleCount').textContent = examples.length + ' example' + (examples.length !== 1 ? 's' : '');

    if (examples.length === 0) {
        container.innerHTML = '<div style="color: rgba(255,255,255,.4); text-align: center; padding: 1rem;">No training examples yet. Add some below!</div>';
        return;
    }

    var html = '';
    examples.forEach(function(ex, i) {
        html += '<div class="card p-3" style="padding: .75rem 1rem; margin-bottom: .5rem;">';
        html += '<div style="display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .4rem;">';
        html += '<span style="color: #f59e0b; font-size: .8rem; white-space: nowrap; font-weight: 600;">Q' + (i+1) + ':</span>';
        html += '<span style="color: #fff; font-size: .85rem;">' + escapeHtml(ex.q) + '</span>';
        html += '</div>';
        html += '<div style="display: flex; align-items: flex-start; gap: .5rem;">';
        html += '<span style="color: #10b981; font-size: .8rem; white-space: nowrap; font-weight: 600;">A' + (i+1) + ':</span>';
        html += '<span style="color: rgba(255,255,255,.7); font-size: .85rem;">' + escapeHtml(ex.a) + '</span>';
        html += '</div></div>';
    });
    container.innerHTML = html;
}

async function addTrainingExample(btn) {
    var q = document.getElementById('trainQuestion').value.trim();
    var a = document.getElementById('trainAnswer').value.trim();
    if (!q || !a) { showToast('warning', 'Missing Fields', 'Both question and answer are required'); return; }

    var result = await ajaxPost('/bot-training/train', { question: q, answer: a }, btn);
    if (result.success) {
        document.getElementById('trainQuestion').value = '';
        document.getElementById('trainAnswer').value = '';
        loadPersonality(); // Refresh examples
    }
}

// Test Chat
async function sendTestChat(btn) {
    var input = document.getElementById('chatInput');
    var msg = input.value.trim();
    if (!msg) return;

    var container = document.getElementById('chatMessages');
    // Clear placeholder
    var placeholder = container.querySelector('div[style*="text-align: center"]');
    if (placeholder) container.innerHTML = '';

    container.innerHTML += '<div style="text-align: right; margin-bottom: .75rem;"><span style="display: inline-block; background: rgba(124,58,237,.3); border: 1px solid rgba(124,58,237,.4); padding: .5rem .75rem; border-radius: 12px 12px 0 12px; color: #fff; max-width: 80%; text-align: left;">' + escapeHtml(msg) + '</span></div>';
    input.value = '';
    container.scrollTop = container.scrollHeight;

    var typingId = 'typing_' + Date.now();
    container.innerHTML += '<div id="' + typingId + '" style="margin-bottom: .75rem;"><span style="display: inline-block; background: rgba(255,255,255,.05); padding: .5rem .75rem; border-radius: 12px 12px 12px 0; color: rgba(255,255,255,.5);"><i class="bi bi-three-dots"></i> Typing...</span></div>';
    container.scrollTop = container.scrollHeight;

    try {
        var data = await ajaxPost('/bot-training/test', { message: msg }, btn);
        var el = document.getElementById(typingId);
        if (el) el.remove();
        var reply = data.response || 'No response';
        container.innerHTML += '<div style="margin-bottom: .75rem;"><span style="display: inline-block; background: rgba(0,212,255,.1); border: 1px solid rgba(0,212,255,.2); padding: .5rem .75rem; border-radius: 12px 12px 12px 0; color: #fff; max-width: 80%;">' + escapeHtml(reply) + '</span></div>';
    } catch (e) {
        var el = document.getElementById(typingId);
        if (el) el.remove();
        container.innerHTML += '<div style="margin-bottom: .75rem; color: #ef4444; font-size: .85rem;">Error: Could not get response</div>';
    }
    container.scrollTop = container.scrollHeight;
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Init
document.addEventListener('DOMContentLoaded', function() {
    loadKnowledge();
    loadPersonality();
});
</script>

<style>
.tab-btn {
    background: none; border: none; color: rgba(255,255,255,.4); padding: .75rem 1.25rem;
    font-size: .85rem; cursor: pointer; border-bottom: 2px solid transparent; transition: all .2s;
}
.tab-btn:hover { color: rgba(255,255,255,.7); }
.tab-btn.active { color: #00d4ff; border-bottom-color: #00d4ff; }
.inline-alert { border-radius: 8px; padding: .75rem 1rem; font-size: .85rem; line-height: 1.5; }
.alert-success { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3); color: #10b981; }
.alert-error { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.3); color: #f87171; }
.glass-sm { background: rgba(255,255,255,.03); border-radius: 8px; border: 1px solid rgba(255,255,255,.06); }
/* Bootstrap class overrides for toggle buttons */
</style>
@endsection
