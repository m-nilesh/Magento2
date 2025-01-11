<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Import;

use Firebear\ImportExport\Traits\Import\Entity as ImportTrait;
use Magento\Customer\Model\Config\Share;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\CustomerImportExport\Model\Import\Customer as MagentoCustomer;
use Magento\Tax\Model\ClassModel as TaxClassModel;
use Symfony\Component\Console\Output\ConsoleOutput;
use \Magento\ImportExport\Model\Import\AbstractEntity;
use Firebear\ImportExport\Model\Import;
use Firebear\ImportExport\Model\Export\Customer as ExportCustomer;
use Magento\Customer\Model\GroupFactory;
use Magento\Customer\Model\ResourceModel\Group\Collection as CustomerGroupCollection;
use Magento\Tax\Model\ResourceModel\TaxClass\Collection as TaxClassCollection;
use Magento\Customer\Model\Customer as CustomerModel;

/**
 * Class Customer
 *
 * @package Firebear\ImportExport\Model\Import
 */
class Customer extends MagentoCustomer
{
    use ImportTrait;

    const ERROR_CREATING_GROUP = 'creatingGroup';

    protected $_debugMode;

    /**
     * @var array
     */
    protected $superUserList = [];

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;

    /**
     * Error code
     */
    const ERROR_DUPLICATE_EMAIL_FOR_GLOBAL_SCOPE = 'dublicateEmailForGlobalScope';

    /**
     * @var GroupFactory
     */
    protected $customerGroupFactory;

    /**
     * @var CustomerGroupCollection
     */
    protected $customerGroupCollection;

    /**
     * @var TaxClassCollection
     */
    protected $taxClassCollection;

    /**
     * @var array
     */
    protected $customerTaxClasses = [];

    /**
     * @var array
     */
    protected $createdCustomerGroups = [];

    /**
     * @param Context $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\ImportExport\Model\ImportFactory $importFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\ImportExport\Model\Export\Factory $collectionFactory
     * @param \Magento\CustomerImportExport\Model\ResourceModel\Import\Customer\StorageFactory $storageFactory
     * @param \Magento\Customer\Model\ResourceModel\Attribute\CollectionFactory $attrCollectionFactory
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param ConsoleOutput $output
     * @param \Firebear\ImportExport\Helper\Data $helper
     * @param GroupFactory $groupFactory
     * @param CustomerGroupCollection $customerGroup
     * @param TaxClassCollection $taxClassCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\ImportExport\Model\ImportFactory $importFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\ImportExport\Model\Export\Factory $collectionFactory,
        \Magento\CustomerImportExport\Model\ResourceModel\Import\Customer\StorageFactory $storageFactory,
        \Magento\Customer\Model\ResourceModel\Attribute\CollectionFactory $attrCollectionFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        ConsoleOutput $output,
        \Firebear\ImportExport\Helper\Data $helper,
        GroupFactory $groupFactory,
        CustomerGroupCollection $customerGroup,
        TaxClassCollection $taxClassCollection,
        array $data = []
    ) {
        parent::__construct(
            $context->getStringUtils(),
            $scopeConfig,
            $importFactory,
            $context->getResourceHelper(),
            $context->getResource(),
            $context->getErrorAggregator(),
            $storeManager,
            $collectionFactory,
            $context->getConfig(),
            $storageFactory,
            $attrCollectionFactory,
            $customerFactory,
            $data
        );
        $this->_availableBehaviors = [
            \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND,
            \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE,
            \Magento\ImportExport\Model\Import::BEHAVIOR_REPLACE,
        ];
        $this->output = $output;
        $this->_logger = $context->getLogger();
        $this->_debugMode = $helper->getDebugMode();
        $this->_dataSourceModel = $context->getDataSourceModel();
        $this->_resource = $context->getResource();
        $this->_helper = $helper;
        $this->addMessageTemplate(
            self::ERROR_DUPLICATE_EMAIL_FOR_GLOBAL_SCOPE,
            __('A customer with email %s already exists in an associated website.')
        );
        $this->customerGroupFactory = $groupFactory;
        $this->customerGroupCollection = $customerGroup;
        $this->taxClassCollection = $taxClassCollection;
        $this->customerTaxClasses = $this->getCustomerTaxClasses();
        $this->addMessageTemplate(
            self::ERROR_CREATING_GROUP,
            __('Failed to create new customer group. Please check the group name and tax id')
        );
    }

    /**
     * @return array
     */
    public function getAllFields()
    {
        $options = array_merge($this->getValidColumnNames(), $this->_specialAttributes);
        $options = array_merge($options, $this->_permanentAttributes);

        return array_unique($options);
    }

