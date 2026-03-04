# ðŸš€ AI Marketing Platform â€” Complete Setup Guide

Follow this step-by-step to connect **every** platform and start posting.

---

## âš¡ STEP 0: Prerequisites (Install Once)

### A. Python 3.11+
```
# Check version
python --version
# If not 3.11+, download from https://python.org/downloads
```

### B. MySQL 8.x
1. Download from https://dev.mysql.com/downloads/installer/
2. During install, set a root password (remember it!)
3. Open MySQL Shell or Command Line Client:
```sql
CREATE DATABASE marketing_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'marketing_platform'@'localhost' IDENTIFIED BY 'YourStrongPassword123!';
GRANT ALL PRIVILEGES ON marketing_platform.* TO 'marketing_platform'@'localhost';
FLUSH PRIVILEGES;
```

### C. FFmpeg (for video editing)
1. Go to https://github.com/BtbN/FFmpeg-Builds/releases
2. Download `ffmpeg-master-latest-win64-gpl.zip`
3. Extract to `C:\ffmpeg\`
4. Add `C:\ffmpeg\bin` to your system PATH:
   - Win+R â†’ `sysdm.cpl` â†’ Advanced â†’ Environment Variables
   - Under "System variables", find `Path`, click Edit
   - Add `C:\ffmpeg\bin`
5. Verify: Open new PowerShell â†’ `ffmpeg -version`

### D. Install Python packages
```powershell
cd "D:\Projects\Marketing agents"
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -e .
```

---

## ðŸ“± STEP 1: Telegram Bot (Your Control Panel)

This is how you talk to the system â€” you MUST do this first.

### Create the Bot
1. Open Telegram, search for `@BotFather`
2. Send `/newbot`
3. Name it: `Your Business Marketing`
4. Username: `your_business_bot` (or any unique name ending in `bot`)
5. BotFather gives you a token like: `7123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`
6. **Copy this token** â†’ This is your `TELEGRAM_BOT_TOKEN`

### Get Your Chat ID
1. Search for `@userinfobot` on Telegram
2. Send it any message
3. It replies with your info â€” copy the `Id` number (e.g., `123456789`)
4. **This is your `TELEGRAM_ADMIN_CHAT_ID`**

### Fill in `.env`
```powershell
copy config\.env.example config\.env
# Open config\.env in VS Code or Notepad
```
Set these lines:
```
TELEGRAM_BOT_TOKEN=7123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_ADMIN_CHAT_ID=123456789
MYSQL_PASSWORD=YourStrongPassword123!
```

### Test the Bot
```powershell
python main.py
```
Open Telegram â†’ Go to your bot â†’ Send `/start`. You should get a welcome message!

> Press `Ctrl+C` to stop the bot after testing.

---

## ðŸ§  STEP 2: AI Keys (Required)

### A. Google Gemini API (Vision Analysis â€” ~$2-5/month)
1. Go to https://aistudio.google.com/apikey
2. Sign in with `your-business@email.com`
3. Click **"Create API Key"**
4. Select or create a Google Cloud project (any name, e.g., "my-marketing")
5. Copy the key â†’ Add to `.env`:
```
GOOGLE_GEMINI_API_KEY=AIzaSy...............................
```

### B. OpenAI API (Captions â€” ~$3-10/month)
1. Go to https://platform.openai.com/signup
2. Create account with your business email
3. Add billing: Settings â†’ Billing â†’ Add payment method
4. Set a monthly limit of $15 (Settings â†’ Billing â†’ Usage limits)
5. Go to https://platform.openai.com/api-keys â†’ Create new secret key
6. Copy â†’ Add to `.env`:
```
OPENAI_API_KEY=sk-proj-..........................
```

---

## ðŸ“¸ STEP 3: Instagram + Facebook (Meta Graph API)

Since you already have an Instagram account, we need to:
1. Convert it to a **Business/Creator account** (if not already)
2. Connect it to a **Facebook Page**
3. Create a **Meta Developer App**

### A. Convert Instagram to Business Account
1. Open Instagram â†’ Profile â†’ â˜° Menu â†’ Settings â†’ Account
2. Tap "Switch to Professional Account"
3. Choose "Business"
4. Select category: "Medical & Health"
5. Connect to a Facebook Page (create one if needed â€” "Your Business")

### B. Create Facebook Page (If You Don't Have One)
1. Go to https://www.facebook.com/pages/create
2. Page name: `Your Business`
3. Category: `Medical & Health`
4. Fill in details and create

### C. Create Meta Developer App
1. Go to https://developers.facebook.com/
2. Sign in with the same Facebook account
3. Click **"Create App"**
4. Select **"Business"** type
5. App name: `Your Business Marketing Bot`
6. Select your Business Portfolio (or create one)

### D. Add Products to App
1. In your app dashboard, click **"Add Product"**
2. Add **"Instagram Graph API"** â†’ Set Up
3. Add **"Facebook Login for Business"** â†’ Set Up

### E. Get Instagram Business Account ID
1. Go to https://developers.facebook.com/tools/explorer/
2. Select your app from the dropdown
3. Click **"Generate Access Token"** â†’ grant all permissions
4. In the API query field, type: `me/accounts` â†’ Submit
5. Find your Facebook Page in the results â†’ copy the `id` and `access_token`
6. Now query: `{page_id}?fields=instagram_business_account`
7. The `instagram_business_account.id` is your **Instagram Business Account ID**

### F. Get Long-Lived Page Access Token
The token from Graph Explorer expires in 1 hour. For a permanent token:

1. In Graph Explorer, get a **User Access Token** with permissions:
   - `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`
   - `instagram_basic`, `instagram_content_publish`, `instagram_manage_comments`
2. Exchange for long-lived user token:
```
GET https://graph.facebook.com/v19.0/oauth/access_token?
    grant_type=fb_exchange_token&
    client_id=YOUR_APP_ID&
    client_secret=YOUR_APP_SECRET&
    fb_exchange_token=SHORT_LIVED_TOKEN
