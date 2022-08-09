<?php

namespace Signifyd\Connect\Model\Magento\Sales\Model\Order;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Sales\Api\CreditmemoManagementInterface as CreditmemoManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Operations\SaleOperation;
use Magento\Sales\Model\Order\Payment\Transaction\ManagerInterface;
use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Model\ScaPreAuth\ScaEvaluation;

class Payment extends \Magento\Sales\Model\Order\Payment
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ScaEvaluation
     */
    protected $scaEvaluation;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param Order\CreditmemoFactory $creditmemoFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository
     * @param ManagerInterface $transactionManager
     * @param Order\Payment\Transaction\BuilderInterface $transactionBuilder
     * @param Order\Payment\Processor $paymentProcessor
     * @param OrderRepositoryInterface $orderRepository
     * @param Logger $logger
     * @param ScaEvaluation $scaEvaluation
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     * @param CreditmemoManager|null $creditmemoManager
     * @param SaleOperation|null $saleOperation
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        PriceCurrencyInterface $priceCurrency,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        ManagerInterface $transactionManager,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        \Magento\Sales\Model\Order\Payment\Processor $paymentProcessor,
        OrderRepositoryInterface $orderRepository,
        Logger $logger,
        ScaEvaluation $scaEvaluation,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = [],
        CreditmemoManager $creditmemoManager = null,
        SaleOperation $saleOperation = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $encryptor,
            $creditmemoFactory,
            $priceCurrency,
            $transactionRepository,
            $transactionManager,
            $transactionBuilder,
            $paymentProcessor,
            $orderRepository,
            $resource,
            $resourceCollection,
            $data,
            $creditmemoManager,
            $saleOperation
        );

        $this->logger = $logger;
        $this->scaEvaluation = $scaEvaluation;
    }

    protected function processAction($action, Order $order)
    {
        try {
            parent::processAction($action, $order);
        } catch (\Exception $e) {
            if ($this->scaEvaluation->getIsSoftDecline()) {
                $this->logger->info("Reprocessing payment due to soft decline error");
                parent::processAction($action, $order);
            }
        }
    }
}