<?php
/**
 * Glissplus_Worldline - frontend payment controller.
 *
 * redirect : create the Hosted Checkout session and send the buyer to Worldline.
 * return   : best-effort handling of the buyer coming back from Worldline.
 * cancel   : buyer aborted on Worldline.
 */
class Glissplus_Worldline_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * @return Glissplus_Worldline_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('worldline');
    }

    /**
     * @return Mage_Checkout_Model_Session
     */
    protected function _checkoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Create the Hosted Checkout session and redirect the buyer.
     */
    public function redirectAction()
    {
        $session = $this->_checkoutSession();
        $orderId = $session->getLastRealOrderId();
        $order   = Mage::getModel('sales/order')->loadByIncrementId($orderId);

        if (!$order->getId() || $order->getPayment()->getMethod() !== Glissplus_Worldline_Model_Standard::CODE) {
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            /** @var Glissplus_Worldline_Model_Standard $method */
            $method = $order->getPayment()->getMethodInstance();
            $redirectUrl = $method->startHostedCheckout($order);
            $this->getResponse()->setRedirect($redirectUrl);
        } catch (Exception $e) {
            $this->_helper()->log('redirect error: ' . $e->getMessage());
            $this->_cancelOrderAndRestoreQuote($order, $this->_helper()->__('Unable to start the Worldline payment.'));
            $this->_getCheckoutSessionError($e->getMessage());
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * Buyer returns from Worldline. The webhook is the source of truth, but we
     * refresh the status here so the buyer immediately lands on the right page.
     */
    public function returnAction()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $order   = Mage::getModel('sales/order')->load($orderId);

        if (!$order->getId() || $order->getPayment()->getMethod() !== Glissplus_Worldline_Model_Standard::CODE) {
            $this->_redirect('checkout/cart');
            return;
        }

        $payment        = $order->getPayment();
        $expectedMac     = (string) $payment->getAdditionalInformation('returnmac');
        $providedMac     = (string) $this->getRequest()->getParam('RETURNMAC');
        if ($expectedMac !== '' && $providedMac !== '' && !hash_equals($expectedMac, $providedMac)) {
            $this->_helper()->log('return RETURNMAC mismatch for order ' . $order->getIncrementId());
            $this->_redirect('checkout/cart');
            return;
        }

        $bucket = 'pending';
        try {
            $hostedId = $payment->getAdditionalInformation('hosted_checkout_id');
            $client   = Mage::getModel('worldline/api_client', array('store' => $order->getStoreId()));
            $hosted   = $client->getHostedCheckout($hostedId);

            $createdPayment = $this->_extractPayment($hosted);
            if ($createdPayment) {
                /** @var Glissplus_Worldline_Model_Standard $method */
                $method = $payment->getMethodInstance();
                $bucket = $method->applyPaymentResult($order, $createdPayment);
            }
        } catch (Exception $e) {
            $this->_helper()->log('return error: ' . $e->getMessage());
        }

        if (in_array($bucket, array('paid', 'authorized'), true)) {
            $this->_redirect('checkout/onepage/success');
            return;
        }

        if (in_array($bucket, array('rejected', 'cancelled'), true)) {
            $this->_cancelOrderAndRestoreQuote($order, $this->_helper()->__('Your Worldline payment was not completed.'));
            $this->_redirect('checkout/cart');
            return;
        }

        // Still pending: show the success page; the webhook will finalise.
        $this->_redirect('checkout/onepage/success');
    }

    /**
     * Buyer aborted the payment on Worldline.
     */
    public function cancelAction()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $order   = Mage::getModel('sales/order')->load($orderId);
        if ($order->getId() && $order->getPayment()->getMethod() === Glissplus_Worldline_Model_Standard::CODE) {
            $this->_cancelOrderAndRestoreQuote($order, $this->_helper()->__('Worldline payment cancelled.'));
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Find the embedded payment object in a hostedcheckouts GET response.
     *
     * @param array $hosted
     * @return array|null
     */
    protected function _extractPayment(array $hosted)
    {
        if (isset($hosted['createdPaymentOutput']['payment'])) {
            return $hosted['createdPaymentOutput']['payment'];
        }
        return null;
    }

    /**
     * Cancel the order and put the cart back so the buyer can retry.
     *
     * @param Mage_Sales_Model_Order $order
     * @param string $comment
     * @return void
     */
    protected function _cancelOrderAndRestoreQuote(Mage_Sales_Model_Order $order, $comment)
    {
        try {
            if ($order->getId() && $order->canCancel()) {
                $order->cancel()->addStatusHistoryComment($comment)->save();
            }
        } catch (Exception $e) {
            $this->_helper()->log('cancel order error: ' . $e->getMessage());
        }
        $this->_checkoutSession()->getQuote()->setIsActive(true)->save();
        $this->_restoreQuote();
    }

    /**
     * Restore the quote of the last order so the buyer keeps their cart.
     *
     * @return void
     */
    protected function _restoreQuote()
    {
        try {
            $this->_checkoutSession()->restoreQuote();
        } catch (Exception $e) {
            $this->_helper()->log('restore quote error: ' . $e->getMessage());
        }
    }

    /**
     * @param string $message
     * @return void
     */
    protected function _getCheckoutSessionError($message)
    {
        $this->_checkoutSession()->addError(
            $this->_helper()->__('Worldline payment error: %s', $message)
        );
    }
}
