<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Ui\Component\Listing\Column\Filter\Type;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class Options
 */
class Options implements OptionSourceInterface
{
    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['label' => 'Equal', 'value' => 'equal'],
            ['label' => 'Not equal', 'value' => 'not_equal'],
            ['label' => 'More or equal', 'value' => 'more_or_equal'],
            ['label' => 'Less or equal', 'value' => 'less_or_equal'],
            ['label' => 'More or equal', 'value' => 'more_or_equal_date'],
            ['label' => 'Less or equal', 'value' => 'less_or_equal_date'],
            ['label' => 'Range', 'value' => 'range'],
            ['label' => 'Range', 'value' => 'range_date'],
            ['label' => 'Contains', 'value' => 'contains'],
            ['label' => 'Not contains', 'value' => 'not_contains'],
        ];
    }
}
