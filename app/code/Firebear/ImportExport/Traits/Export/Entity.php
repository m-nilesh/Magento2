<?php
declare(strict_types=1);
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Traits\Export;

use DateTime;
use Exception;
use Firebear\ImportExport\Model\ExportJob\Processor;
use Firebear\ImportExport\Traits\General as GeneralTrait;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Export;
use Magento\Store\Model\Store;

/**
 * Trait Entity
 *
 * @package Firebear\ImportExport\Traits\Export
 */
trait Entity
{
    use GeneralTrait;

    /**
     * @var int
     */
    protected $lastEntityId;

    protected $_pageSize;

    protected $_byPagesIterator;

    /**
     * Iterate through given collection page by page and export items
     *
     * @param \Magento\Framework\Data\Collection\AbstractDb $collection
     * @return void
     */
    protected function _exportCollectionByPages(\Magento\Framework\Data\Collection\AbstractDb $collection)
    {
        $this->_pageSize = !empty($this->_parameters[Processor::BEHAVIOR_DATA]['page_size'])
            ? $this->_parameters[Processor::BEHAVIOR_DATA]['page_size'] : 500;
        $this->addLogWriteln(
            __('Bunch size: %1', $this->_pageSize),
            $this->output
        );
        $this->addLogWriteln(
            __('Total entities: %1', $collection->getSize()),
            $this->output
        );
        $this->addLogWriteln(
            __('Total pages: %1', (int)($collection->getSize() / $this->_pageSize) + 1),
            $this->output
        );
        $this->_byPagesIterator->iterate($collection, $this->_pageSize, [[$this, 'exportItem']]);
    }

    /**
     * @param array $data
     * @param string|null $entityFieldID
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function changeData($data, $entityFieldID = null)
    {
        $listCodes = $this->_parameters['list'];
        if ($entityFieldID) {
            $listCodes[] = $entityFieldID;
        }
        $replaces = $this->_parameters['replace_code'];
        $replacesValues = $this->_parameters['replace_value'];
        $newData = [];
        $allFields = $this->_parameters['all_fields'];
        foreach ($data as $record) {
            $newRecord = [];
            foreach ($record as $code => $value) {
                if (isset($this->_fieldsMap)) {
                    $code = $this->getKeyFromList($this->_fieldsMap, $code) ?: $code;
                }
                if (in_array($code, $listCodes)) {
                    $keyCode = $this->getKeyFromList($listCodes, $code);
                    $newCode = $code;
                    if (is_numeric($keyCode) && isset($replaces[$keyCode])) {
                        $newCode = $replaces[$keyCode];
                    }
                    $newRecord[$newCode] = $value;
                    if (isset($replacesValues[$keyCode]) && $replacesValues[$keyCode] !== '') {
                        $newRecord[$newCode] = $replacesValues[$keyCode];
                    }
                } else {
                    if (!$allFields) {
                        $newRecord[$code] = $value;
                    }
                }
            }

            $noFullList = array_diff($listCodes, array_keys($newRecord));
            if (!empty($noFullList)) {
                $newCode = '';
                foreach ($noFullList as $code => $value) {
                    if (isset($replaces[$code])) {
                        $newCode = $replaces[$code];
                    }
                    if (isset($replacesValues[$code])
                        && $replacesValues[$code] !== ''
                        && !isset($newRecord[$newCode])
                    ) {
                        $newRecord[$newCode] = $replacesValues[$code];
                    }
                    $newRecord[$code] = $value;
                }
            }
            if (!empty($newRecord)) {
                $newData[] = $newRecord;
            }
        }

        return $newData ? $newData : $data;
    }

    /**
     * @param array $list
     * @param string $search
     * @return false|int|string
     */
    protected function getKeyFromList($list, $search)
    {
        return array_search($search, $list);
    }

    /**
     * @param array $row
     * @return array
     */
    public function changeRow($row)
    {
        $listCodes = $this->_parameters['list'];
        $replaces = $this->_parameters['replace_code'];
        $allFields = $this->_parameters['all_fields'];
        $replacesValues = $this->_parameters['replace_value'];
        $newRecord = [];
        foreach ($row as $code => $value) {
            if (in_array($code, $listCodes)) {
                $keyCode = $this->getKeyFromList($listCodes, $code);
                $newCode = $code;
                if (isset($replaces[$keyCode])) {
                    $newCode = $replaces[$keyCode];
                }
                $newRecord[$newCode] = is_array($value) ? json_encode($value) : $value;
                if (isset($replacesValues[$keyCode]) && !empty($replacesValues[$keyCode])) {
                    $newRecord[$newCode] = $replacesValues[$keyCode];
                }
            } else {
                if (!$allFields) {
                    $newRecord[$code] = is_array($value) ? json_encode($value) : $value;
                }
            }
        }

//        $noFullList = array_diff($listCodes, array_keys($newRecord));
//        if (!empty($noFullList)) {
//            foreach ($noFullList as $code => $value) {
//                $newRecord[$code] = $value;
//            }
//        }

        return $newRecord;
    }

