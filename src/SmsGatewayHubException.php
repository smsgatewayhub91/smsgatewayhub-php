<?php

namespace SmsGatewayHub;

/**
 * The single exception type thrown by the SMSGatewayHub SDK.
 *
 * - getStatus()    HTTP status (0 for network errors, timeouts and configuration errors)
 * - getErrorCode() string code, e.g. "bad_request", "13", "http_502", "network_error", "timeout", "config_error"
 * - getMessage()   human readable message (never contains your keys)
 * - getChannel()   channel reported by the CPaaS API (e.g. "sms"), or null
 * - getRaw()       response body text, clipped to 2000 characters (keys redacted)
 *
 * Note: PHP's built-in Exception::getCode() is an integer and is always 0 here;
 * use getErrorCode() for the string code.
 */
class SmsGatewayHubException extends \RuntimeException
{
    const RAW_LIMIT = 2000;

    /** @var int */
    private $status;

    /** @var string */
    private $errorCode;

    /** @var string|null */
    private $channel;

    /** @var string */
    private $raw;

    /**
     * @param string          $message
     * @param int             $status
     * @param string          $errorCode
     * @param string|null     $channel
     * @param string          $raw
     * @param \Throwable|null $previous
     */
    public function __construct($message, $status = 0, $errorCode = 'error', $channel = null, $raw = '', \Throwable $previous = null)
    {
        parent::__construct((string) $message, 0, $previous);
        $this->status = (int) $status;
        $this->errorCode = (string) $errorCode;
        $this->channel = ($channel === null || $channel === '') ? null : (string) $channel;
        $this->raw = self::clip((string) $raw);
    }

    /** @return int HTTP status, 0 for network/config errors */
    public function getStatus()
    {
        return $this->status;
    }

    /** @return string */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /** @return string|null */
    public function getChannel()
    {
        return $this->channel;
    }

    /** @return string response body text (max 2000 characters) */
    public function getRaw()
    {
        return $this->raw;
    }

    /**
     * @param string $text
     * @return string
     */
    private static function clip($text)
    {
        if (function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > self::RAW_LIMIT) {
                return mb_substr($text, 0, self::RAW_LIMIT, 'UTF-8');
            }
            return $text;
        }
        if (strlen($text) > self::RAW_LIMIT) {
            return substr($text, 0, self::RAW_LIMIT);
        }
        return $text;
    }
}
