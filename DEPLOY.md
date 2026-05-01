# Deployment

## Normal workflow

```
git push
```

That's it. A GitHub webhook calls `cron/deploy-hook.php` on the cPanel server,
which runs `git pull` and copies the top-level directories to the live web root.
Site updates within ~15 seconds of the push.

## When `git push` isn't enough

The webhook is a PHP script that lives on the server. When you add a NEW
top-level directory (like `donate/` or `events/`), the **currently deployed**
webhook doesn't know about it yet — it has a hardcoded directory list. The
fix is one manual deploy to ship the updated webhook + the new directory:

```
./deploy.sh
```

After that, future pushes auto-deploy the new directory normally.

Other reasons to use `./deploy.sh`:
- Webhook delivery failed (check GitHub repo > Settings > Webhooks > Recent Deliveries)
- You want to verify what's actually on the server matches GitHub
- Emergency rollback (after `git revert HEAD && git push`, run `./deploy.sh` to force-sync)

## Infrastructure overview

```
Local repo (Dropbox)              GitHub                cPanel server
─────────────────────             ──────                ─────────────
~/.../votewood-prepared/   ─push─▶ main  ─webhook─▶ deploy-hook.php
                                                           │
                                                           ├─ git pull /home/seanw2/votewood-prepared
                                                           │
                                                           └─ cp -rf */  /home/seanw2/public_html/votewood.ca/
```

- **Local repo:** `~/Library/CloudStorage/Dropbox/PRINTABLES/votewood-prepared`
- **GitHub:** [github.com/seancanada-bit/votewood-prepared](https://github.com/seancanada-bit/votewood-prepared) (public)
- **Server clone:** `/home/seanw2/votewood-prepared` (where the webhook does `git pull`)
- **Web root:** `/home/seanw2/public_html/votewood.ca` (what visitors see)
- **Webhook:** `cron/deploy-hook.php` — validates GitHub HMAC signature against `/home/seanw2/deploy-secret.txt`

## SSH access

Use the `votewood` alias defined in `~/.ssh/config`:

```
ssh votewood                          # interactive shell
ssh votewood "ls public_html/votewood.ca"   # one-off command
```

Connection details (already in `~/.ssh/config` — listed here for reference):
- **Host:** seanwood.com (votewood.ca is an addon domain on the same cPanel server)
- **Port:** 1546
- **User:** seanw2
- **Key:** `~/.ssh/id_rsa_cpanel`
- **Algorithm flag:** `+ssh-rsa` (server is older OpenSSH that doesn't offer ed25519 host keys)

## Data fetcher (separate from web deploy)

The `votewood-data` repo is a separate Node.js service that polls government
APIs and writes to MySQL. It runs on the same cPanel server as a **cron job**,
NOT via the web deploy. To update it:

```
cd ~/.../votewood-data
git push
ssh votewood "cd /home/seanw2/votewood-data && git pull"
```

The cron job runs every 2 hours (set in cPanel > Cron Jobs). PHP version is 8.2
(`/usr/local/bin/ea-php82`). Database credentials live at
`/home/seanw2/db-config.php` — outside the repo, outside `public_html`.

## Adding a new top-level page directory

1. Create the directory locally: `mkdir new-section/`
2. Add an `index.html` inside it
3. Add it to `.cpanel.yml` (line: `- /bin/cp -rf new-section/ /home/seanw2/public_html/votewood.ca/`)
4. Add it to the `$dirs` array in `cron/deploy-hook.php`
5. Add it to the `DIRS` line in `deploy.sh`
6. `git add . && git commit -m "Add new-section page" && git push`
7. Run `./deploy.sh` ONCE — this ships the updated webhook so future pushes auto-deploy
