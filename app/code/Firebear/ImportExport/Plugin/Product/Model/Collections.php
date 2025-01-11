<?php
/**
 * @copyright: Copyright © 2023 Firebear Studio GmbH. All rights reserved.
 * @author: Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Plugin\Product\Model;

/**
 * Class Collections to avoid additional filtering by website when a Firebear job is running
 */
class Collections
{
    /**
     * Around LimitProducts
     *
     * @param \Magento\AdminGws\Model\Collections $model
     * @param \Closure $proceed
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     */
    public function aroundLimitProducts(
        \Magento\AdminGws\Model\Collections $model,
        \Closure $proceed,
        $collection
    ) {
        $flag = $collection->getFlag('firebear_product_collection');
        if (!$flag) {
            $proceed($collection);
        }
    }

    /**
     * Around LimitCatalogCategories
     *
     * @param \Magento\AdminGws\Model\Collections $model
     * @param \Closure $proceed
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     */
    public function aroundLimitCatalogCategories(
        \Magento\AdminGws\Model\Collections $model,
        \Closure $proceed,
        $collection
    ) {
        $flag = $collection->getFlag('firebear_category_collection');
        if (!$flag) {
            $proceed($collection);
        }
    }
}
