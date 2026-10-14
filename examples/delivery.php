<?php
// php examples/delivery.php 20047      (env: SGH_API_KEY)

require __DIR__ . '/../autoload.php';

use SmsGatewayHub\Client;
use SmsGatewayHub\SmsGatewayHubException;

$jobId = isset($argv[1]) ? $argv[1] : '20047';
$client = new Client();

try {
    $result = $client->sms()->delivery($jobId);
    foreach ($result['reports'] as $r) {
        echo $r['messageId'] . '  ' . $r['status'] . '  ' . $r['deliveredAt'] . PHP_EOL;
    }
} catch (SmsGatewayHubException $e) {
    echo 'Error ' . $e->getErrorCode() . ': ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
