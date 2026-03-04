@extends('layouts.app')

@section('title', 'Strategy & Growth')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white">Strategy &amp; Growth</h1>
        <p class="text-secondary small mb-0">AI-powered insights and content recommendations</p>
    </div>
</div>

{{-- Strategy Brief --}}
<div class="card p-4 mb-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="stat-icon purple"><i class="bi bi-bullseye"></i></div>
        <div>
            <h6 class="text-white mb-0">Content Strategy Brief</h6>
            <p class="text-secondary small mb-0">AI-generated recommendations based on your performance</p>
        </div>
    </div>

    @php $strat = is_array($strategy) && !isset($strategy['error']) ? $strategy : []; @endphp
    @if(!empty($strat))
        <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
            <div class="col">
                <div class="card p-3 h-100">
                    <div class="text-uppercase text-secondary mb-2" style="font-size:.7rem;letter-spacing:.06em;">Focus Area</div>
                    <div class="text-white small">{{ $strat['focus_area'] ?? $strat['primary_focus'] ?? '—' }}</div>
                </div>
            </div>
            <div class="col">
                <div class="card p-3 h-100">
                    <div class="text-uppercase text-secondary mb-2" style="font-size:.7rem;letter-spacing:.06em;">Best Posting Time</div>
                    <div class="text-white small">{{ $strat['best_time'] ?? $strat['optimal_posting_time'] ?? '—' }}</div>
                </div>
            </div>
            <div class="col">
                <div class="card p-3 h-100">
                    <div class="text-uppercase text-secondary mb-2" style="font-size:.7rem;letter-spacing:.06em;">Recommended Frequency</div>
                    <div class="text-white small">{{ $strat['frequency'] ?? $strat['posting_frequency'] ?? '—' }}</div>
                </div>
            </div>
        </div>

        @if(!empty($strat['recommendations'] ?? $strat['summary'] ?? null))
            <div class="card p-3">
                <div class="text-secondary small mb-2"><i class="bi bi-lightbulb text-warning me-1"></i> Key Recommendations</div>
                @if(is_array($strat['recommendations'] ?? null))
                    <ul class="text-secondary small mb-0" style="padding-left:1.25rem;line-height:1.8;">
                        @foreach($strat['recommendations'] as $rec)
                            <li>{{ $rec }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-secondary small mb-0">{{ $strat['summary'] ?? $strat['recommendations'] ?? '' }}</p>
                @endif
            </div>
        @endif
    @else
        <div class="text-center py-4 text-secondary">
            <i class="bi bi-compass fs-2 d-block mb-2"></i>
            Strategy brief will be generated after you publish a few posts
        </div>
    @endif
</div>

{{-- Growth Ideas --}}
<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="stat-icon green"><i class="bi bi-rocket-takeoff"></i></div>
            <h6 class="text-white mb-0">Growth Ideas</h6>
        </div>
        <span class="badge bg-info text-dark">AI Generated</span>
    </div>

    @php $ideaList = is_array($ideas) && !isset($ideas['error']) ? ($ideas['ideas'] ?? $ideas) : []; @endphp
    @if(count($ideaList) > 0)
        <div class="row row-cols-1 row-cols-lg-2 g-3">
            @foreach($ideaList as $i => $idea)
                <div class="col">
                    <div class="card p-3 h-100">
                        <div class="d-flex gap-3">
                            <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0 fw-bold"
                                 style="width:28px;height:28px;background:rgba(16,185,129,.1);color:#10b981;font-size:.75rem;">
                                {{ $i + 1 }}
                            </div>
                            <div>
                                <div class="text-white small fw-semibold mb-1">
                                    {{ is_array($idea) ? ($idea['title'] ?? $idea['idea'] ?? '') : $idea }}
                                </div>
                                @if(is_array($idea) && !empty($idea['description'] ?? $idea['details'] ?? null))
                                    <div class="text-secondary" style="font-size:.8rem;line-height:1.5;">
                                        {{ $idea['description'] ?? $idea['details'] }}
                                    </div>
                                @endif
                                @if(is_array($idea) && !empty($idea['platform'] ?? null))
                                    <span class="badge bg-info text-dark mt-1">{{ $idea['platform'] }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-4 text-secondary">
            Growth ideas will appear here once the AI analyzes your content
        </div>
    @endif
</div>

{{-- Content Gaps --}}
<div class="card p-4 mb-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="stat-icon amber"><i class="bi bi-puzzle-fill"></i></div>
        <div>
            <h6 class="text-white mb-0">Content Gaps</h6>
            <p class="text-secondary small mb-0">Topics and themes your content is missing</p>
        </div>
    </div>

    @php $gapList = is_array($gaps) && !isset($gaps['error']) ? ($gaps['gaps'] ?? $gaps) : []; @endphp
    @if(count($gapList) > 0)
        <div class="d-flex flex-wrap gap-2">
            @foreach($gapList as $gap)
                @php
                    $gapLabel = is_array($gap) ? ($gap['topic'] ?? $gap['gap'] ?? $gap['title'] ?? '') : $gap;
                    $priority = is_array($gap) ? ($gap['priority'] ?? null) : null;
                    $priorityBadge = $priority === 'high' ? 'bg-danger' : 'bg-warning text-dark';
                @endphp
                <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded"
                     style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);">
                    <i class="bi bi-exclamation-diamond text-warning" style="font-size:.8rem;"></i>
                    <span class="text-white small">{{ $gapLabel }}</span>
                    @if($priority)
                        <span class="badge {{ $priorityBadge }}" style="font-size:.65rem;">{{ $priority }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-4 text-secondary">
            <i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>
            No content gaps detected — great work!
        </div>
    @endif
</div>
@endsection