```
3. Then get a never-expiring Page token:
```
GET https://graph.facebook.com/v19.0/me/accounts?access_token=LONG_LIVED_USER_TOKEN
```
4. The `access_token` for your page in the response **never expires**.

### G. Add to `.env`:
```
META_APP_ID=123456789012345
META_APP_SECRET=abcdef1234567890abcdef1234567890
META_PAGE_ACCESS_TOKEN=EAAxxxxxxxxxxxxxxx.....very_long_token
INSTAGRAM_BUSINESS_ACCOUNT_ID=17841400000000000
```

> **âš ï¸ IMPORTANT**: Instagram Graph API requires your photos/videos to be publicly
> accessible URLs (not local files). The system handles this by uploading to
> your Facebook Page first, then cross-posting. For direct Reels, you may need
> to host media on a web server. We can set up a free solution for this later.

---

## ðŸŽ¥ STEP 4: YouTube + Google Calendar (Gmail: your-business@email.com)

This uses the SAME Google account for YouTube uploads AND interview scheduling.

### A. Create Google Cloud Project
1. Go to https://console.cloud.google.com/
2. Sign in with `your-business@email.com`
3. Click **"Select a Project"** â†’ **"New Project"**
4. Name: `Your Business Marketing`  â†’ Create

### B. Enable APIs
1. Go to **APIs & Services** â†’ **Library**
2. Search and **Enable** each:
   - `YouTube Data API v3`
   - `Google Calendar API`

### C. Create OAuth Credentials
1. Go to **APIs & Services** â†’ **Credentials**
2. Click **"+ CREATE CREDENTIALS"** â†’ **"OAuth client ID"**
3. If prompted, configure the **OAuth Consent Screen** first:
   - User Type: **External**
   - App name: `Your Business Marketing`
   - User support email: `your-business@email.com`
   - Developer contact: `your-business@email.com`
   - Scopes: Add `youtube.upload`, `calendar.events`
   - Test users: Add `your-business@email.com`
   - Save
4. Now create the OAuth Client:
   - Application type: **Desktop app**
   - Name: `Marketing Desktop Client`
5. Copy **Client ID** and **Client Secret** â†’ Add to `.env`:
```
GOOGLE_CLIENT_ID=123456789-abc.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxxxxxxxxxxx
```

### D. Run the OAuth Setup
```powershell
python tools/google_auth_setup.py
```
- A browser window opens â†’ Sign in with `your-business@email.com`
- Grant YouTube + Calendar permissions
- You'll see: `âœ… Google credentials saved`
- This creates `config/credentials/google_token.json` â€” don't delete this!

### E. Create a YouTube Channel (if not done)
1. Go to https://www.youtube.com with your Gmail
2. Click profile â†’ "Create a channel"
3. Channel name: `Your Business`

---

## ðŸ’¼ STEP 5: LinkedIn

### A. Create LinkedIn Company Page
1. Go to LinkedIn â†’ click "For Business" â†’ "Create a Company Page"
2. Company name: `Your Business`
3. Complete the page setup

### B. Create LinkedIn Developer App
1. Go to https://www.linkedin.com/developers/
2. Click **"Create App"**
3. App name: `Your Business Marketing`
4. LinkedIn Page: Select `Your Business`
5. App logo: Upload your business logo

### C. Request API Access
1. In your app â†’ **Products** tab
2. Request access to **"Share on LinkedIn"** and **"Sign In with LinkedIn using OpenID Connect"**
3. Go to the **Auth** tab:
   - Add Redirect URL: `http://localhost:8080/callback`
   - Copy `Client ID` and `Client Secret`

