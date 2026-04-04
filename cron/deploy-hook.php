<?php
// GitHub webhook auto-deploy
// Called by GitHub on every push to main
// Pulls latest code and triggers .cpanel.yml deploy

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

// Pull and deploy
$repo = '/home/seanw2/votewood-prepared';
$output = [];
exec("cd $repo && /usr/local/cpanel/3rdparty/bin/git pull origin main 2>&1", $output);
exec("cd $repo && /usr/local/cpanel/3rdparty/bin/git checkout -- . 2>&1", $output);

// Trigger .cpanel.yml deploy via API
exec("/usr/local/cpanel/bin/uapi VersionControl update repository_root=$repo branch=main 2>&1", $output);

header('Content-Type: text/plain');
echo implode("\n", $output);
