#!/bin/bash
# votewood.ca deploy script
#
# What this does:
#   1. Pushes any local commits to GitHub (if there are any)
#   2. SSHes into the cPanel server
#   3. Pulls the latest code from GitHub
#   4. Copies all site directories to the live web root
#
# When you need this:
#   - Manual fallback when the GitHub webhook fails
#   - First deploy of new top-level directories (the webhook only knows about
#     directories that were already in deploy-hook.php at the time it last ran)
#   - When you want to verify a deploy worked
#
# Normal workflow doesn't need this — `git push` triggers the webhook
# automatically (cron/deploy-hook.php on the server). This is the manual override.
#
# SSH alias used: 'votewood' (defined in ~/.ssh/config)
# Server path: /home/seanw2/votewood-prepared (Git clone)
# Live path: /home/seanw2/public_html/votewood.ca (web root for addon domain)

set -e

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_DIR"

echo "==> Pushing local commits to GitHub..."
git push 2>&1 | tail -3 || true

echo ""
echo "==> Pulling and deploying on cPanel server..."

# All top-level directories that should be copied to the live site
# Add new directories here when you create them
DIRS="prepared cron water housing downtown parks safety community environment infrastructure grants donate photos fonts"

# Build the cp commands for each directory
CP_DIRS=""
for d in $DIRS; do
  CP_DIRS="$CP_DIRS -rf $d/"
done

ssh votewood "
  cd /home/seanw2/votewood-prepared && \
  /usr/local/cpanel/3rdparty/bin/git pull origin main 2>&1 | tail -5 && \
  /bin/cp $CP_DIRS /home/seanw2/public_html/votewood.ca/ && \
  /bin/cp -f index.html style.css .htaccess /home/seanw2/public_html/votewood.ca/ 2>/dev/null || true && \
  echo '---DEPLOYED---'
" 2>&1 | grep -v "post-quantum\|This session\|server may need" | tail -10

echo ""
echo "==> Verifying live site..."
STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://votewood.ca/)
echo "votewood.ca: HTTP $STATUS"
