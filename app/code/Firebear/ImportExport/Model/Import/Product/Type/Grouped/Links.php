<?php
/**
 * @copyright: Copyright © 2018 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Import\Product\Type\Grouped;

use Magento\CatalogImportExport\Model\Import\Product as ProductImport;
use Magento\Framework\App\ResourceConnection;

/**
 * Class Downloadable
 */
class Links extends \Magento\GroupedImportExport\Model\Import\Product\Type\Grouped\Links
{

    protected $fireImportFactory;
    /** @var \Firebear\ImportExport\Api\JobRepositoryInterface  */
    protected $importJobRepository;

    /**
     * Links constructor.
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Link $productLink
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\ImportExport\Model\ImportFactory $importFactory
     * @param \Firebear\ImportExport\Model\ImportFactory $fireImportFactory
     * @param \Firebear\ImportExport\Api\JobRepositoryInterface $importJobRepository
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Product\Link $productLink,
        ResourceConnection $resource,
        \Magento\ImportExport\Model\ImportFactory $importFactory,
        \Firebear\ImportExport\Model\ImportFactory $fireImportFactory,
        \Firebear\ImportExport\Api\JobRepositoryInterface $importJobRepository
    ) {
        parent::__construct($productLink, $resource, $importFactory);
        $this->fireImportFactory = $fireImportFactory;
        $this->importJobRepository = $importJobRepository;
    }

    /**
     * Saves the linksData to database
     *
     * @param array $linksData
     * @param ProductImport $productImport
     * @return void
     */
    public function customSaveLinksData(array $linksData)
    {
        $mainTable = $this->productLink->getMainTable();
        $relationTable = $this->productLink->getTable('catalog_product_relation');
        // save links and relations
        if ($linksData['product_ids']) {
            $this->customDeleteOldLinks(array_keys($linksData['product_ids']));
            $mainData = [];
            foreach ($linksData['relation'] as $productData) {
                $mainData[] = [
                    'product_id' => $productData['parent_id'],
                    'linked_product_id' => $productData['child_id'],
                    'link_type_id' => $this->getLinkTypeId()
                ];
            }
            $this->connection->insertOnDuplicate($mainTable, $mainData);
            $this->connection->insertOnDuplicate($relationTable, $linksData['relation']);
        }
        $attributes = $this->getAttributes();
        // save positions and default quantity
        if ($linksData['attr_product_ids']) {
            $savedData = $this->connection->fetchPairs(
                $this->connection->select()->from(
                    $mainTable,
                    [new \Zend_Db_Expr('CONCAT_WS(" ", product_id, linked_product_id)'), 'link_id']
                )->where(
                    'product_id IN (?) AND link_type_id = ' . $this->connection->quote($this->getLinkTypeId()),
                    array_keys($linksData['attr_product_ids'])
                )
            );
            foreach ($savedData as $pseudoKey => $linkId) {
                if (isset($linksData['position'][$pseudoKey])) {
                    $linksData['position'][$pseudoKey]['link_id'] = $linkId;
                }
                if (isset($linksData['qty'][$pseudoKey])) {
                    $linksData['qty'][$pseudoKey]['link_id'] = $linkId;
                }
            }
            if (!empty($linksData['position'])) {
                $this->connection->insertOnDuplicate($attributes['position']['table'], $linksData['position']);
            }
            if (!empty($linksData['qty'])) {
                $this->connection->insertOnDuplicate($attributes['qty']['table'], $linksData['qty']);
            }
        }
    }

    /**
     * @param $productIds
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function customDeleteOldLinks($productIds)
    {
        $jobId = $this->fireImportFactory->create()->getFireDataSourceModel()->getJobId();
        $importJobData = $this->importJobRepository->getById($jobId);
        $sourceData = $importJobData->getSourceData();
        $relationTable = $this->productLink->getTable('catalog_product_relation');
        $this->behavior = $importJobData->getBehaviorData()['behavior']
            ?? \Magento\ImportExport\Model\Import\AbstractEntity::getDefaultBehavior();
        if ($this->behavior != \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND
            || (isset($sourceData['remove_product_association']) && $sourceData['remove_product_association'] == 1)
        ) {
            $this->connection->delete(
                $this->productLink->getMainTable(),
                $this->connection->quoteInto(
                    'product_id IN (?) AND link_type_id = ' . $this->getLinkTypeId(),
                    $productIds
                )
            );
            // Remove Product Relations form catalog_product_relation
            $this->connection->delete(
                $relationTable,
                $this->connection->quoteInto(
                    'parent_id IN (?)',
                    $productIds
                )
            );
        }
    }
}