    public function getValidColumnNames()
    {
        return array_merge(
            parent::getValidColumnNames(),
            ['allowed_assistance']
        );
    }

    protected function _importData()
    {
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entitiesCreate = [];
            $entitiesUpdate = [];
            $entitiesDelete = [];
            $attributesToSave = [];
            $assistanceAllowed = [];
            $deleteAssistanceAllowed = [];

            foreach ($bunch as $rowNumber => $rowData) {
                $time = explode(" ", microtime());
                $startTime = $time[0] + $time[1];
                $email = $rowData['email'];
                $rowData = $this->joinIdenticalyData($rowData);
                $website = $rowData[self::COLUMN_WEBSITE];
                if (isset($this->_newCustomers[strtolower($rowData[self::COLUMN_EMAIL])][$website])) {
                    continue;
                }
                $rowData = $this->customChangeData($rowData);
                if (!$this->validateRow($rowData, $rowNumber)) {
                    $this->addLogWriteln(__('customer with email: %1 is not valided', $email), $this->output, 'info');
                    continue;
                }
                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNumber);
                    continue;
                }

                if (\Magento\ImportExport\Model\Import::BEHAVIOR_DELETE == $this->getBehavior($rowData)) {
                    $entitiesDelete[] = $this->_getCustomerId(
                        $rowData[self::COLUMN_EMAIL],
                        $rowData[self::COLUMN_WEBSITE]
                    );
                }
                if (\Magento\ImportExport\Model\Import::BEHAVIOR_ADD_UPDATE == $this->getBehavior($rowData)
                    || \Magento\ImportExport\Model\Import::BEHAVIOR_REPLACE == $this->getBehavior($rowData)
                ) {
                    $processedData = $this->_prepareDataForUpdate($rowData);
                    if (isset($rowData['allowed_assistance'])) {
                        if ($rowData['allowed_assistance'] === '1') {
                            $assistanceAllowed[] = [
                                'customer_id' => $processedData[self::ENTITIES_TO_CREATE_KEY][0]['entity_id']
                                    ?? $processedData[self::ENTITIES_TO_UPDATE_KEY][0]['entity_id']
                            ];
                        } else if ($rowData['allowed_assistance'] === '0') {
                            $deleteAssistanceAllowed[] = [
                                'customer_id' => $processedData[self::ENTITIES_TO_CREATE_KEY][0]['entity_id']
                                    ?? $processedData[self::ENTITIES_TO_UPDATE_KEY][0]['entity_id']
                            ];
                        }
                    }
                    if (\Magento\ImportExport\Model\Import::BEHAVIOR_ADD_UPDATE == $this->getBehavior($rowData)) {
                        $entitiesCreate = array_merge($entitiesCreate, $processedData[self::ENTITIES_TO_CREATE_KEY]);
                    }
                    $entitiesUpdate = array_merge($entitiesUpdate, $processedData[self::ENTITIES_TO_UPDATE_KEY]);
                    foreach ($processedData[self::ATTRIBUTES_TO_SAVE_KEY] as $tableName => $customerAttributes) {
                        if (!isset($attributesToSave[$tableName])) {
                            $attributesToSave[$tableName] = [];
                        }
                        $attributesToSave[$tableName] =
                            array_diff_key(
                                $attributesToSave[$tableName],
                                $customerAttributes
                            ) + $customerAttributes;
                    }
                }
                $time = explode(" ", microtime());
                $endTime = $time[0] + $time[1];
                $totalTime = $endTime - $startTime;
                $totalTime = round($totalTime, 5);

