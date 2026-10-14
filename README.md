# SMSGatewayHub PHP SDK

Official PHP library for [SMSGatewayHub](https://www.smsgatewayhub.com): classic SMS API, CPaaS messages with automatic fallback (WhatsApp, SMS, RCS, Email), OneTap Verify and Email Verification.

- PHP 7.2 or newer, `ext-json`. Uses `ext-curl` when installed, otherwise PHP streams (HTTPS then needs `ext-openssl`).
- Zero third-party dependencies.
- **Server-side only.** Never put your keys in mobile apps, browsers or any code you ship to users.

## Install

Composer:

```bash
composer require smsgatewayhub/smsgatewayhub
```

Direct download (no Composer): https://www.smsgatewayhub.com/sdk/smsgatewayhub-php-1.0.0.zip - unzip it and

```php
require __DIR__ . '/smsgatewayhub-php/autoload.php';
```

## Quick start

```php
require 'vendor/autoload.php';

use SmsGatewayHub\Client;

$client = new Client(array(
    'apiKey'   => getenv('SGH_API_KEY'),   // classic SMS API key: Panel > API
    'cpaasKey' => getenv('SGH_CPAAS_KEY'), // CPaaS key sghcp_...: Panel > CPaaS > API
));

$result = $client->sms()->send(array('senderId' => 'SGHTST', 'to' => '919800000001', 'text' => 'Hello!'));
echo $result['jobId'];
```

`new Client()` with no options reads `SGH_API_KEY`, `SGH_CPAAS_KEY` and `SGH_BASE_URL` from the environment.

| Option | Default | Notes |
|---|---|---|
| `apiKey` | env `SGH_API_KEY` | needed for `sms()` |
| `cpaasKey` | env `SGH_CPAAS_KEY` | needed for `messages()`, `verifyLink()`, `emailVerify()` |
| `baseUrl` | env `SGH_BASE_URL`, else `https://www.smsgatewayhub.com` | trailing slash is removed |
| `timeout` | `30` | seconds |
| `maxRetries` | `2` | see Errors and retries |
| `transport` | `auto` | `auto` (curl when available), `curl` or `stream` |

API groups are reached through **accessor methods** on the client: `$client->sms()`, `$client->messages()`, `$client->verifyLink()`, `$client->emailVerify()`. Every method returns plain PHP arrays (decoded JSON).

## Classic SMS

```php
$r = $client->sms()->send(array(
    'senderId'      => 'SGHTST',
    'to'            => array('919800000001', '919800000002'), // string or array, max 100 numbers
    'text'          => 'Your OTP is 123456',
    'channel'       => 'otp',          // 1 / 'promotional', 2 / 'transactional' (default), 'OTP'
    'route'         => 1,              // default 1
    // 'unicode'    => true,           // default: auto-detected from the text
    // 'flash'      => true,
    // 'scheduleAt' => new DateTime('2026-10-06 20:30'), // or a string "2026/10/06 08:30:00 PM"
    // 'groupId'    => '...',
    'entityId'      => '1201159000000000000',
    'dltTemplateId' => '1207160000000000000',
));
// array('jobId' => '20047', 'messages' => array(array('number' => '...', 'messageId' => '...')), 'raw' => array(...))

$b = $client->sms()->balance();          // array('balances' => array('Promo' => 9988, 'Trans' => 0), 'raw' => ...)
$d = $client->sms()->delivery('20047');  // array('reports' => array(array('messageId', 'status', 'deliveredAt')), 'raw' => ...)
```

## CPaaS messages with fallback

One call, many channels: the first channel is tried and, if it is not delivered within `fallbackAfter` seconds, the next one is used.

```php
$msg = $client->messages()->send(array(
    'channels'      => array('whatsapp', 'sms'),
    'to'            => '919800000001',
    'fallbackAfter' => 300,
    'ref'           => 'order-1001',
    'callbackUrl'   => 'https://example.com/hooks/sgh',
    'whatsapp'      => array('template' => 'order_update', 'language' => 'en', 'variables' => array('Asha', '1001')),
    'sms'           => array('text' => 'Hi Asha, order 1001 has shipped.', 'sender' => 'SGHTST', 'templateId' => '...', 'entityId' => '...'),
    // 'rcs'        => array('template' => '...', 'bot' => '...', 'variables' => array('name' => 'Asha')),
    // 'email'      => array('from' => '...', 'subject' => '...', 'html' => '...'), plus 'toEmail' => 'a@b.com'
), array('idempotencyKey' => 'order-1001-shipped')); // optional

echo $msg['id'];      // "cp_..."
echo $msg['status'];

$client->messages()->get($msg['id']);                                    // status + event timeline
$client->messages()->list(array('status' => 'delivered', 'ref' => 'order-1001', 'limit' => 50));
```

The body is sent as-is, so any field supported by the API can be used. An `Idempotency-Key` is always sent (a random UUID when you give none) and the same key is reused on every retry, so a retry can never send a message twice.

## OneTap Verify

Passwordless sign-in with a one-time link sent over WhatsApp, SMS or email. `{{link}}` in your channel texts is replaced by the link.

```php
// 1. Send the link
$link = $client->verifyLink()->create(array(
    'to'          => '919800000001',
    'channels'    => array('whatsapp', 'sms'),
    'redirectUrl' => 'https://example.com/verified.php',
    'state'       => $csrfState,
    'appName'     => 'Demo Store',
    'sms'         => array('text' => 'Tap to sign in: {{link}}'),
));
$client->verifyLink()->get($link['id']);     // created / sent / opened / confirmed / verified / expired / failed / cancelled
$client->verifyLink()->cancel($link['id']);

// 2. On redirectUrl: the user arrives with ?vl_token=...
$token  = isset($_GET['vl_token']) ? $_GET['vl_token'] : Client::parseRedirect($fullUrl); // getVerifyToken() is an alias
$result = $client->verifyLink()->verify($token); // valid once
```

## Email Verification

```php
$r = $client->emailVerify()->check('someone@example.com');
// result (valid|invalid|risky|unknown), reason, suggestion, disposable, roleBased, freeProvider, hasMx, domain

$batch  = $client->emailVerify()->batch($emails, array('name' => 'newsletter')); // max 1000
$status = $client->emailVerify()->batchStatus($batch['batchId'], array('results' => true, 'page' => 1, 'pageSize' => 100, 'filter' => 'invalid'));
```

## Webhooks

Delivery and status callbacks (`callbackUrl`) are plain JSON POSTs to your URL:

```php
// hooks/sgh.php
$event = json_decode(file_get_contents('php://input'), true);
if (is_array($event)) {
    // store $event['id'], $event['status'], ... then answer quickly
}
http_response_code(200);
```

## Errors and retries

Every failure throws `SmsGatewayHub\SmsGatewayHubException` (extends `RuntimeException`):

| Method | Meaning |
|---|---|
| `getStatus()` | HTTP status; `0` for network errors, timeouts and configuration errors |
| `getErrorCode()` | string code: API code (e.g. `bad_request`), classic `ErrorCode` (e.g. `13`), `http_<status>` when the body is not JSON, `network_error`, `timeout`, `config_error` |
| `getMessage()` | readable message (never contains your keys) |
| `getChannel()` | channel reported by the CPaaS API, or `null` |
| `getRaw()` | response body text, max 2000 characters |

```php
try {
    $client->messages()->send($body);
} catch (\SmsGatewayHub\SmsGatewayHubException $e) {
    error_log($e->getErrorCode() . ' ' . $e->getStatus() . ' ' . $e->getMessage());
}
```

- Classic SMS API answers HTTP 200 with an `ErrorCode`; anything other than `000`/`0` is thrown with `getStatus() === 200`.
- A missing key, more than 100 numbers or more than 1000 emails throw `config_error` before any network call.
- Retries (`maxRetries`, default 2): network errors, HTTP 429 (honours `Retry-After`, max 10 s) and 5xx, with 0.5 s then 1 s backoff. `sms()->balance()`, `sms()->delivery()`, the CPaaS GET calls and `messages()->send()` (thanks to the Idempotency-Key) follow these rules. `sms()->send()` has no idempotency, so it is retried only when the connection could not be opened or on HTTP 429 - never after a timeout, read error or 5xx, because that could send the SMS twice. `verifyLink()->create/verify/cancel` and `emailVerify()->check/batch` are only retried when the connection could not be opened, never after the request was sent.

## White-label base URL

Resellers and white-label partners point the SDK at their own domain:

```php
$client = new Client(array('baseUrl' => 'https://sms.yourbrand.com', 'apiKey' => getenv('SGH_API_KEY')));
```

or set `SGH_BASE_URL=https://sms.yourbrand.com`.

## Security

- Use the SDK on your server only. Keys in apps or browsers can be extracted and abused.
- Keep keys in environment variables `SGH_API_KEY` / `SGH_CPAAS_KEY` (or your secret manager), never in source control.
- The SDK never logs keys and removes them from error messages.

## Tests

```bash
php tests/run.php   # runs against the local mock server, SGH_BASE_URL (default http://127.0.0.1:8765)
```

## Support

- support@smsgatewayhub.com
- https://www.smsgatewayhub.com/api-developer-hub

Licence: MIT
