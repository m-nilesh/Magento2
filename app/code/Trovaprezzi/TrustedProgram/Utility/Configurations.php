<?php

declare(strict_types=1);

namespace Trovaprezzi\TrustedProgram\Utility;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Configurations
 */
readonly class Configurations
{
    /**
     * Configurations constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check if Trusted is enabled and with key
     *
     * @return bool
     */
    public function getAccountId(): bool
    {
        return $this->scopeConfig->getValue(Constants::XML_PATH_ACCOUNT, ScopeInterface::SCOPE_STORE) || '';
    }

    /**
     * Check if Trusted is enabled and with key
     *
     * @return bool
     */
    public function isTPAvailable(): bool
    {
        return $this->getAccountId() && $this->scopeConfig->isSetFlag(Constants::XML_PATH_ACTIVE);
    }
}
