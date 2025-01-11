<?php
/**
 * @copyright: Copyright © 2018 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Import\Product;

use Exception;
use Firebear\ImportExport\Model\ResourceModel\Import\Data as ImportData;
use Firebear\ImportExport\Traits\General;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Data as CatalogHelperData;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\Option\CollectionFactory as ProductOptionCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Option\Value\Collection as ProductOptionValueCollection;
use Magento\Catalog\Model\ResourceModel\Product\Option\Value\CollectionFactory as ProductOptionValueCollectionFactory;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\Option as BaseOption;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory;
use Magento\ImportExport\Model\ResourceModel\Helper as ResourceHelper;
use Magento\Store\Model\StoreManagerInterface;
use Zend_Db_Statement_Exception;
use Magento\Store\Model\Store;
use Symfony\Component\Console\Output\ConsoleOutput;
use Firebear\ImportExport\Logger\Logger;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\App\CacheInterface;

/**
 * Class Option
 *
 * @package Firebear\ImportExport\Model\Import\Product
 */
class Option extends BaseOption
{
    use General;

    const COLUMN_ID = 'opt_id';
    const COLUMN_ROW_ID = 'opt_row_id';
    const CACHE_TAG = 'import_data_product_options';

    /**
     * Product entity link field
     *
     * @var string
     */
    private $productEntityLinkField;

    /**
     * @var ProductOptionValueCollectionFactory
     */
    private $productOptionValueCollectionFactory;

    /**
     * @var array
     */
    private $optionTypeTitles;

    /**
     * @var array
     */
    private $_invalidRows;

    /**
     * @var array
     */
    private $lastOptionTitle;

    /**
     * @var array
     */
    private $initCustomOptionsByProductIdsCache = [];

    /**
     * @var array
     */
    private $optionTitles;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * List of specific custom option types
     *
     * @var array
     */
    protected $_specificTypes = [
        'date' => ['price', 'sku'],
        'date_time' => ['price', 'sku'],
        'time' => ['price', 'sku'],
        'field' => ['price', 'sku', 'max_characters'],
        'area' => ['price', 'sku', 'max_characters'],
        'drop_down' => true,
        'radio' => true,
        'checkbox' => true,
        'multiple' => true,
        'file' => ['price','sku', 'file_extension', 'image_size_x', 'image_size_y'],
    ];

    /**
     * @var array
     */
    protected $optionCachedData;

    /**
     * Option constructor.
     *
     * @param ImportData $importData
     * @param ResourceConnection $resource
     * @param ResourceHelper $resourceHelper
     * @param StoreManagerInterface $storeManager
     * @param ProductFactory $productFactory
     * @param ProductOptionCollectionFactory $optionColFactory
     * @param CollectionByPagesIteratorFactory $colIteratorFactory
     * @param CatalogHelperData $catalogData
     * @param ScopeConfigInterface $scopeConfig
     * @param TimezoneInterface $dateTime
     * @param ProcessingErrorAggregatorInterface $errorAggregator
     * @param ProductOptionValueCollectionFactory $productOptionValueCollectionFactory
     * @param ConsoleOutput $output
     * @param Logger $logger
     * @param SerializerInterface $serializer
     * @param CacheInterface $cache
     * @param array $data
     * @throws LocalizedException
     */
    public function __construct(
        ImportData $importData,
        ResourceConnection $resource,
        ResourceHelper $resourceHelper,
        StoreManagerInterface $storeManager,
        ProductFactory $productFactory,
        ProductOptionCollectionFactory $optionColFactory,
        CollectionByPagesIteratorFactory $colIteratorFactory,
        CatalogHelperData $catalogData,
        ScopeConfigInterface $scopeConfig,
        TimezoneInterface $dateTime,
        ProcessingErrorAggregatorInterface $errorAggregator,
        ProductOptionValueCollectionFactory $productOptionValueCollectionFactory,
        ConsoleOutput $output,
        Logger $logger,
        SerializerInterface $serializer,
        CacheInterface $cache,
        array $data = []
    ) {
        parent::__construct(
            $importData,
            $resource,
            $resourceHelper,
            $storeManager,
            $productFactory,
            $optionColFactory,
            $colIteratorFactory,
            $catalogData,
            $scopeConfig,
            $dateTime,
            $errorAggregator,
            $data
        );
        $this->productOptionValueCollectionFactory = $productOptionValueCollectionFactory;
        $this->output = $output;
        $this->_logger = $logger;
        $this->serializer = $serializer;
        $this->cache = $cache;
    }

