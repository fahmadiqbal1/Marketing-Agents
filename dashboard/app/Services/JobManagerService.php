<?php

namespace App\Services;

use App\Models\JobListing;
use App\Models\JobCandidate;
use App\Models\Business;

/**
 * Job Manager Service — creates job listings and screens candidates.
 * Converted from Python: agents/job_manager.py
 *
 * Features:
 * - Generate AI-powered job descriptions
 * - Screen resumes and score candidates
 * - Suggest interview questions
 * - Create social media job posts
 */
class JobManagerService
{
    private int $businessId;
    private ?OpenAIService $openai = null;

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // JOB POSTING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Create a job listing with AI-generated description.
     */
    public function createJobListing(array $data): array
    {
        $openai = $this->getOpenAI();
        $business = Business::find($this->businessId);

        $title = $data['title'] ?? 'Position';
        $department = $data['department'] ?? '';
        $experience = $data['experience'] ?? 'Mid-level';
        $requirements = $data['requirements'] ?? [];
        $salaryRange = $data['salary_range'] ?? null;
        $notes = $data['notes'] ?? '';

        if ($openai && $openai->isConfigured() && $business) {
            $prompt = "Generate a job posting for:\n\n"
                    . "Title: {$title}\n"
                    . "Department: {$department}\n"
                    . "Experience: {$experience}\n"
                    . "Requirements: " . implode(', ', $requirements) . "\n"
                    . ($salaryRange ? "Salary: {$salaryRange}\n" : "")
                    . "Company: {$business->name} ({$business->industry})\n"
                    . "Brand voice: {$business->brand_voice}\n"
                    . ($notes ? "Notes: {$notes}\n" : "")
                    . "\nCreate a compelling job description that:\n"
                    . "- Uses inclusive language\n"
                    . "- Highlights company culture\n"
                    . "- Lists clear responsibilities\n"
                    . "- Specifies required vs nice-to-have skills\n\n"
                    . "Return JSON: {\"description\": \"...\", \"responsibilities\": [...], \"requirements\": [...], \"benefits\": [...], \"social_caption\": \"...\"}";

            $result = $openai->chatCompletion($prompt, 'job_manager', 'create_listing');

            if ($result['success']) {
                $parsed = json_decode($result['content'], true);
                return [
                    'success'     => true,
                    'description' => $parsed['description'] ?? '',
                    'data'        => $parsed,
                ];
            }
        }

        // Fallback stub
        return [
            'success'     => true,
            'description' => $this->stubJobDescription($title, $department, $experience, $requirements),
            'data'        => [
                'responsibilities' => ['Contribute to team goals', 'Collaborate with colleagues'],
                'requirements'     => $requirements ?: ['Relevant experience', 'Strong communication'],
                'benefits'         => ['Competitive salary', 'Professional development', 'Great team culture'],
                'social_caption'   => "🚀 We're hiring! Join us as a {$title}. Apply now!",
            ],
        ];
    }

