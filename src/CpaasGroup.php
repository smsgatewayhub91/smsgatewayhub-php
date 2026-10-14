<?php

namespace SmsGatewayHub;

/**
 * Shared plumbing for the CPaaS groups (Bearer cpaasKey, JSON).
 *
 * @internal
 */
abstract class CpaasGroup
{
    /** @var HttpTransport */
    protected $http;

    /** @var string */
    private $cpaasKey;

    /**
     * @internal use the accessor methods on Client
     * @param HttpTransport $http
     * @param string        $cpaasKey
     */
    public function __construct(HttpTransport $http, $cpaasKey)
    {
        $this->http = $http;
        $this->cpaasKey = (string) $cpaasKey;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array  $query
     * @param mixed  $body
     * @param string $retryMode
     * @param array  $headers
     * @return array decoded JSON response
     */
    protected function call($method, $path, array $query, $body, $retryMode, array $headers = array())
    {
        if ($this->cpaasKey === '') {
            throw self::config('CPaaS key missing: pass the "cpaasKey" option or set SGH_CPAAS_KEY (find it in Panel > CPaaS > API)');
        }
        $headers['Authorization'] = 'Bearer ' . $this->cpaasKey;
        list($data) = $this->http->requestJson($method, $path, $query, $body, $headers, $retryMode);
        return $data;
    }

    /**
     * @param mixed  $value
     * @param string $name
     * @return string
     */
    protected static function required($value, $name)
    {
        $v = is_scalar($value) ? trim((string) $value) : '';
        if ($v === '') {
            throw self::config($name . ' is required');
        }
        return $v;
    }

    /**
     * Copies non-empty values only.
     *
     * @param array $source
     * @param array $map  option name => query name
     * @return array
     */
    protected static function pick(array $source, array $map)
    {
        $out = array();
        foreach ($map as $from => $to) {
            if (!array_key_exists($from, $source)) {
                continue;
            }
            $v = $source[$from];
            if ($v === null || $v === '' || $v === false) {
                continue;
            }
            if ($v === true) {
                $v = '1';
            }
            if (is_scalar($v)) {
                $out[$to] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * @param string $message
     * @return SmsGatewayHubException
     */
    protected static function config($message)
    {
        return new SmsGatewayHubException($message, 0, 'config_error');
    }
}