### D. Get Access Token
1. Open this URL in your browser (replace YOUR_CLIENT_ID):
```
https://www.linkedin.com/oauth/v2/authorization?response_type=code&client_id=YOUR_CLIENT_ID&redirect_uri=http://localhost:8080/callback&scope=w_member_social%20w_organization_social%20r_organization_social
```
2. Grant permissions â†’ You get redirected to `http://localhost:8080/callback?code=XXXXXX`
3. Copy the `code` from the URL
4. Exchange for token (run in PowerShell):
```powershell
$body = @{
    grant_type = "authorization_code"
    code = "PASTE_CODE_HERE"
    redirect_uri = "http://localhost:8080/callback"
    client_id = "YOUR_CLIENT_ID"
    client_secret = "YOUR_CLIENT_SECRET"
}
Invoke-RestMethod -Method Post -Uri "https://www.linkedin.com/oauth/v2/accessToken" -Body $body
```
5. Copy the `access_token` from the response

### E. Get Organization ID
Your LinkedIn company page URL is like: `linkedin.com/company/your-business`
The organization ID is found via API:
```powershell
$headers = @{ Authorization = "Bearer YOUR_ACCESS_TOKEN" }
Invoke-RestMethod -Uri "https://api.linkedin.com/v2/organizationAcls?q=roleAssignee" -Headers $headers
```

### F. Add to `.env`:
```
LINKEDIN_CLIENT_ID=77xxxxxxxxxx
LINKEDIN_CLIENT_SECRET=xxxxxxxxxxxxxxxx
LINKEDIN_ACCESS_TOKEN=AQVxxxxxxxxxxxxxxx....long_token
LINKEDIN_ORGANIZATION_ID=12345678
```

---

## ðŸŽµ STEP 6: TikTok

### A. Create TikTok Developer Account
1. Go to https://developers.tiktok.com/
2. Sign in with your TikTok account
3. Create a **Developer App**

### B. App Setup
1. App name: `Your Business Marketing`
2. App icon: Upload your business logo
3. In **Manage apps** â†’ your app â†’ **Add Products**:
   - Add **"Content Posting API"**
   - Add **"Login Kit"**
4. Fill in redirect URL: `http://localhost:8080/callback`

### C. Get Credentials
1. Go to your app â†’ **Keys** section
2. Copy `Client Key` and `Client Secret`

### D. Get Access Token
1. Authorization URL (replace CLIENT_KEY):
```
https://www.tiktok.com/v2/auth/authorize/?client_key=YOUR_CLIENT_KEY&scope=user.info.basic,video.publish,video.upload&response_type=code&redirect_uri=http://localhost:8080/callback
```
2. Grant permissions â†’ Copy the `code` from redirect URL
3. Exchange for token:
```powershell
$body = @{
    client_key = "YOUR_CLIENT_KEY"
    client_secret = "YOUR_CLIENT_SECRET"
    code = "PASTE_CODE_HERE"
    grant_type = "authorization_code"
    redirect_uri = "http://localhost:8080/callback"
} | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "https://open.tiktokapis.com/v2/oauth/token/" -Body $body -ContentType "application/json"
```
4. Copy `access_token`

### E. Add to `.env`:
```
TIKTOK_CLIENT_KEY=aw.........
TIKTOK_CLIENT_SECRET=xxxxxxxxxxxxxxxxx
TIKTOK_ACCESS_TOKEN=act.xxxxxxxxxxxxxxxxxxxx
```

> **Note**: TikTok's Content Posting API may have approval delays.
> By default, videos go to your **Inbox/Drafts** â€” you publish from the app.

---

## ðŸ” STEP 7: SerpAPI (Hashtag & Music Trend Research)

