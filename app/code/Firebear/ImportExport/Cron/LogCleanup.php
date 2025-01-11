<?php
/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */
namespace Firebear\ImportExport\Cron;

use Firebear\ImportExport\Logger\Logger;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\DateTime as DateConversion;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Firebear\ImportExport\Model\ResourceModel\Import\History as ImportHistory;
use Firebear\ImportExport\Model\ResourceModel\Export\History as ExportHistory;

class LogCleanup
{
    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var DateConversion
     */
    private $date;

    /**
     * @var ImportHistory
     */
    private $importHistoryResource;

    /**
     * @var ExportHistory
     */
    private $exportHistoryResource;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * LogCleanup constructor.
     * @param DateTime $dateTime
     * @param ScopeConfigInterface $scopeConfig
     * @param DateConversion $time
     * @param ImportHistory $importHistoryResource
     * @param ExportHistory $exportHistoryResource
     * @param Logger $logger
     */
    public function __construct(
        DateTime $dateTime,
        ScopeConfigInterface $scopeConfig,
        DateConversion $time,
        ImportHistory $importHistoryResource,
        ExportHistory $exportHistoryResource,
        Logger $logger
    ) {
        $this->dateTime = $dateTime;
        $this->scopeConfig = $scopeConfig;
        $this->date = $time;
        $this->importHistoryResource = $importHistoryResource;
        $this->exportHistoryResource = $exportHistoryResource;
        $this->logger = $logger;
    }

    /**
     * Remove all expired logs
     *
     * @return void
     */
    public function execute()
    {
        $isEnableClearLog = $this->scopeConfig->getValue('firebear_importexport/general/clear_log');

        if ($isEnableClearLog) {
            $this->clearLogFile($this->importHistoryResource);
            $this->clearLogFile($this->exportHistoryResource);
        }
    }

    /**
     * Clear LogFile
     *
     * @param ImportHistory|ExportHistory $resource
     */
    protected function clearLogFile($resource)
    {
        $connection = $resource->getConnection();
        $logLifetime = 3600 * 24 * (int)$this->scopeConfig->getValue('firebear_importexport/general/log_lifetime');
        $maxLogUpdateAtTime = $this->dateTime->formatDate($this->date->gmtTimestamp() - $logLifetime);
        $select = $connection->select()
            ->from($resource->getMainTable(),['file'])
            ->where('started_at <= ?', $maxLogUpdateAtTime);
        $filesToRemove = $connection->fetchAssoc($select);
        if (!empty($filesToRemove)) {
            foreach ($filesToRemove as $file) {
                $this->logger->setFileName($file['file']);
                $this->logger->clear();
            }
        }
    }
}
