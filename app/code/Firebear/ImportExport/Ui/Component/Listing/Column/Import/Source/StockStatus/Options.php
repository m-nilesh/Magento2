<?php

namespace Firebear\ImportExport\Ui\Component\Listing\Column\Import\Source\StockStatus;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class Options
 */
class Options implements OptionSourceInterface
{
    const NOT_SET = 0;
    const OUT_OF_STOCK = 1;
    const ZERO_QTY = 2;
    const OUT_OF_STOCK_AND_ZERO_QTY = 3;
    const DISABLE_PRODUCT = 4;
    const CHANGE_VISILIBILITY_TO_CATALOG_SEARCH = 5;
    const CHANGE_VISILIBILITY_TO_SEARCH = 6;
    const CHANGE_VISILIBILITY_TO_CATALOG = 7;

    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'label' => __('Not set'),
                'value' => self::NOT_SET
            ],
            [
                'label' => __('Set products to \'Out of stock\' status'),
                'value' => self::OUT_OF_STOCK
            ],
            [
                'label' => __('Set products qty to 0'),
                'value' => self::ZERO_QTY
            ],
            [
                'label' => __('Set products to \'Out of stock\' status and set products qty to 0'),
                'value' => self::OUT_OF_STOCK_AND_ZERO_QTY
            ],
            [
                'label' => __('Disable products'),
                'value' => self::DISABLE_PRODUCT
            ],
            [
                'label' => __('Change visibility to Catalog,Search'),
                'value' => self::CHANGE_VISILIBILITY_TO_CATALOG_SEARCH
            ],
            [
                'label' => __('Change visibility to Search'),
                'value' => self::CHANGE_VISILIBILITY_TO_SEARCH
            ],
            [
                'label' => __('Change visibility to Catalog'),
                'value' => self::CHANGE_VISILIBILITY_TO_CATALOG
            ],
        ];
    }
}
