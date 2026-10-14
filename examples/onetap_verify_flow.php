<?php
// OneTap Verify - passwordless sign-in with a one-time link.      (env: SGH_CPAAS_KEY)
//
// Step 1 (your login page):   create a link for the user's phone/email.
// Step 2 (your redirectUrl):  the user taps the link and lands on redirectUrl?vl_token=...&state=...
//                             verify the token (valid once) and sign the user in.

require __DIR__ . '/../autoload.php';

use SmsGatewayHub\Client;
use SmsGatewayHub\SmsGatewayHubException;

$client = new Client();

// Step 2: running as the redirect handler (e.g. https://example.com/verified.php)
if (isset($_GET['vl_token']) || (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_URI']))) {
    $token = isset($_GET['vl_token']) ? (string) $_GET['vl_token'] : null;
    // Alternative when you only have the full URL:
    // $token = Client::parseRedirect('https://example.com/verified?vl_token=...&state=...');
    if ($token === null || $token === '') {
        http_response_code(400);
        echo 'Missing token';
        exit;
    }
    try {
        $result = $client->verifyLink()->verify($token);
        // Check $result (status "verified", the "to"/"toEmail" and your "state") and start the session here.
        header('Content-Type: application/json');
        echo json_encode($result);
    } catch (SmsGatewayHubException $e) {
        http_response_code(401);
        echo 'Verification failed: ' . htmlspecialchars($e->getMessage());
    }
    exit;
}

// Step 1: run from the command line to send a link
try {
    $link = $client->verifyLink()->create(array(
        'to' => '919800000001',
        'channels' => array('whatsapp', 'sms'),
        'fallbackAfter' => 60,
        'expiry' => 600,
        'redirectUrl' => 'https://example.com/verified.php',
        'state' => bin2hex(random_bytes(8)),
        'appName' => 'Demo Store',
        'sms' => array('text' => 'Tap to sign in to Demo Store: {{link}}', 'sender' => 'SGHTST'),
    ));
    echo 'OneTap Verify id: ' . $link['id'] . PHP_EOL;

    $status = $client->verifyLink()->get($link['id']);
    echo 'Status: ' . (isset($status['status']) ? $status['status'] : '?') . PHP_EOL;

    // Changed your mind? $client->verifyLink()->cancel($link['id']);
} catch (SmsGatewayHubException $e) {
    echo 'Error ' . $e->getErrorCode() . ': ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
