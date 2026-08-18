# Crane Training International — website

Static site (HTML5 + CSS + vanilla JS, no build step) for
`https://gestion.avba.com.mx/Cranetraininginternational/`.

## Structure

- `index.html`, `programs.html`, `standards.html`, `onsite.html`,
  `inspections.html`, `about.html`, `facility.html`, `contact.html`,
  `privacy.html`, `terms.html`, `404.html` — top-level pages.
- `programs/*.html` — one detail page per certification program.
- `assets/css`, `assets/js`, `assets/img` — all site assets, no CDN except
  Google Fonts (allowed by the server's Content-Security-Policy).
- `api/contact.php`, `api/smtp-mailer.php` — contact form backend.
- `config/` — server-only configuration (see below). Blocked from direct web
  access by `config/.htaccess`.
- `storage/` — runtime bookkeeping for the contact form's rate limiter.
  Blocked from direct web access by `storage/.htaccess`.
- `.htaccess` — overrides the inherited `DirectoryIndex` and
  `Content-Security-Policy` from the server root so this subfolder serves
  correctly next to the AVBA certification system.

## Contact form — required server setup

The contact form (`contact.html` → `api/contact.php`) sends mail via SMTP
using a small dependency-free client (`api/smtp-mailer.php`), the same way
the credentials are needed but never committed as elsewhere in this repo
(see the root `config/database.php` pattern).

**Before the form will work in production**, create this file directly on
the server (it is *not* part of the deployed git content — `config/` is
git-ignored except for the sample):

```
Cranetraininginternational/config/mail-config.php
```

Start from the committed sample and fill in real values:

```bash
cp config/mail-config.sample.php config/mail-config.php
# then edit config/mail-config.php with real SMTP credentials
```

Required keys (see `config/mail-config.sample.php` for the exact shape):

- `smtp_host`, `smtp_port`, `smtp_secure` (`'tls'` or `'ssl'`)
- `smtp_username`, `smtp_password`
- `from_email`, `from_name`
- `contact_to` — the mailbox that should receive quote requests

If this file is missing, the form fails gracefully: `contact.php` returns a
clear JSON error, and the page's JavaScript shows the visitor a message
pointing them to the `[PLACEHOLDER: CTI contact email]` shown on the page.

## Antispam

- Hidden honeypot field (`website`) in the form — real visitors never see or
  fill it (hidden via CSS, not just `type="hidden"`), so a filled value
  marks a bot.
- Per-IP rate limiting (5 submissions / hour) tracked in
  `storage/rate-limit.json`, pruned automatically.
- No external CAPTCHA — keeps the CSP clean and avoids third-party scripts.

## Deployment

`.github/workflows/deploy-cranetraining.yml` deploys this folder's contents
(and only this folder) to
`/home/u218429682/domains/gestion.avba.com.mx/public_html/Cranetraininginternational`
on every push to `claude/cranetraining-web-m770n6` that touches this folder.

## Placeholders to fill in before go-live

Search the site for `[PLACEHOLDER: ...]`: street address, phone, email,
WhatsApp number, office hours, and social links. Also review
`privacy.html` and `terms.html`, marked as legal drafts pending review, and
`standards.html` / `about.html` / `terms.html`, marked
`[PENDING: confirm accreditation]` wherever a third-party accreditation
claim would need to be added later.

## Regenerating pages

All HTML pages are generated from a small Node script kept outside this
repo (in the session scratchpad) that centralizes the shared header/nav/
footer and page content. The output here is the actual deployed site —
editing these `.html` files directly is fine; there is no build step in
production.
