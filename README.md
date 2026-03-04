# AI Marketing Platform — Marketing Agents

A full-featured AI-powered social media marketing system built with **Laravel**. Manage multiple businesses, connect social platforms, configure AI providers, train Telegram bots, and automate your marketing — all from a clean dashboard.

---

## Features

- **Multi-Business Management** — Add and switch between multiple businesses; each gets its own dashboard, platforms, and AI agents
- **Social Platform Integration** — Connect Instagram, Facebook, Twitter/X, LinkedIn, TikTok, YouTube, Pinterest, Threads, Snapchat, and Telegram
- **AI-Powered Content** — Generate captions, hashtags, and content strategies using OpenAI, Google Gemini, Anthropic Claude, Mistral, DeepSeek, Groq, Ollama (local), or any OpenAI-compatible endpoint
- **Agent Cloning** — When you add a new business with selected platforms, the system auto-clones your trained AI agents for that business
- **Telegram Bot** — Train a custom bot with personality, knowledge base, and custom commands
- **Content Calendar** — Plan, schedule, and approve posts across all platforms
- **Analytics & Insights** — Track engagement, growth, and content performance
- **SEO Tools** — Keyword research, site audits, Google My Business post generation
- **HR / Recruitment** — Create job postings, screen resumes, and manage candidates
- **Billing & Plans** — Usage tracking, subscription management, credit requests

---

## Quick Start

### Prerequisites

- **PHP 8.2+**
- **Composer**
- **MySQL 8.x** (or SQLite for development)
- **Node.js & NPM** (optional, for Vite asset compilation)

### Setup

```bash
# Navigate to the dashboard
cd dashboard

# Install PHP dependencies
composer install

# Copy environment file and generate app key
cp .env.example .env
php artisan key:generate

# Configure your database in .env
# For MySQL:
#   DB_CONNECTION=mysql
#   DB_DATABASE=marketing_platform
#   DB_USERNAME=your_user
#   DB_PASSWORD=your_password
#
# For SQLite (quick dev):
#   DB_CONNECTION=sqlite
#   DB_DATABASE=database/database.sqlite

# Run migrations
php artisan migrate

# Start the development server
php artisan serve
```

Then open **http://localhost:8000** in your browser.

### First Steps

1. **Register** — Create an account with your business name
2. **Setup** — Complete the business profile wizard
3. **Connect Platforms** — Go to Platforms & AI Models to connect social accounts
4. **Configure AI** — Add your AI provider API keys (OpenAI, Gemini, Claude, or local Ollama)
5. **Upload Content** — Start creating and scheduling posts

---

## Architecture

```
dashboard/
├── app/
│   ├── Http/Controllers/    # Auth, Dashboard, API controllers
│   ├── Models/              # 25 Eloquent models
│   ├── Services/            # 27 service classes (AI, publishing, bots, etc.)
│   └── Providers/           # Service providers
├── config/                  # Laravel configuration
├── database/
│   └── migrations/          # 20 migration files (37 tables)
├── resources/views/         # 16 Blade templates + layouts
├── routes/
│   ├── web.php              # Dashboard routes (session auth)
│   └── api.php              # REST API routes (Sanctum token auth)
└── public/                  # Web root
```

### Key API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register new user + business |
| POST | `/api/auth/login` | Login, receive Sanctum token |
| POST | `/api/auth/logout` | Revoke current token |
| GET | `/api/auth/me` | Profile + connected platforms + AI providers |
| GET | `/api/businesses` | List all user's businesses |
| POST | `/api/businesses` | Create business with agent cloning |
| POST | `/api/auth/switch-business` | Switch active business |
| GET | `/api/ai-models` | List configured AI providers |
| POST | `/api/ai-models` | Add/update AI provider |
| GET | `/api/platforms/status` | Platform connection status |
| POST | `/api/posts` | Create a new post |
| GET | `/api/posts` | List posts with filters |
| POST | `/api/generate/caption` | AI-generated caption |
| POST | `/api/generate/hashtags` | AI hashtag research |

### Supported AI Providers

| Provider | Type | Key |
|----------|------|-----|
| OpenAI | Cloud | `openai` |
| Google Gemini | Cloud | `google_gemini` |
| Anthropic Claude | Cloud | `anthropic` |
| Mistral AI | Cloud | `mistral` |
| DeepSeek | Cloud | `deepseek` |
| Groq | Cloud | `groq` |
| Ollama | Local | `ollama` |
| Custom Endpoint | Local/Cloud | `openai_compatible` |

---

## License

Private — all rights reserved.