    public function validateRow(array $rowData, $rowNumber)
    {
        if (isset($this->_validatedRows[$rowNumber])) {
            return !isset($this->_invalidRows[$rowNumber]);
        }
        $this->_validatedRows[$rowNumber] = true;

        $multiRowData = $this->_getMultiRowFormat($rowData);

        foreach ($multiRowData as $optionData) {
            $combinedData = array_merge($rowData, $optionData);

            if ($this->_isRowWithCustomOption($combinedData)) {
                if ($this->_isMainOptionRow($combinedData)) {
                    if (!$this->_validateMainRow($combinedData, $rowNumber)) {
                        return false;
                    }
                }
                if ($this->_isSecondaryOptionRow($combinedData)) {
                    if (!$this->_validateSecondaryRow($combinedData, $rowNumber)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @return bool
     * @throws LocalizedException
     * @throws Zend_Db_Statement_Exception
     * @throws Exception
     */
    protected function _importData()
    {
        $this->_initProductsSku();

        $nextOptionId = $this->_resourceHelper->getNextAutoincrement(
            $this->_tables['catalog_product_option']
        );
        $nextValueId = $this->_resourceHelper->getNextAutoincrement(
            $this->_tables['catalog_product_option_type_value']
        );
        $prevOptionId = 0;
        $optionId = null;
        $valueId = null;
        $title = null;
        $prevRowSku = null;

        $this->optionCachedData = [];
        $optionCachedData = $this->cache->load('option_data');
        if (!empty($optionCachedData)) {
            $this->optionCachedData = $this->serializer->unserialize($optionCachedData);
        }

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $products = [];
            $options = [];
            $titles = [];
            $prices = [];
            $typeValues = [];
            $typePrices = [];
            $typeTitles = [];
            $parentCount = [];
            $childCount = [];
            $optionsToRemove = [];

            foreach ($bunch as $rowNumber => $rowData) {
                $customOptionsForAllStores = $this->checkCustomOptionsForAllStores($bunch);

                $rowSku = !empty($rowData[self::COLUMN_SKU])
                    ? mb_strtolower($rowData[self::COLUMN_SKU])
                    : '';

                if ($rowSku !== $prevRowSku) {
                    $nextOptionId = $optionId ?? $nextOptionId;
                    $nextValueId = $valueId ?? $nextValueId;
                    $prevRowSku = $rowSku;
                }

                $optionId = $nextOptionId;
                $valueId = $nextValueId;
                $multiRowData = $this->_getMultiRowFormat($rowData);
                if (!empty($rowData[self::COLUMN_SKU]) && isset($this->_productsSkuToId[$rowData[self::COLUMN_SKU]])) {
                    $this->_rowProductId = $this->_productsSkuToId[$rowData[self::COLUMN_SKU]];
                    if (array_key_exists('custom_options', $rowData)
                        && (
                            trim($rowData['custom_options'] ?? '') === '' ||
                            trim($rowData['custom_options'] ?? '') ===
                            $this->_productEntity->getEmptyAttributeValueConstant()
                        )
                    ) {
                        $optionsToRemove[] = $this->_rowProductId;
                    }
                }
                $optionIncrementId = 1;
                $isMultiRowStoreChanged = null;

                foreach ($multiRowData as $optionData) {

                    $combinedData = array_merge($rowData, $optionData);

                    if (!$this->isRowAllowedToImport($combinedData, $rowNumber)) {
                        continue;
                    }

                    if (!$this->_parseRequiredData($combinedData)) {
                        continue;
                    }

                    if (!empty($optionData[self::COLUMN_TITLE])) {
                        $title = $optionData[self::COLUMN_TITLE];
                    }

                    $rowTitle = $optionData[self::COLUMN_ROW_TITLE] ?? null;

                    if (isset($this->_specificTypes[$this->_rowType])
                        && is_array($this->_specificTypes[$this->_rowType])
                        && empty($rowTitle)) {
                        $rowTitle = $title;
                    }

                    if ((!$customOptionsForAllStores && !isset($this->optionCachedData[$rowData['sku']]))
                        || $isMultiRowStoreChanged) {
                        if ($title && $rowTitle
                            && empty($this->optionTitles[$this->_rowProductId][$title][$rowTitle])) {
                            $combinedData[Product::COL_STORE_VIEW_CODE] = null;
                            $combinedData[self::COLUMN_STORE] = Store::DEFAULT_STORE_ID;
                            $this->_rowStoreId = Store::DEFAULT_STORE_ID;
                            $this->optionTitles[$this->_rowProductId][$title][$rowTitle] = true;
                            $isMultiRowStoreChanged = true;
                        }
                    }

                    $optionData = $this->_collectOptionMainData(
                        $combinedData,
                        $prevOptionId,
                        $optionId,
                        $products,
                        $prices
                    );

                    $this->_collectOptionTypeData(
                        $combinedData,
                        $prevOptionId,
                        $valueId,
                        $typeValues,
                        $typePrices,
                        $typeTitles,
                        $parentCount,
                        $childCount
                    );
                    $this->_collectOptionTitle($combinedData, $prevOptionId, $titles);

                    if (isset($titles[$prevOptionId][Store::DEFAULT_STORE_ID])) {
                        $this->optionCachedData[$rowData['sku']][$optionIncrementId] = [
                            'option_id' => $prevOptionId,
                            'title' => $titles[$prevOptionId][Store::DEFAULT_STORE_ID]
                        ];
                    }

                    if ($optionData != null) {
                        $optionData['increment_id'] = $optionIncrementId;
                        $optionIncrementId++;
                        $options[] = $optionData;
                    }
                }
            }

            // Remove all existing options if import behaviour is APPEND
            // in other case remove options for products with empty "custom_options" row only
            if ($this->getBehavior() != Import::BEHAVIOR_APPEND) {
                $this->_deleteEntities(array_keys($products));
            } elseif (!empty($optionsToRemove)) {
                // Remove options for products with empty "custom_options" row
                $this->_deleteEntities($optionsToRemove);
            }

            // Save prepared custom options data
            if ($this->_isReadyForSaving($options, $titles, $typeValues)) {
                $types = [
                    'values' => $typeValues,
                    'prices' => $typePrices,
                    'titles' => $typeTitles
                ];

                $uniqueOptions = [];
                foreach ($options as $key => $option) {
                    if (!isset($uniqueOptions[$option['option_id']])) {
                        $uniqueOptions[$option['option_id']] = $option['option_id'];
                    } else {
                        unset($options[$key]);
                    }
                }

                $this->savePreparedCustomOptions($products, $options, $titles, $prices, $types);
            }
        }

        $this->cache->save(
            $this->serializer->serialize($this->optionCachedData),
            'option_data',
            [self::CACHE_TAG]
        );

        return true;
    }

    /**
     * @param $bunch
     * @return bool
     */
    protected function checkCustomOptionsForAllStores($bunch)
    {
        foreach ($bunch as $rowNumber => $rowData) {
            if (empty($rowData[PRODUCT::COL_STORE_VIEW_CODE])
                && !empty($rowData['custom_options'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieve option data
     *
     * @param array $rowData
     * @param int $productId
     * @param int $optionId
     * @param string $type
     * @return array
     */
    protected function _getOptionData(array $rowData, $productId, $optionId, $type)
    {
        $optionData = [
            'option_id' => $optionId,
            'sku' => '',
            'max_characters' => 0,
            'file_extension' => null,
            'image_size_x' => 0,
            'image_size_y' => 0,
            'product_id' => $productId,
            'type' => $type,
            'is_require' => empty($rowData[self::COLUMN_IS_REQUIRED]) ? 0 : 1,
            'sort_order' => empty($rowData[self::COLUMN_SORT_ORDER]) ? 0 : abs($rowData[self::COLUMN_SORT_ORDER]),
        ];
        if (!empty($rowData[self::COLUMN_ID])) {
            $optionData[self::COLUMN_ID] = $rowData[self::COLUMN_ID];
            $optionData['option_id'] = $rowData[self::COLUMN_ID];
        }
        if (!$this->_isRowHasSpecificType($type)) {
            // simple option may have optional params
            foreach ($this->_specificTypes[$type] as $paramSuffix) {
                if (isset($rowData[self::COLUMN_PREFIX . $paramSuffix])) {
                    $data = $rowData[self::COLUMN_PREFIX . $paramSuffix];

                    if (array_key_exists($paramSuffix, $optionData)) {
                        $optionData[$paramSuffix] = $data;
                    }
                }
            }
        }
        return $optionData;
    }

    /**
     * Checks that option exists in DB
     *
     * @param array $newOptionData
     * @param array $newOptionTitles
     * @return bool|int
     */
    protected function _findExistingOptionId(array $newOptionData, array $newOptionTitles)
    {
        $productId = $newOptionData['product_id'];

        $productSku = '';
        foreach ($this->_productsSkuToId as $sku => $id) {
            if ($productId == $id) {
                $productSku = $sku;
            }
        }
        if (isset($this->_oldCustomOptions[$productId])) {
            ksort($newOptionTitles);
            $existingOptions = $this->_oldCustomOptions[$productId];
            if (!empty($newOptionData[self::COLUMN_ID]) && !empty($this->_parameters['include_option_id'])) {
                if (isset($existingOptions[$newOptionData[self::COLUMN_ID]])) {
                    return $newOptionData[self::COLUMN_ID];
                }
                $this->addLogWriteln(
                    __("Custom option id = %1 doesn't exist.", $newOptionData[self::COLUMN_ID]),
                    $this->output,
                    'error'
                );
                return false;
            }

            foreach ($existingOptions as $optionId => $optionData) {
                if ($optionData['type'] == $newOptionData['type']
                    && isset($this->optionCachedData[$productSku][$newOptionData['increment_id']])
                    && isset($optionData['titles'][Store::DEFAULT_STORE_ID])
                    && $optionData['titles'][Store::DEFAULT_STORE_ID]
                    == $this->optionCachedData[$productSku][$newOptionData['increment_id']]['title']
                ) {
                    return $optionId;
                }
            }
        } elseif (isset($this->optionCachedData[$productSku][$newOptionData['increment_id']])) {
            return $this->optionCachedData[$productSku][$newOptionData['increment_id']]['option_id'];
        }

        return false;
    }

    /**
     * Get multiRow format from one line data.
     *
     * @param array $rowData
     * @return array
     */
    protected function _getMultiRowFormat($rowData)
    {
        $proceed = parent::_getMultiRowFormat($rowData);
        if (!$proceed) {
            return $proceed;
        }
        $options = $this->_parseCustomOptions($rowData)['custom_options'];
        $columnTitle = '';
        foreach ($proceed as &$item) {
            if (!empty($item[self::COLUMN_TITLE])) {
                $columnTitle = $item[self::COLUMN_TITLE];
            }
            if (empty($options[$columnTitle])) {
                continue;
            }
            $isRowId = !empty(current($options[$columnTitle])[self::COLUMN_ID]);
            if (!empty($options[$columnTitle]) && $isRowId && !empty($this->_parameters['include_option_id'])) {
                $item[self::COLUMN_ID] = current($options[$columnTitle])[self::COLUMN_ID];
            }
            if (!empty($item[self::COLUMN_ROW_TITLE]) && $isRowId && !empty($options[$columnTitle])
                && !empty($this->_parameters['include_option_id'])) {
                foreach ($options[$columnTitle] as $option) {
                    if (empty($option[self::COLUMN_ROW_ID])) {
                        continue;
                    }
                    if ($option['option_title'] == $item[self::COLUMN_ROW_TITLE]) {
                        $item[self::COLUMN_ROW_ID] = $option[self::COLUMN_ROW_ID];
                        break;
                    }
                }
            }
        }
        return $proceed;
    }

    /**
     * @return $this|BaseOption
     * @throws Exception
     */
    protected function _initProductsSku()
    {
        if (!$this->_productsSkuToId) {
            $select = $this->_connection->select()->from(
                $this->_tables['catalog_product_entity'],
                [ProductInterface::SKU, $this->getProductEntityLinkField()]
            );
            $this->_productsSkuToId = $this->_connection->fetchPairs($select);
        }

        return $this;
    }

    /**
     * Add new imported products to existed products
     *
     * @param $entityId
     * @param $sku
     *
     * @return $this
     */
    public function addNewSkuToId($entityId, $sku)
    {
        $this->_productsSkuToId[$sku] = $entityId;
        return $this;
    }

    /**
     * @return $this|BaseOption
     */
    protected function _initOldCustomOptions()
    {
        if (!$this->_oldCustomOptions) {
            $this->_oldCustomOptions = [];
        }
        return $this;
    }

    /**
     * Initialize Custom Options By Product Identifiers
     *
     * @param array $productIds
     * @return $this
     * @throws Zend_Db_Statement_Exception
     */
    protected function initCustomOptionsByProductIds($productIds)
    {
        $productsIdsHash = hash('md5', json_encode($productIds));
        foreach ($this->_storeCodeToId as $storeId) {
            if (empty($this->initCustomOptionsByProductIdsCache[$storeId][$productsIdsHash])) {
                $select = $this->_connection->select()
                    ->from(
                        ['option' => $this->_tables['catalog_product_option']],
                        ['option_id', 'product_id', 'type']
                    )
                    ->join(
                        ['option_title' => $this->_tables['catalog_product_option_title']],
                        'option_title.option_id = option.option_id',
                        ['title']
                    )
                    ->where(
                        'option_title.store_id = ?',
                        $storeId
                    )->where(
                        'option.product_id IN (?)',
                        $productIds
                    );
                $this->initCustomOptionsByProductIdsCache[$storeId][$productsIdsHash] =
                    $this->_connection->fetchAll($select);
            }

            foreach ($this->initCustomOptionsByProductIdsCache[$storeId][$productsIdsHash] as $row) {
                $optionId = $row['option_id'];
                $productId = $row['product_id'];
                $type = $row['type'];
                $title = $row['title'];

                if (!isset($this->_oldCustomOptions[$productId])) {
                    $this->_oldCustomOptions[$productId] = [];
                }
                if (isset($this->_oldCustomOptions[$productId][$optionId])) {
                    $this->_oldCustomOptions[$productId][$optionId]['titles'][$storeId] = $title;
                } else {
                    $this->_oldCustomOptions[$productId][$optionId] = [
                        'titles' => [$storeId => $title],
                        'type' => $type,
                    ];
                }
            }
        }

        return $this;
    }

    /**
     * @param array $options
     * @param array $titles
     * @param array $prices
     * @param array $typeValues
     * @return $this|BaseOption
     * @throws Zend_Db_Statement_Exception
     */
    protected function _compareOptionsWithExisting(array &$options, array &$titles, array &$prices, array &$typeValues)
    {
        $productIds = [];
        foreach ($options as $option) {
            $productIds[] = $option['product_id'];
        }
        $this->initCustomOptionsByProductIds($productIds);
        foreach ($options as &$optionData) {
            $newOptionId = $optionData['option_id'];
            $optionId = $this->_findExistingOptionId($optionData, $titles[$newOptionId]);
            unset($optionData['increment_id']);
            if ($optionId && $optionId != $newOptionId) {
                $optionData['option_id'] = $optionId;
                if (isset($titles[$optionId])) {
                    $titles[$optionId] = array_replace($titles[$optionId], $titles[$newOptionId]);
                } else {
                    $titles[$optionId] = $titles[$newOptionId];
                }
                unset($titles[$newOptionId]);
                if (isset($prices[$newOptionId])) {
                    foreach ($prices[$newOptionId] as $storeId => $priceStoreData) {
                        $prices[$newOptionId][$storeId]['option_id'] = $optionId;
                        $priceStoreData['option_id'] = $optionId;
                        $prices[$optionId][$storeId] = $priceStoreData;
                    }
                }
                if (isset($typeValues[$newOptionId]) && $newOptionId != $optionId) {
                    $typeValues[$optionId] = $typeValues[$newOptionId];
                    unset($typeValues[$newOptionId]);
                }
            }
        }
        return $this;
    }

    /**
     * @return array
     * @throws Zend_Db_Statement_Exception
     */
    protected function _findNewOldOptionsTypeMismatch()
    {
        $this->initCustomOptionsByProductIds(array_keys($this->_newOptionsOldData));

        return parent::_findNewOldOptionsTypeMismatch();
    }

    /**
     * @return array
     * @throws Zend_Db_Statement_Exception
     */
    protected function _findOldOptionsWithTheSameTitles()
    {
        $this->initCustomOptionsByProductIds(array_keys($this->_newOptionsOldData));

        return parent::_findOldOptionsWithTheSameTitles();
    }

    /**
     * @param array $rowData
     * @param int $optionTypeId
     * @param bool $defaultStore
     * @return array|bool|false
     */
    protected function _getSpecificTypeData(array $rowData, $optionTypeId, $defaultStore = true)
    {
        if (!empty($rowData[self::COLUMN_ROW_TITLE]) && $defaultStore && empty($rowData[self::COLUMN_STORE])) {
            $valueData = [
                'option_type_id' => $optionTypeId,
                'sort_order' => empty($rowData[self::COLUMN_ROW_SORT]) ? 0 : abs($rowData[self::COLUMN_ROW_SORT]),
                'sku' => !empty($rowData[self::COLUMN_ROW_SKU]) ? $rowData[self::COLUMN_ROW_SKU] : '',
            ];
            if (!empty($rowData[self::COLUMN_ROW_ID]) && !empty($this->_parameters['include_option_id'])) {
                $valueData[self::COLUMN_ROW_ID] = $rowData[self::COLUMN_ROW_ID];
                $valueData['option_type_id'] = $rowData[self::COLUMN_ROW_ID];
            }
            if (!empty($rowData[self::COLUMN_ROW_PRICE])) {
                $priceData = [
                    'price' => (double)rtrim($rowData[self::COLUMN_ROW_PRICE], '%'),
                    'price_type' => 'fixed',
                ];
                if ('%' == substr($rowData[self::COLUMN_ROW_PRICE], -1)) {
                    $priceData['price_type'] = 'percent';
                }
            } else {
                $priceData = [
                    'price' => 0,
                    'price_type' => 'fixed'
                ];
            }

            return [
                'value' => $valueData,
                'title' => $rowData[self::COLUMN_ROW_TITLE],
                'price' => $priceData
            ];
        } elseif (!empty($rowData[self::COLUMN_ROW_TITLE]) && !$defaultStore && !empty($rowData[self::COLUMN_STORE])) {
            $data = [
                'title' => $rowData[self::COLUMN_ROW_TITLE]
            ];
            if (!empty($rowData[self::COLUMN_ROW_PRICE])) {
                $data['price'] = [
                    'price' => $rowData[self::COLUMN_ROW_PRICE],
                    'price_type' => !empty($rowData[self::COLUMN_ROW_PRICE])
                        ? $rowData[self::COLUMN_ROW_PRICE] : 'fixed'
                ];
            }
            return $data;
        }

        return false;
    }

    /**
     * Collect custom option title to import
     *
     * @param array  $rowData
     * @param int    $prevOptionId
     * @param array &$titles
     * @return void
     */
    protected function _collectOptionTitle(array $rowData, $prevOptionId, array &$titles)
    {
        $defaultStoreId = Store::DEFAULT_STORE_ID;
        $currentOptionId = $rowData[self::COLUMN_ID] ?? $prevOptionId;
        if (!empty($rowData[self::COLUMN_TITLE])) {
            if (!isset($titles[$currentOptionId][$defaultStoreId])) {
                if (isset($this->lastOptionTitle[$currentOptionId])) {
                    $titles[$currentOptionId] = $this->lastOptionTitle[$currentOptionId];
                    unset($this->lastOptionTitle);
                }
            }
            $titles[$currentOptionId][$this->_rowStoreId] = $rowData[self::COLUMN_TITLE];
        }
    }

    /**
     * Collect custom option main data to import
     *
     * @param array  $rowData
     * @param int   &$prevOptionId
     * @param int   &$nextOptionId
     * @param array &$products
     * @param array &$prices
     * @return array|null
     */
    protected function _collectOptionMainData(
        array $rowData,
        &$prevOptionId,
        &$nextOptionId,
        array &$products,
        array &$prices
    ) {
        $optionData = null;

        if ($this->_rowIsMain) {
            $optionData = $this->_getOptionData($rowData, $this->_rowProductId, $nextOptionId, $this->_rowType);

            $currentOptionId = $optionData[self::COLUMN_ID] ?? $nextOptionId;
            if (!$this->_isRowHasSpecificType($this->_rowType)
                && ($priceData = $this->_getPriceData($rowData, $currentOptionId, $this->_rowType))
            ) {
                if ($this->_isPriceGlobal) {
                    $priceData['store_id'] = Store::DEFAULT_STORE_ID;
                    $prices[$currentOptionId][Store::DEFAULT_STORE_ID] = $priceData;
                } else {
                    $prices[$currentOptionId][$this->_rowStoreId] = $priceData;
                }
            }

            if (!isset($products[$this->_rowProductId])) {
                $products[$this->_rowProductId] = $this->_getProductData($rowData, $this->_rowProductId);
            }
            $prevOptionId = $nextOptionId++;
        }

        return $optionData;
    }

    /**
     * Collect custom option type data to import
     *
     * @param array  $rowData
     * @param int   &$prevOptionId
     * @param int   &$nextValueId
     * @param array &$typeValues
     * @param array &$typePrices
     * @param array &$typeTitles
     * @param array &$parentCount
     * @param array &$childCount
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _collectOptionTypeData(
        array $rowData,
        &$prevOptionId,
        &$nextValueId,
        array &$typeValues,
        array &$typePrices,
        array &$typeTitles,
        array &$parentCount,
        array &$childCount
    ) {

        $currentValueId = null;

        if ($this->_isRowHasSpecificType($this->_rowType) && $prevOptionId) {
            $specificTypeData = $this->_getSpecificTypeData($rowData, $nextValueId);
            //For default store
            if ($specificTypeData) {
                $currentOptionId = $rowData[self::COLUMN_ID] ?? $prevOptionId;
                $typeValues[$currentOptionId][] = $specificTypeData['value'];
                $currentValueId = $specificTypeData['value'][self::COLUMN_ROW_ID] ?? $nextValueId;
                if (!isset($typeTitles[$currentValueId][Store::DEFAULT_STORE_ID])) {
                    $typeTitles[$currentValueId][Store::DEFAULT_STORE_ID] = $specificTypeData['title'];
                }
                if ($specificTypeData['price']) {
                    if ($this->_isPriceGlobal) {
                        $typePrices[$currentValueId][Store::DEFAULT_STORE_ID] = $specificTypeData['price'];
                    } else {
                        if (!isset($typePrices[$currentValueId][Store::DEFAULT_STORE_ID])) {
                            $typePrices[$currentValueId][Store::DEFAULT_STORE_ID] = $specificTypeData['price'];
                        }
                        $typePrices[$currentValueId][$this->_rowStoreId] = $specificTypeData['price'];
                    }
                }
                if ($nextValueId == $currentValueId) {
                    $nextValueId++;
                }
            }
            $specificTypeData = $this->_getSpecificTypeData($rowData, 0, false);
            //For others stores
            if ($specificTypeData) {
                if (!empty($rowData[self::COLUMN_ROW_ID]) && !empty($this->_parameters['include_option_id'])) {
                    $currentValueId = $rowData[self::COLUMN_ROW_ID];
                }
                if ($currentValueId) {
                    $valueId = $currentValueId;
                } else {
                    $valueId = $nextValueId++;
                }
                if (isset($specificTypeData['price'])) {
                    $typePrices[$valueId][$this->_rowStoreId] = $specificTypeData['price'];
                }
                $typeTitles[$valueId][$this->_rowStoreId] = $specificTypeData['title'];
            }
        }
    }

    /**
     * @param $products
     * @throws Zend_Db_Statement_Exception
     */
    protected function prepareExistingOptionTypeIds($products)
    {
        $productIds = array_keys($products);
        foreach ($this->_storeCodeToId as $storeId) {
            if (!isset($this->optionTypeTitles[$storeId])) {
                /** @var ProductOptionValueCollection $optionTypeCollection */
                $optionTypeCollection = $this->productOptionValueCollectionFactory->create();
                $optionTable = $optionTypeCollection->getTable('catalog_product_option');
                $optionTypeCollection->addTitleToResult($storeId);
                $optionTypeCollection->getSelect()
                    ->joinLeft(
                        ['product_option' => $optionTable],
                        'product_option.option_id = main_table.option_id',
                        ['product_id' => 'product_id']
                    )->where(
                        'product_id IN (?)',
                        $productIds
                    );

                $stmt = $this->_connection->query($optionTypeCollection->getSelect());
                while ($row = $stmt->fetch()) {
                    $this->optionTypeTitles[$storeId][$row['option_id']][$row['option_type_id']] = $row['title'];
                }
            }
        }
    }

    /**
     * Restore original IDs for existing option types.
     *
     * Warning: arguments are modified by reference
     *
     * @param array $typeValues
     * @param array $typePrices
     * @param array $typeTitles
     * @return void
     */
    private function restoreOriginalOptionTypeIds(array &$typeValues, array &$typePrices, array &$typeTitles)
    {
        $includeOptionId = !empty($this->_parameters['include_option_id']);
        foreach ($typeValues as $optionId => &$optionTypes) {
            foreach ($optionTypes as &$optionType) {
                $optionTypeId = $optionType['option_type_id'];
                foreach ($typeTitles[$optionTypeId] as $storeId => $optionTypeTitle) {
                    $existingTypeId = null;
                    if (!empty($optionType[self::COLUMN_ROW_ID]) && $includeOptionId) {
                        $existingTypeId = $this->getExistingOptionTypeIdByOldTypeId(
                            $optionId,
                            $storeId,
                            $optionType[self::COLUMN_ROW_ID]
                        );
                    }
                    if (!$existingTypeId) {
                        $existingTypeId = $this->getExistingOptionTypeId($optionId, $storeId, $optionTypeTitle);
                    }
                    if ($existingTypeId) {
                        $optionType['option_type_id'] = $existingTypeId;
                        $typeTitles[$existingTypeId] = $typeTitles[$optionTypeId];
                        $typePrices[$existingTypeId] = $typePrices[$optionTypeId];
                        if (!$includeOptionId || $existingTypeId !== $optionTypeId) {
                            unset($typeTitles[$optionTypeId]);
                            unset($typePrices[$optionTypeId]);
                        }
                        break;
                    }
                }
            }
        }
    }

    /**
     * Identify ID of the provided option type by its title in the specified store.
     *
     * @param int $optionId
     * @param int $storeId
     * @param string $optionTypeTitle
     * @return int|null
     */
    private function getExistingOptionTypeId($optionId, $storeId, $optionTypeTitle)
    {
        if (isset($this->optionTypeTitles[$storeId][$optionId])
            && is_array($this->optionTypeTitles[$storeId][$optionId])
        ) {
            foreach ($this->optionTypeTitles[$storeId][$optionId] as $optionTypeId => $currentTypeTitle) {
                if ($optionTypeTitle === $currentTypeTitle) {
                    return $optionTypeId;
                }
            }
        }

        return null;
    }

    /**
     * @param $optionId
     * @param $storeId
     * @param $optRowId
     * @return int|null
     */
    protected function getExistingOptionTypeIdByOldTypeId($optionId, $storeId, $optRowId)
    {
        if (isset($this->optionTypeTitles[$storeId][$optionId])
            && is_array($this->optionTypeTitles[$storeId][$optionId])
        ) {
            if (in_array($optRowId, array_keys($this->optionTypeTitles[$storeId][$optionId]))) {
                return $optRowId;
            }
        }
        return null;
    }

    /**
     * Get product entity link field
     *
     * @return string
     * @throws Exception
     */
    private function getProductEntityLinkField()
    {
        if (!$this->productEntityLinkField) {
            $this->productEntityLinkField = $this->getMetadataPool()
                ->getMetadata(ProductInterface::class)
                ->getLinkField();
        }

        return $this->productEntityLinkField;
    }

    /**
     * Save prepared custom options
     *
     * @param array $products
     * @param array $options
     * @param array $titles
     * @param array $prices
     * @param array $types
     * @return void
     * @throws Zend_Db_Statement_Exception
     */
    private function savePreparedCustomOptions(
        array $products,
        array $options,
        array $titles,
        array $prices,
        array $types
    ) {
        if ($this->getBehavior() == Import::BEHAVIOR_APPEND) {
            $this->_compareOptionsWithExisting($options, $titles, $prices, $types['values']);
            $this->prepareExistingOptionTypeIds($products);
            $this->restoreOriginalOptionTypeIds($types['values'], $types['prices'], $types['titles']);
        }

        $titles = $this->checkTitles($options, $titles);
        $types['titles'] = $this->checkTypesTitles($types);

        $this->prepareSaveOptions($options);
        $this->prepareSaveSpecificTypeValues($types['values']);
        $this->_saveOptions($options)
            ->_saveTitles($titles)
            ->_savePrices($prices)
            ->_saveSpecificTypeValues($types['values'])
            ->_saveSpecificTypePrices($types['prices'])
            ->_saveSpecificTypeTitles($types['titles'])
            ->_updateProducts($products);
    }

    /**
     * Save custom option prices
     *
     * @param array $prices Option prices data
     * @return \Magento\CatalogImportExport\Model\Import\Product\Option
     */
    protected function _savePrices(array $prices)
    {
        if ($prices) {
            $optionPriceRows = [];
            foreach ($prices as $optionId => $storesData) {
                foreach ($storesData as $row) {
                    $optionPriceRows[] = $row;
                }
            }
            if ($optionPriceRows) {
                $this->_connection->insertOnDuplicate(
                    $this->_tables['catalog_product_option_price'],
                    $optionPriceRows,
                    ['price', 'price_type']
                );
            }
        }

        return $this;
    }

    /**
     * Save custom option titles
     *
     * @param array $titles Option titles data
     * @return \Magento\CatalogImportExport\Model\Import\Product\Option
     */
    protected function _saveTitles(array $titles)
    {
        $titleRows = [];
        foreach ($titles as $optionId => $storeInfo) {
            $uniqStoreInfo = array_unique($storeInfo);
            foreach ($uniqStoreInfo as $storeId => $title) {
                $titleRows[] = ['option_id' => $optionId, 'store_id' => $storeId, 'title' => $title];
            }
        }
        if ($titleRows) {
            $this->_connection->insertOnDuplicate(
                $this->_tables['catalog_product_option_title'],
                $titleRows,
                ['title']
            );
        }

        return $this;
    }

    /**
     * @param array $typeValues
     * @return $this|Option
     */
    protected function _saveSpecificTypeValues(array $typeValues)
    {
        if (!empty($this->_parameters['include_option_id'])) {
            $typeValueRows = [];
            foreach ($typeValues as $optionId => $optionInfo) {
                foreach ($optionInfo as $row) {
                    $row['option_id'] = $optionId;
                    $typeValueRows[] = $row;
                }
            }

            if ($typeValueRows) {
                $this->_connection->insertOnDuplicate(
                    $this->_tables['catalog_product_option_type_value'],
                    $typeValueRows,
                    ['option_type_id', 'option_id']
                );
            }
        } else {
            parent::_saveSpecificTypeValues($typeValues);
        }

        return $this;
    }

    /**
     * @param array $typeTitles
     * @return $this|Option
     */
    protected function _saveSpecificTypeTitles(array $typeTitles)
    {
        if (!empty($this->_parameters['include_option_id'])) {
            $optionTypeTitleRows = [];
            foreach ($typeTitles as $optionTypeId => $storesData) {
                //for use default
                $uniqStoresData = array_unique($storesData);
                foreach ($uniqStoresData as $storeId => $title) {
                    $optionTypeTitleRows[] = [
                        'option_type_id' => $optionTypeId,
                        'store_id' => $storeId,
                        'title' => $title,
                    ];
                }
            }
            if ($optionTypeTitleRows) {
                $this->_connection->insertOnDuplicate(
                    $this->_tables['catalog_product_option_type_title'],
                    $optionTypeTitleRows,
                    ['option_type_id','store_id','title']
                );
            }
        } else {
            parent::_saveSpecificTypeTitles($typeTitles);
        }
        return $this;
    }

    /**
     * @param $options
     * @return $this
     */
    protected function prepareSaveOptions(&$options)
    {
        foreach ($options as &$option) {
            unset($option[self::COLUMN_ID]);
        }
        return $this;
    }

    /**
     * @param $values
     * @return $this
     */
    protected function prepareSaveSpecificTypeValues(&$values)
    {
        foreach ($values as &$value) {
            foreach ($value as &$item) {
                unset($item[self::COLUMN_ROW_ID]);
            }
        }
        return $this;
    }

    /**
     * @param array $types
     * @return array
     */
    private function checkTypesTitles($types)
    {
        $typesValues = $types['values'];
        $typesTitles = $types['titles'];

        $optionTypeIds = [];
        foreach ($typesValues as $type) {
            foreach ($type as $element) {
                $optionTypeIds[] = $element['option_type_id'];
            }
        }
        foreach ($typesTitles as $key => $title) {
            if (!in_array($key, $optionTypeIds)) {
                unset($typesTitles[$key]);
            }
        }

        return $typesTitles;
    }

    /**
     * @param array $options
     * @param array $titles
     * @return array
     */
    private function checkTitles($options, $titles)
    {
        $newTitles = [];
        foreach ($options as $option) {
            if (isset($titles[$option['option_id']])) {
                $newTitles[$option['option_id']] = $titles[$option['option_id']];
            }
        }

        return $newTitles;
    }
}
