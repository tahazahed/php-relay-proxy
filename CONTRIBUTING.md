# Contributing

Thanks for taking a look. This is a small project with a narrow goal, so a bit of context
before you spend time on a pull request.

## The constraint that shapes everything

This has to keep running on a **plain shared hosting account with no shell access**. That
single requirement rules out most of the obvious improvements, and it is not negotiable:

- **No Composer dependencies.** `composer install` needs a shell, and the people this is
  built for do not have one. There is no `vendor/` directory and there will not be one.
- **No build step.** What is in the repository is what runs on the server. Someone has to
  be able to upload a ZIP through cPanel's File Manager and have it work.
- **PHP 7.4 compatible.** Plenty of shared hosts still default to it. Union types, enums,
  `readonly`, constructor promotion and first-class callables are all out.
- **No background processes, no cron requirement, no database.** Rate limiting and logging
  are file-based for this reason.

If a change needs any of the above, it is probably a different project rather than a patch
to this one. I am happy to discuss it in an issue first.

## Before you open a pull request

1. **Open an issue first** for anything beyond a bug fix or a typo. It saves us both the
   effort of a PR that was never going to land.
2. **Check syntax on 7.4.** At minimum:
   ```bash
   find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l
   ```
3. **Run the self-test** against a working install:
   ```bash
   php tools/selftest.php
   ```
4. **Exercise the thing you changed** through the panel's Request test tab, against a real
   destination. The interesting bugs in a proxy are the ones that only show up with real
   traffic — chunked bodies, multipart uploads, redirects, large downloads.

## Style

Follow what is already there rather than any particular standard:

- `declare(strict_types=1)` at the top of every PHP file.
- Four spaces, no tabs.
- Short classes with one job. `src/` is organised by responsibility, not by layer.
- Comments explain *why*, not *what*. If a line needs a comment to say what it does, the
  line probably needs rewriting instead.
- Existing comments are in Persian. New ones can be in Persian or English — I would rather
  have a clear comment in either language than an awkward one in the "right" one.

## Changes that are especially welcome

- Compatibility fixes for specific hosting setups (DirectAdmin quirks, LiteSpeed edge
  cases, hosts with unusual mod_security rules)
- Anything that makes the SSRF defences stricter without breaking normal use
- Better error messages, particularly ones that tell an operator what to actually fix
- Documentation: a scenario that tripped you up is worth writing down

## Security issues

Do not open a public issue. See [SECURITY.md](SECURITY.md).
