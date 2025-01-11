<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport9413\Model\Import\Category;

use Firebear\ImportExport\Model\Import\Category;
use Magento\Catalog\Model\Category as MagentoCategoryModel;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
/**
 * Class Category
 *
 * @package Firebear\ImportExport\Model\Import
 */
class ExtendedCategory extends Category
{
    protected function updateCategoriesByPath($rowPath, $rowData, $entityid = 0)
    {
        $result = true;
        if ($entityid) {
            $categoryId = $entityid;
        } else {
            $categoryId = $this->categories[$rowPath];
        }
        $category = $this->categoryFactory->create()->setStoreId($rowData['store_id'])->load($categoryId);
        if (!$category->getId()) {
            return $this->prepareCategoriesByPath($rowPath, $rowData);
        }
        $defaultCategory = $this->categoryFactory->create()->setStoreId(0)->load($categoryId);
        /**
         * Avoid changing category name and path
         */
        if (isset($rowData[self::COL_STORE_NAME]) && !empty($rowData[self::COL_STORE_NAME])) {
            $rowData[self::COL_NAME] = $rowData[self::COL_STORE_NAME];
            unset($rowData[self::COL_STORE_NAME]);
        } elseif (isset($rowData[self::COL_NAME])) {
            if ($categoryId) {
                //update store view category name so no need to add name
                $rowData[self::COL_NAME] = isset($rowData['_actual_name'])
                    ? $rowData['_actual_name']
                    : $rowData[self::COL_NAME];
                if ($rowData[self::COL_NAME] == $defaultCategory->getName()) {
                    if ($rowData['store_id'] != 0) {
                        $category->setName(null);
                    }
                }
            }
            //unset($rowData[self::COL_NAME]);
        }
        if (isset($rowData[self::COL_STORE]) && empty($rowData[self::COL_STORE])) {
            unset($rowData[self::COL_STORE]);
        }
        if (isset($rowData[self::COL_PATH])) {
            unset($rowData[self::COL_PATH]);
        }
        try {
            foreach (\array_keys($this->attrData) as $attrCode) {
                if (!isset($rowData[$attrCode])) {
                    if ($category->getData($attrCode) == $defaultCategory->getData($attrCode)) {
                        if ($rowData['store_id'] != 0) {
                            $category->setData($attrCode, null);
                        }
                    }
                    continue;
                }
                if ($rowData[$attrCode] == $defaultCategory->getData($attrCode)) {
                    if ($rowData['store_id'] != 0) {
                        $category->setData($attrCode, null);
                    }
                    continue;
                }
                if (isset($rowData[$attrCode])
                    && $category->getData($attrCode) !== $rowData[$attrCode]
                ) {
                    if ($attrCode === MagentoCategoryModel::KEY_AVAILABLE_SORT_BY) {
                        $attrValue = \explode(
                            $this->getMultipleValueSeparator(),
                            $rowData[$attrCode]
                        );
                        $category->setData($attrCode, $attrValue);
                    } else {
                        $category->setData($attrCode, $rowData[$attrCode]);
                    }
                }
            }

            /**
             * set url_key in OrigData for method \Magento\Framework\Model\AbstractModel::dataHasChangedFor
             * cause url_path was change
             */
            if ((Import::BEHAVIOR_APPEND == $this->getBehavior()
                    && $this->_parameters['generate_url'] == 1
                    && isset($rowData['is_url_path_generated'])
                    && $rowData['is_url_path_generated'] == 1)
                || (Import::BEHAVIOR_REPLACE == $this->getBehavior()
                    && $this->_parameters['generate_url'] == 1
                    && isset($rowData['is_url_path_generated'])
                    && $rowData['is_url_path_generated'] == 1)
            ) {
                $urlCheck = $rowData['url_key'] . '1';
                $category->setOrigData('url_key', $urlCheck);
            }

            if (!$category->getUrlKey()) {
                $useDefault = $category->getData('use_default') ?: [];
                $useDefault['url_key'] = true;
                $category->setData('use_default', $useDefault);
            }
            $category->save();
        } catch (\Exception $e) {
            $this->getErrorAggregator()->addError(
                $e->getCode(),
                ProcessingError::ERROR_LEVEL_NOT_CRITICAL,
                $this->_processedRowsCount,
                null,
                $e->getMessage()
            );
            $result = false;
        }

        return $result;
    }
}
