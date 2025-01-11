<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Import\Product\Type;

use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\Framework\App\ObjectManager;
use Magento\BundleImportExport\Model\Import\Product\Type\Bundle\RelationsDataSaver;
use Magento\CatalogImportExport\Model\Import\Product;
use Firebear\ImportExport\Model\ImportFactory;
use Firebear\ImportExport\Api\JobRepositoryInterface;

/**
 * Class Bundle
 *
 * @package Firebear\ImportExport\Model\Import\Product\Type
 */
class Bundle extends \Magento\BundleImportExport\Model\Import\Product\Type\Bundle
{
    use \Firebear\ImportExport\Traits\Import\Product\Type;

    private $relationsDataSaver;
    protected $resource;

    /**
     * @var bool
     */
    protected $importBundleByIds = true;

    public static $specialAttributes = [
        'price_type',
        'weight_type',
        'sku_type',
    ];

    /**
     * @var ImportFactory
     */
    protected $fireImportFactory;

    /** @var JobRepositoryInterface  */
    protected $importJobRepository;

    /**
     * Bundle constructor.
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory $attrSetColFac
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $prodAttrColFac
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param ImportFactory $fireImportFactory
     * @param JobRepositoryInterface $importJobRepository
     * @param array $params
     * @param \Magento\Framework\EntityManager\MetadataPool|null $metadataPool
     * @param RelationsDataSaver|null $relationsDataSaver
     */
    public function __construct(
        \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory $attrSetColFac,
        \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $prodAttrColFac,
        \Magento\Framework\App\ResourceConnection $resource,
        ImportFactory $fireImportFactory,
        JobRepositoryInterface $importJobRepository,
        array $params,
        \Magento\Framework\EntityManager\MetadataPool $metadataPool = null,
        RelationsDataSaver $relationsDataSaver = null
    ) {
        parent::__construct($attrSetColFac, $prodAttrColFac, $resource, $params, $metadataPool);

        $this->relationsDataSaver = $relationsDataSaver
            ?: ObjectManager::getInstance()->get(RelationsDataSaver::class);
        $this->resource = $resource;
        $this->fireImportFactory = $fireImportFactory;
        $this->importJobRepository = $importJobRepository;
    }

    /**
     * Insert selections.
     *
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function insertSelections()
    {
        $selections = [];

        foreach ($this->_cachedOptions as $productId => $options) {
            foreach ($options as $option) {
                $index = 0;
                foreach ($option['selections'] as $selection) {
                    if (isset($selection['position'])) {
                        $index = $selection['position'];
                    }
                    if (!isset($selection['can_change_qty']) && isset($selection['selection_can_change_qty'])) {
                        $selection['can_change_qty'] = $selection['selection_can_change_qty'];
                    }
                    if ($tmpArray = $this->populateSelectionTemplate(
                        $selection,
                        $option['option_id'],
                        $productId,
                        $index
                    )) {
                        $selections[] = $tmpArray;
                        $index++;
                    }
                }
            }
        }

        $selectedSelections = [];

        foreach ($selections as $key => $selection) {
            $productId = $selection['product_id'];
            if (isset($selectedSelections[$selection['option_id']])) {
                if (isset($selectedSelections[$selection['option_id']][$productId]) &&
                    $selectedSelections[$selection['option_id']][$productId] == $productId) {
                    unset($selections[$key]);
                } else {
                    $selectedSelections[$selection['option_id']][$productId] = $productId;
                }
            } else {
                $selectedSelections[$selection['option_id']][$productId] = $productId;
            }
        }

        $jobId = $this->fireImportFactory->create()->getFireDataSourceModel()->getJobId();
        $importJobData = $this->importJobRepository->getById($jobId);
        $sourceData = $importJobData->getSourceData();

        if (isset($sourceData['remove_bundle_product_association'])
            && $sourceData['remove_bundle_product_association'] == 1) {
            $this->removeOldSelections($selections);
            $this->removeOldOptions($selections);
        }

        if (version_compare($this->_entityModel->getProductMetadata()->getVersion(), '2.2.0', '>=')) {
            $this->relationsDataSaver->saveSelections($selections);
        } else {
            $selectionTable = $this->_resource->getTableName('catalog_product_bundle_selection');
            if (!empty($selections)) {
                $this->connection->insertOnDuplicate(
                    $selectionTable,
                    $selections,
                    [
                        'selection_id',
                        'product_id',
                        'position',
                        'is_default',
                        'selection_price_type',
                        'selection_price_value',
                        'selection_qty',
                        'selection_can_change_qty'
                    ]
                );
            }
        }
        $this->saveCatalogProductRelation($selections);
        return $this;
    }

    /**
     * Remove OldSelections
     *
     * @param [] $selections
     */
    protected function removeOldSelections($selections)
    {
        $selectionsByProductId = [];
        foreach ($selections as $selection) {
            $selectionsByProductId[$selection['parent_product_id']][] = $selection['product_id'];
        }

        foreach ($selectionsByProductId as $parentProductId => $childIds) {
            $selectionTable = $this->_resource->getTableName('catalog_product_bundle_selection');
            $select = $this->connection->select()->from(
                $selectionTable,
                ['product_id']
            )->where(
                'parent_product_id = ?',
                $parentProductId
            );
            $oldSelections = $this->connection->fetchCol($select);
            $delete = array_diff($oldSelections, $childIds);
            if (!empty($oldSelections) && !empty($delete)) {
                $where = implode(
                    ' AND ',
                    [
                        $this->connection->quoteInto('parent_product_id = ?', $parentProductId),
                        $this->connection->quoteInto('product_id IN(?)', $delete)
                    ]
                );
                $this->connection->delete($selectionTable, $where);
            }
        }
    }

