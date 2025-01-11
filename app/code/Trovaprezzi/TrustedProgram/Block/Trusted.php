<?php

declare(strict_types=1);

namespace Trovaprezzi\TrustedProgram\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Trovaprezzi\TrustedProgram\Utility\Configurations;

class Trusted extends Template
{
    /**
     * @param Context $context
     * @param CollectionFactory $salesOrderCollection
     * @param Configurations $configurations
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly CollectionFactory $salesOrderCollection,
        private readonly Configurations $configurations,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Render information about specified orders and their items
     *
     * @return string
     */
    public function getOrdersTrackingCode(): string
    {
        $orderIds = $this->getOrderIds();
        if (empty($orderIds) || !is_array($orderIds)) {
            return '';
        }

        $collection = $this->salesOrderCollection->create();
        $collection->addFieldToFilter('entity_id', ['in' => $orderIds]);
        $result = [];

        foreach ($collection as $order) {
            $prodRows = [];
			foreach ($order->getAllVisibleItems() as $item) {
				$prodRows[] = "window._tpt.push({ event: \"addItem\", sku: '"
                    . $this->escapeJsQuote($item->getSku())."', product_name: '"
                    . $this->escapeJsQuote($item->getName())."' });";
			}

			$prod= implode("\n", $prodRows);

            $result[] = sprintf(
                "function tpt_push() {
                    window._tpt.push({ event: \"setAccount\", id: '%s' });
				    window._tpt.push({ event: \"setOrderId\", order_id: '%s' });
				    window._tpt.push({ event: \"setEmail\", email: '%s' });
				    %s
				    window._tpt.push({ event: \"setAmount\", amount: '%s' });
                    window._tpt.push({ event: \"orderSubmit\"});
                };",
				$this->configurations->getAccountId(), //MKEY
                $order->getIncrementId(),   //ID
                $order->getCustomerEmail(), //MAIL
				$prod,  //SKU-NAME
                $order->getBaseGrandTotal() - $order->getBaseShippingAmount()   //AMOUNT
            );

            $result[] = "if (window._tpt === undefined) {
                            window.addEventListener('load', tpt_push);
                        } else {
                            tpt_push();
                        }";
        }
        return implode("\n", $result);
    }

    /**
     * Retrieves the tracking jQuery code.
     *
     * @return string
     */
	public function getTrackingJquery(): string
    {
	    $orderIds = $this->getOrderIds();
        if (empty($orderIds) || !is_array($orderIds)) {
            return '';
        }
		return "<script type=\"text/javascript\" src=\"https://tracking.trovaprezzi.it/javascripts/tracking-vanilla.min.js\"></script>";
	}

    /**
     * Render TrovaPrezzi tracking scripts
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->configurations->isTPAvailable()) {
            return '';
        }

        return parent::_toHtml();
    }
}
