<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Helper\ConfigHelper;
use Signifyd\Connect\Model\Casedata;
use Signifyd\Connect\Model\CasedataFactory;
use Signifyd\Connect\Model\ResourceModel\Casedata\CollectionFactory as CasedataCollectionFactory;
use Signifyd\Connect\Model\ResourceModel\Casedata as CasedataResourceModel;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\State as AppState;
use Signifyd\Connect\Model\ResourceModel\Order as SignifydOrderResourceModel;
use Signifyd\Connect\Model\UpdateOrder\Action as UpdateOrderAction;
use Signifyd\Connect\Model\Api\RecipientFactory;
use Signifyd\Connect\Model\Api\SaleOrderFactory;
use Signifyd\Connect\Model\Api\Core\Client;

/**
 * Observer for purchase event. Sends order data to Signifyd service
 */
class Purchase implements ObserverInterface
{
    /**
     * @var Logger;
     */
    protected $logger;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var CasedataFactory
     */
    protected $casedataFactory;

    /**
     * @var CasedataResourceModel
     */
    protected $casedataResourceModel;

    /**
     * @var SignifydOrderResourceModel
     */
    protected $signifydOrderResourceModel;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * Methods that should wait e-mail sent to hold order
     * @var array
     */
    protected $specialMethods = ['payflow_express'];

    /**
     * List of methods that uses a different event for triggering case creation
     * This is useful when it's needed case creation to be delayed to wait for other processes like data return from
     * payment method
     *
     * @var array
     */
    protected $ownEventsMethods = ['authorizenet_directpost', 'paypal_express'];

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfigInterface;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var AppState
     */
    protected $appState;

    /**
     * @var CasedataCollectionFactory
     */
    protected $casedataCollectionFactory;

    /**
     * @var JsonSerializer
     */
    protected $jsonSerializer;

    /**
     * @var UpdateOrderAction
     */
    protected $updateOrderAction;

    /**
     * @var RecipientFactory
     */
    protected $recipientFactory;

    /**
     * @var SaleOrderFactory
     */
    protected $saleOrderFactory;

    /**
     * @var Client
     */
    protected $client;

    /**
     * Purchase constructor.
     * @param Logger $logger
     * @param ConfigHelper $configHelper
     * @param CasedataFactory $casedataFactory
     * @param CasedataResourceModel $casedataResourceModel
     * @param SignifydOrderResourceModel $signifydOrderResourceModel
     * @param OrderFactory $orderFactory
     * @param UpdateOrderAction $updateOrderAction
     * @param DateTime $dateTime
     * @param ScopeConfigInterface $scopeConfigInterface
     * @param StoreManagerInterface $storeManager
     * @param AppState $appState
     * @param CasedataCollectionFactory $casedataCollectionFactory
     * @param JsonSerializer $jsonSerializer
     * @param RecipientFactory $recipientFactory
     * @param SaleOrderFactory $saleOrderFactory
     * @param Client $client
     */
    public function __construct(
        Logger $logger,
        ConfigHelper $configHelper,
        CasedataFactory $casedataFactory,
        CasedataResourceModel $casedataResourceModel,
        SignifydOrderResourceModel $signifydOrderResourceModel,
        OrderFactory $orderFactory,
        UpdateOrderAction $updateOrderAction,
        DateTime $dateTime,
        ScopeConfigInterface $scopeConfigInterface,
        StoreManagerInterface $storeManager,
        AppState $appState,
        CasedataCollectionFactory $casedataCollectionFactory,
        JsonSerializer $jsonSerializer,
        RecipientFactory $recipientFactory,
        SaleOrderFactory $saleOrderFactory,
        Client $client
    ) {
        $this->logger = $logger;
        $this->configHelper = $configHelper;
        $this->casedataFactory = $casedataFactory;
        $this->casedataResourceModel = $casedataResourceModel;
        $this->signifydOrderResourceModel = $signifydOrderResourceModel;
        $this->orderFactory = $orderFactory;
        $this->updateOrderAction = $updateOrderAction;
        $this->dateTime = $dateTime;
        $this->scopeConfigInterface = $scopeConfigInterface;
        $this->storeManager = $storeManager;
        $this->appState = $appState;
        $this->casedataCollectionFactory = $casedataCollectionFactory;
        $this->jsonSerializer = $jsonSerializer;
        $this->recipientFactory = $recipientFactory;
        $this->saleOrderFactory = $saleOrderFactory;
        $this->client = $client;
    }

