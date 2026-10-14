<?php

namespace SmsGatewayHub;

/**
 * CPaaS unified messages API (/cpaas/v1/messages.ashx). Obtain via $client->messages().
 */
final class Messages extends CpaasGroup
{
    const PATH = '/cpaas/v1/messages.ashx';

    /**
     * Sends one message across one or more channels with automatic fallback.
     * The body is passed through as JSON (see README for the shape), e.g.
     * ['channels' => ['whatsapp', 'sms'], 'to' => '9198...', 'fallbackAfter' => 300, 'whatsapp' => [...], 'sms' => [...]].
     *
     * An Idempotency-Key is always sent (a random UUID when you give none) and reused on every retry,
     * so retries can never create a duplicate message.
     *
     * @param array|object $body
     * @param array        $options ['idempotencyKey' => string]
     * @return array the full response object (contains 'id' "cp_..." and 'status')
     */
    public function send($body, array $options = array())
    {
        if (!is_array($body) && !is_object($body)) {
            throw self::config('body must be an array or object');
        }
        $key = (isset($options['idempotencyKey']) && (string) $options['idempotencyKey'] !== '')
            ? (string) $options['idempotencyKey']
            : self::uuid4();

        return $this->call('POST', self::PATH, array(), $body, HttpTransport::RETRY_ALL, array('Idempotency-Key' => $key));
    }

    /**
     * Status and event timeline of one message.
     *
     * @param string $id "cp_..."
     * @return array
     */
    public function get($id)
    {
        $id = self::required($id, 'id');
        return $this->call('GET', self::PATH, array('id' => $id), null, HttpTransport::RETRY_ALL);
    }

    /**
     * Lists messages.
     *
     * @param array $filters ['status' => string, 'ref' => string, 'limit' => int]
     * @return array
     */
    public function list(array $filters = array())
    {
        $query = array_merge(
            array('action' => 'list'),
            self::pick($filters, array('status' => 'status', 'ref' => 'ref', 'limit' => 'limit'))
        );
        return $this->call('GET', self::PATH, $query, null, HttpTransport::RETRY_ALL);
    }

    /**
     * @return string random RFC 4122 version 4 UUID
     */
    private static function uuid4()
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
