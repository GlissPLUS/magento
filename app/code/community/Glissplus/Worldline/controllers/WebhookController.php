<?php
/**
 * Glissplus_Worldline - webhook endpoint (source of truth for order status).
 *
 * Worldline POSTs payment events here. Authenticity is verified with
 *   X-GCS-Signature = base64( HMAC-SHA256( webhookSecret, rawBody ) )
 * compared in constant time. The matching secret is selected via the
 * X-GCS-KeyId header.
 *
 * Declare this URL in the CAWL portal:
 *   https://<your-domain>/worldline/webhook
 */
class Glissplus_Worldline_WebhookController extends Mage_Core_Controller_Front_Action
{
    /**
     * @return Glissplus_Worldline_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('worldline');
    }

    /**
     * CSRF/form-key validation must not apply to a server-to-server webhook.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return true;
    }

    public function indexAction()
    {
        $rawBody = $this->getRequest()->getRawBody();
        if ($rawBody === false || $rawBody === null) {
            $rawBody = file_get_contents('php://input');
        }
        $rawBody = (string) $rawBody;

        $signature = (string) $this->getRequest()->getHeader('X-GCS-Signature');
        $keyId     = (string) $this->getRequest()->getHeader('X-GCS-KeyId');

        if (!$this->_verifySignature($rawBody, $signature, $keyId)) {
            $this->_helper()->log('webhook signature verification failed');
            $this->getResponse()->setHttpResponseCode(400)->setBody('invalid signature');
            return;
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            $this->getResponse()->setHttpResponseCode(400)->setBody('invalid payload');
            return;
        }

        $this->_helper()->log(array('webhook_event' => $event));

        $payment = isset($event['payment']) ? $event['payment'] : null;
        if (!is_array($payment)) {
            // Acknowledge non-payment events (e.g. test notifications) with 200.
            $this->getResponse()->setHttpResponseCode(200)->setBody('ignored');
            return;
        }

        try {
            $order = $this->_resolveOrder($payment);
            if ($order && $order->getId()
                && $order->getPayment()->getMethod() === Glissplus_Worldline_Model_Standard::CODE
            ) {
                /** @var Glissplus_Worldline_Model_Standard $method */
                $method = $order->getPayment()->getMethodInstance();
                $method->applyPaymentResult($order, $payment);
            } else {
                $this->_helper()->log('webhook: order not found for event');
            }
        } catch (Exception $e) {
            $this->_helper()->log('webhook processing error: ' . $e->getMessage());
            // Ask Worldline to retry later.
            $this->getResponse()->setHttpResponseCode(500)->setBody('error');
            return;
        }

        $this->getResponse()->setHttpResponseCode(200)->setBody('ok');
    }

    /**
     * Verify the webhook signature in constant time.
     *
     * @param string $rawBody
     * @param string $signature base64 from X-GCS-Signature
     * @param string $keyId     X-GCS-KeyId (informational)
     * @return bool
     */
    protected function _verifySignature($rawBody, $signature, $keyId)
    {
        if ($signature === '') {
            return false;
        }

        $secret = $this->_helper()->getEncrypted('webhook_secret');
        if ($secret === '') {
            return false;
        }

        // When a webhook key id is configured, require it to match.
        $configuredKeyId = $this->_helper()->getConfig('webhook_key_id');
        if ($configuredKeyId !== '' && $keyId !== '' && !hash_equals($configuredKeyId, $keyId)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($expected, $signature);
    }

    /**
     * Resolve the Magento order from the webhook payment payload using the
     * merchantReference (= order increment id).
     *
     * @param array $payment
     * @return Mage_Sales_Model_Order|null
     */
    protected function _resolveOrder(array $payment)
    {
        $reference = null;
        if (isset($payment['paymentOutput']['references']['merchantReference'])) {
            $reference = $payment['paymentOutput']['references']['merchantReference'];
        } elseif (isset($payment['references']['merchantReference'])) {
            $reference = $payment['references']['merchantReference'];
        }

        if (!$reference) {
            return null;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($reference);
        return $order->getId() ? $order : null;
    }
}
