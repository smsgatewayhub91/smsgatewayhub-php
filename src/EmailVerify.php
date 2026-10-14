<?php

namespace SmsGatewayHub;

/**
 * Email Verification (/cpaas/v1/email-verify.ashx). Obtain via $client->emailVerify().
 */
final class EmailVerify extends CpaasGroup
{
    const PATH = '/cpaas/v1/email-verify.ashx';
    const MAX_BATCH = 1000;

    /**
     * Verifies one address.
     *
     * @param string $email
     * @return array result object: result (valid|invalid|risky|unknown), reason, suggestion,
     *               disposable, roleBased, freeProvider, hasMx, domain
     */
    public function check($email)
    {
        $email = self::required($email, 'email');
        return $this->call('POST', self::PATH, array(), array('email' => $email), HttpTransport::RETRY_CONNECT);
    }

    /**
     * Starts a batch verification (max 1000 addresses).
     *
     * @param string[] $emails
     * @param array    $options ['name' => string]
     * @return array response object; 'batchId' ("ev_...") is always present when the server returned an id
     */
    public function batch(array $emails, array $options = array())
    {
        $list = array();
        foreach ($emails as $e) {
            if (is_scalar($e) && trim((string) $e) !== '') {
                $list[] = trim((string) $e);
            }
        }
        if (count($list) === 0) {
            throw self::config('emails must contain at least one address');
        }
        if (count($list) > self::MAX_BATCH) {
            throw self::config('Max 1000 emails per batch');
        }
        $body = array('emails' => $list);
        if (isset($options['name']) && (string) $options['name'] !== '') {
            $body['name'] = (string) $options['name'];
        }

        $data = $this->call('POST', self::PATH, array(), $body, HttpTransport::RETRY_CONNECT);
        if (!isset($data['batchId']) && isset($data['id']) && is_scalar($data['id'])) {
            $data['batchId'] = (string) $data['id'];
        }
        return $data;
    }

    /**
     * Progress (and optionally results) of a batch.
     *
     * @param string $batchId "ev_..."
     * @param array  $options ['results' => bool, 'page' => int, 'pageSize' => int, 'filter' => string]
     * @return array
     */
    public function batchStatus($batchId, array $options = array())
    {
        $batchId = self::required($batchId, 'batchId');
        $query = array_merge(
            array('batch' => $batchId),
            self::pick($options, array('results' => 'results', 'page' => 'page', 'pageSize' => 'pageSize', 'filter' => 'filter'))
        );
        return $this->call('GET', self::PATH, $query, null, HttpTransport::RETRY_ALL);
    }
}
