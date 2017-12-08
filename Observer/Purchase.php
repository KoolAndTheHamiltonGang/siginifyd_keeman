<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Psr\Log\LoggerInterface;
use \Magento\Framework\ObjectManagerInterface;
use Signifyd\Connect\Helper\PurchaseHelper;
use Signifyd\Connect\Helper\LogHelper;
use Signifyd\Connect\Helper\SignifydAPIMagento;
use Signifyd\Connect\Model\CaseRetry;

/**
 * Observer for purchase event. Sends order data to Signifyd service
 */
class Purchase implements ObserverInterface
{
    /**
     * @var \Signifyd\Connect\Helper\LogHelper
     */
    protected $logger;

    /**
     * @var \Signifyd\Connect\Helper\PurchaseHelper
     */
    protected $helper;

    /**
     * @var SignifydAPIMagento
     */
    protected $api;

    /**
     * @var ScopeConfigInterface
     */
    protected $coreConfig;

    protected $specialMethods = ['payflow_express'];

//    protected $restrictedMethods = ['checkmo', 'banktransfer', 'purchaseorder', 'cashondelivery'];
    protected $restrictedMethods = ['banktransfer', 'purchaseorder', 'cashondelivery'];

    public function __construct(
        LogHelper $logger,
        PurchaseHelper $helper,
        SignifydAPIMagento $api,
        ScopeConfigInterface $coreConfig
    ) {
        $this->logger = $logger;
        $this->helper = $helper;
        $this->api = $api;
        $this->coreConfig = $coreConfig;
    }

    public function execute(Observer $observer)
    {
        if(!$this->api->enabled()) return;

        try {
            /** @var $order Order */
            $order = $observer->getEvent()->getOrder();

            // Check if a payment is available for this order yet
            if($order->getState() == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) { return; }
            $this->logger->debug($order->getPayment()->getMethod());

            if(in_array($order->getPayment()->getMethod(), $this->restrictedMethods)){ return; }

            // Check if case already exists for this order
            if ($this->helper->doesCaseExist($order)) {
                // backup hold order
                $this->holdOrder($order);
                return;
            }

            $orderData = $this->helper->processOrderData($order);

            // Add order to database
            $case = $this->helper->createNewCase($order);

            // Post case to signifyd service
            $result = $this->helper->postCaseToSignifyd($orderData, $order);

            // Initial hold order
            $this->holdOrder($order);

            if($result){
                $case->setCode($result);
                $case->setMagentoStatus(CaseRetry::IN_REVIEW_STATUS)->setUpdated(strftime('%Y-%m-%d %H:%M:%S', time()));
                try {
                    $case->getResource()->save($case);
                    $this->logger->debug('Case saved. Order No:' . $order->getIncrementId());
                } catch (\Exception $e) {
                    $this->logger->error('Exception in: ' . __FILE__ . ', on line: ' . __LINE__);
                    $this->logger->error('Exception:' . $e->__toString());
                }
            }
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
        }
    }

    public function holdOrder($order)
    {
        $case = $this->helper->getCase($order);
        $positiveAction = $case->getPositiveAction();
        $negativeAction = $case->getNegativeAction();

        if (($positiveAction != 'nothing' || $negativeAction != 'nothing') && $order->canHold()) {
            if (in_array($order->getPayment()->getMethod(), $this->specialMethods)) {
                if (!$order->getEmailSent()){
                    return false;
                }

                if ($this->helper->hasGuaranty($order)) {
                    return false;
                }
            }

            if (!$this->helper->hasGuaranty($order)) {
                $this->logger->debug('Purchase Observer Order Hold: No: ' . $order->getIncrementId());
                $order->hold()->getResource()->save($order);
            }
        }

        return true;
    }
}
