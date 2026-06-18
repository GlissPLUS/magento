<?php
/**
 * Glissplus_Worldline - Hosted Checkout payment method.
 *
 * Offsite (redirect) payment method: the customer is sent to the Worldline
 * Hosted Checkout page. The order is created in "pending_payment" and the final
 * state is driven by the webhook (source of truth), with the customer return as
 * a best-effort fallback.
 */
class Glissplus_Worldline_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
    const CODE = 'worldline';

    /** @var string */
    protected $_code = self::CODE;

    /** @var bool Order state is set in initialize() (redirect method). */
    protected $_isInitializeNeeded      = true;
    protected $_canUseInternal          = false;
    protected $_canUseForMultishipping  = false;
    protected $_canUseCheckout          = true;

    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = true;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid                 = true;

    protected $_formBlockType = 'worldline/form';
    protected $_infoBlockType = 'payment/info';

    /**
     * @return Glissplus_Worldline_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('worldline');
    }

    /**
     * @param null|int|string $store
     * @return Glissplus_Worldline_Model_Api_Client
     */
    protected function _client($store = null)
    {
        return Mage::getModel('worldline/api_client', array('store' => $store));
    }

    /**
     * Redirect method: send the buyer to our redirect controller after the
     * order is placed.
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('worldline/payment/redirect', array('_secure' => true));
    }

    /**
     * Set the initial order state when the order is placed.
     *
     * @param string $paymentAction
     * @param Varien_Object $stateObject
     * @return $this
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state  = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $status = $this->getConfigData('order_status');
        if (!$status) {
            $status = Mage::getSingleton('sales/order_config')->getStateDefaultStatus($state);
        }
        $stateObject->setState($state);
        $stateObject->setStatus($status);
        $stateObject->setIsNotified(false);
        return $this;
    }

    /**
     * Create the Hosted Checkout session for an order and return the URL the
     * buyer must be redirected to.
     *
     * @param Mage_Sales_Model_Order $order
     * @return string redirectUrl
     * @throws Mage_Core_Exception
     */
    public function startHostedCheckout(Mage_Sales_Model_Order $order)
    {
        $store  = $order->getStoreId();
        $helper = $this->_helper();
        $body   = $this->_buildHostedCheckoutRequest($order);

        $response = $this->_client($store)->createHostedCheckout($body);

        if (empty($response['redirectUrl']) || empty($response['hostedCheckoutId'])) {
            Mage::throwException($helper->__('Worldline did not return a redirect URL.'));
        }

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('hosted_checkout_id', $response['hostedCheckoutId']);
        if (!empty($response['RETURNMAC'])) {
            $payment->setAdditionalInformation('returnmac', $response['RETURNMAC']);
        }
        $order->addStatusHistoryComment(
            $helper->__('Worldline Hosted Checkout created (id: %s).', $response['hostedCheckoutId'])
        );
        $order->save();

        return $response['redirectUrl'];
    }

    /**
     * Build the createHostedCheckout request body from the order.
     *
     * @param Mage_Sales_Model_Order $order
     * @return array
     */
    protected function _buildHostedCheckoutRequest(Mage_Sales_Model_Order $order)
    {
        $store    = $order->getStoreId();
        $helper   = $this->_helper();
        $currency = $order->getOrderCurrencyCode();
        // Worldline expects the amount in the currency's minor units (cents).
        $amount   = (int) round($order->getGrandTotal() * 100);

        $returnUrl = Mage::getUrl('worldline/payment/return', array('_secure' => true))
            . '?order_id=' . $order->getId();

        // directSale (authorize+capture) vs finalAuthorization (authorize only).
        $captureMode = $this->getConfigData('payment_action') === self::ACTION_AUTHORIZE
            ? 'FINAL_AUTHORIZATION'
            : 'SALE';

        $body = array(
            'hostedCheckoutSpecificInput' => array(
                'returnUrl'      => $returnUrl,
                'showResultPage' => false,
                'locale'         => str_replace('_', '-', Mage::app()->getLocale()->getLocaleCode()),
            ),
            'order' => array(
                'amountOfMoney' => array(
                    'currencyCode' => $currency,
                    'amount'       => $amount,
                ),
                'references' => array(
                    'merchantReference' => $order->getIncrementId(),
                ),
            ),
            'cardPaymentMethodSpecificInput' => array(
                'authorizationMode' => $captureMode,
            ),
        );

        // Branding: inject the CAWL portal template name when configured.
        $variant = trim((string) $this->getConfigData('hosted_variant'));
        if ($variant !== '') {
            $body['hostedCheckoutSpecificInput']['variant'] = $variant;
        }

        // Billing customer details (best-effort).
        $billing = $order->getBillingAddress();
        if ($billing) {
            $body['order']['customer'] = array(
                'contactDetails'    => array(
                    'emailAddress' => $order->getCustomerEmail(),
                ),
                'billingAddress'    => array(
                    'countryCode' => $billing->getCountryId(),
                    'zip'         => $billing->getPostcode(),
                    'city'        => $billing->getCity(),
                    'street'      => $billing->getStreetFull(),
                ),
            );
        }

        $helper->log(array('hosted_checkout_request' => $body));
        return $body;
    }

    /**
     * Apply a Worldline payment result to the order: move it to the right state
     * and create the invoice when captured/paid. Shared by the return and
     * webhook flows.
     *
     * @param Mage_Sales_Model_Order $order
     * @param array $payment Worldline "payment" object (with status + paymentOutput)
     * @return string Resolved bucket: paid|authorized|pending|rejected|cancelled|unknown
     */
    public function applyPaymentResult(Mage_Sales_Model_Order $order, array $payment)
    {
        $helper  = $this->_helper();
        $status  = isset($payment['status']) ? $payment['status'] : '';
        $bucket  = $this->_mapStatus($status);
        $payId   = isset($payment['id']) ? $payment['id'] : '';

        $orderPayment = $order->getPayment();
        if ($payId) {
            $orderPayment->setAdditionalInformation('worldline_payment_id', $payId);
            $orderPayment->setLastTransId($payId);
        }
        $orderPayment->setAdditionalInformation('worldline_status', $status);

        switch ($bucket) {
            case 'paid':
                $this->_registerCapture($order, $payment, $payId, $status);
                break;

            case 'authorized':
                if ($order->getState() !== Mage_Sales_Model_Order::STATE_PROCESSING) {
                    $order->setState(
                        Mage_Sales_Model_Order::STATE_PROCESSING,
                        true,
                        $helper->__('Worldline payment authorized (status: %s).', $status)
                    );
                }
                break;

            case 'rejected':
                if (!$order->isCanceled()) {
                    $order->registerCancellation($helper->__('Worldline payment rejected (status: %s).', $status));
                }
                break;

            case 'cancelled':
                if (!$order->isCanceled()) {
                    $order->registerCancellation($helper->__('Worldline payment cancelled (status: %s).', $status));
                }
                break;

            case 'pending':
            default:
                $order->addStatusHistoryComment(
                    $helper->__('Worldline payment pending (status: %s).', $status)
                );
                break;
        }

        $order->save();
        return $bucket;
    }

    /**
     * Create an invoice for a captured/paid Worldline payment (idempotent).
     *
     * @param Mage_Sales_Model_Order $order
     * @param array  $payment
     * @param string $payId
     * @param string $status
     * @return void
     */
    protected function _registerCapture(Mage_Sales_Model_Order $order, array $payment, $payId, $status)
    {
        $helper = $this->_helper();

        if ($order->hasInvoices() || !$order->canInvoice()) {
            // Already invoiced (e.g. duplicate webhook) - just ensure processing.
            if (!$order->isCanceled() && $order->getState() !== Mage_Sales_Model_Order::STATE_COMPLETE) {
                $order->setState(
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    true,
                    $helper->__('Worldline payment confirmed (status: %s).', $status)
                );
            }
            return;
        }

        $invoice = $order->prepareInvoice();
        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
        if ($payId) {
            $invoice->setTransactionId($payId);
        }
        $invoice->register();

        $transaction = Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder());
        $transaction->save();

        $order->addStatusHistoryComment(
            $helper->__('Worldline payment captured (status: %s, invoice: %s).', $status, $invoice->getIncrementId())
        );
    }

    /**
     * Map a Worldline payment status string to a coarse processing bucket.
     *
     * @param string $status
     * @return string
     */
    protected function _mapStatus($status)
    {
        switch (strtoupper((string) $status)) {
            case 'CAPTURED':
            case 'PAID':
            case 'CAPTURE_REQUESTED':
                return 'paid';

            case 'AUTHORIZATION_REQUESTED':
            case 'PENDING_CAPTURE':
            case 'ACCOUNT_VERIFIED':
                return 'authorized';

            case 'REJECTED':
            case 'REJECTED_CAPTURE':
                return 'rejected';

            case 'CANCELLED':
                return 'cancelled';

            case 'CREATED':
            case 'PENDING_PAYMENT':
            case 'PENDING_FRAUD_APPROVAL':
            case 'PENDING_APPROVAL':
            case 'PENDING_COMPLETION':
            case 'REDIRECTED':
                return 'pending';

            default:
                return 'unknown';
        }
    }

    /**
     * Capture from the admin (invoice creation in authorize-only mode).
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this
     */
    public function capture(Varien_Object $payment, $amount)
    {
        parent::capture($payment, $amount);

        $payId = $payment->getAdditionalInformation('worldline_payment_id');
        if (!$payId) {
            $payId = $payment->getLastTransId();
        }
        if (!$payId) {
            // Nothing captured online yet (offline invoice / already paid via webhook).
            return $this;
        }

        // Already captured online (sale mode) - do not capture twice.
        $status = (string) $payment->getAdditionalInformation('worldline_status');
        if (in_array(strtoupper($status), array('CAPTURED', 'PAID', 'CAPTURE_REQUESTED'), true)) {
            return $this;
        }

        $order    = $payment->getOrder();
        $currency = $order->getOrderCurrencyCode();
        $body = array(
            'amount'             => (int) round($amount * 100),
            'isFinal'            => true,
        );
        $response = $this->_client($order->getStoreId())->capturePayment($payId, $body);
        $newStatus = isset($response['status']) ? $response['status'] : '';
        $payment->setAdditionalInformation('worldline_status', $newStatus);
        $payment->setTransactionId($payId)->setIsTransactionClosed(1);
        $this->_helper()->log(array('capture_response' => $response));
        return $this;
    }

    /**
     * Refund from the admin (credit memo).
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this
     */
    public function refund(Varien_Object $payment, $amount)
    {
        parent::refund($payment, $amount);

        $payId = $payment->getAdditionalInformation('worldline_payment_id');
        if (!$payId) {
            $payId = $payment->getLastTransId();
        }
        if (!$payId) {
            Mage::throwException($this->_helper()->__('No Worldline payment id available for refund.'));
        }

        $order    = $payment->getOrder();
        $currency = $order->getOrderCurrencyCode();
        $body = array(
            'amountOfMoney' => array(
                'currencyCode' => $currency,
                'amount'       => (int) round($amount * 100),
            ),
        );
        $response = $this->_client($order->getStoreId())->refundPayment($payId, $body);
        $this->_helper()->log(array('refund_response' => $response));
        return $this;
    }

    /**
     * Void / cancel an authorisation from the admin.
     *
     * @param Varien_Object $payment
     * @return $this
     */
    public function void(Varien_Object $payment)
    {
        parent::void($payment);
        $this->_cancelOnline($payment);
        return $this;
    }

    /**
     * @param Varien_Object $payment
     * @return $this
     */
    public function cancel(Varien_Object $payment)
    {
        $this->_cancelOnline($payment);
        return $this;
    }

    /**
     * @param Varien_Object $payment
     * @return void
     */
    protected function _cancelOnline(Varien_Object $payment)
    {
        $payId = $payment->getAdditionalInformation('worldline_payment_id');
        if (!$payId) {
            return;
        }
        $status = strtoupper((string) $payment->getAdditionalInformation('worldline_status'));
        // Only authorisations that have not been captured can be cancelled.
        if (!in_array($status, array('AUTHORIZATION_REQUESTED', 'PENDING_CAPTURE', 'ACCOUNT_VERIFIED'), true)) {
            return;
        }
        try {
            $response = $this->_client($payment->getOrder()->getStoreId())->cancelPayment($payId);
            $this->_helper()->log(array('cancel_response' => $response));
        } catch (Exception $e) {
            $this->_helper()->log('cancel error: ' . $e->getMessage());
        }
    }
}
