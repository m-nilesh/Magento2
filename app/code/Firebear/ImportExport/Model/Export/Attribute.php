<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Export;

use DateTime;
use Exception;
use Firebear\ImportExport\Helper\Data as Helper;
use Firebear\ImportExport\Model\Export\Dependencies\Config as ExportConfig;
use Firebear\ImportExport\Model\ExportJob\Processor;
use Firebear\ImportExport\Model\Import\Attribute as ImportAttribute;
use Firebear\ImportExport\Model\Source\Factory as SourceFactory;
use Firebear\ImportExport\Traits\Export\Entity as ExportTrait;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute as EntityAttributeModel;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as EntityAttributeResourceModel;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection as EntityAttributeCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\ImportExport\Model\Export\AbstractEntity;
use Magento\ImportExport\Model\Export\Factory as ExportFactory;
use Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Attribute export adapter
 */
class Attribute extends AbstractEntity implements EntityInterface
{
    use ExportTrait;

    /**
     * Attribute collection name
     */
    const ATTRIBUTE_COLLECTION_NAME = EntityAttributeCollection::class;

    /**
     * XML path to page size parameter
     */
    const XML_PATH_PAGE_SIZE = 'firebear_importexport/page_size/attribute';

    /**
     * Product Entity Type
     */
    const PRODUCT_ENTITY_TYPE = 'product';

    /**
     * Export config data
     *
     * @var array
     */
    protected $_exportConfig;

    /**
     * Source Factory
     *
     * @var SourceFactory
     */
    protected $_sourceFactory;

    /**
     * Helper
     *
     * @var Helper
     */
    protected $_helper;

    /**
     * Resource Model
     *
     * @var ResourceConnection
     */
    protected $_resourceModel;

    /**
     * DB connection
     *
     * @var AdapterInterface
     */
    protected $_connection;

    /**
     * Item export data
     *
     * @var array
     */
    protected $_exportData = [];

    /**
     * EAV config
     *
     * @var array
     */
    protected $_eavConfig;

    /**
     * Catalog product entity typeId
     *
     * @var int
     */
    protected $_entityTypeId;

    /**
     * value from filter
     *
     * @var
     */
    protected $attributeSetNameFilter;

    /**
     * value from filter for store_id
     *
     * @var
     */
    protected $filterStoreIdValue;

    /**
     * $_cachedOptionData[$attributeID][$storeId] = []
     * @var array
     */
    protected $_cachedOptionData = [];

    /**
     * @var array
     */
    protected $_cachedSetsData = [];

    /**
     * @var string[]
     */
    protected $optionColumns = [
        'option:sort_order' => '',
        'option:swatch_value' => '',
        'option:value' => '',
        'option:base_value' => '',
        'store_id' => ''
    ];

    /**
     * Initialize export
     *
     * @param LoggerInterface $logger
     * @param ConsoleOutput $output
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param ExportFactory $collectionFactory
     * @param CollectionByPagesIteratorFactory $resourceColFactory
     * @param ExportConfig $exportConfig
     * @param SourceFactory $sourceFactory
     * @param ResourceConnection $resource
     * @param Helper $helper
     * @param EavConfig $eavConfig
     * @param array $data
     */
    public function __construct(
        LoggerInterface $logger,
        ConsoleOutput $output,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        ExportFactory $collectionFactory,
        CollectionByPagesIteratorFactory $resourceColFactory,
        ExportConfig $exportConfig,
        SourceFactory $sourceFactory,
        ResourceConnection $resource,
        Helper $helper,
        EavConfig $eavConfig,
        array $data = []
    ) {
        $this->_logger = $logger;
        $this->output = $output;
        $this->_exportConfig = $exportConfig->get();
        $this->_sourceFactory = $sourceFactory;
        $this->_resourceModel = $resource;
        $this->_connection = $resource->getConnection();
        $this->_helper = $helper;
        $this->_eavConfig = $eavConfig;

        parent::__construct(
            $scopeConfig,
            $storeManager,
            $collectionFactory,
            $resourceColFactory,
            $data
        );

        $this->_initStores();
    }

    /**
     * Retrieve entity type code
     *
     * @return string
     */
    public function getEntityTypeCode()
    {
        return 'attribute';
    }

    /**
     * Retrieve header columns
     *
     * @return array
     * @throws LocalizedException
     */
    public function _getHeaderColumns()
    {
        return $this->changeHeaders(
            array_keys($this->describeTable())
        );
    }

