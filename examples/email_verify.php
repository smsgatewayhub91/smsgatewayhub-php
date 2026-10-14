<?php
// php examples/email_verify.php      (env: SGH_CPAAS_KEY)

require __DIR__ . '/../autoload.php';

use SmsGatewayHub\Client;
use SmsGatewayHub\SmsGatewayHubException;

$client = new Client();

try {
    // Single address
    $r = $client->emailVerify()->check('someone@example.com');
    echo 'Result: ' . $r['result'] . (isset($r['suggestion']) && $r['suggestion'] ? ' (did you mean ' . $r['suggestion'] . '?)' : '') . PHP_EOL;

    // Batch (max 1000 addresses)
    $batch = $client->emailVerify()->batch(array('a@example.com', 'b@example.com'), array('name' => 'newsletter-import'));
    echo 'Batch id: ' . $batch['batchId'] . PHP_EOL;

    $status = $client->emailVerify()->batchStatus($batch['batchId'], array('results' => true, 'page' => 1, 'pageSize' => 100));
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (SmsGatewayHubException $e) {
    echo 'Error ' . $e->getErrorCode() . ': ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
