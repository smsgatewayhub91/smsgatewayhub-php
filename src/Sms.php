<?php

namespace SmsGatewayHub;

/**
 * Classic SMS API (GET requests, APIKey query parameter). Obtain via $client->sms().
 */
final class Sms
{
    const MAX_NUMBERS = 100;

    /** @var HttpTransport */
    private $http;

    /** @var string */
    private $apiKey;

    /**
     * @internal use $client->sms()
     * @param HttpTransport $http
     * @param string        $apiKey
     */
    public function __construct(HttpTransport $http, $apiKey)
    {
        $this->http = $http;
        $this->apiKey = (string) $apiKey;
    }

    /**
     * Sends an SMS.
     *
     * @param array $params {
     *   senderId: string (required),
     *   to: string|string[] (required, max 100 numbers),
     *   text: string (required),
     *   channel?: int|string  1 | 2 | "OTP" | "promotional" | "transactional" | "otp" (default 2),
     *   route?: int|string    default 1,
     *   unicode?: bool        default: auto-detect (true when the text has a non-ASCII character),
     *   flash?: bool          default false,
     *   scheduleAt?: string|\DateTimeInterface  string passed as-is, dates formatted "Y/m/d h:i:s A",
     *   groupId?: string, entityId?: string, dltTemplateId?: string
     * }
     * @return array{jobId: string|null, messages: array, raw: array}
     */
    public function send(array $params)
    {
        $this->requireKey();

        $senderId = isset($params['senderId']) ? trim((string) $params['senderId']) : '';
        if ($senderId === '') {
            throw self::config('senderId is required');
        }

        $to = isset($params['to']) ? $params['to'] : null;
        if (is_array($to)) {
            $raw = $to;
        } elseif ($to === null) {
            $raw = array();
        } else {
            $raw = explode(',', (string) $to);
        }
        $numbers = array();
        foreach ($raw as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $numbers[] = $n;
            }
        }
        if (count($numbers) === 0) {
            throw self::config('to is required');
        }
        if (count($numbers) > self::MAX_NUMBERS) {
            throw self::config('Max 100 numbers per request');
        }

        $text = isset($params['text']) ? (string) $params['text'] : '';
        if ($text === '') {
            throw self::config('text is required');
        }

        $channel = self::normalizeChannel(array_key_exists('channel', $params) && $params['channel'] !== null ? $params['channel'] : 2);
        $route = (isset($params['route']) && (string) $params['route'] !== '') ? (string) $params['route'] : '1';

        if (array_key_exists('unicode', $params) && $params['unicode'] !== null) {
            $unicode = (bool) $params['unicode'];
        } else {
            $unicode = (preg_match('/[\x80-\xFF]/', $text) === 1);
        }
        $flash = !empty($params['flash']);

        $query = array(
            'APIKey' => $this->apiKey,
            'senderid' => $senderId,
            'channel' => $channel,
            'DCS' => $unicode ? '8' : '0',
            'flashsms' => $flash ? '1' : '0',
            'number' => implode(',', $numbers),
            'text' => $text,
            'route' => $route,
        );

        if (isset($params['scheduleAt']) && $params['scheduleAt'] !== '') {
            $at = $params['scheduleAt'];
            $query['schedtime'] = ($at instanceof \DateTimeInterface) ? $at->format('Y/m/d h:i:s A') : (string) $at;
        }
        if (isset($params['groupId']) && (string) $params['groupId'] !== '') {
            $query['groupid'] = (string) $params['groupId'];
        }
        if (isset($params['entityId']) && (string) $params['entityId'] !== '') {
            $query['EntityId'] = (string) $params['entityId'];
        }
        if (isset($params['dltTemplateId']) && (string) $params['dltTemplateId'] !== '') {
            $query['dlttemplateid'] = (string) $params['dltTemplateId'];
        }

        // No idempotency on SendSMS: retried only on connect failure or HTTP 429, never after a timeout or 5xx.
        $data = $this->call('/api/mt/SendSMS', $query, HttpTransport::RETRY_CONNECT_OR_429);

