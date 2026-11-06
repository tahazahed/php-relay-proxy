# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Use GitHub's private reporting instead — **Security → Report a vulnerability** on this
repository — or email the address on [my GitHub profile](https://github.com/tahazahed).

Include what you need to make the problem reproducible: the request, the configuration
that was in effect, and what you expected to happen instead. I will confirm receipt within
a few days and tell you whether I consider it a vulnerability and what the fix looks like.

This is a side project maintained by one person, so please be realistic about timelines.
I would rather hear about a problem late than not at all.

## Supported versions

The `main` branch is the only supported version. There are no long-term support branches.

## What counts as a vulnerability here

The relay's whole job is to make outbound requests on someone else's behalf, so the
interesting failures are the ones that let a caller reach something they should not:

- Bypassing the host allowlist (encoding tricks, redirect chains, DNS rebinding,
  `user:pass@host` forms, IPv6-mapped addresses, and so on)
- Reaching a private or link-local range despite `block_private_networks` being on,
  especially cloud metadata endpoints
- Getting a request relayed without a valid key, or replaying an HMAC signature
- Header or response splitting through a crafted request
- Reading `config.php`, `settings.json` or the log files over HTTP
- Authentication bypass in the admin panel, or CSRF on a panel action

## What does not count

- **Running as an open proxy because `target.host_allowlist` was left empty.** The panel
  shows a red warning when you turn the allowlist off. That is a configuration decision,
  not a bug.
- **Secrets leaking because `app.debug` was left on.** Debug mode returns error detail by
  design; it is off by default and documented as production-unsafe.
- **A leaked access key being usable.** Keys are bearer credentials. Revoke it in the panel
  and use `auth.ip_allowlist` if you need defence in depth.
- Missing rate limits on a self-hosted install where the operator disabled them.
- Reports produced by a scanner with no demonstrated impact.

## Hardening checklist for operators

If you run this, these are the things that actually matter:

1. Keep `target.host_allowlist` as small as you can get away with.
2. Keep `app.debug` set to `false`.
3. Store `config.php` outside the document root (`../relay-private/config.php`).
4. Use `panel.password_hash`, never a plaintext `panel.password`.
5. If your client has a static IP, set `auth.ip_allowlist`. It is the strongest control
   available here.
6. Serve the relay over HTTPS only. The key travels in the URL.
7. Use one key per client so you can revoke one without breaking the rest.
8. Leave `log.log_bodies` off unless you are actively debugging — it will capture whatever
   is in your request bodies, tokens included.
