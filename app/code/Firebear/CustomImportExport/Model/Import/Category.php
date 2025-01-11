<?php
namespace Firebear\CustomImportExport\Model\Import;

use Firebear\ImportExport\Traits\Import\Entity as ImportTrait;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category as MagentoCategoryModel;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor;
use Magento\Cms\Model\Page\DomValidationState;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Json\Helper\Data;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\StringUtils;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Entity\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ResourceModel\Helper;
use Symfony\Component\Console\Output\ConsoleOutput;

class Category extends \Firebear\ImportExport\Model\Import\Category
{
	
	/**
     * Gather and save information about product entities.
     *
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function saveCategoriesData()
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/saveCategoriesData.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        $logger->info(__LINE__);
        
        $this->_initSourceType('url');
        $groupCategoryId = [];
        $logger->info(__LINE__);
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $logger->info(__LINE__);
          
            $in = 0;
            $up = 0;
            $this->categoriesCache = [];
            $bunch = $this->prepareImagesFromSource($bunch);
            foreach ($bunch as $rowNum => $rowData) {
                $logger->info(__LINE__);
                if ($rowData['name'] == 'Root Catalog') {
                    continue;
                }
                $this->_processedRowsCount++;
                $rowData = $this->joinIdenticalyData($rowData);
                $rowData = $this->customChangeData($rowData);
                $rowData = $this->clearEmptyData($rowData, $rowNum);

                if (!$rowData) {
                    continue;
                }

                if (!isset($rowData[self::COL_NAME])) {
                    $logger->info(__LINE__);
                    $this->getErrorAggregator()->addError(
                        self::ERROR_CODE_NAME_REQUIRED,
                        ProcessingError::ERROR_LEVEL_CRITICAL,
                        $this->_processedRowsCount
                    );
                    continue;
                }

                if (isset($rowData[self::COL_CUSTOM_LAYOUT_UPDATE])
                    && !empty($rowData[self::COL_CUSTOM_LAYOUT_UPDATE])) {
                        $logger->info(__LINE__);
                    $rowData[self::COL_CUSTOM_LAYOUT_UPDATE] = stripslashes($rowData[self::COL_CUSTOM_LAYOUT_UPDATE]);
                    if (!$this->validateLayoutUpdateRow($rowData[self::COL_CUSTOM_LAYOUT_UPDATE])) {
                        $this->getErrorAggregator()->addError(
                            self::ERROR_CODE_LAYOUT_UPDATE_IS_NOT_VALID,
                            ProcessingError::ERROR_LEVEL_WARNING,
                            $this->_processedRowsCount
                        );
                        continue;
                    }
                }

                if (!$this->validateRow($rowData, $rowNum)) {
                    $this->addLogWriteln(
                        __('Category with name: %1 is not valided', $rowData[self::COL_NAME]),
                        $this->output,
                        'info'
                    );
                    continue;
                }
                $time = explode(" ", microtime());
                $startTime = $time[0] + $time[1];
                $name = $rowData[self::COL_NAME];
                if (!$this->validateRow($rowData, $rowNum)) {
                    continue;
                }

                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNum);
                    continue;
                }

                if (Import::BEHAVIOR_REPLACE == $this->getBehavior()) {
                    if (isset($rowData['entity_id'])) {
                        unset($rowData['entity_id']);
                    }
                    if (!empty($this->categoriesDeleted)) {
                        if (in_array($rowData[self::COL_NAME], $this->categoriesDeleted)) {
                            $rowData[self::COL_NAME];
                        } else {
                            continue;
                        }
                    }
                }

                $rowData = $this->changeData($rowData);
                $rowData['store_id'] = 0;
                if (!empty($rowData[self::COL_STORE])) {
                    if (isset($this->nameToId[$rowData[self::COL_STORE]])) {
                        $rowData['store_id'] = $this->nameToId[$rowData[self::COL_STORE]];
                        unset($rowData[self::COL_STORE]);
                    } else {
                        $this->addRowError(
                            "Store could not find for this category:".$rowData[self::COL_NAME],
                            $this->_processedRowsCount
                        );
                    }
                }

                $rowPath = $rowData[self::COL_NAME];

                if (!empty($rowPath)) {
                    if (is_int($rowPath)) {
                        try {
                            /** @var \Magento\Catalog\Model\Category $category */
                            $category = $this->categoryFactory->create();
                            if (!($parentCategory = isset($this->categoriesCache[$rowPath])
                                ? $this->categoriesCache[$rowPath] : null)
                            ) {
                                $parentCategory = $this->categoryFactory->create()->load($rowPath);
                            }
                            $category->setParentId($rowPath);

