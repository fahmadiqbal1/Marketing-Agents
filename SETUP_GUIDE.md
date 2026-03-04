# 🚀 AI Marketing Platform — Complete Setup Guide

Follow this step-by-step guide to set up your marketing platform and start posting.

---

## ⚡ STEP 0: Prerequisites

### A. PHP 8.2+
```bash
php -v    # Should show 8.2 or higher
```

### B. Composer
```bash
composer --version
```

### C. MySQL 8.x
```bash
mysql --version
```
Or use **SQLite** for quick local development (no server required).

### D. Node.js & NPM (optional, for asset compilation)
```bash
node -v && npm -v
```

---

## 🔧 STEP 1: Install & Configure

```bash
cd dashboard

# Install dependencies
composer install

# Copy environment and generate app key
cp .env.example .env
php artisan key:generate
```

### Database Configuration

Edit `dashboard/.env`:

**For MySQL (recommended for production):**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=marketing_platform
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

**For SQLite (quick dev):**
```env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

If using SQLite, create the file:
```bash
touch database/database.sqlite
```

### Run Migrations
```bash
php artisan migrate
```

This creates all 37 tables needed for the platform.

---

## 🌐 STEP 2: Start the Server

```bash
php artisan serve
```

Open **http://localhost:8000** in your browser.

---

## 👤 STEP 3: Register & Set Up Your Business

1. Go to **http://localhost:8000/register**
2. Fill in your name, email, password, and business name
3. Complete the business setup wizard (industry, timezone, brand voice)

---

## 🔌 STEP 4: Connect Social Platforms

Go to **Platforms & AI Models** in the sidebar.

### Instagram
1. Go to [developers.facebook.com](https://developers.facebook.com)
2. Create an App → Add Instagram Graph API
3. Generate a long-lived Page Access Token
4. Enter your Meta App ID, App Secret, Access Token, and Instagram Business Account ID

### Facebook
1. Same Meta Developer portal
2. Graph API Explorer → Generate Page Access Token
3. Enter App ID, Secret, Token, and Page ID

### Twitter / X
1. Go to [developer.twitter.com](https://developer.twitter.com)
2. Create a Project → Create an App
3. Generate Consumer Keys and Access Tokens
4. Enter API Key, API Secret, Access Token, Access Token Secret

### TikTok
1. Go to [developers.tiktok.com](https://developers.tiktok.com)
2. Create App → Add Content Posting API
3. Generate tokens
4. Enter Client Key, Client Secret, Access Token

### YouTube
1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Enable YouTube Data API v3
3. Create OAuth 2.0 credentials
4. Enter Client ID, Client Secret, Refresh Token

### LinkedIn
1. Go to [linkedin.com/developers](https://www.linkedin.com/developers/)
2. Create App → Verify Company
3. Auth tab → Copy credentials
4. Enter Client ID, Client Secret, Access Token, Organization ID

### Telegram
1. Message [@BotFather](https://t.me/BotFather) on Telegram
2. Create a new bot and copy the token
3. Configure in the Telegram section on the Platforms page

---

## 🤖 STEP 5: Configure AI Providers

On the **Platforms & AI Models** page, you'll see the AI Models section.

### Cloud Providers (API Key Required)
- **OpenAI** — `gpt-4o`, `gpt-4o-mini`
- **Google Gemini** — `gemini-2.0-flash`, `gemini-1.5-pro`
- **Anthropic Claude** — `claude-sonnet-4-20250514`, `claude-3-5-sonnet`
- **Mistral AI** — `mistral-large-latest`
- **DeepSeek** — `deepseek-chat`
- **Groq** — `llama-3.1-70b-versatile`

### Local / Custom Providers
- **Ollama** — Run models locally on your machine (llama3, mistral, codellama)
  - Install from [ollama.com](https://ollama.com)
  - Base URL: `http://localhost:11434`
- **Custom Endpoint** — Any OpenAI-compatible API (LM Studio, vLLM, text-generation-webui)
  - Base URL: your server address

---

## 🏢 STEP 6: Add Multiple Businesses

1. Go to **Businesses** in the sidebar
2. Click **Add Business**
3. Enter business name, industry
4. Select platforms to auto-clone your trained agents for the new business
5. Use the **Switch Business** dropdown to manage different businesses

Each business gets its own:
- Social platform connections
- AI provider configurations
- Content calendar
- Analytics dashboard
- Telegram bot

---

## 📱 STEP 7: Start Creating Content

1. **Upload** — Go to Upload to create a post with media
2. **Calendar** — Use the Content Calendar to plan ahead
3. **Strategy** — Check Strategy for AI-recommended content ideas
4. **Bot Training** — Train your Telegram bot with custom knowledge

---

## 🔐 Security Notes

- All API keys are stored encrypted in the database
- Session-based auth for the dashboard, Sanctum tokens for the API
- Role-based access control (owner, admin, editor, viewer)
- Audit logging for security events
- CSRF protection on all forms

---

## 🛠 Troubleshooting

### "Table not found" errors
Run migrations: `php artisan migrate`

### Can't login
Clear sessions: `php artisan session:table && php artisan migrate`

### API returns 401
Make sure you're sending the Sanctum token in the `Authorization: Bearer <token>` header.

### Ollama connection fails
Make sure Ollama is running: `ollama serve`

---

## 📧 Support

For issues or feature requests, please open an issue on GitHub.
