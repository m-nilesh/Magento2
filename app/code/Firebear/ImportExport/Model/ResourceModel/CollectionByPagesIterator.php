<?php

namespace Firebear\ImportExport\Model\ResourceModel;

use Firebear\ImportExport\Logger\Logger;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Collection\AbstractDb;

class CollectionByPagesIterator extends \Magento\ImportExport\Model\ResourceModel\CollectionByPagesIterator
{
    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * CollectionByPagesIterator constructor.
     *
     * @param RequestInterface $request
     * @param Logger $logger
     */
    public function __construct(RequestInterface $request, Logger $logger)
    {
        $this->request = $request;
        $this->logger = $logger;
    }

    /**
     * @param AbstractDb $collection
     * @param int $pageSize
     * @param array $callbacks
     */
    public function iterate(AbstractDb $collection, $pageSize, array $callbacks)
    {
        /** @var $paginatedCollection AbstractDb */
        $paginatedCollection = null;
        $pageNumber = 1;
        $logFileName = $this->request->getParam('file');
        if (!empty($logFileName)) {
            $this->logger->setFileName($logFileName);
        }
        do {
            $paginatedCollection = clone $collection;
            $paginatedCollection->clear();

            $paginatedCollection->setPageSize($pageSize)->setCurPage($pageNumber);

            if ($paginatedCollection->count() > 0) {
                foreach ($paginatedCollection as $item) {
                    foreach ($callbacks as $callback) {
                        call_user_func($callback, $item);
                    }
                }
                if ($pageNumber < $paginatedCollection->getLastPageNumber() && !empty($logFileName)) {
                    $processedItems = $pageNumber * $pageSize;
                    $this->logger->info(__('Items processed: %1', $processedItems));
                }
            }
            $pageNumber++;
        } while ($pageNumber <= $paginatedCollection->getLastPageNumber());
        $paginatedCollection->clear();
        unset($paginatedCollection);
    }
}
