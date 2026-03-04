@extends('layouts.app')

@section('title', 'HR')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-person-badge-fill text-warning me-2"></i>HR Center</h1>
        <p class="text-secondary small mb-0">AI-powered hiring, resume screening, and employer branding</p>
    </div>
</div>

{{-- ── Tab Navigation ─────────────────── --}}
<ul class="nav nav-tabs mb-4" id="hrTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="job-tab" data-bs-toggle="tab" data-bs-target="#tab-job-posting" type="button" role="tab">
            <i class="bi bi-briefcase me-1"></i>Create Job Posting
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="resume-tab" data-bs-toggle="tab" data-bs-target="#tab-resume" type="button" role="tab">
            <i class="bi bi-file-earmark-person me-1"></i>Screen Resume
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="brand-tab" data-bs-toggle="tab" data-bs-target="#tab-brand" type="button" role="tab">
            <i class="bi bi-megaphone me-1"></i>Employer Branding
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="listings-tab" data-bs-toggle="tab" data-bs-target="#tab-listings" type="button" role="tab">
            <i class="bi bi-list-check me-1"></i>Active Listings
        </button>
    </li>
</ul>

<div class="tab-content" id="hrTabsContent">

    {{-- ── Job Posting Tab ─────────────── --}}
    <div class="tab-pane fade show active" id="tab-job-posting" role="tabpanel">
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="card p-4 h-100">
                    <h5 class="mb-3">New Job Posting</h5>
                    <div class="mb-3">
                        <label class="form-label">Job Title</label>
                        <input type="text" id="job-title" class="form-control" placeholder="e.g., Senior Marketing Manager">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <input type="text" id="job-dept" class="form-control" placeholder="e.g., Marketing, Engineering">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label">Job Type</label>
                            <select id="job-type" class="form-select">
                                <option value="full-time">Full Time</option>
                                <option value="part-time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="internship">Internship</option>
                                <option value="remote">Remote</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Experience Level</label>
                            <select id="job-level" class="form-select">
                                <option value="entry">Entry Level</option>
                                <option value="mid">Mid Level</option>
                                <option value="senior">Senior Level</option>
                                <option value="lead">Lead / Principal</option>
                                <option value="executive">Executive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Key Requirements <small class="text-secondary">(one per line)</small></label>
                        <textarea id="job-requirements" class="form-control" rows="4" placeholder="5+ years marketing experience&#10;SEO/SEM expertise&#10;Team leadership"></textarea>
                    </div>
                    <button class="btn btn-primary" onclick="createJobPosting(this)" id="job-btn">
                        <i class="bi bi-magic me-1"></i>Generate Job Posting
                    </button>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card p-4 h-100" id="job-results">
                    <h5 class="mb-3">Generated Posting</h5>
                    <div class="text-center py-4 text-secondary">
                        <i class="bi bi-briefcase fs-2 d-block mb-2"></i>
                        Fill in the details and click Generate
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Resume Screen Tab ─────────────── --}}
    <div class="tab-pane fade" id="tab-resume" role="tabpanel">
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="card p-4 h-100">
                    <h5 class="mb-3">Screen Resume</h5>
                    <div class="mb-3">
                        <label class="form-label">Resume Text</label>
                        <textarea id="resume-text" class="form-control" rows="7" placeholder="Paste the candidate's resume text here..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Job Title to Match</label>
                        <input type="text" id="resume-job" class="form-control" placeholder="e.g., Frontend Developer">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Required Skills <small class="text-secondary">(comma-separated)</small></label>
                        <input type="text" id="resume-skills" class="form-control" placeholder="React, TypeScript, Node.js">
                    </div>
                    <button class="btn btn-primary" onclick="screenResume(this)" id="resume-btn">
                        <i class="bi bi-person-check me-1"></i>Screen Resume
                    </button>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card p-4 h-100" id="resume-results">
                    <h5 class="mb-3">Screening Results</h5>
                    <div class="text-center py-4 text-secondary">
                        <i class="bi bi-file-earmark-person fs-2 d-block mb-2"></i>
                        Paste a resume and job details to screen
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Employer Branding Tab ─────────────── --}}
    <div class="tab-pane fade" id="tab-brand" role="tabpanel">
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="card p-4 h-100">
                    <h5 class="mb-3">Employer Brand Post</h5>
                    <div class="mb-3">
                        <label class="form-label">Topic / Theme</label>
                        <input type="text" id="brand-topic" class="form-control" placeholder="e.g., team culture, behind the scenes, employee spotlight">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Target Platform</label>
                        <select id="brand-platform" class="form-select">
                            <option value="linkedin">LinkedIn</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="twitter">Twitter / X</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" onclick="generateBrandPost(this)" id="brand-btn">
                        <i class="bi bi-megaphone me-1"></i>Generate Post
                    </button>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card p-4 h-100" id="brand-results">
                    <h5 class="mb-3">Brand Post Preview</h5>
                    <div class="text-center py-4 text-secondary">
                        <i class="bi bi-megaphone fs-2 d-block mb-2"></i>
                        Choose a topic and platform to generate
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Active Listings Tab ────────────── --}}
    <div class="tab-pane fade" id="tab-listings" role="tabpanel">
        <div class="card p-4 mb-4">
            <h5 class="mb-3"><i class="bi bi-briefcase text-warning me-2"></i>Active Job Listings</h5>
            @if(isset($listings) && $listings->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Department</th>
                                <th>Type</th>
                                <th class="text-center">Candidates</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Posted</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($listings as $listing)
                            <tr>
                                <td class="fw-semibold">{{ $listing->title ?? 'Untitled' }}</td>
                                <td class="text-secondary">{{ $listing->department ?? '—' }}</td>
                                <td><span class="badge bg-secondary">{{ $listing->job_type ?? 'full-time' }}</span></td>
                                <td class="text-center text-info">{{ $listing->candidates_count ?? 0 }}</td>
                                <td class="text-center">
                                    <span class="badge {{ ($listing->status ?? 'active') === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                        {{ ucfirst($listing->status ?? 'active') }}
                                    </span>
                                </td>
                                <td class="text-end text-secondary small">
                                    {{ $listing->created_at ? $listing->created_at->format('M d, Y') : '—' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-briefcase fs-2 d-block mb-2"></i>
                    No active listings yet.
                </div>
            @endif
        </div>

        <div class="card p-4">
            <h5 class="mb-3"><i class="bi bi-people text-info me-2"></i>Recent Candidates</h5>
            @if(isset($candidates) && $candidates->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role Applied</th>
                                <th class="text-center">Score</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Applied</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($candidates as $candidate)
                            <tr>
                                <td class="fw-semibold">{{ $candidate->name ?? 'Anonymous' }}</td>
                                <td class="text-secondary small">{{ $candidate->jobListing->title ?? '—' }}</td>
                                <td class="text-center">
                                    @php $score = $candidate->match_score ?? 0; @endphp
                                    <span class="badge {{ $score >= 70 ? 'bg-success' : ($score >= 40 ? 'bg-warning text-dark' : 'bg-danger') }}">
                                        {{ $score }}%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary">{{ ucfirst($candidate->status ?? 'new') }}</span>
                                </td>
                                <td class="text-end text-secondary small">
                                    {{ $candidate->created_at ? $candidate->created_at->format('M d, Y') : '—' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-person-x fs-2 d-block mb-2"></i>
                    No candidates yet.
                </div>
            @endif
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function renderAiResult(containerId, title, content) {
    document.getElementById(containerId).innerHTML = `
        <h5 class="mb-3">${title}</h5>
        <div class="bg-dark rounded p-3 mb-3" style="font-size:.85rem;color:#e2e8f0;line-height:1.7;white-space:pre-wrap;max-height:400px;overflow-y:auto;">${content}</div>
        <button class="btn btn-outline-secondary btn-sm" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent);showToast('Copied!','success')">
            <i class="bi bi-clipboard me-1"></i>Copy
        </button>`;
}

async function createJobPosting(btn) {
    const title = document.getElementById('job-title').value.trim();
    if (!title) { showToast('Please enter a job title.', 'warning'); return; }

    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating...';
    document.getElementById('job-results').innerHTML = '<h5 class="mb-3">Generated Posting</h5><div class="text-center py-3"><div class="spinner-border text-info"></div></div>';

    try {
        const data = await ajaxPost('/ai-assistant', {
            task: 'hr_job_posting',
            title,
            department: document.getElementById('job-dept').value,
            job_type: document.getElementById('job-type').value,
            experience_level: document.getElementById('job-level').value,
            requirements: document.getElementById('job-requirements').value.split('\n').filter(r => r.trim()),
        }, btn);
        const result = data.result || data.content || data.text || JSON.stringify(data, null, 2);
        renderAiResult('job-results', 'Generated Posting', result);
        showToast('Job posting generated!', 'success');
    } catch(e) {
        document.getElementById('job-results').innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Failed to generate posting.</div>';
    }
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-magic me-1"></i>Generate Job Posting';
}

async function screenResume(btn) {
    const resumeText = document.getElementById('resume-text').value.trim();
    const jobTitle = document.getElementById('resume-job').value.trim();
    if (!resumeText || !jobTitle) { showToast('Please paste a resume and enter a job title.', 'warning'); return; }

    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Screening...';
    document.getElementById('resume-results').innerHTML = '<h5 class="mb-3">Screening Results</h5><div class="text-center py-3"><div class="spinner-border text-info"></div></div>';

    try {
        const data = await ajaxPost('/ai-assistant', {
            task: 'hr_screen_resume',
            resume_text: resumeText,
            job_title: jobTitle,
            required_skills: document.getElementById('resume-skills').value.split(',').map(s => s.trim()).filter(Boolean),
        }, btn);
        const result = data.result || data.content || data.text || JSON.stringify(data, null, 2);
        const score = data.score || data.match_score;
        let html = '<h5 class="mb-3">Screening Results</h5>';
        if (score !== undefined) {
            const cls = score >= 70 ? 'success' : score >= 40 ? 'warning' : 'danger';
            html += `<div class="text-center mb-3"><span class="badge bg-${cls} fs-4 py-2 px-3">${score}% Match</span></div>`;
        }
        html += `<div class="bg-dark rounded p-3 mb-3" style="font-size:.85rem;color:#e2e8f0;line-height:1.7;white-space:pre-wrap;max-height:350px;overflow-y:auto;">${result}</div>
            <button class="btn btn-outline-secondary btn-sm" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent);showToast('Copied!','success')">
                <i class="bi bi-clipboard me-1"></i>Copy</button>`;
        document.getElementById('resume-results').innerHTML = html;
        showToast('Resume screened!', 'success');
    } catch(e) {
        document.getElementById('resume-results').innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Screening failed.</div>';
    }
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-person-check me-1"></i>Screen Resume';
}

async function generateBrandPost(btn) {
    const topic = document.getElementById('brand-topic').value.trim();
    if (!topic) { showToast('Please enter a topic.', 'warning'); return; }

    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating...';
    document.getElementById('brand-results').innerHTML = '<h5 class="mb-3">Brand Post Preview</h5><div class="text-center py-3"><div class="spinner-border text-info"></div></div>';

    try {
        const data = await ajaxPost('/ai-assistant', {
            task: 'hr_brand_post',
            topic,
            platform: document.getElementById('brand-platform').value,
        }, btn);
        const result = data.result || data.content || data.text || data.post || JSON.stringify(data, null, 2);
        renderAiResult('brand-results', 'Brand Post Preview', result);
        showToast('Brand post generated!', 'success');
    } catch(e) {
        document.getElementById('brand-results').innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Generation failed.</div>';
    }
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-megaphone me-1"></i>Generate Post';
}
</script>
@endpush
