<?php
/**
 * Glissplus_Worldline - source model for the Environment select.
 */
class Glissplus_Worldline_Model_Source_Environment
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array('value' => 'test', 'label' => Mage::helper('worldline')->__('Test (preprod)')),
            array('value' => 'live', 'label' => Mage::helper('worldline')->__('Live (production)')),
        );
    }
}
