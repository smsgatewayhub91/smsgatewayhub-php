<?php
// php examples/balance.php      (env: SGH_API_KEY)

require __DIR__ . '/../autoload.php';

use SmsGatewayHub\Client;
use SmsGatewayHub\SmsGatewayHubException;

$client = new Client();

try {
    $result = $client->sms()->balance();
    foreach ($result['balances'] as $name => $credits) {
        echo $name . ': ' . $credits . PHP_EOL;
    }
} catch (SmsGatewayHubException $e) {
    echo 'Error ' . $e->getErrorCode() . ': ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
