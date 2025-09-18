# SimpleMoney

A lightweight personal-finance web app that connects to banks via **TrueLayer**, aggregates **accounts & cards** (incl. **AMEX**), shows **transactions & insights**, manages **budgets & categories**, and ships with a finance-only **AI “Spend Coach”** (Groq).

No build step. **PHP + MySQL + Tailwind + Chart.js**.

---

## ✨ Features

- **Bank connections (TrueLayer)**
  - Accounts via `/data/v1/accounts`
  - Cards (e.g., **AMEX**) via `/data/v1/cards`
  - “Connect”, **Reconnect**, and **Delete** per connection
  - Provider logos with a clean fallback avatar
- **Transactions**
  - Multi-select **Accounts / Cards**
  - Date range, **DB-backed categories**, and text search
  - KPIs (count, spent, income) + pagination
  - Handles SCA expiry (403) with a re-auth flow
- **Insights**
  - 90-day **cash-flow** & **category breakdown** charts
  - Uses your **`categories`** table (not provider types)
- **Budgets** (basic)
- **AI “Spend Coach”**
  - Uses **Groq** (`GROQ_API_KEY`)
  - Answers grounded in **your** data, with finance-only guardrails

---

## 🧰 Tech

- PHP 8.1+ (PDO, cURL, JSON, OpenSSL)
- MySQL 8.x
- TailwindCSS, Chart.js
- TrueLayer Data API
- Groq LLM API

---

## 📁 Repository layout
/public
  /api
    accounts.php
    transactions.php
    connections.php
    connection_delete.php
    connection_reauth.php
    ai_chat.php
  /auth
    tl_start.php        # starts the TrueLayer consent flow (direct link)
    tl_callback.php     # OAuth redirect handler (TL_REDIRECT_URI should point here)
  /partials
    navbar.php
    chat_widget.php
  dashboard.php
  transactions.php
  insights.php
  connect.php          # polished Connect page (buttons + logos)
  login.php
  logout.php

/src
  auth_guard.php       # requireUser / requireUserApi (+ base URL helper)
  tl_api.php           # TrueLayer HTTP + auth URL builders + helpers

simplemoney.sql        # schema + seed

> The app auto-detects your **`/public`** base path, so it works when hosted at `/SimpleMoney/public` or any folder name.

---

## ⚙️ Prerequisites

- PHP **8.1+** with PDO, cURL, OpenSSL
- MySQL **8**
- Apache/Nginx (HTTPS recommended for OAuth)
- A **TrueLayer** client (Data API)
- A **Groq** API key (for the chat widget)

---

## 🚀 Quick Start

1. **Clone** and make sure your web server serves the `/public` directory (e.g., `https://localhost/SimpleMoney/public`).

2. **Database**
   ```bash
   mysql -u root -p -e "CREATE DATABASE simplemoney CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p simplemoney < simplemoney.sql
   
Create a .env at the project root (or set system env vars):
 ```bash
# ==== App & Server ====
APP_ENV=local
BASE_URL=http://localhost/simplemoney/

# ==== Database ====
DB_DSN="mysql:host=127.0.0.1;dbname=simplemoney;charset=utf8mb4"
DB_USER="root"
DB_PASS=""

 ==== JWT (optional for cookie tokens) ====
JWT_SECRET=""

 ==== Google OAuth (optional) ====
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

# ==== TrueLayer (Data API) ====
TL_CLIENT_ID=
TL_CLIENT_SECRET=
TL_REDIRECT_URI=https://localhost/SimpleMoney/public/auth/tl_callback.php

# Endpoints (defaults are fine if unset)
TL_AUTH_BASE=https://auth.truelayer.com
TL_TOKEN_URL=https://auth.truelayer.com/connect/token
TL_API_BASE=https://api.truelayer.com

# Encrypt TL tokens at rest (optional; 32-byte hex)
TL_TOKEN_KEY=
TL_PRIVATE_KEY_PATH=

# ==== Groq (AI chat) ====
GROQ_API_KEY=YOUR_GROQ_API_KEY
GROQ_MODEL=llama-3.3-70b-versatile 
 ```

Never commit real secrets. Keep .env git-ignored.

TLS / Redirect

Serve /public over HTTPS in development (self-signed is fine).

Set your TrueLayer Redirect URI to:

https://localhost/SimpleMoney/public/auth/tl_callback.php


Run

Visit /public/login.php, create/log in a user.

Go to Connect Bank → Connect with TrueLayer (goes straight to TL via auth/tl_start.php).

After the callback, check Transactions and Insights.

🔐 Authentication & Guards

requireUser($pdo) – for pages, redirects to /public/login.php?next=…

requireUserApi($pdo) – for APIs, returns 401 JSON (no redirect)

public/logout.php – destroys session, clears cookies, (optionally) revokes refresh tokens, then redirects to login

🏦 TrueLayer Flow

Connect button in public/connect.php is a plain <a> to public/auth/tl_start.php → opens the TrueLayer consent.

Reconnect links to tl_start.php?action=reauth&id=<connection_id>.

Callback (auth/tl_callback.php) exchanges the code for tokens and stores/updates a row in tl_connections.

Duplicates after reconnect?
If reconnect inserts a new row, either:

Hide older duplicates in public/api/connections.php (keep newest per provider), and/or

In tl_start.php, set $_SESSION['tl_replace_id'] for ?action=reauth&id=…, then in the callback delete/replace that row.

AMEX note
AMEX is a card provider, so data comes from /data/v1/cards (not /accounts). The UI merges accounts + cards.

💬 AI “Spend Coach”

Widget: public/partials/chat_widget.php

API: public/api/ai_chat.php (uses Groq; answers grounded by your recent transactions)

Guardrails: finance-only (ignores image generation and unrelated prompts)

No server-side chat storage; last ~40 turns per user live in localStorage

Disable by removing the widget include (e.g., from navbar.php).

🗃️ Database

Used tables (lean):

users, user_refresh_tokens

tl_connections (TrueLayer connection + tokens)

categories (master list used in filters/insights)

budgets (optional)

If your dump contains older mirrors (e.g., local accounts/transactions caches), they’re not required.

🧪 Troubleshooting

403 “SCA exemption has expired”
The resource is protected; use Reconnect to obtain fresh consent.

501 endpoint_not_supported on /accounts
Some providers (e.g., AMEX) don’t expose /accounts. The app uses /cards for these.

“Network error starting connection”
Ensure public/auth/tl_start.php exists and env values are set. For API-based starts, confirm public/api/connection_reauth.php returns { ok: true, url: "https://auth.truelayer.com/..." }.

AMEX doesn’t appear in the selector
Confirm the consent includes cards scope and the provider id (ob-amex). The UI shows cards and accounts together.

🤝 Contributing

PRs welcome! Please keep the UI minimal, avoid heavy build tooling, and don’t commit secrets or real data.

📄 License

MIT (change to your preferred license if needed)

🗺️ Roadmap

Auto-categorisation rules

Scheduled refresh & caching

Alerts (budget overspend / unusual activity)

CSV export

Server-side chat history (opt-in)

::contentReference[oaicite:0]{index=0}