    /**
     * @param Observer $observer
     * @param bool $checkOwnEventsMethods
     */
    public function execute(Observer $observer, $checkOwnEventsMethods = true)
    {
        try {
            /** @var $order Order */
            $order = $observer->getEvent()->getOrder();

            $this->logger->info('Processing Signifyd event ' . $observer->getEvent()->getName(), ['entity' => $order]);

            if (isset($order) === false) {
                return;
            }

            $storeId = $order->getStoreId();

            $enabledConfig = $this->scopeConfigInterface->getValue(
                'signifyd/general/enabled',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORES,
                $storeId
            );

            $isPassive = $enabledConfig == 'passive';

            /** @var \Signifyd\Connect\Model\ResourceModel\Casedata\Collection $casesFromQuotes */
            $casesFromQuotes = $this->casedataCollectionFactory->create();
            $casesFromQuotes->addFieldToFilter('quote_id', ['eq' => $order->getQuoteId()]);
            $casesFromQuotes->addFieldToFilter('policy_name', ['eq' => Casedata::PRE_AUTH]);

            if ($casesFromQuotes->count() > 0 &&
                $casesFromQuotes->getFirstItem()->getMagentoStatus() != 'completed'
            ) {
                $casesFromQuote = $casesFromQuotes->getFirstItem();
                /** @var $case \Signifyd\Connect\Model\Casedata */
                $casesFromQuoteLoaded = $this->casedataFactory->create();
                $this->casedataResourceModel->load($casesFromQuoteLoaded, $casesFromQuote->getCode(), 'code');
                $orderId = $casesFromQuoteLoaded->getData('order_id');

                if (isset($orderId) &&
                    $casesFromQuoteLoaded->getData('magento_status') == Casedata::IN_REVIEW_STATUS
                ) {
                    return;
                }

                $casesFromQuoteLoaded->setData('order_increment', $order->getIncrementId());
                $casesFromQuoteLoaded->setData('order_id', $order->getId());

                if ($casesFromQuoteLoaded->getData('magento_status') == Casedata::PRE_AUTH) {
                    $this->logger->info(
                        "Completing case for order {$order->getIncrementId()} ({$order->getId()}) " .
                        "because it is a pre auth case", ['entity' => $order]
                    );
                    $casesFromQuoteLoaded->setData('magento_status', Casedata::COMPLETED_STATUS);
                }

                $this->casedataResourceModel->save($casesFromQuoteLoaded);

                if ($casesFromQuote->getData('guarantee') == 'HOLD' ||
                    $casesFromQuote->getData('guarantee') == 'PENDING'
                ) {
                    $this->holdOrder($order, $casesFromQuoteLoaded, $isPassive);
                }

                return;
            }

            if (!is_object($order)) {
                return;
            }

            if ($this->configHelper->isEnabled($order) == false) {
                return;
            }

            $incrementId = $order->getIncrementId();

            if ($this->isIgnored($order)) {
                $this->logger->debug("Order {$incrementId} ignored", ['entity' => $order]);
                return;
            }

            $paymentMethod = $order->getPayment()->getMethod();
            $isOrderProcessedByAmazon = $paymentMethod === 'amazon_payment_v2' &&
                $this->configHelper->getIsOrderProcessedByAmazon($order) === true;

            $checkOwnEventsMethodsEvent = $observer->getEvent()->getCheckOwnEventsMethods();

            if ($checkOwnEventsMethodsEvent !== null) {
                $checkOwnEventsMethods = $checkOwnEventsMethodsEvent;
            }

            if ($checkOwnEventsMethods && in_array($paymentMethod, $this->ownEventsMethods)) {
                return;
            }

            if ($this->configHelper->isPaymentRestricted($paymentMethod)) {
                $message = 'Case creation for order ' . $incrementId .
                    ' with payment ' . $paymentMethod . ' is restricted';
                $this->logger->debug($message, ['entity' => $order]);
                return;
            }

            /** @var $case \Signifyd\Connect\Model\Casedata */
            $case = $this->casedataFactory->create();
            $this->casedataResourceModel->load($case, $order->getId(), 'order_id');

            if ($case->isEmpty()) {
                $this->casedataResourceModel->load($case, $order->getQuoteId(), 'quote_id');

                if (empty($case->getData('order_id')) === false &&
                    $case->getData('order_id') !== $order->getId()) {
                    $case = $this->casedataFactory->create();
                }

                $makeRecipient = $this->recipientFactory->create();
                $recipient = $makeRecipient($order);
                $recipientJson = $this->jsonSerializer->serialize($recipient);
                $hashToValidateReroute = sha1($recipientJson);

                $case->setEntries('hash', $hashToValidateReroute);
                $case->setData('magento_status', Casedata::NEW);
                $case->setData('order_increment', $order->getIncrementId());
                $case->setData('order_id', $order->getId());
                $case->setData('quote_id', $order->getQuoteId());
                $case->setData('policy_name', Casedata::POST_AUTH);

                if (is_object($this->storeManager)) {
                    $isAdmin = ('adminhtml' === $this->appState->getAreaCode());
                    $storeCode = $this->storeManager->getStore($isAdmin ? 'admin' : true)->getCode();
                    if (!empty($storeCode)) {
                        $case->setData('origin_store_code', $storeCode);
                    }
                }

                $this->casedataResourceModel->save($case);
            } elseif ($case->getData('magento_status') != Casedata::NEW) {
                if ($isOrderProcessedByAmazon && $case->getMagentoStatus() === Casedata::AWAITING_PSP) {
                    // Hold order after Amazon capture the payment
                    $this->holdOrder($order, $case, $isPassive);
                    $case->setMagentoStatus(Casedata::IN_REVIEW_STATUS);

                    $this->casedataResourceModel->save($case);
                }

                return;
            } elseif ($case->isEmpty() === false && $isPassive) {
                return;
            }

            // Check if a payment is available for this order yet
            if ($order->getState() == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
                return;
            }

            $state = $order->getState();

            if ($this->isStateRestricted($state, 'create')) {
                $message = 'Case creation for order ' . $incrementId . ' with state ' . $state . ' is restricted';
                $this->logger->debug($message, ['entity' => $order]);
                return;
            }

            $message = "Creating case for order {$incrementId} ({$order->getId()}),
            state {$state}, payment method {$paymentMethod}";
            $this->logger->debug($message, ['entity' => $order]);

            $case->setSignifydStatus("PENDING");
            $case->setCreated(date('Y-m-d H:i:s', time()));
            $case->setUpdated();
            $order->setData('origin_store_code', $case->getData('origin_store_code'));
            $saleOrder = $this->saleOrderFactory->create();
            $orderData = $saleOrder($order);

            // Stop case sending if order has an async payment method
            if (in_array($paymentMethod, $this->getAsyncPaymentMethodsConfig()) &&
                isset($orderData['transactions']) &&
                isset($orderData['transactions'][0]) &&
                isset($orderData['transactions'][0]['verifications']) &&
                isset($orderData['transactions'][0]['verifications']['avsResponseCode']) === false &&
                isset($orderData['transactions'][0]['verifications']['cvvResponseCode']) === false
            ) {
                $case->setMagentoStatus(Casedata::ASYNC_WAIT);

                try {
                    $this->casedataResourceModel->save($case);
                    $this->logger->debug(
                        'Case for order:#' . $incrementId . ' was not sent because of an async payment method',
                        ['entity' => $case]
                    );

                    // Initial hold order
                    $this->holdOrder($order, $case, $isPassive);
                } catch (\Exception $ex) {
                    $this->logger->error($ex->__toString(), ['entity' => $order]);
                }

                return;
            }

            $saleResponse = $this->client->postCaseToSignifyd($orderData, $order);

            if ($saleResponse === false) {
                return;
            }

            if (is_object($saleResponse)) {
                $case->setCode($saleResponse->getSignifydId());

                if ($paymentMethod === 'amazon_payment_v2') {
                    if ($this->configHelper->getIsOrderProcessedByAmazon($order) === true) {
                        $magentoStatus = Casedata::IN_REVIEW_STATUS;
                    } else {
                        $magentoStatus = Casedata::AWAITING_PSP;
                    }
                } else {
                    $magentoStatus = Casedata::IN_REVIEW_STATUS;
                }

                $case->setMagentoStatus($magentoStatus);

                $case->setUpdated();
            }

            $this->casedataResourceModel->save($case);

            // Initial hold order
            $this->holdOrder($order, $case, $isPassive);

            if ($isPassive === false) {
                $this->signifydOrderResourceModel->save($order);
            }
        } catch (\Exception $ex) {
            $context = [];

            if (isset($order) && $order instanceof Order) {
                $context['entity'] = $order;
            }

            $this->logger->error($ex->getMessage(), $context);
        }
    }