    /**
     * Retrieve attribute collection
     *
     * @return EntityAttributeCollection
     * @throws LocalizedException
     */
    protected function _getEntityCollection()
    {
        /** @var EntityAttributeCollection $attributeCollection */
        $attributeCollection = $this->getAttributeCollection();
        return $attributeCollection->setEntityTypeFilter($this->_getEntityTypeId());
    }

    /**
     * Get entity type id
     *
     * @return int
     * @throws LocalizedException
     */
    protected function _getEntityTypeId()
    {
        if (!$this->_entityTypeId) {
            $entityType = $this->_eavConfig->getEntityType('catalog_product');
            $this->_entityTypeId = $entityType->getId();
        }
        return $this->_entityTypeId;
    }

    /**
     * Export process
     *
     * @return array
     * @throws LocalizedException
     * @throws Exception
     */
    public function export()
    {
        //Execution time may be very long
        set_time_limit(0);

        $this->addLogWriteln(__('Begin Export'), $this->output);
        $this->addLogWriteln(__('Scope Data'), $this->output);

        $collection = $this->_getEntityCollection();
        $this->_prepareEntityCollection($collection);
        $this->_exportCollectionByPages($collection);
        // create export file
        return [
            $this->getWriter()->getContents(),
            $this->_processedEntitiesCount,
            $this->lastEntityId,
        ];
    }

    /**
     * Export one item
     *
     * @param EntityAttributeModel $item
     * @return void
     * @throws LocalizedException
     */
    public function exportItem($item)
    {
        $itemExported = false;
        $attributeOptionRows = [];
        foreach ($this->_getExportData($item) as $storeRow) {
            foreach ($storeRow as $row) {
                $storeId = $row['store_id'] ?? '';
                $row = array_merge($row, $this->optionColumns, ['store_id' => $storeId]);
                $isOptionForStoreExists = false;
                if (!empty($row['attribute_options'])) {
                    $isOptionForStoreExists = true;
                    $attributeOptionRows = $row['attribute_options'];
                    $row = array_merge($row, array_shift($attributeOptionRows));
                    unset($row['attribute_options']);
                }
                $row = $this->changeRow($row);
                $this->getWriter()->writeRow($row);
                if (!empty($attributeOptionRows) && $isOptionForStoreExists) {
                    foreach ($attributeOptionRows as $optionRow) {
                        $optionRow = $this->changeRow($optionRow);
                        $this->getWriter()->writeRow($optionRow);
                    }
                }
                $itemExported = true;
                if (!empty($row['attribute_code'])) {
                    $this->addLogWriteln(
                        __(
                            'Export %1 attribute',
                            $row['attribute_code']
                        ),
                        $this->getOutput(),
                        'info'
                    );
                }
            }
        }
        if ($itemExported) {
            $this->_processedEntitiesCount++;
        }
    }