        $messages = array();
        if (isset($data['MessageData']) && is_array($data['MessageData'])) {
            foreach ($data['MessageData'] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $messages[] = array(
                    'number' => isset($m['Number']) ? (string) $m['Number'] : null,
                    'messageId' => isset($m['MessageId']) ? (string) $m['MessageId'] : null,
                );
            }
        }

        return array(
            'jobId' => (isset($data['JobId']) && is_scalar($data['JobId'])) ? (string) $data['JobId'] : null,
            'messages' => $messages,
            'raw' => $data,
        );
    }

    /**
     * Account balance, e.g. ['balances' => ['Promo' => 9988, 'Trans' => 0], 'raw' => [...]].
     *
     * @return array{balances: array, raw: array}
     */
    public function balance()
    {
        $this->requireKey();
        $data = $this->call('/api/mt/GetBalance', array('APIKey' => $this->apiKey));

        $balances = array();
        if (isset($data['Balance']) && is_scalar($data['Balance'])) {
            foreach (explode('|', (string) $data['Balance']) as $part) {
                $pos = strpos($part, ':');
                if ($pos === false) {
                    continue;
                }
                $name = trim(substr($part, 0, $pos));
                $value = trim(substr($part, $pos + 1));
                if ($name === '') {
                    continue;
                }
                $balances[$name] = is_numeric($value) ? $value + 0 : $value;
            }
        }

        return array('balances' => $balances, 'raw' => $data);
    }

    /**
     * Delivery reports for a job.
     *
     * @param string $jobId
     * @return array{reports: array, raw: array}
     */
    public function delivery($jobId)
    {
        $this->requireKey();
        $jobId = trim((string) $jobId);
        if ($jobId === '') {
            throw self::config('jobId is required');
        }
        $data = $this->call('/api/mt/GetDelivery', array('APIKey' => $this->apiKey, 'jobid' => $jobId));

        $reports = array();
        if (isset($data['DeliveryReports']) && is_array($data['DeliveryReports'])) {
            foreach ($data['DeliveryReports'] as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $reports[] = array(
                    'messageId' => isset($r['MessageId']) ? (string) $r['MessageId'] : null,
                    'status' => isset($r['DeliveryStatus']) ? (string) $r['DeliveryStatus'] : null,
                    'deliveredAt' => isset($r['DeliveryDate']) ? (string) $r['DeliveryDate'] : null,
                );
            }
        }

        return array('reports' => $reports, 'raw' => $data);
    }

    /**
     * @param string $path
     * @param array  $query
     * @param string $retryMode
     * @return array
     */
    private function call($path, array $query, $retryMode = HttpTransport::RETRY_ALL)
    {
        list($data, $text) = $this->http->requestJson('GET', $path, $query, null, array(), $retryMode);

        if (array_key_exists('ErrorCode', $data) && $data['ErrorCode'] !== null && is_scalar($data['ErrorCode'])) {
            $ec = trim((string) $data['ErrorCode']);
            if ($ec !== '000' && $ec !== '0') {
                $msg = (isset($data['ErrorMessage']) && is_scalar($data['ErrorMessage']) && (string) $data['ErrorMessage'] !== '')
                    ? (string) $data['ErrorMessage']
                    : 'SMS API error ' . $ec;
                throw $this->http->makeError($msg, 200, $ec, null, $text);
            }
        }
        return $data;
    }

    /**
     * @param mixed $channel
     * @return string
     */
    private static function normalizeChannel($channel)
    {
        $c = strtolower(trim((string) $channel));
        if ($c === '1' || $c === 'promotional') {
            return '1';
        }
        if ($c === '2' || $c === 'transactional') {
            return '2';
        }
        if ($c === 'otp') {
            return 'OTP';
        }
        throw self::config('channel must be 1 (promotional), 2 (transactional) or "OTP"');
    }

    private function requireKey()
    {
        if ($this->apiKey === '') {
            throw self::config('API key missing: pass the "apiKey" option or set SGH_API_KEY (find it in Panel > API)');
        }
    }

    /**
     * @param string $message
     * @return SmsGatewayHubException
     */
    private static function config($message)
    {
        return new SmsGatewayHubException($message, 0, 'config_error');
    }
}
