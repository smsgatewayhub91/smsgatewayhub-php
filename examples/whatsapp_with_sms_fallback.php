<?php
// php examples/whatsapp_with_sms_fallback.php      (env: SGH_CPAAS_KEY)
// WhatsApp template first; if it is not delivered within 300 seconds the SMS is sent instead.

require __DIR__ . '/../autoload.php';

use SmsGatewayHub\Client;
use SmsGatewayHub\SmsGatewayHubException;

$client = new Client(); // reads SGH_CPAAS_KEY

try {
    $msg = $client->messages()->send(array(
        'channels' => array('whatsapp', 'sms'),
        'to' => '919800000001',
        'fallbackAfter' => 300,
        'ref' => 'order-1001',
        'callbackUrl' => 'https://example.com/hooks/sgh',
        'whatsapp' => array(
            'template' => 'order_update',
            'language' => 'en',
            'variables' => array('Asha', '1001'),
        ),
        'sms' => array(
            'text' => 'Hi Asha, your order 1001 has shipped.',
            'sender' => 'SGHTST',
            'templateId' => '1207160000000000000',
            'entityId' => '1201159000000000000',
        ),
    ), array('idempotencyKey' => 'order-1001-shipped')); // optional; a UUID is generated when omitted

    echo 'Message id: ' . $msg['id'] . ', status: ' . (isset($msg['status']) ? $msg['status'] : '?') . PHP_EOL;

    $status = $client->messages()->get($msg['id']);
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $recent = $client->messages()->list(array('ref' => 'order-1001', 'limit' => 10));
    echo json_encode($recent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (SmsGatewayHubException $e) {
    echo 'Error ' . $e->getErrorCode() . ' (HTTP ' . $e->getStatus() . ', channel ' . $e->getChannel() . '): ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