    /**
     * Remove OldOptions
     *
     * @param [] $selections
     */
    protected function removeOldOptions($selections): void
    {
        $optionsByProductId = [];
        foreach ($selections as $selection) {
            $optionsByProductId[$selection['parent_product_id']][] = $selection['option_id'];
        }

        foreach ($optionsByProductId as $parentProductId => $childIds) {
            $optionsTable = $this->_resource->getTableName('catalog_product_bundle_option');
            $where = implode(
                ' AND ',
                [
                    $this->connection->quoteInto('parent_id = ?', $parentProductId),
                    $this->connection->quoteInto('option_id NOT IN(?)', $childIds)
                ]
            );
            $this->connection->delete($optionsTable, $where);
        }
    }

    /**
     * Insert data to catalog_product_relation table
     * Solve problem: bundle products always show out of stock in front-end
     */
    protected function saveCatalogProductRelation($selections)
    {
        if (!empty($selections)) {
            $catalogProductRelations = [];
            foreach ($selections as $selection) {
                $catalogProductRelations[] = [
                    'parent_id' => $selection['parent_product_id'],
                    'child_id' => $selection['product_id']
                ];
            }
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName('catalog_product_relation'),
                $catalogProductRelations,
                [
                    'parent_id',
                    'child_id',
                ]
            );
        }
    }

    /**
     * @return $this|ImportProduct\Type\AbstractType
     */
    public function saveData()
    {
        if ($this->_entityModel->getBehavior() == \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE) {
            $productIds = [];
            $newProducts = $this->_entityModel->getNewSku();
            while ($bunch = $this->_entityModel->getNextBunch()) {
                foreach ($bunch as $rowNum => $rowData) {
                    $productData = $newProducts[strtolower($rowData[ImportProduct::COL_SKU])];
                    $productIds[] = $productData[$this->getProductEntityLinkField()];
                }
                $this->deleteOptionsAndSelections($productIds);
            }
        } else {
            $newProducts = $this->_entityModel->getNewSku();
            while ($bunch = $this->_entityModel->getNextBunch()) {
                foreach ($bunch as $rowNum => $rowData) {
                    if (!$this->_entityModel->isRowAllowedToImport($rowData, $rowNum)) {
                        continue;
                    }
                    $productData = $newProducts[strtolower($rowData[ImportProduct::COL_SKU])];
                    if ($this->_type != $productData['type_id']) {
                        continue;
                    }
                    $this->parseSelections($rowData, $productData[$this->getProductEntityLinkField()]);
                }
                if (!empty($this->_cachedOptions)) {
                    $this->retrieveProductsByCachedSkus();
                    $this->populateExistingOptions();
                    $this->insertOptions();
                    $this->insertSelections();
                    $this->insertParentChildRelations();
                    $this->clear();
                }
            }
        }
        return $this;
    }

    /**
     * @return $this|ImportProduct\Type\AbstractType
     */
    private function insertParentChildRelations()
    {
        foreach ($this->_cachedOptions as $productId => $options) {
            $childIds = [];
            foreach ($options as $option) {
                foreach ($option['selections'] as $selection) {
                    if (!isset($this->_cachedSkuToProducts[$selection['sku']])) {
                        continue;
                    }
                    $childIds[] = $this->_cachedSkuToProducts[$selection['sku']];
                }
                $this->relationsDataSaver->saveProductRelations($productId, array_unique($childIds));
            }
        }

        return $this;
    }

    /**
     * Parse the option.
     *
     * @param array $values
     *
     * @return array
     */
    protected function parseOption($values)
    {
        $option = parent::parseOption($values);

        $select = $this->connection->select()->from(
            $this->_resource->getTableName('catalog_product_entity'),
            ['sku', 'entity_id']
        )->where(
            'sku = (?)',
            $option['sku']
        );

        $isSkuExists = $this->connection->fetchOne($select);
        if (!$isSkuExists) {
            unset($option['sku'], $option['name']);
        }

        return $option;
    }

    /**
     * Parse selections.
     *
     * @param array $rowData
     * @param int $entityId
     *
     * @return array
     */
    protected function parseSelections($rowData, $entityId)
    {
        if (empty($rowData['bundle_values'])) {
            $selections = [];
        } else {
            $rowData['bundle_values'] = str_replace(
                self::BEFORE_OPTION_VALUE_DELIMITER,
                $this->_entityModel->getMultipleValueSeparator(),
                $rowData['bundle_values']
            );
            $selections = explode(
                Product::PSEUDO_MULTI_LINE_SEPARATOR,
                $rowData['bundle_values']
            );
            foreach ($selections as $selection) {
                $values = explode($this->_entityModel->getMultipleValueSeparator(), $selection);
                $option = $this->parseOption($values);

                if (isset($option['sku']) && isset($option['option_id'])
                    && isset($option['selection_id'])) {
                    if (!isset($this->_cachedOptions[$entityId])) {
                        $this->_cachedOptions[$entityId] = [];
                    }
                    $this->_cachedSkus[] = $option['sku'];
                    if (!isset($this->_cachedOptions[$entityId][$option['option_id']])) {
                        $this->_cachedOptions[$entityId][$option['option_id']] = [];
                        $this->_cachedOptions[$entityId][$option['option_id']] = $option;
                        $this->_cachedOptions[$entityId][$option['option_id']]['selections'] = [];
                    }
                    $this->_cachedOptions[$entityId][$option['option_id']]['selections'][] = $option;
                    $this->_cachedOptionSelectQuery[] =
                        $this->connection->quoteInto(
                            '(parent_id = ' . (int)$entityId . ' AND bo.option_id = ?)',
                            $option['option_id']
                        );
                } elseif (isset($option['sku']) && isset($option['name'])) {
                    $this->importBundleByIds = false;
                    if (!isset($this->_cachedOptions[$entityId])) {
                        $this->_cachedOptions[$entityId] = [];
                    }
                    $this->_cachedSkus[] = $option['sku'];
                    if (!isset($this->_cachedOptions[$entityId][$option['name']])) {
                        $this->_cachedOptions[$entityId][$option['name']] = [];
                        $this->_cachedOptions[$entityId][$option['name']] = $option;
                        $this->_cachedOptions[$entityId][$option['name']]['selections'] = [];
                    }
                    $this->_cachedOptions[$entityId][$option['name']]['selections'][] = $option;
                    $this->_cachedOptionSelectQuery[] =
                        $this->connection->quoteInto(
                            '(parent_id = ' . (int)$entityId . ' AND title = ?)',
                            $option['name']
                        );
                }
            }
        }
        return $selections;
    }

    /**
     * @param array $rowData
     * @return array
     */
    protected function addAdditionalAttributes(array $rowData)
    {
        return [];
    }

    /**
     * Populates existing options.
     *
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function populateExistingOptions()
    {
        $existingOptions = $this->connection->fetchAssoc(
            $this->connection->select()->from(
                ['bo' => $this->_resource->getTableName('catalog_product_bundle_option')],
                ['option_id', 'parent_id', 'required', 'position', 'type']
            )->joinLeft(
                ['bov' => $this->_resource->getTableName('catalog_product_bundle_option_value')],
                'bo.option_id = bov.option_id',
                ['value_id', 'title']
            )->where(
                implode(' OR ', $this->_cachedOptionSelectQuery)
            )
        );

        if ($this->importBundleByIds) {
            foreach ($existingOptions as $optionId => $option) {
                $this->_cachedOptions[$option['parent_id']][$option['option_id']]['option_id'] = $optionId;
                foreach ($option as $key => $value) {
                    if (!isset($this->_cachedOptions[$option['parent_id']][$option['option_id']][$key])) {
                        $this->_cachedOptions[$option['parent_id']][$option['option_id']][$key] = $value;
                    }
                }
            }
        } else {
            foreach ($existingOptions as $optionId => $option) {
                $this->_cachedOptions[$option['parent_id']][$option['title']]['option_id'] = $optionId;
                foreach ($option as $key => $value) {
                    if (!isset($this->_cachedOptions[$option['parent_id']][$option['title']][$key])) {
                        $this->_cachedOptions[$option['parent_id']][$option['title']][$key] = $value;
                    }
                }
            }
        }

        $this->populateExistingSelections($existingOptions);
        return $this;
    }

    /**
     * Populate existing selections.
     *
     * @param array $existingOptions
     *
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function populateExistingSelections($existingOptions)
    {
        //@codingStandardsIgnoreStart
        $existingSelections = $this->connection->fetchAll(
            $this->connection->select()->from(
                $this->_resource->getTableName('catalog_product_bundle_selection')
            )->where(
                'option_id IN (?)',
                array_keys($existingOptions)
            )
        );

        if ($this->importBundleByIds) {
            foreach ($existingSelections as $existingSelection) {
                $optionId = $existingSelection['option_id'];
                $cachedOptionsSelections = $this->_cachedOptions[$existingSelection['parent_product_id']][$optionId]['selections'];
                foreach ($cachedOptionsSelections as $selectIndex => $selection) {
                    $productId = $this->_cachedSkuToProducts[$selection['sku']];
                    if ($productId == $existingSelection['product_id']) {
                        foreach (array_keys($existingSelection) as $origKey) {
                            $key = isset($this->_bundleFieldMapping[$origKey])
                                ? $this->_bundleFieldMapping[$origKey]
                                : $origKey;
                            if (
                            !isset($this->_cachedOptions[$existingSelection['parent_product_id']][$optionId]['selections'][$selectIndex][$key])
                            ) {
                                $this->_cachedOptions[$existingSelection['parent_product_id']][$optionId]['selections'][$selectIndex][$key] =
                                    $existingSelection[$origKey];
                            }
                        }
                        break;
                    }
                }
            }
        } else {
            foreach ($existingSelections as $existingSelection) {
                $optionTitle = $existingOptions[$existingSelection['option_id']]['title'];
                $cachedOptionsSelections = $this->_cachedOptions[$existingSelection['parent_product_id']][$optionTitle]['selections'];
                foreach ($cachedOptionsSelections as $selectIndex => $selection) {
                    $productId = $this->_cachedSkuToProducts[$selection['sku']];
                    if ($productId == $existingSelection['product_id']) {
                        foreach (array_keys($existingSelection) as $origKey) {
                            $key = isset($this->_bundleFieldMapping[$origKey])
                                ? $this->_bundleFieldMapping[$origKey]
                                : $origKey;
                            if (
                            !isset($this->_cachedOptions[$existingSelection['parent_product_id']][$optionTitle]['selections'][$selectIndex][$key])
                            ) {
                                $this->_cachedOptions[$existingSelection['parent_product_id']][$optionTitle]['selections'][$selectIndex][$key] =
                                    $existingSelection[$origKey];
                            }
                        }
                        break;
                    }
                }
            }
        }

        // @codingStandardsIgnoreEnd
        return $this;
    }

    /**
     * @param array $optionIds
     * @return array
     */
    protected function populateInsertOptionValues(array $optionIds): array
    {
        $optionValues = [];

        if ($this->importBundleByIds) {
            foreach ($this->_cachedOptions as $entityId => $options) {
                foreach ($options as $key => $option) {
                    foreach ($optionIds as $optionId => $assoc) {
                        if ($assoc['position'] == $this->_cachedOptions[$entityId][$key]['index']
                            && $assoc['parent_id'] == $entityId
                            && $assoc['option_id'] == $option['option_id']) {
                            $option['parent_id'] = $entityId;
                            $optionValues[] = $this->populateOptionValueTemplate($option, $optionId);
                            $this->_cachedOptions[$entityId][$key]['option_id'] = $optionId;
                            break;
                        }
                    }
                }
            }
        } else {
            foreach ($this->_cachedOptions as $entityId => $options) {
                foreach ($options as $key => $option) {
                    foreach ($optionIds as $optionId => $assoc) {
                        if ($assoc['position'] == $this->_cachedOptions[$entityId][$key]['index'] &&
                            $assoc['parent_id'] == $entityId &&
                            (empty($assoc['title']) || $assoc['title']
                                == $this->_cachedOptions[$entityId][$key]['name'])
                        ) {
                            $option['parent_id'] = $entityId;
                            $optionValues[] = $this->populateOptionValueTemplate($option, $optionId);
                            $this->_cachedOptions[$entityId][$key]['option_id'] = $optionId;
                            break;
                        }
                    }
                }
            }
        }

        return array_merge([], ...$optionValues);
    }
}