    /**
     * Get export data for collection
     *
     * @param EntityAttributeModel $attribute
     * @return array
     */
    protected function _getExportData($attribute)
    {
        $filterStoreIdValue = $this->filterStoreIdValue;
        $this->_exportData = [];
        $exportData = [];
        $attributeSeData = [];
        $attributeId = $attribute->getId();
        $options = $this->_getOptionData($attribute->getId(), array_keys($this->_storeIdToCode));
        $attributeCode = $attribute->getAttributeCode();
        $attributeLabels = $this->_getStoreLabels($attributeId);
        $this->lastEntityId = $attributeId;
        $multipleAttributeSetSeparator =
            $this->_parameters[Processor::BEHAVIOR_DATA]['multiple_attribute_set_separator'] ?? '';
        $attributeSetParametersSeparator =
            $this->_parameters[Processor::BEHAVIOR_DATA]['attribute_set_parameters_separator'] ?? '';

        $setsData = $this->_getSetData($attributeId) ?: [];
        if (!empty($multipleAttributeSetSeparator) && !empty($attributeSetParametersSeparator)) {
            $attributeSetData = [];
            foreach ($setsData as $setData) {
                $attributeSetData[] = implode($attributeSetParametersSeparator, $setData);
            }
            $attributeSetData = implode($multipleAttributeSetSeparator, $attributeSetData);
            $exportData[Store::DEFAULT_STORE_ID]['attribute_code'] = $attributeCode;
            $exportData[Store::DEFAULT_STORE_ID]['attribute_set'] = $attributeSetData;
            foreach ($attributeLabels as $storeId => $label) {
                $filterCondition = $this->getExportFilterType('store_id', $filterStoreIdValue);

                if ($filterCondition == 'not_contains') {
                    if ((!empty($filterStoreIdValue)
                            || $filterStoreIdValue == '0') && (int)$filterStoreIdValue == $storeId) {
                        continue;
                    }
                } else {
                    if ((!empty($filterStoreIdValue)
                            || $filterStoreIdValue == '0') && (int)$filterStoreIdValue !== $storeId) {
                        continue;
                    }
                }

                $labelRow['frontend_label'] = $label;
                $labelRow['store_id'] = $storeId;
                if (!empty($exportData[$storeId])) {
                    $exportData[$storeId] = array_merge($exportData[$storeId], $labelRow);
                } else {
                    $exportData[$storeId] = $labelRow;
                }
            }
            foreach ($options as $option) {
                foreach ($option as $storeId => $optionData) {
                    $optionRow = [];

                    $filterCondition = $this->getExportFilterType('store_id', $filterStoreIdValue);

                    if ($filterCondition == 'not_contains') {
                        if ((!empty($filterStoreIdValue)
                                || $filterStoreIdValue == '0') && (int)$filterStoreIdValue == $storeId) {
                            continue;
                        }
                    } else {
                        if ((!empty($filterStoreIdValue)
                                || $filterStoreIdValue == '0') && (int)$filterStoreIdValue !== $storeId) {
                            continue;
                        }
                    }
                    $optionRow['store_id'] = $storeId;
                    $optionRow = array_merge($optionRow, $optionData);
                    $exportData[$storeId]['attribute_options'][] = $optionRow;
                }
            }
            $exportData[Store::DEFAULT_STORE_ID]['entity_type'] = self::PRODUCT_ENTITY_TYPE;
            $exportData[Store::DEFAULT_STORE_ID] =
                array_merge($exportData[Store::DEFAULT_STORE_ID], $attribute->toArray());
            $this->_exportData[] = $exportData;
            $exportData[Store::DEFAULT_STORE_ID]['store_id'] = Store::DEFAULT_STORE_ID;
            $this->initAttributeOptionTemplate($exportData);
        }
        return $this->_exportData;
    }

    /**
     * Get set data for attribute
     *
     * @param integer $attributeId
     * @return array
     */
    protected function _getSetData($attributeId)
    {
        if (!isset($this->_cachedSetsData[$attributeId])) {
            $resource = $this->_resourceModel;
            $table = $resource->getTableName('eav_entity_attribute');
            $setTable = $resource->getTableName('eav_attribute_set');
            $groupTable = $resource->getTableName('eav_attribute_group');

            $select = $this->_connection->select();
            $select->from(
                ['e' => $table],
                []
            )->join(
                ['s' => $setTable],
                'e.attribute_set_id = s.attribute_set_id',
                ['s.attribute_set_name']
            )->join(
                ['g' => $groupTable],
                'e.attribute_group_id = g.attribute_group_id',
                ['g.attribute_group_name', 'g.sort_order']
            )->where(
                'e.attribute_id = ?',
                $attributeId
            );
            if (!empty($this->attributeSetNameFilter)) {
                $filterCondition = $this->getExportFilterType('attribute_set', $this->attributeSetNameFilter);
                $condition = ($filterCondition == 'not_contains') ? 'not like' : 'like';
                $select->where(
                    "s.attribute_set_name $condition ?",
                    '%' . $this->attributeSetNameFilter . '%'
                );
            }
            $this->_cachedSetsData[$attributeId] = $this->_connection->fetchAll($select);
        }

        return $this->_cachedSetsData[$attributeId];
    }

    /**
     * Get option data for attribute
     *
     * @param integer $attributeId
     * @param array $storeIds
     * @return array
     */
    protected function _getOptionData($attributeId, $storeIds)
    {
        $result = [];
        $optionValues = $this->getAttributeOptionValues($attributeId, $storeIds);
        $optionSwatches = $this->getAttributeOptionSwatches($attributeId, $storeIds);
        $options = array_merge($optionValues, $optionSwatches);
        foreach ($options as $optionValue) {
            $optionId = $optionValue['option_id'];
            $storeId = $optionValue['store_id'];
            $result[$optionId][$storeId]['store_id'] = $storeId;
            if (isset($optionValue['sort_order'])) {
                $result[$optionId][$storeId]['option:sort_order'] = $optionValue['sort_order'];
            }
            $result[$optionId][$storeId]['option:swatch_value'] = $optionValue['swatch_value'] ?? '';
            if (isset($result[$optionId][$storeId]['option:value'])
                && !empty($result[$optionId][$storeId]['option:value'])) {
                continue;
            }
            $result[$optionId][$storeId]['option:value'] = $optionValue['value'] ?? '';
        }
        $result = $this->prepareBaseOptionData($result);
        return $result;
    }

