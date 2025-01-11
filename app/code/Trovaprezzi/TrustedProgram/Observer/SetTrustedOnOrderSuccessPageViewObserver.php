<?php

namespace Trovaprezzi\TrustedProgram\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;

class SetTrustedOnOrderSuccessPageViewObserver implements ObserverInterface
{
    /**
     * @param LayoutInterface $layout
     */
    public function __construct(
        private readonly LayoutInterface $layout,
    ) {
    }

    /**
     * Add order information into block to render on checkout success pages
     *
     * @param EventObserver $observer
     * @return void
     */
    public function execute(EventObserver $observer): void
    {
        $orderIds = $observer->getEvent()->getOrderIds();

        if (empty($orderIds) || !is_array($orderIds)) {
            return;
        }
        $block = $this->layout->getBlock('trustedprogram');
        if ($block) {
            $block->setOrderIds($orderIds);
        }
    }
}