    /**
     * @param array $headers
     * @return array
     */
    public function changeHeaders($headers)
    {
        $allFields = $this->_parameters['all_fields'];
        $listCodes = $this->_parameters['list'];
        $countCodes = count($listCodes);
        $replaces = $this->_parameters['replace_code'];
        $newHeaders = [];
        foreach ($headers as $code) {
            if (in_array($code, $listCodes)) {
                $newCode = $code;
                $keyCode = $this->getKeyFromList($listCodes, $code);
                if (isset($replaces[$keyCode])) {
                    $newCode = $replaces[$keyCode];
                    $newHeaders[array_search($code, $listCodes)] = $newCode;
                } else {
                    $newHeaders[$countCodes++] = $newCode;
                }
            } else {
                if (!$allFields) {
                    $newHeaders[$countCodes++] = $code;
                }
            }
        }
        ksort($newHeaders);

        return $newHeaders ? $newHeaders : $headers;
    }

    /**
     * @return int
     */
    public function getCount()
    {
        /** @var AbstractDb $entityCollection */
        $entityCollection = $this->_getEntityCollection();
        return $entityCollection->getSize();
    }

    /**
     * @return array
     */
    public function getParameters()
    {
        return $this->_parameters;
    }

    /**
     * Apply filter to collection and add not skipped attributes to select.
     *
     * @param AbstractCollection $collection
     * @return AbstractCollection
     * @throws LocalizedException
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _prepareEntityCollection($collection)
    {
        $entity = $this->getEntityTypeCode();
        $fields = [];
        $columns = $this->getFieldColumns();
        foreach ($columns[$entity] as $field) {
            $fields[$field['field']] = $field['type'];
        }

        $collection = $this->addFiltersToCollection($collection, $entity, $fields);
        return $collection;
    }

    /**
     * Add FilterToCollection
     *
     * @param AbstractCollection $collection
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
     * Apply filter to collection
     *
     * @param AbstractCollection $collection
     * @return AbstractCollection
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function filterEntityCollection(AbstractCollection $collection)
    {
        if (!isset(
                $this->_parameters[Export::FILTER_ELEMENT_GROUP]
            ) || !is_array(
                $this->_parameters[Export::FILTER_ELEMENT_GROUP]
            )
        ) {
            $exportFilter = [];
        } else {
            $exportFilter = $this->_parameters[Export::FILTER_ELEMENT_GROUP];
        }

        $exportCodes = $this->_getExportAttrCodes();

        /** @var $attribute AbstractAttribute */
        foreach ($this->filterAttributeCollection($this->getAttributeCollection()) as $attribute) {
            $attributeCode = $attribute->getAttributeCode();

            // filter applying
            if (isset($exportFilter[$attributeCode])) {
                $attributeFilterType = Export::getAttributeFilterType($attribute);
                $filterCondition = $this->getExportFilterType($attributeCode, $exportFilter[$attributeCode]);

                if (Export::FILTER_TYPE_SELECT == $attributeFilterType) {
                    if (is_scalar($exportFilter[$attributeCode]) && trim($exportFilter[$attributeCode])) {
                        $condition = ($filterCondition == 'not_equal') ? 'neq' : 'eq';
                        $collection->addAttributeToFilter(
                            $attributeCode,
                            [$condition => $exportFilter[$attributeCode]]
                        );
                    } elseif (is_array($exportFilter[$attributeCode])) {
                        $collection->addAttributeToFilter(
                            $attributeCode,
                            ['in' => $exportFilter[$attributeCode]]
                        );
                    }
                } elseif (Export::FILTER_TYPE_MULTISELECT == $attributeFilterType) {
                    if (is_array($exportFilter[$attributeCode])) {
                        array_filter($exportFilter[$attributeCode]);
                        foreach ($exportFilter[$attributeCode] as $val) {
                            $collection->addAttributeToFilter(
                                $attributeCode,
                                ['finset' => $val]
                            );
                        }
                    }
                } elseif (Export::FILTER_TYPE_INPUT == $attributeFilterType) {
                    if (is_scalar($exportFilter[$attributeCode]) && trim($exportFilter[$attributeCode])) {
                        $condition = ($filterCondition == 'not_contains') ? 'nlike' : 'like';
                        $collection->addAttributeToFilter(
                            $attributeCode,
                            [$condition => "%{$exportFilter[$attributeCode]}%"]
                        );
                    }
                } elseif (Export::FILTER_TYPE_DATE == $attributeFilterType) {
                    if (is_array($exportFilter[$attributeCode]) && count($exportFilter[$attributeCode]) == 2) {
                        switch ($filterCondition) {
                            case 'less_or_equal_date':
                                array_shift($exportFilter[$attributeCode]);
                                $to = array_shift($exportFilter[$attributeCode]);
                                break;
                            case 'more_or_equal_date':
                                $from = array_shift($exportFilter[$attributeCode]);
                                break;
                            default:
                                $from = array_shift($exportFilter[$attributeCode]);
                                $to = array_shift($exportFilter[$attributeCode]);
                        }

                        if (!empty($from) && is_scalar($from)) {
                            $date = (new \DateTime($from))->format('m/d/Y');
                            $collection->addAttributeToFilter($attributeCode, ['from' => $date, 'date' => true]);
                        }
                        if (!empty($to) && is_scalar($to)) {
                            $date = (new \DateTime($to))->format('m/d/Y');
                            $collection->addAttributeToFilter($attributeCode, ['to' => $date, 'date' => true]);
                        }
                    }
                } elseif (Export::FILTER_TYPE_NUMBER == $attributeFilterType) {
                    if (is_array($exportFilter[$attributeCode]) && count($exportFilter[$attributeCode]) == 2) {
                        switch ($filterCondition) {
                            case 'equal':
                                $from = array_shift($exportFilter[$attributeCode]);
                                $collection->addAttributeToFilter($attributeCode, ['eq' => $from]);
                                break;
                            case 'not_equal':
                                $from = array_shift($exportFilter[$attributeCode]);
                                $collection->addAttributeToFilter($attributeCode, ['neq' => $from]);
                                break;
                            case 'more_or_equal':
                                $from = array_shift($exportFilter[$attributeCode]);
                                if (!empty($from)
                                    && is_numeric($from)) {
                                    $collection->addAttributeToFilter($attributeCode, ['from' => $from]);
                                }
                                break;
                            case 'less_or_equal':
                                array_shift($exportFilter[$attributeCode]);
                                $to = array_shift($exportFilter[$attributeCode]);
                                if (!empty($to) && is_numeric($to)) {
                                    $collection->addAttributeToFilter($attributeCode, ['to' => $to]);
                                }
                                break;
                            default:
                                $from = array_shift($exportFilter[$attributeCode]);
                                $to = array_shift($exportFilter[$attributeCode]);
                                if (!empty($from)
                                    && is_numeric($from)) {
                                    $collection->addAttributeToFilter($attributeCode, ['from' => $from]);
                                }
                                if (!empty($to) && is_numeric($to)) {
                                    $collection->addAttributeToFilter($attributeCode, ['to' => $to]);
                                }
                        }
                    }
                }
            }
            if (in_array($attributeCode, $exportCodes)) {
                $collection->addAttributeToSelect($attributeCode);
            }
        }
        return $collection;
    }


    /**
     * Get ExportFilterType
     *
     * @param string $fild
     * @param string $value
     * @return false|mixed
     */
    protected function getExportFilterType($fild, $value)
    {
        foreach ($this->_parameters['export_filter_type'] as $item) {
            if (($item['field'] == $fild) && ($item['value'] == $value)) {
                return $item['type'] ?? '';
            }
            if (is_array($value)
                && $item['field'] == $fild
                && (in_array($item['value'], $value))) {
                return $item['type'] ?? '';
            }
        }
        return false;
    }

    /**
     * Retrieve entity field for export
     *
     * @return array
     */
    public function getFieldsForExport()
    {
        return [];
    }

    /**
     * Retrieve entity field for filter
     *
     * @return array
     */
    public function getFieldsForFilter()
    {
        return [];
    }

    /**
     * Retrieve store ids for filter
     *
     * @return array
     */
    public function getStoreIdsForFilter()
    {
        if (!empty($this->_parameters['only_admin'])) {
            return [Store::DEFAULT_STORE_ID];
        }
        return $this->_parameters['behavior_data']['store_ids'] ?? [];
    }

    /**
     * Retrieve entity field columns
     *
     * @return array
     */
    public function getFieldColumns()
    {
        return [];
    }

    /**
     * Retrieve entity attribute type
     *
     * @param $type
     * @return string
     */
    private function getAttributeType($type)
    {
        if (in_array($type, ['int', 'decimal', 'price'])) {
            return 'int';
        }
        if (in_array($type, ['varchar', 'text', 'textarea'])) {
            return 'text';
        }
        if (in_array($type, ['select', 'multiselect', 'boolean'])) {
            return 'select';
        }
        if (in_array($type, ['datetime', 'date'])) {
            return 'date';
        }
        return 'not';
    }
}