    /**
     * Get open job listings.
     */
    public function getOpenJobs(): array
    {
        return JobListing::where('business_id', $this->businessId)
            ->where('status', 'open')
            ->withCount('candidates')
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Close a job listing.
     */
    public function closeJob(int $jobId): bool
    {
        return JobListing::where('business_id', $this->businessId)
            ->where('id', $jobId)
            ->update(['status' => 'closed', 'closed_at' => now()]) > 0;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // RESUME SCREENING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Screen a resume against a job listing.
     */
    public function screenResume(int $jobId, string $resumeText): array
    {
        $job = JobListing::where('business_id', $this->businessId)
            ->find($jobId);

        if (!$job) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        $openai = $this->getOpenAI();

        if ($openai && $openai->isConfigured()) {
            $prompt = "Screen this resume against the job requirements:\n\n"
                    . "JOB: {$job->title}\n"
                    . "REQUIREMENTS: {$job->requirements}\n"
                    . "DESCRIPTION: {$job->description}\n\n"
                    . "RESUME:\n{$resumeText}\n\n"
                    . "Analyze and return JSON:\n"
                    . "{\n"
                    . "  \"match_score\": 0-100,\n"
                    . "  \"name\": \"candidate name\",\n"
                    . "  \"email\": \"if found\",\n"
                    . "  \"phone\": \"if found\",\n"
                    . "  \"strengths\": [\"...\"],\n"
                    . "  \"gaps\": [\"...\"],\n"
                    . "  \"experience_years\": number,\n"
                    . "  \"recommendation\": \"shortlist|consider|reject\",\n"
                    . "  \"interview_questions\": [\"tailored questions\"]\n"
                    . "}";

            $result = $openai->chatCompletion($prompt, 'job_manager', 'screen_resume');

            if ($result['success']) {
                $parsed = json_decode($result['content'], true);
                return [
                    'success' => true,
                    'score'   => $parsed['match_score'] ?? 50,
                    'data'    => $parsed,
                ];
            }
        }

        // Fallback
        return [
            'success' => true,
            'score'   => 60,
            'data'    => [
                'match_score'          => 60,
                'name'                 => 'Candidate',
                'strengths'            => ['Resume received'],
                'gaps'                 => ['AI analysis unavailable'],
                'recommendation'       => 'consider',
                'interview_questions'  => [
                    'Tell us about your relevant experience.',
                    'Why are you interested in this role?',
                    'What are your salary expectations?',
                ],
            ],
        ];
    }

    /**
     * Get candidates for a job.
     */
    public function getCandidates(int $jobId, float $minScore = 0): array
    {
        return JobCandidate::where('job_listing_id', $jobId)
            ->when($minScore > 0, fn($q) => $q->where('match_score', '>=', $minScore))
            ->orderByDesc('match_score')
            ->get()
            ->toArray();
    }

    /**
     * Update candidate status.
     */
    public function updateCandidateStatus(int $candidateId, string $status): bool
    {
        return JobCandidate::whereHas('jobListing', fn($q) => $q->where('business_id', $this->businessId))
            ->where('id', $candidateId)
            ->update(['status' => $status]) > 0;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SOCIAL MEDIA JOB POSTS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate social media posts for a job listing.
     */
    public function generateJobSocialPosts(int $jobId): array
    {
        $job = JobListing::where('business_id', $this->businessId)->find($jobId);

        if (!$job) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        $openai = $this->getOpenAI();

        if ($openai && $openai->isConfigured()) {
            $prompt = "Create social media posts for this job opening:\n\n"
                    . "Title: {$job->title}\n"
                    . "Department: {$job->department}\n"
                    . "Description: {$job->description}\n\n"
                    . "Generate posts for:\n"
                    . "1. LinkedIn (professional, 200-300 chars)\n"
                    . "2. Instagram (engaging, 150 chars max)\n"
                    . "3. Twitter (concise, 280 chars max)\n\n"
                    . "Return JSON: {\"linkedin\": \"...\", \"instagram\": \"...\", \"twitter\": \"...\", \"hashtags\": [...]}";

            $result = $openai->chatCompletion($prompt, 'job_manager', 'social_posts');

            if ($result['success']) {
                return [
                    'success' => true,
                    'posts'   => json_decode($result['content'], true),
                ];
            }
        }

        // Fallback
        return [
            'success' => true,
            'posts'   => [
                'linkedin'  => "🚀 We're hiring a {$job->title}! Join our team and make an impact. Apply now.",
                'instagram' => "🔥 NOW HIRING: {$job->title}! Link in bio ⬆️",
                'twitter'   => "📢 We're looking for a {$job->title}! DM us or apply today. #hiring #jobs",
                'hashtags'  => ['hiring', 'jobs', 'careers', 'joinus', 'opportunity'],
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function stubJobDescription(string $title, string $dept, string $exp, array $reqs): string
    {
        $reqList = !empty($reqs) ? implode("\n• ", $reqs) : "Relevant experience\n• Strong communication skills";

        return "## {$title}\n\n"
             . "**Department:** " . ($dept ?: 'General') . "\n"
             . "**Experience:** {$exp}\n\n"
             . "### About the Role\n"
             . "We are looking for a talented {$title} to join our growing team.\n\n"
             . "### Requirements\n• {$reqList}\n\n"
             . "### What We Offer\n"
             . "• Competitive compensation\n"
             . "• Professional development\n"
             . "• Great team culture\n\n"
             . "*Connect an AI model for personalized job descriptions.*";
    }

    private function getOpenAI(): ?OpenAIService
    {
        if ($this->openai === null) {
            $this->openai = new OpenAIService($this->businessId);
        }
        return $this->openai;
    }
}
