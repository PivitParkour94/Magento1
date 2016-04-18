<?php
/**
*  Shippit Pty Ltd
*
*  NOTICE OF LICENSE
*
*  This source file is subject to the terms
*  that is available through the world-wide-web at this URL:
*  http://www.shippit.com/terms
*
*  @category   Shippit
*  @copyright  Copyright (c) 2016 by Shippit Pty Ltd (http://www.shippit.com)
*  @author     Matthew Muscat <matthew@mamis.com.au>
*  @license    http://www.shippit.com/terms
*/

class Shippit_Shippit_Model_Observer_Order_Sync
{
    // Prevents recursive requests to sync
    private $_hasAttemptedSync = false;

    protected $helper;
    protected $carrierCode;

    public function __construct()
    {
        $this->helper = Mage::helper('shippit/sync_order');
        $this->logger = Mage::getModel('shippit/logger');
        $this->carrierCode = $this->helper->getCarrierCode();
    }
    
    /**
     * Set the order to be synced with shippit
     * @param Varien_Event_Observer $observer [description]
     */
    public function addOrder(Varien_Event_Observer $observer)
    {
        // If the module is not active, stop processing
        if (!$this->helper->isActive()) {
            return $this;
        }

        // If the sync mode is custom, stop processing
        if ($this->helper->getMode() == Shippit_Shippit_Helper_Data::SYNC_MODE_CUSTOM) {
            return $this;
        }

        $order = $observer->getEvent()->getOrder();

        // Ensure we have an order
        if (!$order || !$order->getId()) {
            return $this;
        }

        $shippingCountry = $order->getShippingAddress()->getCountryId();

        // Ensure the order is destined for Australia
        if ($shippingCountry != 'AU') {
            return $this;
        }

        $shippingMethod = $order->getShippingMethod();
        $shippitShippingMethod = $this->helper->getShippitShippingMethod($shippingMethod);

        // If send all orders,
        // or shippit shipping class present
        if (($this->helper->isSendAllOrdersActive())
            || $shippitShippingMethod !== FALSE) {

            try {
                $request = Mage::getModel('shippit/request_sync_order')
                    ->setOrderId($order->getId())
                    ->setItems()
                    ->setShippingMethod($shippitShippingMethod);

                // Create a new sync order record
                $syncOrder = Mage::getModel('shippit/sync_order')->addRequest($request)
                    ->save();
            }
            catch (Exception $e) {
                $this->logger->log('Sync Order was unable to be created', $e->getMessage(), Zend_Log::ERR);
                $this->logger->logException($e);
            }

            try {
                // If the sync mode is realtime,
                // or the shipping method is priority
                // - attempt realtime sync now
                if ($this->helper->getMode() == Shippit_Shippit_Helper_Data::SYNC_MODE_REALTIME
                    || $shippitShippingMethod == 'priority') {
                    $this->_syncOrder($syncOrder);
                }
            }
            catch (Exception $e) {
                $this->logger->log('Sync Order was unable to be synced realtime', $e->getMessage(), Zend_Log::ERR);
                $this->logger->logException($e);
            }
        }

        return $this;
    }

    private function _syncOrder($syncOrder)
    {
        $order = $syncOrder->getOrder();

        if (!$this->_hasAttemptedSync
            // ensure the order is in the processing state
            && $order->getState() == Mage_Sales_Model_Order::STATE_PROCESSING
            // ensure the sync order is in the pending state
            && $syncOrder->getStatus() == Shippit_Shippit_Model_Sync_Order::STATUS_PENDING) {
            $this->_hasAttemptedSync = true;
            
            // attempt the sync
            $syncOrderResult = Mage::getModel('shippit/api_order')->sync($syncOrder);

            return $syncOrderResult;
        }
        else {
            return false;
        }
    }
}