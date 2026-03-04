# AI Marketing Platform â€” AI Marketing Agents

An AI-powered social media marketing system that runs locally on your PC. Send a photo or video via Telegram and the system will analyze it, edit it, write captions, research hashtags, and publish to all your social platforms â€” after your approval.

## Quick Start

### 1. Prerequisites
- Python 3.11+
- MySQL 8.x (running locally or via Docker)
- FFmpeg ([download](https://ffmpeg.org/download.html) and add to PATH)
- Telegram Bot Token (from [@BotFather](https://t.me/BotFather))

### 2. Setup

```bash
# Clone / navigate to project
cd "d:\Projects\Marketing agents"

# Create virtual environment  
python -m venv .venv
.venv\Scripts\activate

# Install dependencies
pip install -e .

# Create MySQL database
mysql -u root -p -e "CREATE DATABASE marketing_platform; CREATE USER 'marketing_platform'@'localhost' IDENTIFIED BY 'your_password'; GRANT ALL ON marketing_platform.* TO 'marketing_platform'@'localhost';"

# Copy and fill in environment config
copy config\.env.example config\.env
# Edit config\.env with your API keys
```

### 3. API Keys Needed

| Service | Get it from | Purpose |
|---------|------------|---------|
| Telegram Bot Token | [@BotFather](https://t.me/BotFather) | Bot interface |
| Google Gemini API | [AI Studio](https://aistudio.google.com/apikey) | Vision analysis (~$2-5/mo) |
| OpenAI API Key | [OpenAI Platform](https://platform.openai.com/api-keys) | Captions & screening (~$3-10/mo) |
| Meta App | [Meta Developers](https://developers.facebook.com/) | Instagram & Facebook |
| LinkedIn App | [LinkedIn Developers](https://developer.linkedin.com/) | LinkedIn posting |
| TikTok App | [TikTok Developers](https://developers.tiktok.com/) | TikTok posting |
| Google OAuth | [Cloud Console](https://console.cloud.google.com/) | YouTube & Calendar |

### 4. Google OAuth Setup (for YouTube & Calendar)
```bash
python tools/google_auth_setup.py
```

### 5. Run
```bash
python main.py
```

## How It Works

```
You (Telegram) â†’ Send photo/video
    â†“
ðŸ“¸ Media downloaded to media/inbox/
    â†“
ðŸ” Vision Analyzer (Gemini Flash) â€” analyzes content, category, quality
    â†“
ðŸŽ¯ Platform Router (rule-based, zero tokens) â€” picks best platforms
    â†“
ðŸŽ¨ Media Editor (Pillow + FFmpeg, local) â€” resize, brightness, watermark
    â†“
ðŸŽµ Music Agent (zero tokens) â€” trending background music for videos
    â†“
âœï¸ Caption Writer (GPT-4o-mini) â€” platform-specific captions + SEO keywords
    â†“
#ï¸âƒ£ Hashtag Agent (cached DB + AI) â€” researched hashtags
    â†“
ðŸ“ˆ Growth Engine (zero tokens) â€” engagement scoring, best time, viral tips
    â†“
ðŸ“± Preview sent to Telegram with Approve/Deny buttons
    â†“
âœ… You approve â†’ Published to selected platforms
    â†“
ðŸ” Auto-generates Google My Business post (free Google traffic!)
ðŸ“± Auto-generates WhatsApp Status image (free marketing!)
```

## Telegram Commands

| Command | Description |
|---------|-------------|
| `/start` | Welcome message |
| `/growth` | Daily growth brief â€” best posting times, series, tips |
| `/freeads` | 10 free advertising strategies for your business |
| `/series` | This week's content series plan |
| `/recycle` | Repurpose old content across platforms |
| `/engage` | Auto-reply templates for comments & DMs |
| `/seo` | Local SEO keywords that drive free traffic |
| `/job` | Create a job posting (guided flow) |
| `/jobs` | View open job listings |
| `/packages` | Get AI promotional package ideas |
| `/suggest` | See content gap suggestions |
| `/status` | System statistics |
| Send a photo | Starts the marketing workflow |
| Send a video | Starts the marketing workflow |
| Send a PDF/DOCX | Triggers resume screening |

## Architecture

- **Orchestrator**: LangGraph with MySQL checkpointing
- **Vision**: Google Gemini 2.0 Flash (cheapest vision model)
- **Captions**: OpenAI GPT-4o-mini (best cost/quality for text)
- **Editing**: Pillow + FFmpeg (local, free)
- **Voiceover**: Edge-TTS (free Microsoft voices)
- **Database**: MySQL 8.x via SQLAlchemy
- **Vector Search**: ChromaDB (embedded, for content similarity)
- **Storage**: Local filesystem

## Estimated Monthly Cost

| Component | Cost |
|-----------|------|
| Gemini Flash (vision) | ~$2-5 |
| GPT-4o-mini (captions) | ~$3-8 |
| Edge-TTS (voiceover) | Free |
| Everything else | Free (local) |
| **Total** | **~$10-25/mo** |

## Project Structure

```
Marketing agents/
â”œâ”€â”€ main.py                    # Entry point
â”œâ”€â”€ config/
â”‚   â”œâ”€â”€ settings.py            # All configuration
â”‚   â””â”€â”€ .env.example           # Environment template
â”œâ”€â”€ bot/
â”‚   â””â”€â”€ telegram_bot.py        # Telegram interface + approval flow
â”œâ”€â”€ orchestrator/
â”‚   â””â”€â”€ workflow.py            # LangGraph orchestration
â”œâ”€â”€ agents/
â”‚   â”œâ”€â”€ vision_analyzer.py     # Gemini vision analysis
â”‚   â”œâ”€â”€ platform_router.py     # Rule-based routing (zero tokens)
â”‚   â”œâ”€â”€ media_editor.py        # Image/video editing (Pillow + FFmpeg)
â”‚   â”œâ”€â”€ caption_writer.py      # GPT-4o-mini captions with SEO injection
â”‚   â”œâ”€â”€ hashtag_researcher.py  # Cached hashtag DB + trends
â”‚   â”œâ”€â”€ music_researcher.py    # Background music selection + trends
â”‚   â”œâ”€â”€ publisher.py           # All platform API publishers
â”‚   â”œâ”€â”€ growth_hacker.py       # ðŸš€ Growth engine â€” free ads, SEO, scheduling
â”‚   â”œâ”€â”€ auto_engagement.py     # ðŸ¤– Comment replies, FAQs, review responses
â”‚   â”œâ”€â”€ content_recycler.py    # â™»ï¸ Cross-platform repurposing + evergreen
â”‚   â”œâ”€â”€ package_brain.py       # Promotional package proposals
â”‚   â”œâ”€â”€ job_manager.py         # Job posting + resume screening + scheduling
â”‚   â””â”€â”€ schemas.py             # Pydantic data models
â”œâ”€â”€ memory/
â”‚   â”œâ”€â”€ database.py            # MySQL engine + sessions
â”‚   â”œâ”€â”€ models.py              # SQLAlchemy ORM models (10 tables)
â”‚   â””â”€â”€ content_memory.py      # ChromaDB + content accumulation
â”œâ”€â”€ tools/
â”‚   â”œâ”€â”€ media_utils.py         # File management + metadata
â”‚   â”œâ”€â”€ voiceover.py           # Edge-TTS voice generation
â”‚   â””â”€â”€ google_auth_setup.py   # One-time Google OAuth setup
â”œâ”€â”€ templates/                 # Collage layouts, branding assets
â””â”€â”€ media/                     # Auto-created media directories
    â”œâ”€â”€ inbox/
    â”œâ”€â”€ processed/
    â”œâ”€â”€ snapchat_ready/
    â”œâ”€â”€ collages/
    â”œâ”€â”€ compilations/
    â””â”€â”€ music_library/         # Drop royalty-free MP3s here
```

## Growth Hacking Engine (Zero Extra Cost)

The system includes a next-gen growth engine that runs on **zero additional tokens**:

| Feature | How It Works | Cost |
|---------|-------------|------|
| **Optimal Posting Times** | Research-backed peak hours per platform | Free |
| **Content Series** | Wellness Wed, Transformation Tue, etc. | Free |
| **Local SEO Keywords** | Auto-injected into YouTube descriptions | Free |
| **Engagement Scoring** | Analyzes captions for hooks, CTAs, length | Free |
| **Viral Format Suggestions** | Recommends trending content formats | Free |
| **Google My Business Posts** | Auto-generated after each publish | ~$0.001 |
| **WhatsApp Status Images** | Branded 9:16 images with contact info | Free |
| **FAQ Auto-Replies** | Instant answers to common patient queries | Free |
| **Content Recycling** | Repurpose old posts for new platforms | Free |
| **Evergreen Calendar** | Auto-reminds to repost best content | Free |
| **Free Ad Strategies** | 10 proven zero-cost marketing tactics | Free |
