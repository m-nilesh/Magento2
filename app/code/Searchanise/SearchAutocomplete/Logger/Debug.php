<?php

namespace Searchanise\SearchAutocomplete\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;
use Magento\Framework\Filesystem\DriverInterface;
use Searchanise\SearchAutocomplete\Helper\Data as DataHelper;

class Debug extends Base
{
    /**
     * @var integer
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * @var DataHelper
     */
    private $dataHelper;

    public function __construct(
        DriverInterface $filesystem,
        DataHelper $dataHelper,
        $filePath = null,
        $fileName = null
    ) {
        $this->dataHelper = $dataHelper;
        return parent::__construct($filesystem, $filePath, $fileName);
    }

    /**
     * {@inheritDoc}
     * @param array $record
     * @return void
     */
    public function write(array $record): void
    {
        if ($this->dataHelper->checkDebug(true)) {
            parent::write($record);
        }
    }
}
