<?php

namespace SmsGatewayHub;

/**
 * OneTap Verify - passwordless one-time verification links (/cpaas/v1/verify-link.ashx).
 * Obtain via $client->verifyLink().
 *
 * These POST calls are never retried once the request has been sent (only when the connection could not be opened).
 */
final class VerifyLink extends CpaasGroup
{
    const PATH = '/cpaas/v1/verify-link.ashx';

    /**
     * Creates and sends a OneTap Verify link. "{{link}}" inside the sms/whatsapp/email objects is
     * replaced server-side by the one-time link.
     *
     * @param array|object $body ['to', 'toEmail', 'channels', 'fallbackAfter', 'expiry', 'redirectUrl', 'state', 'ref', 'appName', 'sms', 'whatsapp', 'email']
     * @return array response object (contains 'id' "vl_...")
     */
    public function create($body)
    {
        if (!is_array($body) && !is_object($body)) {
            throw self::config('body must be an array or object');
        }
        return $this->call('POST', self::PATH, array(), $body, HttpTransport::RETRY_CONNECT);
    }

    /**
     * Verifies the token your redirectUrl received as the "vl_token" query parameter (valid once).
     *
     * @param string $token
     * @return array
     */
    public function verify($token)
    {
        $token = self::required($token, 'token');
        return $this->call('POST', self::PATH, array('action' => 'verify'), array('token' => $token), HttpTransport::RETRY_CONNECT);
    }

    /**
     * Status: created / sent / opened / confirmed / verified / expired / failed / cancelled.
     *
     * @param string $id "vl_..."
     * @return array
     */
    public function get($id)
    {
        $id = self::required($id, 'id');
        return $this->call('GET', self::PATH, array('id' => $id), null, HttpTransport::RETRY_ALL);
    }

    /**
     * Cancels a pending link.
     *
     * @param string $id "vl_..."
     * @return array
     */
    public function cancel($id)
    {
        $id = self::required($id, 'id');
        return $this->call('POST', self::PATH, array('action' => 'cancel'), array('id' => $id), HttpTransport::RETRY_CONNECT);
    }
}
