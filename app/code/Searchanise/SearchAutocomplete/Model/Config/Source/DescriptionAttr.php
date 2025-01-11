<?php

namespace Searchanise\SearchAutocomplete\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Searchanise\SearchAutocomplete\Model\Configuration;

class DescriptionAttr implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            Configuration::ATTR_SHORT_DESCRIPTION => __('Short Description'),
            Configuration::ATTR_DESCRIPTION       => __('Description'),
        ];
    }
}
