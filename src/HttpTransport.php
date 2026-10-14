<?php

namespace SmsGatewayHub;

/**
 * HTTP layer used by every group. Uses ext-curl when available, otherwise PHP stream contexts.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class HttpTransport
{
    /** Retry on network errors, 429 and 5xx (GET, and POST /cpaas/v1/messages.ashx with an Idempotency-Key). */
    const RETRY_ALL = 'all';

    /** Retry only when the connection could not be opened (the request was never sent). */
    const RETRY_CONNECT = 'connect';

    /**
     * Classic SendSMS (no idempotency): retry only when the connection could not be opened, or on HTTP 429.
     * Never on timeouts, read errors or 5xx - that could send the SMS twice.
     */
    const RETRY_CONNECT_OR_429 = 'connect_or_429';

    const USER_AGENT = 'smsgatewayhub-php/1.0.0';

    /** @var string */
    private $baseUrl;

    /** @var float */
    private $timeout;

    /** @var int */
    private $maxRetries;

    /** @var string[] */
    private $secrets = array();

    /** @var bool */
    private $useCurl;

    /**
     * Extra request headers added to every call. Test hook only.
     *
     * @internal
     * @var array<string,string>
     */
    public $extraHeaders = array();

    /**
     * @param string   $baseUrl
     * @param float    $timeout    seconds
     * @param int      $maxRetries
     * @param string[] $secrets    values that must never appear in error messages
     * @param string   $transport  "auto", "curl" or "stream"
     */
    public function __construct($baseUrl, $timeout, $maxRetries, array $secrets, $transport = 'auto')
    {
        $this->baseUrl = (string) $baseUrl;
        $this->timeout = (float) $timeout;
        $this->maxRetries = (int) $maxRetries;
        foreach ($secrets as $s) {
            if (is_string($s) && $s !== '') {
                $this->secrets[] = $s;
            }
        }
        if ($transport === 'stream') {
            $this->useCurl = false;
        } elseif ($transport === 'curl') {
            if (!function_exists('curl_init')) {
                throw new SmsGatewayHubException('The "curl" transport was requested but ext-curl is not installed', 0, 'config_error');
            }
            $this->useCurl = true;
        } else {
            $this->useCurl = function_exists('curl_init');
        }
    }

    /** @return string "curl" or "stream" */
    public function transportName()
    {
        return $this->useCurl ? 'curl' : 'stream';
    }

    /**
     * Sends a request and returns the decoded JSON object together with the body text.
     * Throws SmsGatewayHubException for non-2xx responses and for bodies that are not a JSON object/array.
     *
     * @param string                $method    "GET" or "POST"
     * @param string                $path      path starting with "/"
     * @param array                 $query     query parameters (null values are skipped)
     * @param mixed                 $body      JSON body (array/object) or null
     * @param array<string,string>  $headers
     * @param string                $retryMode self::RETRY_ALL, self::RETRY_CONNECT or self::RETRY_CONNECT_OR_429
     * @return array{0: array, 1: string} [decoded, bodyText]
     */
    public function requestJson($method, $path, array $query = array(), $body = null, array $headers = array(), $retryMode = self::RETRY_ALL)
    {
        $res = $this->request($method, $path, $query, $body, $headers, $retryMode);
        $status = $res['status'];
        $text = $res['body'];

        $decoded = null;
        $isJson = false;
        if (trim($text) !== '') {
            $decoded = json_decode($text, true);
            $isJson = (json_last_error() === JSON_ERROR_NONE);
        }

        if ($status < 200 || $status >= 300) {
            throw $this->httpError($status, $text, $isJson ? $decoded : null);
        }
        if (!$isJson || !is_array($decoded)) {
            throw $this->makeError('Unexpected response from server (HTTP ' . $status . ', body is not JSON)', $status, 'http_' . $status, null, $text);
        }
        return array($decoded, $text);
    }

    /**
     * Low level request with retries. Returns ['status' => int, 'headers' => array, 'body' => string].
     *
     * @param string               $method
     * @param string               $path
     * @param array                $query
     * @param mixed                $body
     * @param array<string,string> $headers
     * @param string               $retryMode
     * @return array
     */
    public function request($method, $path, array $query = array(), $body = null, array $headers = array(), $retryMode = self::RETRY_ALL)
    {
        $method = strtoupper((string) $method);
        $url = $this->baseUrl . $path . self::buildQuery($query);

        $payload = null;
        if ($body !== null) {
            $payload = self::encodeJson($body);
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        } elseif ($method === 'POST') {
            $payload = '';
        }

        $all = array(
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/json',
        );
        foreach ($this->extraHeaders as $k => $v) {
            $all[(string) $k] = (string) $v;
        }
        foreach ($headers as $k => $v) {
            $all[(string) $k] = (string) $v;
        }
        foreach ($all as $k => $v) {
            if (preg_match('/[\r\n]/', $k . $v)) {
                throw new SmsGatewayHubException('Header "' . $this->redact($k) . '" contains a line break', 0, 'config_error');
            }
        }

        $attempt = 0;
        while (true) {
            $res = $this->useCurl
                ? $this->sendCurl($method, $url, $all, $payload)
                : $this->sendStream($method, $url, $all, $payload);

            if (!$res['ok']) {
                $canRetry = $attempt < $this->maxRetries
                    && ($retryMode === self::RETRY_ALL || $res['connectFailure']);
                if ($canRetry) {
                    self::pause(self::backoff($attempt));
                    $attempt++;
                    continue;
                }
                $code = $res['timeout'] ? 'timeout' : 'network_error';
                $msg = ($res['timeout'] ? 'Request timed out' : 'Network error') . ': ' . $res['error'];
                throw $this->makeError($msg, 0, $code, null, '');
            }

            $status = $res['status'];
            $retryStatus = ($retryMode === self::RETRY_ALL && ($status === 429 || $status >= 500))
                || ($retryMode === self::RETRY_CONNECT_OR_429 && $status === 429);
            if ($retryStatus && $attempt < $this->maxRetries) {
                $wait = self::backoff($attempt);
                if ($status === 429 && isset($res['headers']['retry-after'])) {
                    $ra = trim($res['headers']['retry-after']);
                    if ($ra !== '' && is_numeric($ra)) {
                        $wait = min(max((float) $ra, 0.0), 10.0);
                    }
                }
                self::pause($wait);
                $attempt++;
                continue;
            }

            return array(
                'status' => $status,
                'headers' => $res['headers'],
                'body' => $res['body'],
            );
        }
    }

    /**
     * Builds an exception with every known key removed from message and raw text.
     *
     * @param string      $message
     * @param int         $status
     * @param string      $code
     * @param string|null $channel
     * @param string      $raw
     * @return SmsGatewayHubException
     */
    public function makeError($message, $status, $code, $channel, $raw)
    {
        return new SmsGatewayHubException(
            $this->redact((string) $message),
            $status,
            $this->redact((string) $code),
            $channel,
            $this->redact((string) $raw)
        );
    }

    /**
     * @param int        $status
     * @param string     $text
     * @param mixed|null $decoded
     * @return SmsGatewayHubException
     */
    private function httpError($status, $text, $decoded)
    {
        $code = 'http_' . $status;
        $message = 'HTTP ' . $status;
        $channel = null;
        if (is_array($decoded) && isset($decoded['error'])) {
            $err = $decoded['error'];
            if (is_array($err)) {
                if (isset($err['code']) && is_scalar($err['code']) && (string) $err['code'] !== '') {
                    $code = (string) $err['code'];
                }
                if (isset($err['message']) && is_scalar($err['message']) && (string) $err['message'] !== '') {
                    $message = (string) $err['message'];
                }
                if (isset($err['channel']) && is_scalar($err['channel'])) {
                    $channel = (string) $err['channel'];
                }
            } elseif (is_scalar($err) && (string) $err !== '') {
                $message = (string) $err;
            }
        } elseif (is_array($decoded) && isset($decoded['ErrorMessage']) && is_scalar($decoded['ErrorMessage'])) {
            $message = (string) $decoded['ErrorMessage'];
        }
        return $this->makeError($message, $status, $code, $channel, $text);
    }

    /**
     * @param string $text
     * @return string
     */
    public function redact($text)
    {
        foreach ($this->secrets as $s) {
            $text = str_replace(array($s, rawurlencode($s), urlencode($s)), '***', $text);
        }
        return $text;
    }

    /**
     * @param array $query
     * @return string "" or "?a=1&b=2" (RFC 3986 encoding, UTF-8)
     */
    public static function buildQuery(array $query)
    {
        $parts = array();
        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return count($parts) ? '?' . implode('&', $parts) : '';
    }

    /**
     * @param mixed $body
     * @return string
     */
    private static function encodeJson($body)
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new SmsGatewayHubException('Request body could not be encoded as JSON: ' . json_last_error_msg() . ' (strings must be UTF-8)', 0, 'config_error');
        }
        return $json;
    }

    /**
     * @param int $attempt 0-based
     * @return float seconds (0.5, 1, 2, ... capped at 10)
     */
    private static function backoff($attempt)
    {
        return min(0.5 * pow(2, $attempt), 10.0);
    }

    /**
     * @param float $seconds
     */
    private static function pause($seconds)
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    /**
     * @param string               $method
     * @param string               $url
     * @param array<string,string> $headers
     * @param string|null          $payload
     * @return array
     */
    private function sendCurl($method, $url, array $headers, $payload)
    {
        $lines = array();
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $lines[] = 'Expect:';

        $respHeaders = array();
        $timeoutMs = (int) max(1, ceil($this->timeout * 1000));

        $ch = curl_init();
        $opts = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$respHeaders) {
                $len = strlen($line);
                $trimmed = trim($line);
                if (stripos($trimmed, 'HTTP/') === 0) {
                    $respHeaders = array();
                } elseif (($p = strpos($trimmed, ':')) !== false) {
                    $respHeaders[strtolower(trim(substr($trimmed, 0, $p)))] = trim(substr($trimmed, $p + 1));
                }
                return $len;
            },
        );
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = ($payload === null) ? '' : $payload;
        } elseif ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($payload !== null) {
                $opts[CURLOPT_POSTFIELDS] = $payload;
            }
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $connectTime = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
            curl_close($ch);
            $isTimeout = ($errno === 28);
            // 5 proxy resolve, 6 host resolve, 7 connect failed, 35 TLS handshake: request never sent.
            $connectFailure = in_array($errno, array(5, 6, 7, 35), true) || ($isTimeout && $connectTime <= 0.0);
            return array(
                'ok' => false,
                'timeout' => $isTimeout,
                'connectFailure' => $connectFailure,
                'error' => $error !== '' ? $error : ('curl error ' . $errno),
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'ok' => true,
            'status' => $status,
            'headers' => $respHeaders,
            'body' => (string) $body,
        );
    }

    /**
     * Fallback when ext-curl is not available.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string,string> $headers
     * @param string|null          $payload
     * @return array
     */
    private function sendStream($method, $url, array $headers, $payload)
    {
        $lines = array();
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $lines[] = 'Connection: close';
        if ($payload !== null) {
            $lines[] = 'Content-Length: ' . strlen($payload);
        }

        $http = array(
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        );
        if ($payload !== null) {
            $http['content'] = $payload;
        }
        $context = stream_context_create(array('http' => $http));

        $lastError = '';
        set_error_handler(function ($errno, $errstr) use (&$lastError) {
            $lastError = $errstr;
            return true;
        });
        try {
            $fp = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($fp === false) {
            $msg = $lastError !== '' ? $lastError : 'could not connect';
            $isTimeout = (stripos($msg, 'timed out') !== false);
            $connectFailure = !$isTimeout && (bool) preg_match(
                '/refused|getaddrinfo|php_network_getaddresses|unable to connect|no such host|could not resolve|unreachable|name or service not known/i',
                $msg
            );
            return array(
                'ok' => false,
                'timeout' => $isTimeout,
                'connectFailure' => $connectFailure,
                'error' => $msg,
            );
        }

        $meta = stream_get_meta_data($fp);
        $lastError = '';
        set_error_handler(function ($errno, $errstr) use (&$lastError) {
            $lastError = $errstr;
            return true;
        });
        try {
            $body = stream_get_contents($fp);
        } finally {
            restore_error_handler();
        }
        $after = stream_get_meta_data($fp);
        fclose($fp);

        if (!empty($after['timed_out'])) {
            return array('ok' => false, 'timeout' => true, 'connectFailure' => false, 'error' => 'read timed out');
        }
        if ($body === false) {
            return array('ok' => false, 'timeout' => false, 'connectFailure' => false, 'error' => $lastError !== '' ? $lastError : 'read failed');
        }

        $status = 0;
        $respHeaders = array();
        $wrapper = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
        foreach ($wrapper as $line) {
            $line = trim((string) $line);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                $status = (int) $m[1];
                $respHeaders = array();
            } elseif (($p = strpos($line, ':')) !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
        }
        if ($status === 0) {
            return array('ok' => false, 'timeout' => false, 'connectFailure' => false, 'error' => 'no HTTP status line in response');
        }

        return array(
            'ok' => true,
            'status' => $status,
            'headers' => $respHeaders,
            'body' => (string) $body,
        );
    }
}