1. Go to https://serpapi.com/ â†’ Create free account
2. Free plan: 100 searches/month (plenty for our use)
3. Go to Dashboard â†’ Copy your API key
4. Add to `.env`:
```
SERPAPI_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

## ðŸ“§ STEP 8: Gmail Integration (your-business@email.com)

This is automatically handled by Step 4 (Google OAuth). The YouTube + Calendar
credentials use your Gmail account. Calendar events and interview scheduling
will use this Gmail.

Your Google Calendar at `your-business@email.com` will be used for:
- Checking available interview slots
- Creating interview events with candidates
- Sending email invites to candidates automatically

**Already configured if you completed Step 4!**

---

## âœ… STEP 9: Business Info

Fill in the remaining `.env` values:
```
DEFAULT_WEBSITE=https://www.yourbusiness.com
DEFAULT_PHONE=+1-XXX-XXX-XXXX
DEFAULT_ADDRESS=Your business address
```

---

## ðŸ STEP 10: Final Launch

### A. Verify your `.env` is complete
Open `config/.env` and make sure ALL these have real values:
```
âœ… TELEGRAM_BOT_TOKEN
âœ… TELEGRAM_ADMIN_CHAT_ID
âœ… MYSQL_PASSWORD
âœ… GOOGLE_GEMINI_API_KEY
âœ… OPENAI_API_KEY
âœ… GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET (+ ran google_auth_setup.py)
âœ… META_APP_ID + META_APP_SECRET + META_PAGE_ACCESS_TOKEN + INSTAGRAM_BUSINESS_ACCOUNT_ID
âœ… LINKEDIN_CLIENT_ID + LINKEDIN_CLIENT_SECRET + LINKEDIN_ACCESS_TOKEN + LINKEDIN_ORGANIZATION_ID
âœ… TIKTOK_CLIENT_KEY + TIKTOK_CLIENT_SECRET + TIKTOK_ACCESS_TOKEN
âœ… SERPAPI_API_KEY
âœ… DEFAULT_WEBSITE + DEFAULT_PHONE + DEFAULT_ADDRESS
```

### B. Start the system
```powershell
cd "D:\Projects\Marketing agents"
.\.venv\Scripts\Activate.ps1
python main.py
```

### C. Test the full flow
1. Open Telegram â†’ Go to your bot
2. Send `/start` â€” you should get a welcome
3. **Take a photo** of your clinic/equipment/team â†’ Send it to the bot
4. Wait ~15 seconds. You'll receive:
   - ðŸ” Analysis (what AI sees in the photo)
   - ðŸ“± Platform recommendations with captions
   - ðŸŽµ Music selection (for videos)
   - âœ…/âŒ Approval buttons per platform
5. Tap **"âœ… Approve ALL"** â†’ Published to all platforms!

### D. Other commands to try
- `/job` â€” Create a job posting
- `/packages` â€” Get promotional package ideas
- `/suggest` â€” See content gap analysis
- `/status` â€” System stats
- Send a PDF resume â†’ It will ask which job to screen it for

---

## ðŸ”§ Troubleshooting

| Problem | Solution |
|---------|----------|
| Bot doesn't respond | Check `TELEGRAM_BOT_TOKEN` is correct and bot is running |
| "Admin only" error | Make sure your `TELEGRAM_ADMIN_CHAT_ID` matches your Telegram ID |
| Database error | Ensure MySQL is running: `net start mysql80` |
| Instagram publish fails | Check `META_PAGE_ACCESS_TOKEN` hasn't expired |
| YouTube upload fails | Re-run `python tools/google_auth_setup.py` |
| FFmpeg not found | Add `C:\ffmpeg\bin` to PATH and restart PowerShell |
| "No module" error | Run `.\.venv\Scripts\Activate.ps1` then `pip install -e .` |

---

## ðŸ’° Monthly Cost Breakdown

| Item | Free Tier | With 5 posts/day |
|------|-----------|-------------------|
| Gemini Flash (vision) | Free under 15 RPM | ~$2-5 |
| OpenAI GPT-4o-mini | First $5 free credit | ~$3-8 |
| SerpAPI | 100 free searches/month | Free for basic use |
| Edge-TTS (voiceover) | Free forever | Free |
| FFmpeg / Pillow | Free forever | Free |
| MySQL (local) | Free | Free |
| **Total** | **$0-5 to start** | **~$10-25/month** |

---

## ðŸŽ¯ Pro Tips

1. **Batch your content**: Take 10 photos â†’ send them all â†’ the system accumulates and suggests collages
2. **Before/after shots**: The AI detects these and creates side-by-side comparisons automatically
3. **Music**: Download free MP3s from [Pixabay Music](https://pixabay.com/music/) into `media/music_library/`
4. **Trending hashtags**: Run automatically, but you can trigger a refresh anytime
5. **Resume screening**: Forward PDF/DOCX resumes to the bot â€” it scores and ranks candidates

Need help? Something not working? Just describe the issue and I'll fix it!
