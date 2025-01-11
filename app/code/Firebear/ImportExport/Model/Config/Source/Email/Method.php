<?php
/**
 * @copyright: Copyright © 2022 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

/**
 * Source for email send method
 */
namespace Firebear\ImportExport\Model\Config\Source\Email;

/**
 * Class Method
 * @package Firebear\ImportExport\Model\Config\Source\Email
 */
class Method implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = [
            ['value' => 'bcc', 'label' => __('Bcc')],
            ['value' => 'cc', 'label' => __('Cc')],
        ];
        return $options;
    }
}
