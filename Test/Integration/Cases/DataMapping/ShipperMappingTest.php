<?php

namespace Signifyd\Connect\Test\Integration\Cases\Order;

use Signifyd\Connect\Model\Casedata;
use Signifyd\Connect\Test\Integration\Cases\Cron\CreateTest;

class ShipperMappingTest extends CreateTest
{
    public function testCronCreateCase()
    {
        // Bypassing test
    }

    /**
     * @magentoDataFixture configFixture
     */
    public function testShipperMappingAction()
    {
        /** @var \Magento\Framework\App\Config\Storage\WriterInterface $writerInterface */
        $writerInterface = $this->objectManager->create(\Magento\Framework\App\Config\Storage\WriterInterface::class);
        $arrayMapping = ["FEDEX" => ["flatrate"]];
        $writerInterface->save('signifyd/general/shipping_method_config', json_encode($arrayMapping));

        $this->processReviewCase();
        $order = $this->getOrder();
        $orderData = $this->purchaseHelper->processOrderData($order);

        $this->assertEquals($orderData['purchase']['shipments'][0]['carrier'], 'FEDEX');
    }
}
