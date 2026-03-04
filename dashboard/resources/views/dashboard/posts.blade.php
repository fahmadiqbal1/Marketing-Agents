@extends('layouts.app')

@section('title', 'Posts')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white">Posts</h1>
        <p class="text-secondary small mb-0">Manage and review your content pipeline</p>
    </div>
    <a href="{{ route('dashboard.upload') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Post
    </a>
</div>

{{-- Filters --}}
<div class="card p-3 mb-4">
    <form method="GET" action="{{ route('dashboard.posts') }}" class="d-flex flex-wrap gap-2 align-items-center">
        <select name="status" class="form-select form-select-sm" style="width:auto;min-width:140px;">
            <option value="">All Statuses</option>
            @foreach(['pending','approved','published','denied','failed'] as $s)
                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <select name="platform" class="form-select form-select-sm" style="width:auto;min-width:140px;">
            <option value="">All Platforms</option>
            @foreach(['instagram','facebook','youtube','linkedin','tiktok','twitter'] as $pl)
                <option value="{{ $pl }}" {{ request('platform') === $pl ? 'selected' : '' }}>{{ ucfirst($pl) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
        @if(request('status') || request('platform'))
            <a href="{{ route('dashboard.posts') }}" class="btn btn-link btn-sm text-secondary p-0">Clear</a>
        @endif
    </form>
</div>

{{-- Posts Table --}}
<div class="card mb-4">
    @if(count($posts) > 0)
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Content</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th>Scheduled</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($posts as $post)
                        @php
                            $pid      = $post['id'] ?? ($post->id ?? 0);
                            $status   = $post['status'] ?? ($post->status ?? 'pending');
                            $platform = $post['platform'] ?? ($post->platform ?? '');
                            $caption  = Str::limit($post['caption'] ?? ($post->caption ?? $post['title'] ?? ($post->title ?? 'Untitled')), 60);
                            $pillar   = $post['pillar'] ?? ($post->pillar ?? '');
                            $thumb    = $post['thumbnail_url'] ?? ($post->thumbnail_url ?? $post['media_url'] ?? ($post->media_url ?? ''));
                            $sched    = $post['scheduled_at'] ?? ($post->scheduled_at ?? $post['created_at'] ?? ($post->created_at ?? '--'));
                            $thread   = $post['thread_id'] ?? ($post->thread_id ?? '');
                            $badgeClass = match($status) {
                                'published' => 'bg-success',
                                'approved'  => 'bg-info text-dark',
                                'pending'   => 'bg-warning text-dark',
                                'denied','failed' => 'bg-danger',
                                default => 'bg-secondary',
                            };
                        @endphp
                        <tr>
                            <td class="ps-4" style="max-width:320px;">
                                <div class="d-flex align-items-center gap-2">
                                    @if($thumb)
                                        <img src="{{ $thumb }}" class="rounded flex-shrink-0"
                                             style="width:44px;height:44px;object-fit:cover;" alt="">
                                    @else
                                        <div class="rounded flex-shrink-0 d-flex align-items-center justify-content-center"
                                             style="width:44px;height:44px;background:rgba(255,255,255,.06);">
                                            <i class="bi bi-image text-secondary"></i>
                                        </div>
                                    @endif
                                    <div class="overflow-hidden">
                                        <div class="text-white small text-truncate">{{ $caption }}</div>
                                        <div class="text-secondary" style="font-size:.7rem;">{{ $pillar }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge rounded-2 p-2"
                                      style="background:rgba(255,255,255,.08);width:28px;height:28px;font-size:.75rem;">
                                    <i class="bi bi-{{ $platform ?: 'globe' }}"></i>
                                </span>
                            </td>
                            <td><span class="badge {{ $badgeClass }}">{{ $status }}</span></td>
                            <td class="text-secondary small">{{ $sched }}</td>
                            <td class="text-end pe-4">
                                @if($status === 'pending')
                                    <div class="d-flex gap-1 justify-content-end">
                                        <form method="POST" action="{{ route('dashboard.posts.approve', $pid) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="thread_id" value="{{ $thread }}">
                                            <button type="submit" class="btn btn-success btn-sm" title="Approve">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.posts.deny', $pid) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="thread_id" value="{{ $thread }}">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Deny">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                @elseif($status === 'published')
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($posts, 'links'))
            <div class="p-3">{{ $posts->links() }}</div>
        @endif
    @else
        <div class="text-center py-5 text-secondary">
            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
            <div class="text-white mb-2">No posts yet</div>
            <p class="small mb-4">Upload media or send photos via Telegram to get started</p>
            <a href="{{ route('dashboard.upload') }}" class="btn btn-primary">
                <i class="bi bi-cloud-arrow-up me-1"></i> Upload Now
            </a>
        </div>
    @endif
</div>
@endsection
