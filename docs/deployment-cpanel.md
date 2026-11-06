# Deploying on cPanel — notes from a real install

The README covers the normal installation. This file is the stuff you only find out by
doing it: the cPanel-specific details that will bite you, and what to verify before you
trust the thing.

Everything below uses `relay.example.com` as the domain and `exampleuser` as the cPanel
account. Substitute your own.

---

## Where the files go

| Thing | Path |
|---|---|
| Document root | `/home/exampleuser/relay.example.com` |
| `config.php` | `/home/exampleuser/relay-private/config.php` — **outside the web root** |
| `settings.json` | `/home/exampleuser/relay-private/settings.json` — written by the panel |
| Relay log | `/home/exampleuser/relay.example.com/storage/logs/relay-YYYY-MM-DD.log` |
| PHP error log | `/home/exampleuser/logs/php.error.log` |

### Why `config.php` lives outside the document root

`bootstrap.php` looks for configuration in this order:

1. The path in the `RELAY_CONFIG` environment variable, if set
2. `../relay-private/config.php`, one level above the document root
3. `./config.php`, next to `index.php`

Option 2 is the one to use. If your host's PHP handler ever breaks — a bad MultiPHP
switch, a corrupt `.htaccess`, an expired handler registration — Apache will fall back to
serving `.php` files as plain text. A `config.php` inside the document root would then
hand out every key you own to anyone who asks for it. One directory up, that cannot happen.

Create the directory in File Manager (enable *Show hidden files* if you need to see dotted
entries), move `config.php` into it, and set the file to `0600`.

---

## The `.htaccess` is a merged file

cPanel writes its own blocks into `.htaccess` and rewrites them whenever you change
something in the control panel. You will see two of them:

```apache
# BEGIN cPanel-generated php ini directives, do not edit
...
# END cPanel-generated php ini directives, do not edit

# php -- BEGIN cPanel-generated handler, do not edit
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php81 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
```

**Leave those alone and put the relay's rules underneath them.** cPanel only rewrites its
own marked blocks, so rules below survive.

What this means in practice: if you change the PHP version from **MultiPHP Manager**,
cPanel rewrites the handler block. The relay's rules below it stay intact — but open
`https://relay.example.com/` once afterwards and confirm you get a `200` rather than a
`500`, because a bad handler swap is the single most common way to break the install.

### Rules the relay adds

Beyond what ships in the repository's `.htaccess`, a cPanel install wants one extra line
so Let's Encrypt validation keeps working:

```apache
RewriteRule ^\.well-known(/|$) - [L]
```

Without it, AutoSSL's validation requests get swallowed by the relay and certificate
renewal silently fails a few months later.

The shipped `.htaccess` already has the equivalent exemptions for the panel and the font:

```apache
RewriteRule ^panel\.php$ - [L]
RewriteRule ^assets/ - [L]
```

### If you get a 500 on every request

On hosts that do not grant `AllowOverride Options`, this line is fatal:

```apache
Options -Indexes -MultiViews
```

Delete it. Everything else keeps working; you lose directory-listing suppression, which
`index.php` handles anyway.

---

## Files already in the document root

If you are deploying into a domain that already existed, three things must be preserved:

- `.well-known/` — AutoSSL validation lives here
- `.user.ini` and `php.ini` — per-directory PHP settings your host may depend on

Do not delete them when you upload.

---

## The panel font is local on purpose

`assets/vazirmatn-var.woff2` is a variable font containing every weight in one 111 KB
file, shipped with its SIL OFL licence in `assets/Vazirmatn-OFL.txt`.

The panel loads it from your own host and makes no request to Google Fonts or any CDN.
That is deliberate: the panel is usually opened from inside the network that cannot reach
things, and a panel that hangs waiting on `fonts.googleapis.com` is useless exactly when
you need it. The explicit `RewriteRule ^assets/ - [L]` keeps that path from ever being
treated as a relay request.

---

## Verifying the install

Run through this before you point production traffic at it. Every row should behave as
listed.

| Test | Expected |
|---|---|
| Key in path / header / query string | `200` |
| No key, and a wrong key | `401` |
| A host outside the allowlist | `403` |
| SSRF: `http://127.0.0.1/` and `http://169.254.169.254/` | `403` |
| Scheme `ftp://` | `403` |
| Direct request to `src/`, `storage/`, `tools/`, `config.php` | No content served |
| A real `GET` and `POST` against an allowed API | Real upstream response passes through |
| A `multipart` upload | Same SHA-256 on the far side, nested fields intact |
| A streamed download | Completes, memory flat |
| A gzipped response | `Content-Encoding: gzip` preserved |
| Rate-limit and request-id headers | Present on every response |
| PHP error log | No warnings or deprecations |

The useful one for a blocked-network deployment: call
`https://api.telegram.org/bot123456:FAKE/getMe` through the relay. A `401` coming back
**from Telegram** is a success — it means the relay reached Telegram and Telegram rejected
the fake token. A timeout means it did not.

---

## Day-to-day operations

### Adding a destination

**Panel → Targets.** One domain per line, save. No restart, no file editing; the next
request picks it up.

Removing the restriction entirely is possible by unticking *only allow the domains below*,
but take the red warning on that screen seriously. An open proxy on shared hosting gets
the account suspended, usually without much of a conversation.

### Changing the panel password

**Panel → Security.** It asks for the current password and stores the new one as a bcrypt
hash in `settings.json`. After that, `panel.password` in `config.php` stops working.

Locked out? Open `/home/exampleuser/relay-private/settings.json` in File Manager and blank
out `panel.password_hash`. The `config.php` password becomes valid again.

### Creating and revoking keys

**Panel → Keys.** Make one key per server or per application, so you can revoke a single
one later without taking everything else down. Changes apply to the next request.

Keys and the allowlist are read from `settings.json`, not from `config.php`. The values in
`config.php` are used exactly once, on first run, to seed `settings.json` — after that they
have no effect. This is intentional: otherwise a key you deleted in the panel would come
back from `config.php` on your next upload.

### Keeping a rollback

Before replacing `panel.php` or anything in `src/`, copy the old file somewhere outside the
web root:

```
/home/exampleuser/relay-private/panel.php.bak
```

Then rolling back is one File Manager copy rather than a re-download.

---

## After you finish deploying

If you used a cPanel API token to automate the upload, **revoke it now** —
*Security → Manage API Tokens*. It has done its job and a long-lived token with file-write
access is not something to leave lying around.

The same goes for any file on your own machine where you wrote down the cPanel password
during setup. Delete it, and change the password if it ever touched a plaintext file.
