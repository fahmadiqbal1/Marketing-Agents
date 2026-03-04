@extends('layouts.app')

@section('title', 'Upload & Create')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <h1 class="page-title h3 mb-0">Upload & Create</h1>
    <p class="page-subtitle text-secondary small mb-0">Drop your photos, videos, or voice notes — AI handles the rest</p>
</div>

<div class="row row-cols-1 row-cols-lg-2 g-3 mb-4">
    {{-- Upload Zone --}}
    <div class="col"><div class="card p-4 h-100">
        <h3 style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">
            <i class="bi bi-cloud-arrow-up-fill" style="color: #00d4ff;"></i> Upload Media
        </h3>

        <form action="{{ route('dashboard.upload.handle') }}" method="POST" enctype="multipart/form-data" id="upload-form">
            @csrf
            <div class="upload-zone" id="drop-zone" onclick="document.getElementById('file-input').click()">
                <div class="upload-zone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                <p style="color: rgba(255,255,255,.5); font-size: .9rem; margin: 0 0 .5rem;">
                    Drag & drop files here, or <span style="color: #00d4ff; text-decoration: underline;">click to browse</span>
                </p>
                <p style="color: rgba(255,255,255,.25); font-size: .75rem; margin: 0;">
                    Supports images, videos, and audio — up to 100MB
                </p>
                <input type="file" name="media" id="file-input" accept="image/*,video/*,audio/*" style="display:none;">
            </div>

            {{-- File Preview --}}
            <div id="file-preview" style="display: none; margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,.03); border-radius: 12px; border: 1px solid rgba(255,255,255,.06);">
                <div style="display: flex; align-items: center; gap: .75rem;">
                    <div style="width: 48px; height: 48px; border-radius: 10px; background: rgba(0,212,255,.1); display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-file-earmark" style="color: #00d4ff; font-size: 1.2rem;" id="file-icon"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-size: .85rem; color: #fff; font-weight: 600;" id="file-name">—</div>
                        <div style="font-size: .7rem; color: rgba(255,255,255,.35);" id="file-size">—</div>
                    </div>
                    <button type="button" onclick="clearFile()" style="background: none; border: none; color: rgba(255,255,255,.3); font-size: 1.1rem; cursor: pointer; padding: .25rem;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>

            <div style="margin-top: 1.25rem;">
                <button type="submit" class="btn btn-primary" id="upload-btn" disabled>
                    <i class="bi bi-rocket-takeoff"></i> Upload & Process
                </button>
            </div>
        </form>
    </div></div>

    {{-- Instructions --}}
    <div class="col">
        <div class="card p-4 mb-3">
            <h3 style="color: #fff; font-size: 1rem; margin: 0 0 1rem;">
                <i class="bi bi-magic" style="color: #7c3aed;"></i> What happens after upload?
            </h3>

            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-primary" style="width:28px;height:28px;font-size:.75rem;font-weight:700;">1</div>
                <div>
                    <div style="font-size: .85rem; color: #fff; font-weight: 600;">AI Vision Analysis</div>
                    <div style="font-size: .75rem; color: rgba(255,255,255,.4);">Gemini 2.0 Flash scans your media to understand content, scenes, and context</div>
                </div>
            </div>
            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-primary" style="width:28px;height:28px;font-size:.75rem;font-weight:700;">2</div>
                <div>
                    <div style="font-size: .85rem; color: #fff; font-weight: 600;">Smart Captions</div>
                    <div style="font-size: .75rem; color: rgba(255,255,255,.4);">GPT-4o writes platform-optimized captions matching your brand voice</div>
                </div>
            </div>
            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-primary" style="width:28px;height:28px;font-size:.75rem;font-weight:700;">3</div>
                <div>
                    <div style="font-size: .85rem; color: #fff; font-weight: 600;">Hashtag Research</div>
                    <div style="font-size: .75rem; color: rgba(255,255,255,.4);">Trending and niche hashtags are researched for maximum reach</div>
                </div>
            </div>
            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-primary" style="width:28px;height:28px;font-size:.75rem;font-weight:700;">4</div>
                <div>
                    <div style="font-size: .85rem; color: #fff; font-weight: 600;">Review & Publish</div>
                    <div style="font-size: .75rem; color: rgba(255,255,255,.4);">Approve via Telegram or dashboard, and it publishes to all platforms</div>
                </div>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <h3 style="color: #fff; font-size: 1rem; margin: 0 0 .75rem;">
                <i class="bi bi-mic-fill" style="color: #10b981;"></i> Quick Tip
            </h3>
            <p style="font-size: .85rem; color: rgba(255,255,255,.5); margin: 0; line-height: 1.6;">
                You can also send media directly through <strong style="color: #fff;">Telegram</strong>! Just send a photo or video to your bot, and the AI will process it automatically. Voice notes work too — say what you want to post and the AI will create it.
            </p>
        </div>
    </div>
</div>

@push('scripts')
<script>
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const preview = document.getElementById('file-preview');
const uploadBtn = document.getElementById('upload-btn');

// Drag & Drop
['dragenter', 'dragover'].forEach(e => {
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); });
});
['dragleave', 'drop'].forEach(e => {
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); });
});
dropZone.addEventListener('drop', ev => {
    fileInput.files = ev.dataTransfer.files;
    showPreview(ev.dataTransfer.files[0]);
});
fileInput.addEventListener('change', () => {
    if (fileInput.files.length) showPreview(fileInput.files[0]);
});

function showPreview(file) {
    document.getElementById('file-name').textContent = file.name;
    document.getElementById('file-size').textContent = formatSize(file.size);
    const icon = document.getElementById('file-icon');
    if (file.type.startsWith('image')) icon.className = 'bi bi-image';
    else if (file.type.startsWith('video')) icon.className = 'bi bi-camera-video';
    else if (file.type.startsWith('audio')) icon.className = 'bi bi-mic';
    else icon.className = 'bi bi-file-earmark';
    preview.style.display = 'block';
    uploadBtn.disabled = false;
}

function clearFile() {
    fileInput.value = '';
    preview.style.display = 'none';
    uploadBtn.disabled = true;
}

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

// Upload progress UI
document.getElementById('upload-form').addEventListener('submit', function() {
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing…';
});
</script>
@endpush
@endsection
