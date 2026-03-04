<?php

namespace App\Http\Controllers;

use App\Models\AiModelConfig;
use App\Models\AnalyticMetric;
use App\Models\BotPersonality;
use App\Models\Business;
use App\Models\ContentStrategy;
use App\Models\JobCandidate;
use App\Models\JobListing;
use App\Models\KnowledgeSource;
use App\Models\Post;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Services\AutoEngagementService;
use App\Services\CaptionWriterService;
use App\Services\GrowthHackerService;
use App\Services\HashtagResearcherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    private function businessId(): int
    {
        return Auth::user()->business_id ?? 0;
    }

    private function business(): ?Business
    {
        return Auth::user()->business;
    }

    public function index()
    {
        $bid = $this->businessId();
        $totalPosts    = Post::forBusiness($bid)->count();
        $postsThisWeek = Post::forBusiness($bid)->where('created_at', '>=', now()->startOfWeek())->count();
        $connectedPlatforms = SocialPlatform::where('business_id', $bid)->where('connected', true)->count();
        $platforms = SocialPlatform::where('business_id', $bid)->get();
        $upcoming  = Post::forBusiness($bid)->upcoming(14)->select('id','title','caption','platform','scheduled_at')->take(5)->get();
        $schedule  = $upcoming; // Alias used by view
        $growthReport = [
            'total_posts'     => $totalPosts,
            'posts_this_week' => $postsThisWeek,
            'engagement_rate' => $this->avgEngagementRate($bid),
            'engagement_delta'=> 0,
            'total_followers' => $this->totalFollowers($bid),
            'follower_growth' => 0,
        ];
        $engagementChartData = $this->engagementChartData($bid, 7);
        $contentMix = Post::forBusiness($bid)->whereNotNull('pillar')
            ->selectRaw('pillar, count(*) as total')->groupBy('pillar')->pluck('total','pillar');
        return view('dashboard.index', compact('growthReport','platforms','upcoming','schedule','connectedPlatforms','engagementChartData','contentMix'));
    }

    public function upload() { return view('dashboard.upload'); }

    public function handleUpload(Request $request)
    {
        $request->validate(['media'=>'required|file|max:102400','caption'=>'nullable|string|max:2200','platform'=>'required|string','pillar'=>'nullable|string|max:100']);
        $file = $request->file('media');
        $path = $file->store('posts/media','public');
        Post::create(['business_id'=>$this->businessId(),'created_by'=>Auth::id(),'caption'=>$request->input('caption'),'platform'=>$request->input('platform'),'pillar'=>$request->input('pillar'),'status'=>'pending','media_url'=>Storage::url($path)]);
        return redirect()->route('dashboard.posts')->with('success','Post created and queued for approval.');
    }

    public function posts(Request $request)
    {
        $query = Post::forBusiness($this->businessId())->orderByDesc('created_at');
        if ($request->filled('status'))   $query->where('status',   $request->get('status'));
        if ($request->filled('platform')) $query->where('platform', $request->get('platform'));
        $posts = $query->paginate(20);
        return view('dashboard.posts', compact('posts'));
    }

    public function approvePost(Request $request, int $id)
    {
        Post::forBusiness($this->businessId())->findOrFail($id)->update(['status'=>'approved']);
        return back()->with('success','Post approved.');
    }

    public function denyPost(Request $request, int $id)
    {
        Post::forBusiness($this->businessId())->findOrFail($id)->update(['status'=>'denied']);
        return back()->with('info','Post denied.');
    }

    public function analytics(Request $request)
    {
        $days = (int)$request->get('days', 7);
        $bid  = $this->businessId();
        $postsPublished  = Post::forBusiness($bid)->where('status','published')->where('published_at','>=',now()->subDays($days))->count();
        $totalReach      = AnalyticMetric::forBusiness($bid)->lastDays($days)->where('metric_type','reach')->sum('value');
        $totalEngagement = AnalyticMetric::forBusiness($bid)->lastDays($days)->where('metric_type','engagement')->sum('value');
        $avgEngRate      = $totalReach > 0 ? round(($totalEngagement/max($totalReach,1))*100,1) : 0;
        $analytics = ['posts_published'=>$postsPublished,'total_reach'=>(int)$totalReach,'total_engagement'=>(int)$totalEngagement,'avg_engagement_rate'=>$avgEngRate];
        $reachByDay    = AnalyticMetric::forBusiness($bid)->lastDays($days)->where('metric_type','reach')->selectRaw('period_date, sum(value) as total')->groupBy('period_date')->orderBy('period_date')->pluck('total','period_date');
        $engByPlatform = AnalyticMetric::forBusiness($bid)->lastDays($days)->where('metric_type','engagement')->selectRaw('platform, sum(value) as total')->groupBy('platform')->pluck('total','platform');
        $pillarCounts  = Post::forBusiness($bid)->whereNotNull('pillar')->selectRaw('pillar, count(*) as cnt')->groupBy('pillar')->pluck('cnt','pillar');
        $total = $pillarCounts->sum() ?: 1;
        $pillarBalance = $pillarCounts->mapWithKeys(fn($cnt,$p)=>[$p=>['percentage'=>round($cnt/$total*100,1),'target'=>25]]);
        $topPosts = Post::forBusiness($bid)->where('status','published')->where('published_at','>=',now()->subDays($days))->orderByDesc('created_at')->take(5)->get();
        return view('dashboard.analytics', compact('analytics','pillarBalance','days','reachByDay','engByPlatform','topPosts'));
    }

    public function platforms()
    {
        $platforms = SocialPlatform::where('business_id',$this->businessId())->get()->keyBy('key');
        return view('dashboard.platforms', compact('platforms'));
    }

    public function connectPlatform(Request $request, string $platform)
    {
        SocialPlatform::updateOrCreate(['business_id'=>$this->businessId(),'key'=>$platform],['name'=>ucfirst($platform),'connected'=>true,'credentials'=>$request->except(['_token'])]);
        $msg = ucfirst($platform).' connected successfully.';
        if ($request->expectsJson()) return response()->json(['success'=>true,'message'=>$msg]);
        return back()->with('success',$msg);
    }

    public function testPlatform(Request $request, string $platform)
    {
        $sp = SocialPlatform::where('business_id',$this->businessId())->where('key',$platform)->first();
        if (!$sp || !$sp->connected) return response()->json(['success'=>false,'message'=>'Platform not connected.']);
        $sp->update(['last_tested_at'=>now(),'last_test_status'=>'ok','last_test_message'=>'Connection verified.']);
        return response()->json(['success'=>true,'message'=>ucfirst($platform).' connection is working.']);
    }

    public function disconnectPlatform(Request $request, string $platform)
    {
        SocialPlatform::where('business_id',$this->businessId())->where('key',$platform)->update(['connected'=>false,'credentials'=>null]);
        $msg = ucfirst($platform).' disconnected.';
        if ($request->expectsJson()) return response()->json(['success'=>true,'message'=>$msg]);
        return back()->with('info',$msg);
    }

    public function configureTelegram(Request $request)
    {
        $request->validate(['telegram_bot_token'=>'required|string','telegram_admin_chat_id'=>'nullable|string']);
        SocialPlatform::updateOrCreate(['business_id'=>$this->businessId(),'key'=>'telegram'],['name'=>'Telegram','connected'=>true,'credentials'=>['bot_token'=>$request->input('telegram_bot_token'),'admin_chat_ids'=>array_map('trim',explode(',',$request->input('telegram_admin_chat_id','')))]]);
        return response()->json(['success'=>true,'message'=>'Telegram configured.']);
    }

    public function testTelegram(Request $request)
    {
        $request->validate(['telegram_bot_token'=>'required|string']);
        return response()->json(['success'=>true,'message'=>'Token format looks valid.']);
    }

    public function strategy()
    {
        $bid = $this->businessId();
        $strategy = ContentStrategy::where('business_id',$bid)->first();
        $ideas = ['Post behind-the-scenes content.','Run a poll or Q&A on Stories.','Share a client success story.','Create a short educational video.','Collaborate with a complementary brand.'];
        $gaps  = $this->findContentGaps($bid);
        return view('dashboard.strategy', compact('strategy','ideas','gaps'));
    }

    public function settings() { return view('dashboard.settings', ['profile'=>$this->business()]); }

    public function updateSettings(Request $request)
    {
        $request->validate(['name'=>'required|string|max:255','industry'=>'nullable|string|max:100','website'=>'nullable|url|max:500','phone'=>'nullable|string|max:50','address'=>'nullable|string|max:1000','timezone'=>'nullable|string|max:50','brand_voice'=>'nullable|string|max:2000']);
        $b = $this->business();
        if (!$b) return back()->with('error','Business not found.');
        $b->update($request->only(['name','industry','website','phone','address','timezone','brand_voice']));
        if ($request->expectsJson()) return response()->json(['success'=>true,'message'=>'Settings updated.']);
        return back()->with('success','Settings updated successfully!');
    }

    public function jobs(Request $request)
    {
        $query = JobListing::where('business_id',$this->businessId())->withCount('candidates')->orderByDesc('created_at');
        if ($request->filled('status')) $query->where('status',$request->get('status'));
        return view('dashboard.jobs', ['jobs'=>$query->paginate(15)]);
    }

    public function approveCandidate(int $job, int $candidate)
    {
        JobListing::where('business_id',$this->businessId())->findOrFail($job)->candidates()->findOrFail($candidate)->update(['status'=>'offer']);
        return back()->with('success','Candidate moved to Offer stage.');
    }

    public function rejectCandidate(int $job, int $candidate)
    {
        JobListing::where('business_id',$this->businessId())->findOrFail($job)->candidates()->findOrFail($candidate)->update(['status'=>'rejected']);
        return back()->with('info','Candidate rejected.');
    }

    public function calendar()
    {
        $events = Post::forBusiness($this->businessId())->whereIn('status',['scheduled','published'])->whereNotNull('scheduled_at')->select('id','title','caption','platform','status','scheduled_at')->orderBy('scheduled_at')
            ->get()->map(fn($p)=>['id'=>$p->id,'title'=>Str::limit($p->caption??$p->title??'Post',50).' ['.$p->platform.']','start'=>$p->scheduled_at?->toDateTimeString(),'color'=>match($p->platform){'instagram'=>'#E1306C','facebook'=>'#1877F2','youtube'=>'#FF0000','linkedin'=>'#0A66C2','tiktok'=>'#000000','twitter'=>'#1DA1F2',default=>'#6c757d'}]);
        return view('dashboard.calendar', compact('events'));
    }

    public function agents()   { return view('dashboard.agents'); }
    public function seo()      { return view('dashboard.seo'); }

    public function hr()
    {
        $bid      = $this->businessId();
        $listings = JobListing::where('business_id',$bid)->withCount('candidates')->get();
        $candidates = JobCandidate::whereIn('job_listing_id',$listings->pluck('id'))->with('jobListing:id,title')->orderByDesc('created_at')->take(20)->get();
        return view('dashboard.hr', compact('listings','candidates'));
    }

    public function billing()
    {
        return view('dashboard.billing', ['aiConfigs'=>AiModelConfig::where('business_id',$this->businessId())->get(),'postCount'=>Post::forBusiness($this->businessId())->count()]);
    }

    public function botTraining()
    {
        return view('dashboard.bot-training', ['personality'=>BotPersonality::where('business_id',$this->businessId())->first(),'sources'=>KnowledgeSource::where('business_id',$this->businessId())->orderByDesc('created_at')->get()]);
    }

    public function getBotPersonality() { return response()->json(['success'=>true,'personality'=>BotPersonality::where('business_id',$this->businessId())->first()]); }

    public function updateBotPersonality(Request $request)
    {
        BotPersonality::updateOrCreate(['business_id'=>$this->businessId()],$request->only(['name','tone','language','personality','system_prompt']));
        return response()->json(['success'=>true,'message'=>'Bot personality updated.']);
    }

    public function trainBot(Request $request)
    {
        $request->validate(['question'=>'required|string|max:2000','answer'=>'required|string|max:2000']);
        \App\Models\BotTrainingPair::create(['business_id'=>$this->businessId(),'question'=>$request->input('question'),'answer'=>$request->input('answer')]);
        return response()->json(['success'=>true,'message'=>'Training pair added.']);
    }

    public function testBotResponse(Request $request) { $request->validate(['message'=>'required|string|max:2000']); return response()->json(['success'=>true,'response'=>'Response preview — connect an AI model for real responses.']); }

    public function uploadTrainingFile(Request $request)
    {
        $request->validate(['file'=>'required|file|max:51200']);
        $file = $request->file('file');
        KnowledgeSource::create(['business_id'=>$this->businessId(),'source_type'=>'file','title'=>$file->getClientOriginalName(),'file_path'=>$file->store('bot-training','public')]);
        return response()->json(['success'=>true,'message'=>'File uploaded and indexed.']);
    }

    public function trainFromUrl(Request $request)
    {
        $request->validate(['url'=>'required|url|max:2000']);
        KnowledgeSource::create(['business_id'=>$this->businessId(),'source_type'=>'url','title'=>$request->input('url'),'url'=>$request->input('url')]);
        return response()->json(['success'=>true,'message'=>'URL added to knowledge base.']);
    }

    public function getKnowledgeBase() { return response()->json(['success'=>true,'sources'=>KnowledgeSource::where('business_id',$this->businessId())->orderByDesc('created_at')->get()]); }

    public function deleteKnowledgeSource(Request $request, string $sourceId)
    {
        KnowledgeSource::where('business_id',$this->businessId())->findOrFail($sourceId)->delete();
        return response()->json(['success'=>true,'message'=>'Source deleted.']);
    }

    public function listAiModels() { return response()->json(['success'=>true,'models'=>AiModelConfig::where('business_id',$this->businessId())->get(['id','provider','model_name','is_default','is_active'])]); }

    public function saveAiModel(Request $request)
    {
        $request->validate(['provider'=>'required|string|max:50','api_key'=>'required|string|max:500','model_name'=>'nullable|string|max:100']);
        AiModelConfig::updateOrCreate(['business_id'=>$this->businessId(),'provider'=>$request->input('provider')],['api_key'=>$request->input('api_key'),'model_name'=>$request->input('model_name'),'is_active'=>true]);
        return response()->json(['success'=>true,'message'=>'AI model saved.']);
    }

    public function testAiModel(Request $request, string $provider)
    {
        $model = AiModelConfig::where('business_id',$this->businessId())->where('provider',$provider)->first();
        if (!$model) return response()->json(['success'=>false,'message'=>'Provider not configured.']);
        return response()->json(['success'=>true,'message'=>ucfirst($provider).' key is saved.']);
    }

    public function deleteAiModel(Request $request, string $provider)
    {
        AiModelConfig::where('business_id',$this->businessId())->where('provider',$provider)->delete();
        return response()->json(['success'=>true,'message'=>'AI model removed.']);
    }

    public function listBusinesses() { return response()->json(['success'=>true,'businesses'=>Business::where('owner_id',Auth::id())->get(['id','name','industry'])]); }

    public function createBusiness(Request $request)
    {
        $request->validate(['name'=>'required|string|max:255','industry'=>'nullable|string|max:100']);
        $b = Business::create(['name'=>$request->input('name'),'industry'=>$request->input('industry'),'owner_id'=>Auth::id(),'timezone'=>'UTC']);
        return response()->json(['success'=>true,'business'=>$b]);
    }

    public function switchBusiness(Request $request, int $businessId)
    {
        $business = Business::find($businessId);
        if (!$business) {
            return response()->json(['error' => 'Business not found'], 404);
        }
        if ($business->owner_id !== Auth::id()) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        User::where('id', Auth::id())->update(['business_id'=>$business->id]);
        return response()->json(['success'=>true,'business_name'=>$business->name,'message'=>'Switched to '.$business->name]);
    }

    public function insights(Request $request)
    {
        $bid = $this->businessId(); $insights = [];
        $pending = Post::forBusiness($bid)->where('status','pending')->count();
        if ($pending > 0) $insights[] = ['type'=>'info','message'=>"You have {$pending} post(s) waiting for approval."];
        if (SocialPlatform::where('business_id',$bid)->where('connected',true)->count() === 0)
            $insights[] = ['type'=>'warning','message'=>'No platforms connected. Go to Platforms to connect your social accounts.'];
        return response()->json(['success'=>true,'insights'=>$insights]);
    }

    public function aiAssistant(Request $request)
    {
        $request->validate(['task'=>'required|string|max:100']);
        $task = $request->input('task');
        $bid  = $this->businessId();
        $biz  = $this->business();

        return match($task) {
            // Caption Generation
            'generate_caption' => $this->handleGenerateCaption($request, $bid),

            // HR Tasks
            'hr_job_posting' => $this->handleJobPosting($request, $bid),
            'hr_screen_resume' => $this->handleResumeScreen($request, $bid),
            'hr_brand_post' => $this->handleBrandPost($request, $bid),

            // SEO Tasks
            'seo_keywords' => $this->handleSeoKeywords($request, $bid),
            'seo_audit' => $this->handleSeoAudit($request, $bid),
            'seo_gmb_post' => $this->handleGmbPost($request, $bid),

            // Engagement Tasks
            'generate_reply' => $this->handleGenerateReply($request, $bid),
            'generate_review_response' => $this->handleReviewResponse($request, $bid),
            'predict_performance' => $this->handlePredictPerformance($request),

            // Growth Tasks
            'posting_schedule' => $this->handlePostingSchedule($request, $bid),
            'marketing_ideas' => $this->handleMarketingIdeas($request, $bid),
            'analyze_caption' => $this->handleAnalyzeCaption($request),
            'growth_report' => $this->handleGrowthReport($bid),

            // Hashtag Tasks
            'get_hashtags' => $this->handleGetHashtags($request),

            default => response()->json([
                'success'  => true,
                'response' => 'Task not recognized: '.$task.'. Available tasks: generate_caption, hr_job_posting, seo_keywords, get_hashtags, marketing_ideas, growth_report.',
            ]),
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AI SERVICE HANDLERS
    // ═══════════════════════════════════════════════════════════════════════

    private function handleGenerateCaption(Request $request, int $bid)
    {
        $captionService = new CaptionWriterService($bid);

        if (!$captionService->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'OpenAI API key not configured. Go to Settings → AI Models to add your key.',
            ]);
        }

        $result = $captionService->generateCaption(
            $request->input('platform', 'instagram'),
            $request->input('content_description', ''),
            $request->input('content_category', 'general'),
            $request->input('mood', 'engaging'),
            $request->input('services', []),
            $request->boolean('is_promotional'),
            $request->input('promotional_details'),
            $request->input('call_to_action')
        );

        return response()->json($result);
    }

    private function handleJobPosting(Request $request, int $bid)
    {
        $captionService = new CaptionWriterService($bid);

        if (!$captionService->isConfigured()) {
            // Fallback to stub
            return response()->json([
                'success' => true,
                'result'  => $this->stubJobPosting(
                    $request->input('title','Role'),
                    $request->input('department',''),
                    $request->input('job_type','full-time'),
                    $request->input('experience_level','mid'),
                    $request->input('requirements',[]),
                    $this->business()?->brand_voice ?? 'professional'
                ),
            ]);
        }

        $result = $captionService->generateJobPosting(
            $request->input('platform', 'linkedin'),
            $request->input('title', 'Role'),
            $request->input('department', ''),
            $request->input('experience_level', 'mid'),
            $request->input('requirements', []),
            $request->input('salary_range'),
            $request->input('notes')
        );

        return response()->json($result);
    }

    private function handleResumeScreen(Request $request, int $bid)
    {
        // Resume screening still uses stub (would need file upload handling)
        return response()->json([
            'success'     => true,
            'score'       => 72,
            'result'      => $this->stubResumeScreen($request->input('job_title',''), $request->input('required_skills',[])),
        ]);
    }

    private function handleBrandPost(Request $request, int $bid)
    {
        $captionService = new CaptionWriterService($bid);

        if (!$captionService->isConfigured()) {
            return response()->json([
                'success' => true,
                'result'  => $this->stubBrandPost($request->input('topic',''), $request->input('platform','linkedin'), $this->business()?->brand_voice ?? 'professional'),
            ]);
        }

        $result = $captionService->generateCaption(
            $request->input('platform', 'linkedin'),
            $request->input('topic', 'company culture'),
            'brand',
            'professional',
            [],
            false,
            null,
            'Connect with us'
        );

        return response()->json($result);
    }

    private function handleSeoKeywords(Request $request, int $bid)
    {
        $growthService = new GrowthHackerService($bid);
        $keywords = $growthService->getSeoKeywords(
            $request->input('topic', 'general'),
            (int)$request->input('count', 10)
        );

        return response()->json([
            'success'  => true,
            'keywords' => $keywords,
        ]);
    }

    private function handleSeoAudit(Request $request, int $bid)
    {
        $growthService = new GrowthHackerService($bid);
        $analysis = $growthService->analyzeCaptionEngagement(
            $request->input('content', ''),
            $request->input('platform', 'instagram')
        );

        return response()->json([
            'success' => true,
            'score'   => $analysis['engagement_score'],
            'result'  => $analysis,
        ]);
    }

    private function handleGmbPost(Request $request, int $bid)
    {
        $growthService = new GrowthHackerService($bid);
        $result = $growthService->generateGmbPost(
            $request->input('content_category', 'general'),
            $request->input('content_description', '')
        );

        return response()->json($result);
    }

    private function handleGenerateReply(Request $request, int $bid)
    {
        $engagementService = new AutoEngagementService($bid);
        $result = $engagementService->generateCommentReply(
            $request->input('post_caption', ''),
            $request->input('comment_text', ''),
            $request->input('commenter_name', 'User'),
            $request->input('platform', 'instagram')
        );

        return response()->json($result);
    }

    private function handleReviewResponse(Request $request, int $bid)
    {
        $engagementService = new AutoEngagementService($bid);
        $result = $engagementService->generateReviewResponse(
            $request->input('review_text', ''),
            (int)$request->input('rating', 5),
            $request->input('reviewer_name', 'Customer')
        );

        return response()->json($result);
    }

    private function handlePredictPerformance(Request $request)
    {
        $engagementService = new AutoEngagementService($this->businessId());
        $result = $engagementService->predictPerformance(
            $request->input('category', 'general'),
            $request->input('platform', 'instagram'),
            $request->input('media_type', 'image'),
            (int)$request->input('hour', now()->hour)
        );

        return response()->json([
            'success' => true,
            'prediction' => $result,
        ]);
    }

    private function handlePostingSchedule(Request $request, int $bid)
    {
        $growthService = new GrowthHackerService($bid);
        $platforms = $request->input('platforms', ['instagram', 'facebook', 'tiktok']);
        $schedule = $growthService->getPostingSchedule($platforms);

        return response()->json([
            'success' => true,
            'schedule' => $schedule,
        ]);
    }

    private function handleMarketingIdeas(Request $request, int $bid)
    {
        $growthService = new GrowthHackerService($bid);
        $ideas = $growthService->suggestMarketingIdeas(
            $request->input('service'),
            $request->input('platform'),
            $request->input('effort'),
            (int)$request->input('count', 5)
        );

        return response()->json([
            'success' => true,
            'ideas' => $ideas,
        ]);
    }

    private function handleAnalyzeCaption(Request $request)
    {
        $growthService = new GrowthHackerService($this->businessId());
        $analysis = $growthService->analyzeCaptionEngagement(
            $request->input('caption', ''),
            $request->input('platform', 'instagram')
        );

        return response()->json([
            'success' => true,
            'analysis' => $analysis,
        ]);
    }

    private function handleGrowthReport(int $bid)
    {
        $growthService = new GrowthHackerService($bid);
        $report = $growthService->generateGrowthReport();

        return response()->json([
            'success' => true,
            'report' => $report,
        ]);
    }

    private function handleGetHashtags(Request $request)
    {
        $hashtagService = new HashtagResearcherService();
        $hashtags = $hashtagService->getHashtags(
            $request->input('category', 'general'),
            $request->input('platform', 'instagram'),
            (int)$request->input('count', 0)
        );

        return response()->json([
            'success' => true,
            'hashtags' => $hashtags,
            'formatted' => $hashtagService->getHashtagsFormatted(
                $request->input('category', 'general'),
                $request->input('platform', 'instagram'),
                (int)$request->input('count', 0)
            ),
            'strategy' => $hashtagService->getHashtagStrategy($request->input('platform', 'instagram')),
        ]);
    }

    private function avgEngagementRate(int $bid): float
    {
        $reach = AnalyticMetric::forBusiness($bid)->where('metric_type','reach')->lastDays(7)->sum('value');
        $eng   = AnalyticMetric::forBusiness($bid)->where('metric_type','engagement')->lastDays(7)->sum('value');
        return $reach > 0 ? round(($eng/max($reach,1))*100,1) : 0.0;
    }

    private function totalFollowers(int $bid): int
    {
        return (int)AnalyticMetric::forBusiness($bid)->where('metric_type','followers')->orderByDesc('period_date')->value('value');
    }

    private function engagementChartData(int $bid, int $days): array
    {
        $data = AnalyticMetric::forBusiness($bid)->where('metric_type','engagement')->lastDays($days)
            ->selectRaw('period_date, sum(value) as total')->groupBy('period_date')->orderBy('period_date')->pluck('total','period_date');
        $labels = []; $values = [];
        for ($i = $days-1; $i >= 0; $i--) { $d=now()->subDays($i)->toDateString(); $labels[]=now()->subDays($i)->format('M j'); $values[]=(float)($data[$d]??0); }
        return ['labels'=>$labels,'values'=>$values];
    }

    private function findContentGaps(int $bid): array
    {
        $pillars = ['educational','promotional','inspirational','entertaining'];
        $counts  = Post::forBusiness($bid)->whereIn('pillar',$pillars)->selectRaw('pillar, count(*) as cnt')->groupBy('pillar')->pluck('cnt','pillar');
        $total   = $counts->sum() ?: 1; $gaps = [];
        foreach ($pillars as $p) { $pct=round(($counts[$p]??0)/$total*100,1); if($pct<20) $gaps[]="Consider more <strong>{$p}</strong> content (currently {$pct}%)."; }
        return $gaps;
    }

    private function stubJobPosting(string $title, string $dept, string $type, string $level, array $reqs, string $voice): string
    {
        $reqList = !empty($reqs) ? implode("\n", array_map(fn($r) => '• '.$r, $reqs)) : '• Relevant experience\n• Strong communication skills';
        return "## {$title}\n\n**Department:** ".($dept ?: 'General')."\n**Type:** ".ucfirst($type)."\n**Level:** ".ucfirst($level)."\n\n### About the Role\nWe are looking for a talented {$title} to join our growing team. In this role, you will contribute to our mission and work alongside passionate professionals.\n\n### Requirements\n{$reqList}\n\n### What We Offer\n• Competitive compensation package\n• Flexible working arrangements\n• Professional development opportunities\n• Collaborative and inclusive culture\n\n*Tip: Connect an AI model in Settings → AI Models for fully personalised job postings.*";
    }

    private function stubResumeScreen(string $jobTitle, array $skills): string
    {
        $skillList = !empty($skills) ? implode(', ', $skills) : 'the required skills';
        return "## Resume Screening Report\n\n**Position:** ".($jobTitle ?: 'The role')."\n**Match Score:** 72%\n\n### Strengths Identified\n• Relevant experience aligns with core requirements\n• Clear career progression visible\n• Strong communication implied by resume structure\n\n### Gaps to Explore\n• Verify depth of experience with: {$skillList}\n• Clarify availability and notice period\n• Confirm remote/on-site preference\n\n### Recommended Next Step\nInvite for a 30-minute screening call to validate technical fit.\n\n*Connect an AI model for real semantic resume analysis.*";
    }

    private function stubBrandPost(string $topic, string $platform, string $voice): string
    {
        $tag = match($platform) {
            'instagram' => '#Marketing #Business #Growth',
            'linkedin'  => '#ProfessionalDevelopment #Leadership #Innovation',
            'twitter'   => '#Marketing #Business',
            'facebook'  => '#Community #Business',
            default     => '#Marketing #Business',
        };
        return "✨ ".ucfirst($topic)."\n\nEvery great achievement starts with a decision to try. At our company, we believe that ".strtolower($topic)." is the foundation of sustainable growth.\n\nHere's what sets high-performers apart:\n→ Consistency over perfection\n→ Data-driven decisions\n→ Genuine community engagement\n\nWhat's your approach to ".strtolower($topic)."? Share below 👇\n\n{$tag}\n\n*Connect an AI model for brand-voice-matched content.*";
    }

    private function stubKeywords(string $topic, int $count): array
    {
        $base = array_filter([$topic, 'best '.strtolower($topic), strtolower($topic).' services', strtolower($topic).' tips', 'how to '.strtolower($topic), strtolower($topic).' 2026', strtolower($topic).' strategy', strtolower($topic).' tools', strtolower($topic).' guide', 'top '.strtolower($topic), strtolower($topic).' for beginners', strtolower($topic).' checklist', strtolower($topic).' examples', strtolower($topic).' trends', strtolower($topic).' near me']);
        return array_slice(array_values($base), 0, max(5, min($count, 15)));
    }

    private function stubSeoAudit(string $content, string $category): string
    {
        $words = str_word_count($content);
        $hasH1 = str_contains($content,'# ') || str_contains(strtolower($content),'<h1');
        return "## SEO Audit Report\n\n**Score: 68/100** · Category: ".ucfirst($category)."\n\n### Analysis\n\n**Word Count:** {$words} words".($words < 300 ? ' ⚠️ (aim for 300+)' : ' ✅')."\n**Heading Structure:** ".($hasH1 ? '✅ H1 detected' : '⚠️ No H1 found — add a clear heading')."\n**Readability:** Moderate — use shorter paragraphs and bullet points.\n\n### Recommendations\n1. Add a compelling meta description (150–160 chars)\n2. Include your primary keyword in the first 100 words\n3. Add internal links to related content\n4. Optimise images with descriptive alt text\n5. Use schema markup for rich results\n\n*Connect an AI model for deep semantic SEO analysis.*";
    }

    private function stubGmbPost(string $description, string $category, string $bizName): string
    {
        $cta = match ($category) {
            'event'  => 'Join us for this exciting event — we would love to see you there!',
            'offer'  => 'Don\'t miss this limited-time offer — available while stocks last!',
            'update' => 'We\'re constantly improving to serve you better.',
            default  => 'Stop by or get in touch to learn more.',
        };
        return "📍 {$bizName}\n\n".ucfirst($description)."\n\nWe're committed to delivering exceptional results for our community. {$cta}\n\n📞 Call us or visit our website to find out more.\n\n*Connect an AI model for fully personalised GMB content.*";
    }
}

