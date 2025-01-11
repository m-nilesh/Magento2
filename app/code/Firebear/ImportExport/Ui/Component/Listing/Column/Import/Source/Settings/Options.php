<?php
/**
 * @copyright: Copyright © 2019 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Ui\Component\Listing\Column\Import\Source\Settings;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Escaper;
use Magento\Store\Model\System\Store as SystemStore;

/**
 * Class Options
 * @package Firebear\ImportExport\Ui\Component\Listing\Column\Import\Source\Settings
 */
class Options implements OptionSourceInterface
{
    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var SystemStore
     */
    protected $systemStore;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var array
     */
    protected $currentOptions = [];

    /**
     * Constructor
     *
     * @param SystemStore $systemStore
     * @param Escaper $escaper
     */
    public function __construct(SystemStore $systemStore, Escaper $escaper)
    {
        $this->systemStore = $systemStore;
        $this->escaper = $escaper;
    }

    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $this->generateCurrentOptions();

        $this->options = array_values($this->currentOptions);

        return $this->options;
    }

    /**
     * Generate current options
     *
     * @return void
     */
    protected function generateCurrentOptions(): void
    {
        $websiteCollection = $this->systemStore->getWebsiteCollection();

        foreach ($websiteCollection as $website) {
            $this->currentOptions[] = [
                'label' => $this->sanitizeName($website->getName()),
                'value' => $website->getId(),
            ];
        }
    }

    /**
     * Sanitize website/store option name
     *
     * @param string $name
     *
     * @return string
     */
    protected function sanitizeName($name)
    {
        $matches = [];
        preg_match('/\$[:]*{(.)*}/', $name ?: '', $matches);
        if (count($matches) > 0) {
            $name = $this->escaper->escapeHtml($this->escaper->escapeJs($name));
        } else {
            $name = $this->escaper->escapeHtml($name);
        }

        return $name;
    }
}
