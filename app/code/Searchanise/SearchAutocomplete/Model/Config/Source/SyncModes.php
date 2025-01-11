<?php

namespace Searchanise\SearchAutocomplete\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Searchanise\SearchAutocomplete\Model\Configuration;

class SyncModes implements OptionSourceInterface
{
    /**
     * Option getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $arr = $this->toArray();
        $ret = [];

        foreach ($arr as $key => $value) {
            $ret[] = [
                'value' => $key,
                'label' => $value
            ];
        }

        return $ret;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        $choose = [
            Configuration::SYNC_MODE_REALTIME => __('When catalog updates'),
            Configuration::SYNC_MODE_PERIODIC => __('Periodically via cron'),
            Configuration::SYNC_MODE_MANUAL   => __('Manual'),
        ];

        return $choose;
    }
}
