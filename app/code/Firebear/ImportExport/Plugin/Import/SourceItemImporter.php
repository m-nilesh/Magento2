<?php
declare(strict_types=1);

namespace Firebear\ImportExport\Plugin\Import;

use Firebear\ImportExport\Model\IsSingleSourceModeCacheProcess;
use Magento\CatalogImportExport\Model\StockItemImporterInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\Framework\App\ObjectManager;
use Firebear\ImportExport\Model\Import\SourceManager;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Inventory\Model\SourceItem\Command\Handler\SourceItemsSaveHandler;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * SourceItemImporter Plugin
 */
class SourceItemImporter
{
    /**
     * @var SourceManager
     */
    protected $sourceManager;

    /**
     * Source Item Interface Factory
     *
     * @var SourceItemInterfaceFactory $sourceItemFactory
     */
    private $sourceItemFactory;

    /**
     * Default Source Provider
     *
     * @var DefaultSourceProviderInterface $defaultSource
     */
    private $defaultSource;

    /**
     * @var SourceItemsSaveHandler
     */
    protected $sourceItemsSaveHandler;

    /**
     * @var IsSingleSourceModeCacheProcess
     */
    protected $isSingleSourceModeCacheProcess;

    /**
     * @var ModuleManager
     */
    protected $moduleManager;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * SourceItemImporter constructor.
     * @param SourceManager $sourceManager
     * @param IsSingleSourceModeCacheProcess $isSingleSourceModeCacheProcess
     * @param ModuleManager $moduleManager
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        SourceManager $sourceManager,
        IsSingleSourceModeCacheProcess $isSingleSourceModeCacheProcess,
        ModuleManager $moduleManager,
        ObjectManagerInterface $objectManager,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->sourceManager = $sourceManager;
        $this->isSingleSourceModeCacheProcess = $isSingleSourceModeCacheProcess;
        $this->moduleManager = $moduleManager;
        $this->objectManager = $objectManager;
        $this->resourceConnection = $resource;
        $this->logger = $logger;
    }

    /**
     * After Import
     *
     * @param StockItemImporterInterface $subject
     * @param mixed $result
     * @param array $stockData
     * @return mixed
     */
    public function afterImport(
        StockItemImporterInterface $subject,
                                   $result,
        array $stockData
    ) {
        if ($this->moduleManager->isEnabled('Magento_Inventory')) {
            $sourceData = [];
            if (method_exists($subject, 'getSourceData')) {
                $sourceData = $subject->getSourceData();
            }
            if (interface_exists(DefaultSourceProviderInterface::class)) {
                $this->defaultSource = $this->objectManager
                    ->get(DefaultSourceProviderInterface::class);
            }
            if (class_exists(SourceItemsSaveHandler::class)) {
                $this->sourceItemsSaveHandler = $this->objectManager
                    ->get(SourceItemsSaveHandler::class);
            }

            /**
             * We can't initialize object in constructor because MSI may be disabled
             */
            $this->sourceItemFactory = $this->objectManager->create(SourceItemInterfaceFactory::class);

            $sourceItems = [];
            foreach ($stockData as $sku => $stockDatum) {
                $sourceCode = $sourceData[$sku] ?? $this->defaultSource->getCode();
                $inStock = (isset($stockDatum['is_in_stock'])) ? ((int)$stockDatum['is_in_stock']) : 0;
                $qty = (isset($stockDatum['qty'])) ? $stockDatum['qty'] : 0;
                $sourceItem = $this->sourceItemFactory->create();
                $sourceItem->setSku((string)$sku);
                $sourceItem->setSourceCode($sourceCode);
                $sourceItem->setQuantity((float)$qty);
                $sourceItem->setStatus($inStock);
                $sourceItems[] = $sourceItem;
            }
            if (!empty($sourceItems)) {
                $this->isSingleSourceModeCacheProcess->enableCache();
                /** SourceItemInterface[] $sourceItems */
                $this->sourceItemsSaveHandler->execute($sourceItems);
                $this->isSingleSourceModeCacheProcess->disableCache();
            }
            /**
             * Update legacy stock status
             */
            $this->updateLegacyStockStatus($stockData);
        }
        return $result;
    }

    /**
     * @param array $stockData
     * @return void
     */
    protected function updateLegacyStockStatus(array $stockData): void
    {
        foreach ($stockData as $data) {
            if (isset($data['product_id']) && isset($data['website_id'])) {
                $productId = (int) $data['product_id'];
                $websiteId = (int) $data['website_id'];
                $inStock = (isset($data['is_in_stock'])) ? ((int)$data['is_in_stock']) : 0;
                $qty = (isset($data['qty'])) ? $data['qty'] : 0;

                try {
                    $connection = $this->resourceConnection->getConnection();
                    $connection->update(
                        $this->resourceConnection->getTableName('cataloginventory_stock_status'),
                        [
                            StockStatusInterface::QTY => $qty,
                            StockStatusInterface::STOCK_STATUS => $inStock,
                        ],
                        [
                            StockStatusInterface::PRODUCT_ID . ' = ?' => $productId,
                            'website_id = ?' => $websiteId,
                        ]
                    );
                } catch (\Exception $e) {
                    $this->logger->warning(
                        __(
                            'Could not save stock status for product %1. Error: %2',
                            $productId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }
    }
}