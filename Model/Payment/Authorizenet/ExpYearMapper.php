<?php

namespace Signifyd\Connect\Model\Payment\Authorizenet;

use Signifyd\Connect\Model\Payment\Base\ExpYearMapper as Base_ExpYearMapper;

class ExpYearMapper extends Base_ExpYearMapper
{
    protected $allowedMethods = array('authorizenet_directpost');

    /**
     * Gets expiry year from XML response from Authorize.net
     *
     * @param \Magento\Sales\Model\Order $order
     * @return null|string
     */
    public function getPaymentData(\Magento\Sales\Model\Order $order)
    {
        $responseXmlDocument = $this->getSignifydPaymentData();

        if (is_object($responseXmlDocument)) {
            $expYear = $responseXmlDocument->transaction->payment->creditCard->expirationDate;
            $expYear = substr($expYear, -2);
        }

        $this->logger->debug('Expiry year found on payment mapper: ' . (empty($expYear) ? 'false' : $expYear), array('entity' => $order));

        if (empty($expYear)) {
            $expYear = parent::getPaymentData($order);
        }

        return $expYear;
    }
}
