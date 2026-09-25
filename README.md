# BotMail

Self-hosted email for AI agents. One API key controls every inbox. Cloudflare Email Routing receives mail, a Worker posts it to Laravel, and Resend sends outbound mail.

## Requirements

- PHP 8.2+ and Composer
- MySQL 8+
- A Cloudflare zone with Email Routing available
- A domain verified in Resend for outbound mail
- Node.js and Wrangler, for the inbound Worker

This project targets Laravel 11. Composer 2.9 blocks that release line because of security advisories, so `composer.json` sets `audit.block-insecure` to false. Prefer a patched framework when you can move off Laravel 11.

## Setup

Create the database:

```sql
CREATE DATABASE botmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Fill in `.env`:

- `DB_*` for MySQL
- `BOTMAIL_KEY` — the `X-API-Key` value
- `INBOUND_WEBHOOK_SECRET` — shared with the Worker
- `CLOUDFLARE_API_TOKEN` — token with Email Routing edit on the zone
- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_EMAIL_WORKER=botmail-inbound`
- `RESEND_API_KEY`
- `RESEND_WEBHOOK_SECRET` — Svix signing secret from Resend
- `MAIL_MAILER=resend`
- `QUEUE_CONNECTION=database`

On WAMP, the MySQL client may not be on `PATH`. Point `DB_HOST` at `127.0.0.1`. The default WAMP account is `root` with an empty password.

```bash
php artisan migrate
php artisan make:filament-user
php artisan queue:work
```

This setup already created a local panel user: `admin@botmail.test` / `password`. Change that password before anyone else can reach the app. The panel is at `/admin`. Inbound mail is queued, so `queue:work` has to stay running.

Raise MySQL `max_allowed_packet` if you expect large attachments. Attachment bytes are stored as base64 in the `messages.attachments` JSON column. There is no file disk for them.

## Cloudflare

1. In the Cloudflare dashboard, enable Email Routing on the zone and verify one destination address. Cloudflare requires that before routing rules can be created.
2. Add the sending domain in Resend and publish the DNS records it gives you.
3. Deploy the Worker:

```bash
cd cloudflare/worker
npm install
npx wrangler secret put WEBHOOK_SECRET
npx wrangler deploy
```

Set `WEBHOOK_URL` in `cloudflare/worker/wrangler.toml` to `https://your-app.example.com/webhook/cloudflare` before deploying. `WEBHOOK_SECRET` must match `INBOUND_WEBHOOK_SECRET`.

4. In BotMail, create the domain and run **Verify**. That checks Email Routing and points the zone catch-all at `botmail-inbound`.
5. Create an inbox. BotMail adds a literal routing rule for that address with action `worker`, so mail is delivered to the Worker and then to `POST /webhook/cloudflare`.

Cloudflare allows 200 routing rules per domain. Creating the 201st inbox is rejected.

You can store a per-user Cloudflare token on the Settings page. It is encrypted at rest. When it is empty, BotMail uses `CLOUDFLARE_API_TOKEN`.

## API

Send `X-API-Key: $BOTMAIL_KEY` on every request. There is no Sanctum guard and no rate limit.

```bash
curl -X POST http://localhost/api/inboxes \
  -H "X-API-Key: $BOTMAIL_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"local_part\":\"agent\",\"domain_id\":1,\"display_name\":\"Agent\"}"

curl http://localhost/api/inboxes \
  -H "X-API-Key: $BOTMAIL_KEY"

curl -X POST http://localhost/api/inboxes/1/messages \
  -H "X-API-Key: $BOTMAIL_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"to\":[\"person@example.com\"],\"subject\":\"Hello\",\"text\":\"Hi\"}"
```

| Method | Path | Action |
| --- | --- | --- |
| POST | `/api/inboxes` | Create an inbox and its routing rule |
| GET | `/api/inboxes` | List inboxes |
| GET | `/api/inboxes/{id}` | Inbox details |
| DELETE | `/api/inboxes/{id}` | Delete the routing rule, then the inbox |
| GET | `/api/inboxes/{id}/messages` | List messages (no bodies or attachment bytes) |
| GET | `/api/inboxes/{id}/messages/{msgId}` | Message details |
| POST | `/api/inboxes/{id}/messages` | Send |
| POST | `/api/inboxes/{id}/messages/{msgId}/reply` | Reply |
| DELETE | `/api/inboxes/{id}/messages/{msgId}` | Delete a message |

List endpoints accept `per_page` up to 100. Message lists also accept `direction` and `unread=1`.

## Inbound webhook

```bash
curl -X POST http://localhost/webhook/cloudflare \
  -H "X-Webhook-Secret: $INBOUND_WEBHOOK_SECRET" \
  -H "Content-Type: application/json" \
  -d "{\"from\":\"ada@example.com\",\"to\":\"agent@example.com\",\"subject\":\"Hello\",\"text\":\"Hi\",\"message_id\":\"<abc@example.com>\"}"
```

A valid secret returns 202 and queues `ProcessInboundEmail`. With a database queue, run `php artisan queue:work` to store the message. An active inbox must already exist for the `to` address.

Resend delivery events go to `POST /webhook/resend`. Point the Resend webhook at that URL. The signing secret is `RESEND_WEBHOOK_SECRET`.

## Panel

- **Inboxes** — create, edit, pause, resume, delete
- **Messages** — filter, read a thread, reply, mark read or unread, download attachments
- **Domains** — create, verify, delete (blocked while inboxes exist)
- **Compose** — send from an inbox, including attachments kept in memory
- **Dashboard** — inbox count, messages today, unread, outbound success rate
