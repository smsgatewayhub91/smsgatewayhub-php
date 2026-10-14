<?php

/**
 * SMSGatewayHub PHP SDK - autoloader for installs without Composer (direct zip download).
 *
 *     require __DIR__ . '/smsgatewayhub-php/autoload.php';
 *     $client = new \SmsGatewayHub\Client(['apiKey' => getenv('SGH_API_KEY')]);
 *
 * With Composer, use vendor/autoload.php instead.
 */
spl_autoload_register(function ($class) {
    $prefix = 'SmsGatewayHub\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
