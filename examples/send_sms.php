<?php
// php examples/send_sms.php      (env: SGH_API_KEY)

require __DIR__ . '/../autoload.php'; // with Composer: require 'vendor/autoload.php';

use SmsGatewayHub\Client;
use SmsGatewayHub\SmsGatewayHubException;

$client = new Client(); // reads SGH_API_KEY from the environment

try {
    $result = $client->sms()->send(array(
        'senderId' => 'SGHTST',
        'to' => array('919800000001', '919800000002'),
        'text' => 'Your OTP is 123456. Do not share it with anyone.',
        'channel' => 'otp',
        'entityId' => '1201159000000000000',
        'dltTemplateId' => '1207160000000000000',
    ));
    echo 'JobId: ' . $result['jobId'] . PHP_EOL;
    foreach ($result['messages'] as $m) {
        echo $m['number'] . ' -> ' . $m['messageId'] . PHP_EOL;
    }
} catch (SmsGatewayHubException $e) {
    echo 'Error ' . $e->getErrorCode() . ' (HTTP ' . $e->getStatus() . '): ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
