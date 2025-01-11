<?php
/**
 * @copyright: Copyright © 2023 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Export\Product\FieldsPool;

use Firebear\ImportExport\Model\Export\Product\AdditionalFieldsInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * ParentSku Model
 */
class ParentSku implements AdditionalFieldsInterface
{
    /**
     * @var ResourceConnection
     */
    protected $resourceModel;

    /**
     * DB connection
     *
     * @var AdapterInterface
     */
    protected $connection;

    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * @var Config
     */
    protected $eavConfig;

    /**
     * @var int
     */
    protected $productUrlAttributeId;

    /**
     * ParentSku constructor.
     * @param ResourceConnection $resource
     * @param MetadataPool $metadataPool
     * @param Config $eavConfig
     */
    public function __construct(
        ResourceConnection $resource,
        MetadataPool $metadataPool,
        Config $eavConfig
    ) {
        $this->resourceModel = $resource;
        $this->connection = $resource->getConnection();
        $this->metadataPool = $metadataPool;
        $this->eavConfig = $eavConfig;
    }

    /**
     * Add Fields
     *
     * @param array $rows
     * @return $this
     */
    public function addFields(array &$rows): self
    {
        $skus = array_map(function ($item) {
            return current($item)[ProductInterface::SKU];
        }, $rows);
        $parentSkus = $this->getParentSkus($this->getProductIds($skus));
        foreach ($rows as $prodId => $product) {
            foreach ($product as $storeId => $fields) {
                if (isset($parentSkus[$prodId])) {
                    $rows[$prodId][$storeId]['parent_sku'] = $parentSkus[$prodId]['sku'];
                    $rows[$prodId][$storeId]['parent_url'] = $parentSkus[$prodId]['url'];
                }
            }
        }
        return $this;
    }

    /**
     * Get ProductIds
     *
     * @param [] $skus
     * @return mixed
     */
    private function getProductIds($skus)
    {
        $select = $this->connection
            ->select()
            ->from(['cpe' => $this->resourceModel->getTableName('catalog_product_entity')], 'entity_id')
            ->where('sku IN (?)', $skus);

        return $this->connection->fetchCol($select);
    }

    /**
     * Get ParentSkus
     *
     * @param array $childProductIds
     * @return array
     */
    private function getParentSkus(array $childProductIds): array
    {
        $result = [];
        $productUrlAttributeId = $this->getProductUrlAttributeId();
        $metadata = $this->metadataPool->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class);
        $fieldForParent = $metadata->getLinkField();
        $select = $this->connection
            ->select()
            ->from(['relation' => $this->resourceModel->getTableName('catalog_product_relation')], ['child_id'])
            ->where('child_id IN (?)', $childProductIds, \Zend_Db::INT_TYPE)
            ->join(
                ['cpe' => $this->resourceModel->getTableName('catalog_product_entity')],
                'relation.parent_id = cpe.' . $fieldForParent,
                ['cpe.sku']
            )->joinLeft(
                ['ev' => $this->resourceModel->getTableName('catalog_product_entity_varchar')],
                "(relation.parent_id = ev.$fieldForParent) and (ev.attribute_id = $productUrlAttributeId)",
                ['ev.value']
            );

        $parentSkus = $this->connection->fetchAll($select);
        foreach ($parentSkus as $key => $data) {
            if (isset($result[$data['child_id']]['sku'])) {
                $result[$data['child_id']]['sku'] .= ',' . $data['sku'];
                $result[$data['child_id']]['url'] .= ',' . $data['value'];
            } else {
                $result[$data['child_id']]['sku'] = $data['sku'];
                $result[$data['child_id']]['url'] = $data['value'];
            }
        }
        return $result;
    }

    /**
     * Get ProductUrlAttributeId
     *
     * @return int|mixed|null
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getProductUrlAttributeId()
    {
        if (empty($this->productUrlAttributeId)) {
            $attribute = $this->eavConfig->getAttribute('catalog_product', 'url_key');
            if ($attribute) {
                $this->productUrlAttributeId = $attribute->getAttributeId();
            }
        }
        return $this->productUrlAttributeId;
    }

    /**
     * Get Headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return ['parent_sku','parent_url'];
    }
}
