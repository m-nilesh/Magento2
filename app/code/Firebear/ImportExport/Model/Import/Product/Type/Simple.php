<?php

/**
 * @copyright: Copyright © 2018 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */
declare(strict_types=1);

namespace Firebear\ImportExport\Model\Import\Product\Type;

use Firebear\ImportExport\Traits\Import\Product\Type;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Import;

/**
 * Class Downloadable
 */
class Simple extends \Magento\CatalogImportExport\Model\Import\Product\Type\Simple
{
    use Type;

    /**
     * @var Config
     */
    protected $eavConfig;

    /**
     * @var AttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * Simple constructor.
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory $attrSetColFac
     * @param CollectionFactory $prodAttrColFac
     * @param ResourceConnection $resource
     * @param array $params
     * @param MetadataPool|null $metadataPool
     * @param Config $eavConfig
     * @throws LocalizedException
     */
    public function __construct(
        \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory $attrSetColFac,
        CollectionFactory $prodAttrColFac,
        ResourceConnection $resource,
        array $params,
        Config $eavConfig,
        AttributeRepositoryInterface $attributeRepository,
        MetadataPool $metadataPool = null
    ) {
        $this->eavConfig = $eavConfig;
        $this->attributeRepository = $attributeRepository;
        parent::__construct($attrSetColFac, $prodAttrColFac, $resource, $params, $metadataPool);

        if (!empty(self::$commonAttributesCache)) {
            foreach (self::$commonAttributesCache as $attributeId => $attributeData) {
                $attribute = $this->attributeRepository->get('catalog_product', $attributeData['code']);
                if (!isset(self::$commonAttributesCache[$attributeId]['is_user_defined'])) {
                    $options = $this->_entityModel->getAttributeOptions(
                        $attribute,
                        $this->_indexValueAttributes
                    );
                    self::$commonAttributesCache[$attributeId] = [
                        'id' => $attributeId,
                        'code' => $attribute->getAttributeCode(),
                        'is_user_defined' => $attribute->getIsUserDefined(),
                        'is_global' => $attribute->getIsGlobal(),
                        'is_required' => $attribute->getIsRequired(),
                        'is_unique' => $attribute->getIsUnique(),
                        'frontend_label' => $attribute->getFrontendLabel(),
                        'is_static' => $attribute->isStatic(),
                        'apply_to' => $attribute->getApplyTo(),
                        'type' => Import::getAttributeType($attribute),
                        'options' => isset($options['admin']) ? $options['admin'] : $options,
                        'options_store' => $options,
                        'additional_data' => \json_decode($attribute->getData('additional_data') ?? '', true),
                        'default_value' => $attribute->getDefaultValue() !== '' ? $attribute->getDefaultValue() : null,
                    ];
                }
            }
        }
        if (!empty($this->_attributes)) {
            foreach ($this->_attributes as $attributeSet => $data) {
                foreach ($data as $attributeCode => $attributeParams) {
                    if (!isset($attributeParams['is_user_defined'])) {
                        $attribute = $this->attributeRepository->get('catalog_product', $attributeCode);
                        $options = $this->_entityModel->getAttributeOptions(
                            $attribute,
                            $this->_indexValueAttributes
                        );
                        $this->_attributes[$attributeSet][$attributeCode] = self::$commonAttributesCache[$attribute->getAttributeId()] ?? [
                            'id' => $attribute->getAttributeId(),
                            'code' => $attribute->getAttributeCode(),
                            'is_user_defined' => $attribute->getIsUserDefined(),
                            'is_global' => $attribute->getIsGlobal(),
                            'is_required' => $attribute->getIsRequired(),
                            'is_unique' => $attribute->getIsUnique(),
                            'frontend_label' => $attribute->getFrontendLabel(),
                            'is_static' => $attribute->isStatic(),
                            'apply_to' => $attribute->getApplyTo(),
                            'type' => Import::getAttributeType($attribute),
                            'options' => isset($options['admin']) ? $options['admin'] : $options,
                            'options_store' => $options,
                            'additional_data' => \json_decode($attribute->getData('additional_data') ?? '', true),
                            'default_value' => $attribute->getDefaultValue() !== '' ? $attribute->getDefaultValue() : null,
                        ];
                    }
                }
            }
        }
    }

    /**
     * @param array $rowData
     * @return array
     */
    protected function addAdditionalAttributes(array $rowData)
    {
        return [];
    }
}
