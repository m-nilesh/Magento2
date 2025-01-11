<?php
/**
 * @copyright: Copyright © 2019 Firebear Studio. All rights reserved.
 * @author : Firebear Studio <fbeardev@gmail.com>
 */
namespace Firebear\ImportExport\Model\Export;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\ImportExport\Model\Export\AbstractEntity;
use Magento\ImportExport\Model\Export\Factory as CollectionFactory;
use Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Newsletter\Model\ResourceModel\Subscriber\Collection as SubscriberCollection;
use Magento\Newsletter\Model\Subscriber;
use Symfony\Component\Console\Output\ConsoleOutput;
use Psr\Log\LoggerInterface;
use Firebear\ImportExport\Traits\Export\Entity as ExportTrait;
use Firebear\ImportExport\Model\ExportJob\Processor;

/**
 * Newsletter Subscriber export
 */
class NewsletterSubscriber extends AbstractEntity implements EntityInterface
{
    use ExportTrait;

    /**
     * Console Output
     *
     * @var \Symfony\Component\Console\Output\ConsoleOutput
     */
    protected $output;

    /**
     * Logger Interface
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * Entity collection
     *
     * @var \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
     */
    protected $entityCollection;

    /**
     * Collection factory
     *
     * @var \Magento\ImportExport\Model\Export\Factory
     */
    protected $collectionFactory;

    /**
     * Export fields
     *
     * @var array
     */
    protected $exportFields = [
        'subscriber_id',
        'subscriber_email',
        'store_id',
        'subscriber_status',
        'change_status_at',
        'firstname',
        'lastname',
        'password_hash',
        'subscriber_confirm_code'
    ];

    /**
     * Resource Model
     *
     * @var ResourceConnection
     */
    protected $_resourceModel;

    /**
     * Initialize export
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CollectionFactory $collectionFactory
     * @param CollectionByPagesIteratorFactory $resourceColFactory
     * @param LoggerInterface $logger
     * @param ConsoleOutput $output
     * @param ResourceConnection $resource
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CollectionFactory $collectionFactory,
        CollectionByPagesIteratorFactory $resourceColFactory,
        LoggerInterface $logger,
        ConsoleOutput $output,
        ResourceConnection $resource,
        array $data = []
    ) {
        $this->_logger = $logger;
        $this->output = $output;
        $this->collectionFactory = $collectionFactory;
        $this->_resourceModel = $resource;

        parent::__construct(
            $scopeConfig,
            $storeManager,
            $collectionFactory,
            $resourceColFactory,
            $data
        );
    }

    /**
     * Export process
     *
     * @return array
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
            $this->lastEntityId
        ];
    }

    /**
     * Export one item
     *
     * @param \Magento\Framework\Model\AbstractModel $item
     * @return void
     */
    public function exportItem($item)
    {
        $this->lastEntityId = $item->getId();
        $rowData = $item->toArray($this->getFieldsForExport());
        $this->getWriter()->writeRow(
            $this->changeRow($rowData)
        );
        $this->_processedEntitiesCount++;
    }

    /**
     * Entity type code getter
     *
     * @return string
     */
    public function getEntityTypeCode()
    {
        return 'newsletter_subscriber';
    }

    /**
     * Retrieve header columns
     *
     * @return array
     */
    protected function _getHeaderColumns()
    {
        return $this->changeHeaders(
            $this->getFieldsForExport()
        );
    }

    /**
     * Apply filter to collection
     *
     * @param AbstractCollection $collection
     * @return \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
     */
    protected function _prepareEntityCollection(AbstractCollection $collection)
    {
        if (!empty($this->_parameters[Processor::LAST_ENTITY_ID]) &&
            $this->_parameters[Processor::LAST_ENTITY_SWITCH] > 0
        ) {
            $collection->addFieldToFilter(
                'main_table.subscriber_id',
                ['gt' => $this->_parameters[Processor::LAST_ENTITY_ID]]
            );
        }

        $entity = $this->getEntityTypeCode();
        $fields = [];
        $columns = $this->getFieldColumns();
        foreach ($columns[$this->getEntityTypeCode()] as $field) {
            $fields[$field['field']] = $field['type'];
        }

        $collection = $this->addFiltersToCollection($collection, $entity, $fields);

        return $collection;
    }

    /**
     * Retrieve entity collection
     *
     * @return \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
     */
    protected function _getEntityCollection()
    {
        if (null === $this->entityCollection) {
            $this->entityCollection = $this->collectionFactory->create(
                SubscriberCollection::class
            )->showCustomerInfo();

            $this->entityCollection->getSelect()
                ->columns(['customer.password_hash']);
        }
        return $this->entityCollection;
    }

    /**
     * Retrieve entity field columns
     *
     * @return array
     */
    public function getFieldColumns()
    {
        $options = [];
        foreach ($this->getFieldsForExport() as $field) {
            $select = [];
            $type = 'text';
            if ($field == 'subscriber_id') {
                $type = 'int';
            }

            if ($field == 'change_status_at') {
                $type = 'date';
            }

            if ($field == 'subscriber_status') {
                $type = 'select';
                $select[] = ['label' => __('Subscribed'), 'value' => Subscriber::STATUS_SUBSCRIBED];
                $select[] = ['label' => __('Not Activated'), 'value' => Subscriber::STATUS_NOT_ACTIVE];
                $select[] = ['label' => __('Unsubscribed'), 'value' => Subscriber::STATUS_UNSUBSCRIBED];
                $select[] = ['label' => __('Unconfirmed'), 'value' => Subscriber::STATUS_UNCONFIRMED];
            }
            $options[$this->getEntityTypeCode()][] = [
                'field' => $field,
                'type' => $type,
                'select' => $select
            ];
        }
        return $options;
    }

    /**
     * Retrieve entity field for filter
     *
     * @return array
     */
    public function getFieldsForFilter()
    {
        $options = [];
        foreach ($this->getFieldsForExport() as $field) {
            $options[] = [
                'label' => $field,
                'value' => $field
            ];
        }
        return [$this->getEntityTypeCode() => $options];
    }

    /**
     * Retrieve entity field for export
     *
     * @return array
     */
    public function getFieldsForExport()
    {
        return $this->exportFields;
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
