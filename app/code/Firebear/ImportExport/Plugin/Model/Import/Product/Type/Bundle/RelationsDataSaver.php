<?php
/**
 * Copyright © 2018 Firebear Studio GmbH. All rights reserved.
 */

namespace Firebear\ImportExport\Plugin\Model\Import\Product\Type\Bundle;

use Magento\Catalog\Model\ResourceModel\Product\Relation;
use Magento\Framework\App\ResourceConnection;

/**
 * Class RelationsDataSaver
 *
 * @package Firebear\ImportExport\Plugin\Model\Import\Product\Type\Bundle
 */
class RelationsDataSaver
{
    /**
     * @var Relation
     */
    protected $management;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * RelationsDataSaver constructor.
     *
     * @param Relation $management
     */
    public function __construct(
        Relation $management,
        ResourceConnection $resource
    ) {
        $this->management = $management;
        $this->resource = $resource;
    }

    /**
     * @param \Magento\BundleImportExport\Model\Import\Product\Type\Bundle\RelationsDataSaver $model
     * @param \Closure $work
     * @param array $selections
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function aroundSaveSelections(
        \Magento\BundleImportExport\Model\Import\Product\Type\Bundle\RelationsDataSaver $model,
        \Closure $work,
        array $selections
    ) {
        $work($selections);
        if (!empty($selections)) {
            foreach ($selections as $item) {
                if ($item['parent_product_id'] && $item['product_id']) {
                    $this->management->addRelation($item['parent_product_id'], $item['product_id']);
                }
            }
        }
    }

    /**
     * @param \Magento\BundleImportExport\Model\Import\Product\Type\Bundle\RelationsDataSaver $model
     * @param \Closure $work
     * @param array $optionValues
     */
    public function aroundSaveOptionValues(
        \Magento\BundleImportExport\Model\Import\Product\Type\Bundle\RelationsDataSaver $model,
        \Closure $work,
        array $optionValues
    ) {
        if (!empty($optionValues)) {
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName('catalog_product_bundle_option_value'),
                $optionValues,
                [
                    'option_id',
                    'store_id',
                    'title',
                    'parent_product_id'
                ]
            );
        }
    }
}
