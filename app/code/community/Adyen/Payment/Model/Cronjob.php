<?php
/**
 * Adyen Payment Module
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category	Adyen
 * @package	Adyen_Payment
 * @copyright	Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license	http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */

class Adyen_Payment_Model_Cronjob {

    /**
     * Collected debug information
     *
     * @var array
     */
    protected $_debugData = array();

    /**
     * This updates the notifications that are in the adyen event queue. This is called by the cronjob of Magento
     * To enable the cronjob on your webserver see the following magento dcoumentation:
     * http://www.magentocommerce.com/wiki/1_-_installation_and_configuration/how_to_setup_a_cron_job
     */
    public function updateNotificationQueue()
    {
        // call ProcessNotifications
        $this->_debugData = Mage::getModel('adyen/processNotification')->updateNotProcessedNotifications();
        $this->_debug(null);
    }

    /**
     * If ZeroAuth is used and capture is manual this cronjob captures
     * pending invoices based on the field that was used to determine
     * capture should be postponed.
     *
     * @return bool
     */
    public function capturePendingInvoicesByDate()
    {
        $useZeroAuth = (bool)Mage::helper('adyen')->getConfigData('use_zero_auth');
        $zeroAuthDateField = (bool)Mage::helper('adyen')->getConfigData('base_zero_auth_on_date');
        $isManual = (bool)Mage::helper('adyen')->getConfigData('capture_mode');

        if (!$useZeroAuth || $isManual !== 'manual') { // not used
            return;
        }

        $collection = Mage::getResourceModel('sales/order_invoice_collection')
            ->addAttributeToFilter('main_table.state', 1);

        $collection->getSelect()->joinLeft(
            'sales_flat_order',
            'main_table.order_id = sales_flat_order.entity_id',
            ['scheduled_at']
        );

        $collection->addAttributeToFilter('sales_flat_order.' . $zeroAuthDateField, ['lteq' => date('Y-m-d') . ' 23:59:59']);
        $collection->addAttributeToFilter('sales_flat_order.state', ['in' => ['processing','payment_pending','payment_review','pending_payment']]);

        foreach ($collection as $invoice) {
            if ($invoice->canCapture()) {
                try {
                    $invoice->capture();

                    $invoice->getOrder()->setIsInProcess(true);

                    Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->save();
                } catch (\Exception $e) {
                    Mage::log("{$e}", Zend_Log::ERROR, 'adyen_capture_cron.log');
                }
            } else {
                Mage::log("{$invoice->getIncrementId()} up for capture, canCapture returned false", Zend_Log::WARN, 'adyen_capture_cron.log');
            }
        }

        return true;
    }

    /**
     * Log debug data to file
     *
     * @param $storeId
     * @param mixed $debugData
     */
    protected function _debug($storeId)
    {
        if ($this->_getConfigData('debug', 'adyen_abstract', $storeId)) {
            $file = 'adyen_process_notification_cron.log';
            Mage::getModel('core/log_adapter', $file)->log($this->_debugData);
        }
    }

    /**
     * @param $code
     * @param null $paymentMethodCode
     * @param null $storeId
     * @return mixed
     */
    protected function _getConfigData($code, $paymentMethodCode = null, $storeId = null)
    {
        return Mage::helper('adyen')->getConfigData($code, $paymentMethodCode, $storeId);
    }
}