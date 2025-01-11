<?php

namespace Searchanise\SearchAutocomplete\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Error extends Base
{
    /**
     * @var integer
     */
    protected $loggerType = Logger::WARNING;
}
