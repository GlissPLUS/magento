<?php
/**
 * Glissplus_Worldline - source model for the Payment Action select.
 */
class Glissplus_Worldline_Model_Source_PaymentAction
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array(
                'value' => Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE,
                'label' => Mage::helper('worldline')->__('Authorize only'),
            ),
            array(
                'value' => Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE,
                'label' => Mage::helper('worldline')->__('Authorize and Capture'),
            ),
        );
    }
}