    /**
     * Prepare BaseOptionData
     *
     * @param array $data
     * @return mixed
     */
    protected function prepareBaseOptionData($data)
    {
        $baseValues = [];
        foreach ($data as $optionId => $optionData) {
            foreach ($optionData as $storeId => $dataByStoreId) {
                if ($storeId == Store::DEFAULT_STORE_ID) {
                    $baseValues[$optionId] = $dataByStoreId['option:value'];
                }
            }
        }
        foreach ($data as $optionId => $optionData) {
            foreach ($optionData as $storeId => $dataByStoreId) {
                if (isset($baseValues[$optionId])) {
                    $data[$optionId][$storeId]['option:base_value'] = $baseValues[$optionId];
                }
            }
        }
        return $data;
    }

    /**
     * Get attribute option values from DB
     *
     * @param $attributeId
     * @param $storeIds
     * @return mixed
     */
    protected function getAttributeOptionValues($attributeId, $storeIds)
    {
        $resource = $this->_resourceModel;
        $optionTable = $resource->getTableName('eav_attribute_option');
        $optionValueTable = $resource->getTableName('eav_attribute_option_value');
        $select = $this->_connection->select();

        $select->from(
            ['o' => $optionTable],
            ['o.option_id', 'o.sort_order']
        )->join(
            ['ov' => $optionValueTable],
            'o.option_id = ov.option_id',
            ['ov.value', 'ov.store_id']
        )->where(
            'o.attribute_id = ?',
            $attributeId
        )->where(
            'ov.store_id IN (?)',
            $storeIds
        )->order('o.sort_order');

        return $this->_connection->fetchAll($select);
    }

    /**
     * Get attribute option swatches from DB
     *
     * @param $optionIds
     * @param $storeIds
     * @return mixed
     */
    protected function getAttributeOptionSwatches($attributeId, $storeIds)
    {
        $resource = $this->_resourceModel;
        $optionTable = $resource->getTableName('eav_attribute_option');
        $swatchValueTable = $resource->getTableName('eav_attribute_option_swatch');
        $select = $this->_connection->select();
        $select->from(
            ['o' => $optionTable],
            ['o.sort_order']
        )
        ->join(
            ['sv' => $swatchValueTable],
            'o.option_id = sv.option_id',
            ['sv.option_id', 'swatch_value' => 'sv.value', 'sv.store_id']
        )->where(
            'o.attribute_id = ?',
            $attributeId
        )->where(
            'sv.store_id IN (?)',
            $storeIds
        );
        return $this->_connection->fetchAll($select);
    }

    /**
     * Checks if nested structure
     *
     * @return bool
     */
    protected function _isNested()
    {
        return in_array(
            $this->_parameters['behavior_data']['file_format'],
            ['xml', 'json']
        );
    }

    /**
     * Apply filter to collection
     *
     * @param AbstractCollection $collection
     * @return AbstractCollection
     * @throws Exception
     */
    protected function _prepareEntityCollection(AbstractCollection $collection)
    {
        if (!empty($this->_parameters['last_entity_id']) &&
            $this->_parameters['enable_last_entity_id'] > 0
        ) {
            $collection->addFieldToFilter(
                'main_table.attribute_id',
                ['gt' => $this->_parameters['last_entity_id']]
            );
        }
        $collection->addFieldToFilter('additional_table.is_visible', 1);

        $entity = $this->getEntityTypeCode();
        $fields = [];
        $columns = $this->getFieldColumns();
        foreach ($columns['attribute'] as $field) {
            $fields[$field['field']] = $field['type'];
        }

        $collection = $this->addFiltersToCollection($collection, $entity, $fields);
        $collection->setOrder('main_table.attribute_id', EntityAttributeCollection::SORT_ORDER_ASC);
        return $collection;
    }

