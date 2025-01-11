<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Export\Product\Bundle;

use Magento\Bundle\Model\ResourceModel\Selection\Collection as SelectionCollection;
use \Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\CatalogImportExport\Model\Import\Product as ImportProductModel;
use Magento\ImportExport\Model\Import as ImportModel;

/**
 * Class RowCustomizer
 *
 * @package Firebear\ImportExport\Model\Export\Product\Bundle
 */
class RowCustomizer extends \Magento\BundleImportExport\Model\Export\RowCustomizer
{
    /**
     * Mapping for shipment type
     *
     * @var array
     */
    private $shipmentTypeMapping = [
        AbstractType::SHIPMENT_TOGETHER => 'Together',
        AbstractType::SHIPMENT_SEPARATELY => 'Separately',
    ];

    private $shipmentTypeColumn = 'bundle_shipment_type';

    /**
     * Retrieve bundle type value by code
     *
     * @param string $type
     * @return string
     */
    protected function getTypeValue($type)
    {
        $valueDynamic = self::VALUE_DYNAMIC;
        return isset($this->typeMapping[$type]) ? __($this->typeMapping[$type]) : __($valueDynamic);
    }

    protected function getPriceViewValue($type)
    {
        $valuePriceRange = self::VALUE_PRICE_RANGE;
        return isset($this->priceViewMapping[$type]) ? __($this->priceViewMapping[$type]) : __($valuePriceRange);
    }

    protected function getPriceTypeValue($type)
    {
        return isset($this->priceTypeMapping[$type]) ? __($this->priceTypeMapping[$type]) : null;
    }

    private function getShipmentTypeValue($type)
    {
        return isset($this->shipmentTypeMapping[$type]) ? __($this->shipmentTypeMapping[$type]) : null;
    }

    /**
     * Retrieve formatted bundle selections
     *
     * @param string $optionValues
     * @param SelectionCollection $selections
     * @return string
     */
    protected function getFormattedBundleSelections($optionValues, SelectionCollection $selections)
    {
        $bundleData = '';
        $selections->addAttributeToSort('position');
        foreach ($selections as $selection) {
            $selectionData = [
                'sku' => $selection->getSku(),
                'price' => $selection->getSelectionPriceValue(),
                'default' => $selection->getIsDefault(),
                'default_qty' => $selection->getSelectionQty(),
                'price_type' => $this->getPriceTypeValue($selection->getSelectionPriceType()),
                'can_change_qty' => $selection->getSelectionCanChangeQty(),
                'option_id' => $selection->getOptionId(),
                'selection_id' => $selection->getSelectionId()
            ];
            $bundleData .= $optionValues
                . ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR
                . implode(
                    ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR,
                    array_map(
                        function ($value, $key) {
                            return $key . ImportProductModel::PAIR_NAME_VALUE_SEPARATOR . $value;
                        },
                        $selectionData,
                        array_keys($selectionData)
                    )
                )
                . ImportProductModel::PSEUDO_MULTI_LINE_SEPARATOR;
        }

        return $bundleData;
    }
}