                $this->addLogWriteln(
                    __('customer with email: %1 .... %2s', $email, $totalTime),
                    $this->output,
                    'info'
                );
            }
            $this->updateItemsCounterStats($entitiesCreate, $entitiesUpdate, $entitiesDelete);
            /**
             * Save prepared data
             */
            if ($entitiesCreate || $entitiesUpdate) {
                $this->_saveCustomerEntities($entitiesCreate, $entitiesUpdate);
            }
            if ($attributesToSave) {
                $this->_saveCustomerAttributes($attributesToSave);
            }
            if ($entitiesDelete) {
                $this->_deleteCustomerEntities($entitiesDelete);
            }
            if ($assistanceAllowed) {
                $this->saveAssistanceAllowed($assistanceAllowed);
            }
            if ($deleteAssistanceAllowed) {
                $this->deleteAssistanceAllowed($deleteAssistanceAllowed);
            }
        }

        return true;
    }

    /**
     * Validate row for global scope
     *
     * @param $rowData
     */
    protected function validateRowForGlobalScope($rowData)
    {
        $email = $rowData[self::COLUMN_EMAIL] ?? '';
        $website = $rowData[self::COLUMN_WEBSITE] ?? '';
        if (!empty($email) && !empty($website)) {
            $emailInLowercase = strtolower(trim($email));
            $entityId = $this->_getCustomerId($emailInLowercase, $website);
            if ($this->isEmailExists($email) && !$entityId) {
                $this->addRowError(
                    Customer::ERROR_DUPLICATE_EMAIL_FOR_GLOBAL_SCOPE,
                    $this->_processedRowsCount,
                    $email
                );
            }
        }
    }

    /**
     * Get existing customer email addresses
     *
     * @return array
     */
    protected function getExistCustomerEmails()
    {
        $customers = [];
        if (!empty($this->_customerStorage)) {
            $customers = $this->_customerStorage->_customerCollection->toArray();
        }
        return array_column($customers, self::COLUMN_EMAIL);
    }

    /**
     * @param array $rowData
     * @return array
     */
    protected function useOnlyFieldsFromMapping($rowData = [])
    {
        if (empty($this->_parameters['map'])) {
            return $rowData;
        }
        $requiredFields = ['_website' => 'base'];
        $rowDataAfterMapping = [];
        foreach ($this->_parameters['map'] as $parameter) {
            if (array_key_exists($parameter['import'], $rowData)) {
                $rowDataAfterMapping[$parameter['system']] = $rowData[$parameter['import']];
            }
        }
        foreach ($requiredFields as $k => $value) {
            $rowDataAfterMapping[$k] = !empty($rowData[$k]) ? $rowData[$k] : $value;
        }
        if (empty($rowDataAfterMapping['email'])) {
            $this->addRowError(
                "Required field email is not mapped. Please, complete mapping and retry import.",
                $this->_processedRowsCount
            );
        }
        return $rowDataAfterMapping;
    }

    protected function _saveValidatedBunches()
    {
        $source = $this->getSource();
        $bunchRows = [];
        $startNewBunch = false;

        $source->rewind();
        $masterAttributeCode = $this->getMasterAttributeCode();
        $accountShareScope = $this->_scopeConfig->getValue(Share::XML_PATH_CUSTOMER_ACCOUNT_SHARE);
        $file = null;
        $jobId = null;
        if (isset($this->_parameters['file'])) {
            $file = $this->_parameters['file'];
        }
        if (isset($this->_parameters['job_id'])) {
            $jobId = (int) $this->_parameters['job_id'];
            $this->_dataSourceModel->setJobId($jobId);
        }
        $this->_dataSourceModel->cleanBunches();
        $isSuperUserList = $this->initSuperUserListProcess();
        while ($source->valid() || count($bunchRows) || isset($entityGroup)) {
            if ($startNewBunch || !$source->valid()) {
                /* If the end approached add last validated entity group to the bunch */
                if (!$source->valid() && isset($entityGroup)) {
                    foreach ($entityGroup as $key => $value) {
                        $bunchRows[$key] = $value;
                    }
                    unset($entityGroup);
                }
                $this->_dataSourceModel->saveBunches(
                    $this->getEntityTypeCode(),
                    $this->getBehavior(),
                    $jobId,
                    $file,
                    $bunchRows
                );

                $bunchRows = [];
                $startNewBunch = false;
            }
            if ($source->valid()) {
                $valid = true;
                try {
                    $rowData = $source->current();
                    if ($accountShareScope == Share::SHARE_GLOBAL) {
                        $this->validateRowForGlobalScope($rowData);
                    }
                    $rowData = $this->prepareRowCustomerGroupIdValue($rowData, $source->key());
                    if (!empty($this->_parameters['use_only_fields_from_mapping'])) {
                        $rowData = $this->useOnlyFieldsFromMapping($rowData);
                    }
                    foreach ($rowData as $attrName => $element) {
                        if (!mb_check_encoding($element, 'UTF-8')) {
                            $valid = false;
                            $this->addRowError(
                                AbstractEntity::ERROR_CODE_ILLEGAL_CHARACTERS,
                                $this->_processedRowsCount,
                                $attrName
                            );
                        }
                    }
                } catch (\InvalidArgumentException $e) {
                    $valid = false;
                    $this->addRowError($e->getMessage(), $this->_processedRowsCount);
                }
                if (!$valid) {
                    $this->_processedRowsCount++;
                    $source->next();
                    continue;
                }
                $rowData = $this->customBunchesData($rowData);
                if ($isSuperUserList) {
                    $this->checkSuperUser($rowData, $source->key());
                }
                if (isset($rowData[$masterAttributeCode]) && trim($rowData[$masterAttributeCode])) {
                    /* Add entity group that passed validation to bunch */
                    if (isset($entityGroup)) {
                        foreach ($entityGroup as $key => $value) {
                            $bunchRows[$key] = $value;
                        }
                        $productDataSize = strlen($this->phpSerialize($bunchRows));

                        /* Check if the new bunch should be started */
                        $isBunchSizeExceeded = ($this->_bunchSize > 0 && count($bunchRows) >= $this->_bunchSize);
                        $startNewBunch = $productDataSize >= $this->_maxDataSize || $isBunchSizeExceeded;
                    }

                    /* And start a new one */
                    $entityGroup = [];
                }

                if (isset($entityGroup) && $this->validateRow($rowData, $source->key())) {
                    /* Add row to entity group */
                    $entityGroup[$source->key()] = $this->_prepareRowForDb($rowData);
                } elseif (isset($entityGroup)) {
                    /* In case validation of one line of the group fails kill the entire group */
                    unset($entityGroup);
                }

                $this->_processedRowsCount++;
                $source->next();
            }
        }
        return $this;
    }

    /**
     * @return bool
     */
    protected function initSuperUserListProcess()
    {
        $result = false;
        if (\Magento\ImportExport\Model\Import::BEHAVIOR_DELETE == $this->getBehavior()
            && $this->_connection->isTableExists('company')
        ) {
            $tableName = $this->_resource->getTableName('company');
            $select = $this->_connection->select();
            $select->from(['c' => $tableName], 'c.super_user_id');
            try {
                $data = $this->_connection->fetchAll($select);
            } catch (\Exception $e) {
                $this->_logger->error($e->getMessage());
            }
            if (!empty($data)) {
                foreach ($data as $row) {
                    $this->superUserList[$row['super_user_id']] = 1;
                }
                $result = true;
            }
        }
        return $result;
    }

    /**
     * @param $customerId
     * @return int
     */
    protected function isSuperUser($customerId)
    {
        return isset($this->superUserList[$customerId]) ? 1 : 0;
    }

    /**
     * @param $rowData
     * @param $rowNum
     */
    protected function checkSuperUser($rowData, $rowNum)
    {
        if (isset($rowData[self::COLUMN_EMAIL]) && isset($rowData[self::COLUMN_WEBSITE])) {
            $customerId = $this->_getCustomerId(
                $rowData[self::COLUMN_EMAIL],
                $rowData[self::COLUMN_WEBSITE]
            );
            if ($this->isSuperUser($customerId)) {
                $email = $rowData[self::COLUMN_EMAIL];
                $message = 'Cannot delete the company admin. Customer with email: %1 is company admin.';
                $this->addLogWriteln(__($message, $email), $this->output, 'error');
                $this->addRowError(__($message, $email), $rowNum);
            }
        }
    }

    protected function _validateRowForUpdate(array $rowData, $rowNum)
    {
        if ($this->_checkUniqueKey($rowData, $rowNum)) {
            $email = strtolower($rowData[self::COLUMN_EMAIL]);
            $website = $rowData[self::COLUMN_WEBSITE];
            $this->_newCustomers[$email][$website] = false;

            if (!empty($rowData[self::COLUMN_STORE]) && !isset($this->_storeCodeToId[$rowData[self::COLUMN_STORE]])) {
                $this->addRowError(self::ERROR_INVALID_STORE, $rowNum);
            }
            if (isset($rowData['password']) && strlen($rowData['password'])
                && $this->string->strlen($rowData['password']) < self::MIN_PASSWORD_LENGTH
            ) {
                $this->addRowError(self::ERROR_PASSWORD_LENGTH, $rowNum);
            }
            foreach ($this->_attributes as $attributeCode => $attributeData) {
                if (in_array($attributeCode, $this->_ignoredAttributes)) {
                    continue;
                }
                if (isset($rowData[$attributeCode]) && strlen($rowData[$attributeCode])) {
                    $this->isAttributeValid(
                        $attributeCode,
                        $attributeData,
                        $rowData,
                        $rowNum,
                        isset($this->_parameters[Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR])
                            ? $this->_parameters[Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR]
                            : Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR
                    );
                } elseif ($attributeData['is_required'] && !$this->_getCustomerId($email, $website)) {
                    $this->addRowError(self::ERROR_VALUE_IS_REQUIRED, $rowNum, $attributeCode);
                }
            }
        }
    }

    /**
     * Initialize entity attributes
     *
     * @return $this
     */
    protected function _initAttributes()
    {
        $this->_attributes['confirmation'] = [
            'id' => null,
            'code' => 'confirmation',
            'table' => '',
            'is_required' => false,
            'is_static' => true,
            'rules' => null,
            'type' => 'static'
        ];
        $this->validColumnNames[] = 'confirmation';

        return parent::_initAttributes();
    }

    /**
     * Prepare Row Customer Group Id Value
     *
     * @param $rowData
     * @param $rowNumber
     * @return mixed
     */
    protected function prepareRowCustomerGroupIdValue($rowData, $rowNumber)
    {
        $groupId = $rowData[CustomerInterface::GROUP_ID] ?? '';
        $rowCustomerTaxId = $rowData[ExportCustomer::COLUMN_TAX_CLASS_ID] ?? '';
        $rowCustomerTaxName = $rowData[ExportCustomer::COLUMN_TAX_CLASS_NAME] ?? '';
        $rowCustomerGroupName = $rowData[ExportCustomer::COLUMN_CUSTOMER_GROUP_NAME] ?? '';
        $customerTaxNames = array_flip($this->customerTaxClasses);
        $isValidGroupId = !empty($groupId) &&
            isset($this->_attributes[CustomerInterface::GROUP_ID]['options'][$groupId]);

        if (!empty($rowData[ExportCustomer::COLUMN_CUSTOMER_GROUP_NAME])) {
            $groupId = $this->getCustomerGroupIdByName($rowData[ExportCustomer::COLUMN_CUSTOMER_GROUP_NAME]);
            if ($groupId) {
                $rowData[CustomerInterface::GROUP_ID] = $groupId;
                $this->_attributes[CustomerInterface::GROUP_ID]['options'][$groupId] = $groupId;
                return $rowData;
            }
        }

        if (!$isValidGroupId && !empty($rowCustomerGroupName)) {
            if (!empty($rowCustomerTaxId) && in_array($rowCustomerTaxId, $this->customerTaxClasses)) {
                $taxClassId = $rowCustomerTaxId;
            } elseif (!empty($rowCustomerTaxName) && in_array($rowCustomerTaxName, $customerTaxNames)) {
                $taxClassId = $this->customerTaxClasses[$rowCustomerTaxName];
            }
            if (!empty($taxClassId)) {
                $newGroupId = $this->createNewCustomerGroup($rowCustomerGroupName, $taxClassId);
                if ($newGroupId) {
                    $rowData[CustomerInterface::GROUP_ID] = $newGroupId;
                    $this->_attributes[CustomerInterface::GROUP_ID]['options'][$newGroupId] = $newGroupId;
                    $this->createdCustomerGroups[$rowData[ExportCustomer::COLUMN_CUSTOMER_GROUP_NAME]] = $newGroupId;
                } else {
                    $this->addRowError(self::ERROR_CREATING_GROUP, $rowNumber);
                }
            }
            if (empty($newGroupId)) {
                $this->addRowError(self::ERROR_CREATING_GROUP, $rowNumber);
            }
        }
        return $rowData;
    }

    /**
     * Get Customer Group Id By Name
     *
     * @param $customerGroupName
     * @return false|mixed
     */
    public function getCustomerGroupIdByName($customerGroupName)
    {
        $customerGroups = $this->getCustomerGroups();
        return $customerGroups[$customerGroupName] ?? false;
    }

    /**
     * Get Customer Groups
     *
     * @return array
     */
    public function getCustomerGroups()
    {
        $customerGroups = [];
        foreach ($this->customerGroupCollection->toOptionArray() as $customerGroupData) {
            $customerGroups[$customerGroupData['label']] = $customerGroupData['value'];
        }
        return array_merge($this->createdCustomerGroups, $customerGroups);
    }

    /**
     * Get Customer Tax Classes
     *
     * @return array
     */
    public function getCustomerTaxClasses()
    {
        $customerTaxClasses = [];
        $this->taxClassCollection->addFilter(TaxClassModel::KEY_TYPE, CustomerModel::ENTITY);
        foreach ($this->taxClassCollection->toOptionArray() as $customerTaxData) {
            $customerTaxClasses[$customerTaxData['label']] = $customerTaxData['value'];
        }
        return $customerTaxClasses;
    }

    /**
     * Create New Customer Group
     *
     * @param $groupName
     * @param $taxId
     * @return mixed
     */
    protected function createNewCustomerGroup($groupName, $taxId)
    {
        try {
            $group = $this->customerGroupFactory->create();
            $group->setCode($groupName)
                ->setTaxClassId($taxId)
                ->save();
            return $group->getId();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function saveAssistanceAllowed(array $assistanceAllowed)
    {
        $tableName = $this->_resource->getTableName('login_as_customer_assistance_allowed');

        $this->_connection->insertOnDuplicate(
            $tableName,
            $assistanceAllowed
        );
    }

    private function deleteAssistanceAllowed(array $deleteAssistanceAllowed)
    {
        $tableName = $this->_resource->getTableName('login_as_customer_assistance_allowed');

        $this->_connection->delete(
            $tableName,
            [
                'customer_id IN (?)' => $deleteAssistanceAllowed
            ]
        );
    }

    protected function isEmailExists(string $email)
    {
        $connection  = $this->_resource->getConnection();

        $query = $connection->select()
            ->from($this->_resource->getTableName('customer_entity'), ['entity_id'])
            ->where('email = ?', $email);
        $customer = $connection->fetchOne($query);
        return !empty($customer);
    }
}