    /**
     * Add FilterToCollection
     *
     * @param \Magento\Eav\Model\Entity\Collection\AbstractCollection $collection
     * @param string $entity
     * @param array $fields
     * @return mixed
     * @throws Exception
     */
    protected function addFiltersToCollection($collection, $entity, $fields)
    {
        if (!isset($this->_parameters[Processor::EXPORT_FILTER_TABLE]) ||
            !is_array($this->_parameters[Processor::EXPORT_FILTER_TABLE])) {
            $exportFilter = [];
        } else {
            $exportFilter = $this->_parameters[Processor::EXPORT_FILTER_TABLE];
        }
        $filters = [];
        foreach ($exportFilter as $data) {
            if ($data['entity'] == $entity) {
                $filters[$data['field']] = $data['value'];
            }
        }
        foreach ($filters as $key => $value) {
            $type = $fields[$entity][$key]['type'] ?? null;
            if (!$type) {
                $type = $fields[$key] ?? null;
            }
            $filterCondition = $this->getExportFilterType($key, $value);

            if (isset($fields[$key])) {
                if ($key == 'store_id') {
                    $this->filterStoreIdValue = $value;
                    continue;
                }
                if ($key == 'group:name' || $key == 'group:sort_order') {
                    $key = 'eag.attribute_group_name';
                    $collection->getSelect()->joinLeft(
                        ['eea' => 'eav_entity_attribute'],
                        'main_table.attribute_id = eea.attribute_id',
                        ['eea.attribute_group_id']
                    )->joinLeft(
                        ['eag' => 'eav_attribute_group'],
                        'eea.attribute_group_id = eag.attribute_group_id',
                        ['eag.attribute_group_name']
                    )->group('main_table.attribute_id');
                }

                if ($key == 'group:sort_order') {
                    $key = 'eag.sort_order';
                }

                if ($key == 'attribute_set') {
                    $this->attributeSetNameFilter = $value;
                    $key = 'eas.attribute_set_name';
                    $collection->getSelect()->joinLeft(
                        ['ea' => 'eav_entity_attribute'],
                        'main_table.attribute_id = ea.attribute_id',
                        ['ea.attribute_set_id']
                    )->joinLeft(
                        ['eas' => 'eav_attribute_set'],
                        'ea.attribute_set_id = eas.attribute_set_id',
                        ['eas.attribute_set_name']
                    )->group('main_table.attribute_id');
                }
            }

            if ($type) {
                if ('text' == $type) {
                    if (is_scalar($value)) {
                        trim($value);
                    }
                    $condition = (($filterCondition == 'not_contains')
                        || ($filterCondition == 'not_equal')) ? 'nlike' : 'like';
                    $collection->addFieldToFilter($key, [$condition => "%{$value}%"]);
                } elseif ('select' == $type) {
                    $condition = ($filterCondition == 'not_equal' || $filterCondition == 'not_contains') ? 'neq' : 'eq';
                    $collection->addFieldToFilter($key, [$condition => $value]);
                } elseif ('int' == $type) {
                    if (is_array($value) && count($value) == 2) {
                        switch ($filterCondition) {
                            case 'equal':
                                $from = array_shift($value);
                                $collection->addFieldToFilter($key, ['eq' => $from]);
                                break;
                            case 'not_equal':
                                $from = array_shift($value);
                                $collection->addFieldToFilter($key, ['neq' => $from]);
                                break;
                            case 'more_or_equal':
                                $from = array_shift($value);
                                if (!empty($from)
                                    && is_numeric($from)) {
                                    $collection->addFieldToFilter($key, ['from' => $from]);
                                }
                                break;
                            case 'less_or_equal':
                                array_shift($value);
                                $to = array_shift($value);
                                if (!empty($to) && is_numeric($to)) {
                                    $collection->addFieldToFilter($key, ['to' => $to]);
                                }
                                break;
                            default:
                                $from = array_shift($value);
                                $to = array_shift($value);
                                if (!empty($from)
                                    && is_numeric($from)) {
                                    $collection->addFieldToFilter($key, ['from' => $from]);
                                }
                                if (!empty($to) && is_numeric($to)) {
                                    $collection->addFieldToFilter($key, ['to' => $to]);
                                }
                        }
                    } else {
                        if ($filterCondition == 'equal') {
                            $collection->addFieldToFilter($key, $value);
                        } else {
                            $collection->addFieldToFilter($key, ['neq' => $value]);
                        }
                    }
                } elseif ('date' == $type) {
                    if (is_array($value) && count($value) == 2) {
                        switch ($filterCondition) {
                            case 'less_or_equal_date':
                                array_shift($value);
                                $to = array_shift($value);
                                if ($to == 'NaN') {
                                    $to = '';
                                }
                                break;
                            case 'more_or_equal_date':
                                $from = array_shift($value);
                                if ($from == 'NaN') {
                                    $from = '';
                                }
                                break;
                            default:
                                $from = array_shift($value);
                                $to = array_shift($value);
                                if ($from == 'NaN') {
                                    $from = '';
                                }
                                if ($to == 'NaN') {
                                    $to = '';
                                }

                        }
                        if (!empty($from) && is_scalar($from)) {
                            $date = (new DateTime($from))->format('m/d/Y 00:00:00');
                            $collection->addFieldToFilter($key, ['from' => $date, 'date' => true]);
                        }
                        if (!empty($to) && is_scalar($to)) {
                            $date = (new DateTime($to))->format('m/d/Y 23:59:59');
                            $collection->addFieldToFilter($key, ['to' => $date, 'date' => true]);
                        }
                    }
                }
            }
        }
        return $collection;
    }