    /**
     * Get async payment methods from store configs
     *
     * @return array|mixed
     */
    public function getAsyncPaymentMethodsConfig()
    {
        $asyncPaymentMethods = $this->configHelper->getConfigData('signifyd/general/async_payment_methods');

        if (isset($asyncPaymentMethods) === false) {
            return null;
        }

        $asyncPaymentMethods = explode(',', $asyncPaymentMethods);
        $asyncPaymentMethods = array_map('trim', $asyncPaymentMethods);

        return $asyncPaymentMethods;
    }

    /**
     * Check if order is ignored based on installation date
     *
     * If there is no record of the installation date on database order will not be ignored
     *
     * @param Order $order
     * @return bool
     */
    public function isIgnored(Order $order)
    {
        $installationDateConfig = $this->scopeConfigInterface->getValue('signifyd_connect/general/installation_date');

        if (empty($installationDateConfig)) {
            return false;
        }

        $installationDate = $this->dateTime->gmtTimestamp($installationDateConfig);
        $createdAtDate = $this->dateTime->gmtTimestamp($order->getCreatedAt());

        if ($createdAtDate < $installationDate) {
            $this->logger->info("Installation date: {$installationDate}", ['entity' => $order]);
            $this->logger->info("Created at date: {$createdAtDate}", ['entity' => $order]);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if state is restricted
     *
     * @param $state
     * @param string $action
     * @return bool
     */
    public function isStateRestricted($state, $action = 'default')
    {
        if (empty($state)) {
            return true;
        }

        $restrictedStates = $this->configHelper->getConfigData("signifyd/general/restrict_states_{$action}");

        if (isset($restrictedStates) === false) {
            return false;
        }

        $restrictedStates = explode(',', $restrictedStates);
        $restrictedStates = array_map('trim', $restrictedStates);
        $restrictedStates = array_filter($restrictedStates);

        if (empty($restrictedStates) && $action != 'default') {
            return $this->isStateRestricted($state, 'default');
        }

        if (in_array($state, $restrictedStates)) {
            return true;
        }

        return false;
    }

    /**
     * @param $order
     * @return bool
     */
    public function holdOrder(Order $order, Casedata $case, $isPassive = false)
    {
        $positiveAction = $this->updateOrderAction->getPositiveAction($case);
        $negativeAction = $this->updateOrderAction->getNegativeAction($case);

        if (($positiveAction != 'nothing' || $negativeAction != 'nothing')) {
            if (!$order->canHold()) {
                $notHoldableStates = [
                    Order::STATE_CANCELED,
                    Order::STATE_PAYMENT_REVIEW,
                    Order::STATE_COMPLETE,
                    Order::STATE_CLOSED,
                    Order::STATE_HOLDED
                ];

                if (in_array($order->getState(), $notHoldableStates)) {
                    $reason = "order is on {$order->getState()} state";
                } elseif ($order->getActionFlag(Order::ACTION_FLAG_HOLD) === false) {
                    $reason = "order action flag is set to do not hold";
                } else {
                    $reason = "unknown reason";
                }

                $message = "Order {$order->getIncrementId()} can not be held because {$reason}";
                $this->logger->debug($message, ['entity' => $order]);

                return false;
            }

            if (in_array($order->getPayment()->getMethod(), $this->specialMethods)) {
                if (!$order->getEmailSent()) {
                    return false;
                }
            }

            if ($order->getPayment()->getMethod() === 'amazon_payment_v2' &&
                $this->configHelper->getIsOrderProcessedByAmazon($order) === false
            ) {
                $this->logger->debug(
                    "Cannot hold order as Amazon Pay is set to capture" .
                    " the payment and the order is not invoiced",
                    ['entity' => $order]
                );
                $order->addCommentToStatusHistory(
                    "Signifyd: cannot hold order as Amazon Pay is set to capture" .
                    " the payment and the order is not invoiced"
                );
                return false;
            }

            $this->logger->debug(
                'Purchase Observer Order Hold: No: ' . $order->getIncrementId(),
                ['entity' => $order]
            );

            if ($isPassive === false) {
                $order->hold();
            }

            $order->addCommentToStatusHistory("Signifyd: after order place");
            $this->signifydOrderResourceModel->save($order);
        }

        return true;
    }
}
