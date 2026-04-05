<?php
// GitHub webhook auto-deploy
// Called by GitHub on every push to main
// Pulls latest code and copies to live directory

// Simple secret token validation
$secret = file_get_contents('/home/seanw2/deploy-secret.txt');
$secret = trim($secret);

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');

if (!$signature || !$payload || !$secret) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

// Only deploy on push to main
$data = json_decode($payload, true);
if (($data['ref'] ?? '') !== 'refs/heads/main') {
    echo 'Not main branch, skipping';
    exit;
}

// Pull latest
$repo = '/home/seanw2/votewood-prepared';
$dest = '/home/seanw2/public_html/votewood.ca';
$output = [];

exec("cd $repo && /usr/local/cpanel/3rdparty/bin/git pull origin main 2>&1", $output);

// Copy files directly (same as .cpanel.yml)
$dirs = ['prepared', 'cron', 'water', 'housing', 'downtown', 'parks', 'safety',
         'community', 'environment', 'infrastructure', 'grants', 'photos', 'fonts'];

exec("/bin/cp -f $repo/index.html $repo/style.css $repo/.htaccess $dest/ 2>&1", $output);

foreach ($dirs as $dir) {
    if (is_dir("$repo/$dir")) {
        exec("/bin/cp -rf $repo/$dir/ $dest/ 2>&1", $output);
    }
}

header('Content-Type: text/plain');
echo "Deployed at " . date('Y-m-d H:i:s') . "\n";
echo implode("\n", $output);