    /**
     * Retrieve store labels by given attribute id
     *
     * @param int $attributeId
     * @return array
     */
    protected function _getStoreLabels($attributeId)
    {
        /** @var EntityAttributeCollection $attributeCollection */
        $attributeCollection = $this->getAttributeCollection();
        /** @var EntityAttributeResourceModel $resource */
        $resource = $attributeCollection->getResource();
        return $resource->getStoreLabelsByAttributeId($attributeId);
    }

    /**
     * Retrieve entity field for export
     *
     * @return array
     * @throws LocalizedException
     */
    public function getFieldsForExport()
    {
        $fields = array_keys($this->describeTable());
        $fields = array_merge(ImportAttribute::getAdditionalColumns(), $fields);
        return $fields;
    }

    /**
     * Retrieve entity field columns
     *
     * @return array
     * @throws LocalizedException
     */
    public function getFieldColumns()
    {
        $options = [];
        foreach ($this->describeTable() as $key => $field) {
            if ($field == 'entity_type_id' || $field == 'is_visible') {
                continue;
            }
            $select = [];
            $type = $this->_helper->convertTypesTables($field['DATA_TYPE']);
            if ('int' == $type && (
                'is_' == substr($field['COLUMN_NAME'], 0, 3) ||
                'used_' == substr($field['COLUMN_NAME'], 0, 5)
            )) {
                $select[] = ['label' => __('Yes'), 'value' => 1];
                $select[] = ['label' => __('No'), 'value' => 0];
                $type = 'select';
            }
            $options['attribute'][] = ['field' => $key, 'type' => $type, 'select' => $select];
        }
        $options['attribute'][] = ['field' => 'store_id', 'type' => 'text', 'select' => []];
        $options['attribute'][] = ['field' => 'attribute_set', 'type' => 'text', 'select' => []];
        $options['attribute'][] = ['field' => 'group:name', 'type' => 'text', 'select' => []];
        $options['attribute'][] = ['field' => 'group:sort_order', 'type' => 'text', 'select' => []];
        return $options;
    }

    /**
     * Add empty attribute option fields
     *
     * @param $exportData
     */
    protected function initAttributeOptionTemplate(&$exportData)
    {
        $exportData[Store::DEFAULT_STORE_ID]['option:base_value'] = '';
        $exportData[Store::DEFAULT_STORE_ID]['frontend_label'] = '';
        $exportData[Store::DEFAULT_STORE_ID]['option:value'] = '';
        $exportData[Store::DEFAULT_STORE_ID]['option:sort_order'] = '';
        $exportData[Store::DEFAULT_STORE_ID]['option:swatch_value'] = '';
    }

    /**
     * Retrieve entity field for filter
     *
     * @return array
     * @throws LocalizedException
     */
    public function getFieldsForFilter()
    {
        $options = [];
        foreach ($this->getFieldsForExport() as $field) {
            $options[] = [
                'label' => $field,
                'value' => $field,
            ];
        }
        return [$this->getEntityTypeCode() => $options];
    }

    /**
     * Retrieve the column descriptions for a table, include additional table
     *
     * @return array
     * @throws LocalizedException
     */
    protected function describeTable()
    {
        /** @var EntityAttributeCollection $attributeCollection */
        $attributeCollection = $this->getAttributeCollection();
        /** @var EntityAttributeResourceModel $resource */
        $resource = $attributeCollection->getResource();
        $additionalTable = $resource->getAdditionalAttributeTable(
            $this->_getEntityTypeId()
        );
        $fields = $resource->describeTable($resource->getMainTable());
        $fields+= $resource->describeTable($this->_resourceModel->getTableName($additionalTable));

        unset($fields['attribute_id']);
        return $fields;
    }

    /**
     * Retrieve attributes codes which are appropriate for export
     *
     * @return array
     */
    protected function _getExportAttrCodes()
    {
        return [];
    }
}
