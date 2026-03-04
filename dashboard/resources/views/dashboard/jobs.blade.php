@extends('layouts.app')

@section('title', 'Jobs')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white">Jobs &amp; Recruitment</h1>
        <p class="text-secondary small mb-0">Manage job postings and candidates</p>
    </div>
    <a href="{{ route('dashboard.hr') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Create Posting
    </a>
</div>

{{-- Filter Bar --}}
<div class="card p-3 mb-4">
    <form method="GET" action="{{ route('dashboard.jobs') }}" class="d-flex flex-wrap gap-2 align-items-center">
        <select name="status" class="form-select form-select-sm" style="width:auto;min-width:140px;">
            <option value="">All Statuses</option>
            @foreach(['active','closed','draft'] as $s)
                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-funnel me-1"></i>Filter
        </button>
        @if(request('status'))
            <a href="{{ route('dashboard.jobs') }}" class="btn btn-link btn-sm text-secondary p-0">Clear</a>
        @endif
    </form>
</div>

{{-- Jobs List --}}
@php
    $jobList = $jobs instanceof \Illuminate\Database\Eloquent\Collection ? $jobs : (is_array($jobs) ? $jobs : []);
@endphp

@forelse($jobList as $job)
    @php
        $jobId     = $job['id'] ?? ($job->id ?? 0);
        $status    = $job['status'] ?? ($job->status ?? 'active');
        $candidates = $job['candidates'] ?? ($job->candidates ?? []);
        if ($candidates instanceof \Illuminate\Database\Eloquent\Collection) $candidates = $candidates->all();
        $badgeClass = match($status) {
            'active' => 'bg-success', 'closed' => 'bg-danger', 'draft' => 'bg-warning text-dark', default => 'bg-secondary',
        };
    @endphp
    <div class="card p-4 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="d-flex gap-3 align-items-start">
                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:44px;height:44px;background:rgba(124,58,237,.12);">
                    <i class="bi bi-briefcase-fill" style="color:#7c3aed;font-size:1.1rem;"></i>
                </div>
                <div>
                    <h6 class="text-white mb-0">{{ $job['title'] ?? ($job->title ?? 'Untitled Position') }}</h6>
                    <div class="text-secondary small mt-1">
                        {{ $job['department'] ?? ($job->department ?? '') }}
                        @if(!empty($job['department'] ?? $job->department ?? '') && !empty($job['location'] ?? $job->location ?? ''))
                            &middot;
                        @endif
                        {{ $job['location'] ?? ($job->location ?? '') }}
                    </div>
                </div>
            </div>
            <span class="badge {{ $badgeClass }}">{{ $status }}</span>
        </div>

        @php $desc = $job['description'] ?? ($job->description ?? ''); @endphp
        @if($desc)
            <p class="text-secondary small mb-3">{{ Str::limit($desc, 200) }}</p>
        @endif

        @php
            $skills = $job['skills'] ?? ($job->requirements ?? '');
            if (is_string($skills) && $skills) $skills = explode(',', $skills);
            elseif (!is_array($skills)) $skills = [];
        @endphp
        @if(count($skills) > 0)
            <div class="d-flex flex-wrap gap-1 mb-3">
                @foreach($skills as $skill)
                    <span class="badge" style="background:rgba(0,212,255,.1);color:#00d4ff;">{{ trim($skill) }}</span>
                @endforeach
            </div>
        @endif

        <div class="d-flex flex-wrap gap-3 text-secondary small mb-3">
            @if(!empty($job['salary_min'] ?? $job->salary_min ?? ''))
                <span><i class="bi bi-cash me-1"></i>
                    ${{ number_format($job['salary_min'] ?? $job->salary_min ?? 0) }}
                    @if(!empty($job['salary_max'] ?? $job->salary_max ?? '')) — ${{ number_format($job['salary_max'] ?? $job->salary_max ?? 0) }} @endif
                </span>
            @endif
            @if(!empty($job['type'] ?? $job->type ?? ''))
                <span><i class="bi bi-clock me-1"></i>{{ $job['type'] ?? ($job->type ?? '') }}</span>
            @endif
            <span><i class="bi bi-people me-1"></i>{{ count($candidates) }} candidates</span>
        </div>

        {{-- Candidates --}}
        @if(count($candidates) > 0)
            <div class="border-top border-secondary border-opacity-25 pt-3">
                <div class="text-secondary small fw-semibold mb-2">
                    <i class="bi bi-person-check me-1"></i> Candidates
                </div>
                @foreach($candidates as $c)
                    @php
                        $cId     = $c['id'] ?? ($c->id ?? 0);
                        $cStatus = $c['status'] ?? ($c->status ?? 'pending');
                        $cBadge  = match($cStatus) { 'approved' => 'bg-success', 'rejected' => 'bg-danger', default => 'bg-warning text-dark' };
                    @endphp
                    <div class="d-flex align-items-center gap-2 py-2 border-bottom border-secondary border-opacity-10">
                        <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:32px;height:32px;background:rgba(255,255,255,.06);">
                            <i class="bi bi-person text-secondary"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="text-white small">{{ $c['name'] ?? ($c->name ?? 'Unknown') }}</div>
                            <div class="text-secondary" style="font-size:.7rem;">{{ $c['email'] ?? ($c->email ?? '') }}</div>
                        </div>
                        <span class="badge {{ $cBadge }}">{{ $cStatus }}</span>
                        @if($cStatus === 'pending')
                            <div class="d-flex gap-1">
                                <form method="POST" action="{{ route('dashboard.jobs.approve', [$jobId, $cId]) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm py-0 px-2" title="Approve">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.jobs.reject', [$jobId, $cId]) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm py-0 px-2" title="Reject">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@empty
    <div class="card p-5 text-center text-secondary">
        <i class="bi bi-briefcase fs-1 d-block mb-3"></i>
        <div class="text-white mb-2">No job postings yet</div>
        <p class="small mb-4">Create your first job posting to start attracting candidates</p>
        <div>
            <a href="{{ route('dashboard.hr') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Create Job Posting
            </a>
        </div>
    </div>
@endforelse
@endsection
