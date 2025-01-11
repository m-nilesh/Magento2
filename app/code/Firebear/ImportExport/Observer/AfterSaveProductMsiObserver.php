<?php
/**
 * @copyright: Copyright © 2021 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Firebear\ImportExport\Model\Import\Product;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Firebear\ImportExport\Model\Import\SourceManager;

/**
 * Class AfterSaveProductMsiObserver
 * @package Firebear\ImportExport\Observer
 */
class AfterSaveProductMsiObserver implements ObserverInterface
{
    /**
     * @var SourceManager
     */
    protected $sourceManager;

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * AfterSaveProductMsiObserver constructor.
     * @param SourceManager $sourceManager
     * @param ResourceConnection $resource
     */
    public function __construct(
        SourceManager $sourceManager,
        ResourceConnection $resource
    ) {
        $this->sourceManager = $sourceManager;
        $this->resource = $resource;
    }

    /**
     * @param Observer $observer
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Validation\ValidationException
     */
    public function execute(Observer $observer)
    {
        if (!$this->sourceManager->isEnableMsi()) {
            return;
        }
        $fieldNames = $this->sourceManager->getCoreFields($observer->getBunch());
        if (!$fieldNames) {
            return;
        }
        $sources = $this->sourceManager->getSourcesByFieldNames($fieldNames);
        $sourceBunch = [];
        $cache = [];
        foreach ($observer->getBunch() as $item) {
            foreach ($sources as $source) {
                if (empty($item[SourceManager::PREFIX . $source])
                    || !isset($item[SourceManager::PREFIX . $source . SourceManager::QTY_POSTFIX])
                ) {
                    continue;
                }
                if (!empty($cache[$item[Product::COL_SKU]][$source])) {
                    continue;
                }
                $qty = $item[SourceManager::PREFIX . $source . SourceManager::QTY_POSTFIX];
                /**
                 * Default stock status if not presented in import file
                 */
                $defaultStatus = $qty > 0 ? 1 : 0;
                $sourceBunch[] = [
                    SourceItemInterface::SKU => $item[Product::COL_SKU],
                    SourceItemInterface::SOURCE_CODE => $source,
                    SourceItemInterface::STATUS =>
                        $item[SourceManager::PREFIX . $source . SourceManager::STATUS] ?? $defaultStatus,
                    SourceItemInterface::QUANTITY => $qty
                ];
                $cache[$item[Product::COL_SKU]][$source] = true;
            }
        }
        if (!$sourceBunch) {
            return;
        }

        $skuToUpdate = $this->checkSkuToUpdateStockData($cache);
        $this->saveDefaultStockData($skuToUpdate);

        $sourceBunch = $this->sourceManager->getSourceItemConvert()->convert($sourceBunch);
        $this->sourceManager->getSourceItemsSave()->execute($sourceBunch);
    }

    /**
     * Check SkuToUpdateStockData
     *
     * @param array $cache
     * @return array
     */
    protected function checkSkuToUpdateStockData($cache)
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select();
        $skus = array_keys($cache);

        $select->from(
            $this->resource->getTableName('cataloginventory_stock_item'),
            ['product_id']
        )->join(
            ['cpe' => $this->resource->getTableName('catalog_product_entity')],
            'cataloginventory_stock_item.product_id = cpe.entity_id',
            ['sku']
        )->where(
            'sku in(?)',
            $skus
        );

        $defaultStockData = $connection->fetchAssoc($select);

        $skuToUpdate = [];
        foreach ($cache as $sku => $data) {
            if (!isset($defaultStockData[$sku])) {
                $skuToUpdate[] = $sku;
            }
        }
        return $skuToUpdate;
    }

    /**
     * Save DefaultStockData
     *
     * @param array $skuToUpdate
     */
    protected function saveDefaultStockData($skuToUpdate)
    {
        $idsToUpdate = $this->getIdsBySkus($skuToUpdate);

        if (!empty($idsToUpdate)) {
            $connection = $this->resource->getConnection();
            $stockId = $this->sourceManager->getDefaultStockProvider()->getId();
            $updateData = [];
            foreach ($idsToUpdate as $idData) {
                $updateData[] = [
                    'product_id' => $idData['entity_id'],
                    'stock_id' => $stockId,
                    'qty' => 0,
                    'is_in_stock' => 1
                ];
            }
            $connection->insertOnDuplicate(
                $this->resource->getTableName('cataloginventory_stock_item'),
                $updateData
            );
        }
    }

    /**
     * Get IdsBySkus
     *
     * @param array $skuToUpdate
     * @return array
     */
    protected function getIdsBySkus($skuToUpdate)
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select();
        $idsToUpdate = [];
        if (!empty($skuToUpdate)) {
            $select->from(
                $this->resource->getTableName('catalog_product_entity'),
                ['entity_id']
            )->where(
                'sku in(?)',
                $skuToUpdate
            );
            $idsToUpdate = $connection->fetchAll($select);
        }
        return $idsToUpdate;
    }
}
