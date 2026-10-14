<?php

namespace SmsGatewayHub;

/**
 * SMSGatewayHub API client. Server-side use only - never ship your keys in apps or browsers.
 *
 * Groups are reached through accessor methods (created lazily, one instance per client):
 *
 *     $client->sms()          classic SMS API (needs apiKey)
 *     $client->messages()     CPaaS unified messages (needs cpaasKey)
 *     $client->verifyLink()   OneTap Verify (needs cpaasKey)
 *     $client->emailVerify()  Email Verification (needs cpaasKey)
 *
 * Options (all optional; environment variables are used when an option is not given):
 *   apiKey      classic SMS API key (Panel > API)                 env SGH_API_KEY
 *   cpaasKey    CPaaS key "sghcp_..." (Panel > CPaaS > API)        env SGH_CPAAS_KEY
 *   baseUrl     default https://www.smsgatewayhub.com              env SGH_BASE_URL
 *   timeout     seconds, default 30
 *   maxRetries  default 2
 *   transport   "auto" (default: curl when available), "curl" or "stream"
 */
final class Client
{
    const VERSION = '1.0.0';
    const DEFAULT_BASE_URL = 'https://www.smsgatewayhub.com';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $cpaasKey;

    /** @var string */
    private $baseUrl;

    /** @var HttpTransport */
    private $http;

    /** @var Sms|null */
    private $smsGroup;

    /** @var Messages|null */
    private $messagesGroup;

    /** @var VerifyLink|null */
    private $verifyLinkGroup;

    /** @var EmailVerify|null */
    private $emailVerifyGroup;

    /**
     * @param array $options see class doc
     */
    public function __construct(array $options = array())
    {
        $this->apiKey = (string) self::option($options, 'apiKey', 'SGH_API_KEY', '');
        $this->cpaasKey = (string) self::option($options, 'cpaasKey', 'SGH_CPAAS_KEY', '');
        $base = (string) self::option($options, 'baseUrl', 'SGH_BASE_URL', self::DEFAULT_BASE_URL);
        $this->baseUrl = rtrim(trim($base), '/');
        if (!preg_match('#^https?://#i', $this->baseUrl)) {
            throw new SmsGatewayHubException('baseUrl must start with http:// or https://', 0, 'config_error');
        }

        $timeout = 30.0;
        if (isset($options['timeout']) && is_numeric($options['timeout']) && (float) $options['timeout'] > 0) {
            $timeout = (float) $options['timeout'];
        }
        $maxRetries = 2;
        if (isset($options['maxRetries']) && is_numeric($options['maxRetries'])) {
            $maxRetries = max(0, (int) $options['maxRetries']);
        }
        $transport = isset($options['transport']) ? (string) $options['transport'] : 'auto';

        $this->http = new HttpTransport(
            $this->baseUrl,
            $timeout,
            $maxRetries,
            array($this->apiKey, $this->cpaasKey),
            $transport
        );
    }

    /** @return Sms classic SMS API group */
    public function sms()
    {
        if ($this->smsGroup === null) {
            $this->smsGroup = new Sms($this->http, $this->apiKey);
        }
        return $this->smsGroup;
    }

    /** @return Messages CPaaS unified messages group */
    public function messages()
    {
        if ($this->messagesGroup === null) {
            $this->messagesGroup = new Messages($this->http, $this->cpaasKey);
        }
        return $this->messagesGroup;
    }

    /** @return VerifyLink OneTap Verify group */
    public function verifyLink()
    {
        if ($this->verifyLinkGroup === null) {
            $this->verifyLinkGroup = new VerifyLink($this->http, $this->cpaasKey);
        }
        return $this->verifyLinkGroup;
    }

    /** @return EmailVerify Email Verification group */
    public function emailVerify()
    {
        if ($this->emailVerifyGroup === null) {
            $this->emailVerifyGroup = new EmailVerify($this->http, $this->cpaasKey);
        }
        return $this->emailVerifyGroup;
    }

    /** @return string base URL without trailing slash */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * @internal used by the test-suite
     * @return HttpTransport
     */
    public function getTransport()
    {
        return $this->http;
    }

    /**
     * Returns the OneTap Verify token (the "vl_token" query value) from your full redirect URL, or null.
     *
     * @param string $url e.g. "https://example.com/verified?vl_token=abc&state=xyz"
     * @return string|null
     */
    public static function parseRedirect($url)
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            $pos = strpos($url, '?');
            if ($pos === false) {
                return null;
            }
            $query = substr($url, $pos + 1);
            $hash = strpos($query, '#');
            if ($hash !== false) {
                $query = substr($query, 0, $hash);
            }
        }
        $out = array();
        parse_str($query, $out);
        if (isset($out['vl_token']) && is_string($out['vl_token']) && $out['vl_token'] !== '') {
            return $out['vl_token'];
        }
        return null;
    }

    /**
     * Alias of parseRedirect().
     *
     * @param string $url
     * @return string|null
     */
    public static function getVerifyToken($url)
    {
        return self::parseRedirect($url);
    }

    /**
     * @param array  $options
     * @param string $name
     * @param string $env
     * @param string $default
     * @return string
     */
    private static function option(array $options, $name, $env, $default)
    {
        if (isset($options[$name]) && (string) $options[$name] !== '') {
            return (string) $options[$name];
        }
        $value = getenv($env);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
        return $default;
    }
}