                            $isActive = $this->getBooleanValue(self::COL_IS_ACTIVE, $rowData);
                            $category->setIsActive($isActive);
                            $includeInMenu = $this->getBooleanValue(self::COL_INCLUDE_IN_MENU, $rowData);
                            $category->setIncludeInMenu($includeInMenu);
                            
                            $category->setAttributeSetId($category->getDefaultAttributeSetId());
                            $category->setStoreId($rowData['store_id']);
                            $category->addData($rowData);

                            $category->setPath($parentCategory->getPath());
                            $category->save();
                            $this->categoriesCache[$category->getId()] = $category;
                            $in++;
                        } catch (\Exception $e) {
                            $this->getErrorAggregator()->addError(
                                $e->getCode(),
                                ProcessingError::ERROR_LEVEL_NOT_CRITICAL,
                                $this->_processedRowsCount,
                                null,
                                $e->getMessage()
                            );
                        }
                    } else {
                        $rowPathWithDefaultDelimiter = str_replace(
                            $this->_parameters['category_levels_separator'],
                            '/',
                            $rowPath
                        );
                        $logger->info(__LINE__);

                        if(isset($rowData['group']) && !empty($rowData['group'])) {
                            $logger->info(__LINE__);
                            $catname = $rowData['_actual_name'];
                            $catid = '';
                            $logger->info($catid,true);
                            $logger->info(__LINE__);
 
                            
                            $logger->info($rowData['group'], true);

                            if(isset($groupCategoryId[$rowData['group']]) && !empty($groupCategoryId[$rowData['group']]) ) {
                                $catid = $groupCategoryId[$rowData['group']];
                                $logger->info($catid,true);
                                $logger->info($rowData['group'], true);
                            }
                            if(!$catid){
                                $catid = isset($this->categories[$rowPath]) ? $this->categories[$rowPath] :'';
                                $logger->info(__LINE__);
                                $logger->info($catid,true);
                                /*$categorycoll = $this->categoryColFactory->create();
                                $categorycoll->setStoreId($rowData['store_id']);
                                $categorycoll->addFieldToFilter('name',$catname);
                                if($categorycoll->count()){
                                    $catid = $categorycoll->getFirstItem()->getId();
                                }*/
                            }
                            
                            if($catid){
                                $logger->info(__LINE__);
                                $logger->info($catid,true);
                                //update
                                ++$up;
                                $result = $this->updateCategoriesByPath($rowPathWithDefaultDelimiter, $rowData, $catid);
                                $result = true;
                                $groupCategoryId[$rowData['group']] = $catid;
                                $logger->info(__LINE__);
                                $logger->info($catid,true);
                            } else {
                                //insert
                                ++$in;
                                $result = $this->prepareCategoriesByPath($rowPath, $rowData);
                                $groupCategoryId[$rowData['group']] = $result;
                                $logger->info(__LINE__);
                                $logger->info($catid,true);
                                $logger->info($result,true);
                            }
                        } else if(isset($rowData['entity_id']) && !empty($rowData['entity_id'])) {
                              ++$up;
                            $result = $this->updateCategoriesByPath($rowPathWithDefaultDelimiter, $rowData, $rowData['entity_id']);
                            $logger->info(__LINE__);
                            $logger->info($result,true);

                        } else if (!isset($this->categories[$rowPathWithDefaultDelimiter])) {
                            ++$in;
                            $result = $this->prepareCategoriesByPath($rowPath, $rowData);
                            $logger->info(__LINE__);
                            $logger->info($result,true);
                        } else {
                            ++$up;
                            $result = $this->updateCategoriesByPath($rowPathWithDefaultDelimiter, $rowData);
                            $logger->info(__LINE__);
                            $logger->info($result,true);
                        }
                        if ($result === false) {
                            continue;
                        }
                    }
                }
                $time = explode(" ", microtime());
                $endTime = $time[0] + $time[1];
                $totalTime = $endTime - $startTime;
                $totalTime = round($totalTime, 5);
                $this->addLogWriteln(__('category with name ddd: %1 .... %2s', $name, $totalTime), $this->output, 'info');
            }
            $this->addLogWriteln(__('Imported dddddd : %1 rows ', $in), $this->output, 'info');
            $this->addLogWriteln(__('Updated: %1 rows kkkkk', $up), $this->output, 'info');

            $this->eventManager->dispatch(
                'catalog_category_import_bunch_save_after',
                ['adapter' => $this, 'bunch' => $bunch]
            );
        }
        return $this;
    }

    /**
     * Update existing category by path.
     *
     * @param $rowPath
     * @param $rowData
     *
     * @return bool
     */
    protected function updateCategoriesByPath($rowPath, $rowData, $entityid = 0)
    {
        $result = true;
        if ($entityid) {
            $categoryId = $entityid;
        } else {
            $categoryId = $this->categories[$rowPath];
        }
        if (!empty($rowData[self::COL_STORE])) {
            if (isset($this->nameToId[$rowData[self::COL_STORE]])) {
                $rowData['store_id'] = $this->nameToId[$rowData[self::COL_STORE]];
                unset($rowData[self::COL_STORE]);
            } else {
                $this->addRowError(
                    "Store could not find for this category:".$rowData[self::COL_NAME],
                    $this->_processedRowsCount
                );
            }
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
            unset($rowData[self::COL_NAME]);
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
                if (!empty($rowData[$attrCode])
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

            $isActive = $this->getBooleanValue(self::COL_IS_ACTIVE, $rowData);
            $category->setIsActive($isActive);
            $includeInMenu = $this->getBooleanValue(self::COL_INCLUDE_IN_MENU, $rowData);
            $category->setIncludeInMenu($includeInMenu);
            
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

     /**
     * Prepare new category by path.
     *
     * @param $rowPath
     * @param $rowData
     *
     * @return bool
     */
    protected function prepareCategoriesByPath($rowPath, $rowData)
    {
        $result = false;
        $parentId = MagentoCategoryModel::TREE_ROOT_ID;
        $pathParts = explode($this->_parameters['category_levels_separator'], $rowPath);
        $path = '';

        foreach ($pathParts as $pathPart) {
            if ($pathPart == '') {
                continue;
            }
            $path .= $pathPart;
            if (!isset($this->categories[$path])) {
                try {
                    $category = $this->categoryFactory->create();
                    if (!($parentCategory = isset($this->categoriesCache[$parentId])
                        ? $this->categoriesCache[$parentId] : null)
                    ) {
                        $parentCategory = $this->categoryFactory->create()->load($parentId);
                    }
                    $category->addData($rowData);
                    $category->setStoreId(0);
                    $category->setParentId($parentId);

                    $isActive = $this->getBooleanValue(self::COL_IS_ACTIVE, $rowData);
                    $category->setIsActive($isActive);
                    $includeInMenu = $this->getBooleanValue(self::COL_INCLUDE_IN_MENU, $rowData);
                    $category->setIncludeInMenu($includeInMenu);
                    
                    $category->setAttributeSetId($category->getDefaultAttributeSetId());
                    $category->setName($pathPart);
                    if (isset($rowData[MagentoCategoryModel::KEY_AVAILABLE_SORT_BY])
                        && !empty($rowData[MagentoCategoryModel::KEY_AVAILABLE_SORT_BY])
                    ) {
                        $attrValue = \explode(
                            $this->getMultipleValueSeparator(),
                            $rowData[MagentoCategoryModel::KEY_AVAILABLE_SORT_BY]
                        );
                        $category->setAvailableSortBy($attrValue);
                    }
                    $category->setPath($parentCategory->getPath());
                    $category->save();
                    if ($category->getId()) {
                        $category->setPath($parentCategory->getPath() . self::DELIMITER_CATEGORY . $category->getId());
                        $category->save();
                    }
                    $this->categoriesCache[$category->getId()] = $category;
                    $this->categories[$path] = $category->getId();
                    if (!empty($rowData[self::COL_STORE_NAME])) {
                        $this->updateCategoriesByPath($rowPath, $rowData);
                    }
                    $result = $category->getId();
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
            }
            if (isset($this->categories[$path])) {
                $parentId = $this->categories[$path];

                $path .= self::DELIMITER_CATEGORY;
            }
        }
        return $result;
    }

    /**
     * get BooleanValue for a key
     * @param  [type] $key     [description]
     * @param  [type] $rowData [description]
     * @return [type]          [description]
     */
    protected function getBooleanValue($key, $rowData)
    {
        $value = isset($rowData[$key]) ? $rowData[$key] : true;
        if(strtolower($value)==="yes" || strtolower($value)==="no")
        {
            $value = strtolower($value)==="yes"? true: false;
        }
        return (boolean)$value;
    }
}
