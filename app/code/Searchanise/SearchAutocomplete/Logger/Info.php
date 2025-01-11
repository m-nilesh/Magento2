<?php

namespace Searchanise\SearchAutocomplete\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Info extends Base
{
    /**
     * @var integer
     */
    protected $loggerType = Logger::INFO;
}
