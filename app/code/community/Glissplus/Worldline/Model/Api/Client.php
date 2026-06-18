<?php
/**
 * Glissplus_Worldline - Worldline Direct (CAWL) API v2 client.
 *
 * Thin cURL client that signs every request with the GCS v1HMAC scheme:
 *
 *   stringToSign = METHOD          "\n"
 *                + ContentType     "\n"
 *                + Date (RFC1123)  "\n"
 *                + [x-gcs-* headers, canonicalised + sorted, each "name:value\n"]
 *                + path            "\n"
 *
 *   signature  = base64( HMAC-SHA256( apiSecret, stringToSign ) )
 *   header     = Authorization: GCS v1HMAC:{apiKeyId}:{signature}
 *
 * The same Date value used in the signature is sent in the Date header.
 *
 * @link https://docs.direct.worldline-solutions.com/
 */
class Glissplus_Worldline_Model_Api_Client
{
    /** @var Glissplus_Worldline_Helper_Data */
    protected $_helper;

    /** @var null|int|string */
    protected $_store;

    public function __construct($args = array())
    {
        $this->_helper = Mage::helper('worldline');
        $this->_store  = isset($args['store']) ? $args['store'] : null;
    }

    /**
     * @param null|int|string $store
     * @return $this
     */
    public function setStore($store)
    {
        $this->_store = $store;
        return $this;
    }

    /**
     * Create a Hosted Checkout session.
     *
     * @param array $body
     * @return array Decoded response (contains hostedCheckoutId, redirectUrl, ...)
     */
    public function createHostedCheckout(array $body)
    {
        $merchantId = $this->_helper->getConfig('merchant_id', $this->_store);
        return $this->request('POST', '/v2/' . rawurlencode($merchantId) . '/hostedcheckouts', $body);
    }

    /**
     * Retrieve a Hosted Checkout (status, embedded payment result).
     *
     * @param string $hostedCheckoutId
     * @return array
     */
    public function getHostedCheckout($hostedCheckoutId)
    {
        $merchantId = $this->_helper->getConfig('merchant_id', $this->_store);
        return $this->request(
            'GET',
            '/v2/' . rawurlencode($merchantId) . '/hostedcheckouts/' . rawurlencode($hostedCheckoutId)
        );
    }

    /**
     * Retrieve a payment.
     *
     * @param string $paymentId
     * @return array
     */
    public function getPayment($paymentId)
    {
        $merchantId = $this->_helper->getConfig('merchant_id', $this->_store);
        return $this->request(
            'GET',
            '/v2/' . rawurlencode($merchantId) . '/payments/' . rawurlencode($paymentId)
        );
    }

    /**
     * Capture (part of) an authorised payment.
     *
     * @param string $paymentId
     * @param array  $body amountOfMoney etc.
     * @return array
     */
    public function capturePayment($paymentId, array $body)
    {
        $merchantId = $this->_helper->getConfig('merchant_id', $this->_store);
        return $this->request(
            'POST',
            '/v2/' . rawurlencode($merchantId) . '/payments/' . rawurlencode($paymentId) . '/capture',
            $body
        );
    }

    /**
     * Refund (part of) a captured payment.
     *
     * @param string $paymentId
     * @param array  $body amountOfMoney etc.
     * @return array
     */
    public function refundPayment($paymentId, array $body)
    {
        $merchantId = $this->_helper->getConfig('merchant_id', $this->_store);
        return $this->request(
            'POST',
            '/v2/' . rawurlencode($merchantId) . '/payments/' . rawurlencode($paymentId) . '/refund',
            $body
        );
    }

    /**
     * Cancel an authorised payment.
     *
     * @param string $paymentId
     * @return array
     */
    public function cancelPayment($paymentId)
    {
        $merchantId = $this->_helper->getConfig('merchant_id', $this->_store);
        return $this->request(
            'POST',
            '/v2/' . rawurlencode($merchantId) . '/payments/' . rawurlencode($paymentId) . '/cancel'
        );
    }

    /**
     * Perform a signed request and return the decoded JSON body.
     *
     * @param string     $method
     * @param string     $path
     * @param null|array $body
     * @return array
     * @throws Mage_Core_Exception on transport or API error
     */
    public function request($method, $path, $body = null)
    {
        $method      = strtoupper($method);
        $apiKeyId    = $this->_helper->getConfig('api_key_id', $this->_store);
        $apiSecret   = $this->_helper->getEncrypted('api_secret', $this->_store);

        if ($apiKeyId === '' || $apiSecret === '') {
            Mage::throwException($this->_helper->__('Worldline API credentials are not configured.'));
        }

        $contentType = '';
        $payload     = '';
        if ($body !== null) {
            $contentType = 'application/json';
            $payload     = json_encode($body);
        }

        // RFC 1123 date in GMT, e.g. "Mon, 18 Jun 2026 12:00:00 GMT".
        $date = gmdate('D, d M Y H:i:s') . ' GMT';

        $signature = $this->_sign($method, $contentType, $date, $path, $apiSecret);
        $authorization = 'GCS v1HMAC:' . $apiKeyId . ':' . $signature;

        $headers = array(
            'Authorization: ' . $authorization,
            'Date: ' . $date,
        );
        if ($contentType !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $url = $this->_helper->getApiBaseUrl($this->_store) . $path;

        $this->_helper->log(array('request' => $method . ' ' . $url, 'body' => $body));

        list($status, $responseBody) = $this->_send($method, $url, $headers, $payload);

        $this->_helper->log(array('response_status' => $status, 'response_body' => $responseBody));

        $decoded = $responseBody === '' ? array() : json_decode($responseBody, true);

        if ($status < 200 || $status >= 300) {
            $message = $this->_helper->__('Worldline API error (HTTP %s).', $status);
            if (is_array($decoded) && !empty($decoded['errors'][0]['message'])) {
                $message = $this->_helper->__('Worldline API: %s', $decoded['errors'][0]['message']);
            }
            Mage::throwException($message);
        }

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Build the GCS v1HMAC signature. No custom x-gcs-* headers are sent, so the
     * canonicalised-headers block is empty.
     *
     * @param string $method
     * @param string $contentType
     * @param string $date
     * @param string $path
     * @param string $apiSecret
     * @return string base64-encoded HMAC-SHA256
     */
    protected function _sign($method, $contentType, $date, $path, $apiSecret)
    {
        $stringToSign = $method . "\n"
            . $contentType . "\n"
            . $date . "\n"
            . $path . "\n";

        return base64_encode(hash_hmac('sha256', $stringToSign, $apiSecret, true));
    }

    /**
     * Execute the HTTP request with cURL.
     *
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param string $payload
     * @return array [int $statusCode, string $body]
     * @throws Mage_Core_Exception on transport error
     */
    protected function _send($method, $url, array $headers, $payload)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            Mage::throwException($this->_helper->__('Worldline connection error: %s', $error));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array($status, (string) $responseBody);
    }
}
