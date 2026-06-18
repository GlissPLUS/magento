<?php
/**
 * Glissplus_Worldline - data helper.
 *
 * Centralises access to the module configuration (with decryption of the
 * encrypted secrets) and provides the API host depending on the environment.
 */
class Glissplus_Worldline_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH = 'payment/worldline/';

    const HOST_TEST = 'payment.preprod.direct.worldline-solutions.com';
    const HOST_LIVE = 'payment.direct.worldline-solutions.com';

    const LOG_FILE = 'worldline.log';

    /**
     * Read a raw config value for the worldline payment group.
     *
     * @param string $key
     * @param null|int|string $store
     * @return string
     */
    public function getConfig($key, $store = null)
    {
        return (string) Mage::getStoreConfig(self::XML_PATH . $key, $store);
    }

    /**
     * Read and decrypt an encrypted config value (api_secret / webhook_secret).
     *
     * @param string $key
     * @param null|int|string $store
     * @return string
     */
    public function getEncrypted($key, $store = null)
    {
        $value = $this->getConfig($key, $store);
        if ($value === '') {
            return '';
        }
        return (string) Mage::helper('core')->decrypt($value);
    }

    /**
     * @param null|int|string $store
     * @return bool
     */
    public function isLive($store = null)
    {
        return $this->getConfig('environment', $store) === 'live';
    }

    /**
     * API host without scheme for the configured environment.
     *
     * @param null|int|string $store
     * @return string
     */
    public function getApiHost($store = null)
    {
        return $this->isLive($store) ? self::HOST_LIVE : self::HOST_TEST;
    }

    /**
     * Base API URL, e.g. https://payment.preprod.direct.worldline-solutions.com
     *
     * @param null|int|string $store
     * @return string
     */
    public function getApiBaseUrl($store = null)
    {
        return 'https://' . $this->getApiHost($store);
    }

    /**
     * @param null|int|string $store
     * @return bool
     */
    public function isDebug($store = null)
    {
        return (bool) $this->getConfig('debug', $store);
    }

    /**
     * Write a debug line to var/log/worldline.log when debug is enabled.
     *
     * @param mixed $data
     * @return void
     */
    public function log($data)
    {
        if (!$this->isDebug()) {
            return;
        }
        if (!is_scalar($data)) {
            $data = print_r($data, true);
        }
        Mage::log($data, Zend_Log::DEBUG, self::LOG_FILE, true);
    }
}
