<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Ui\Component\Listing\Column\Entity\Import;

use Firebear\ImportExport\Helper\Data as HelperData;
use Firebear\ImportExport\Model\Import\Product;
use Firebear\ImportExport\Model\Source\Config\CartPrice;
use Firebear\ImportExport\Model\Source\Import\Config;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\Attribute\CollectionFactory as CategoryAttributeCollectionFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Registry;
use Magento\ImportExport\Model\Import\Entity\Factory as EntityFactory;
use Firebear\ImportExport\Model\Import\SourceManager;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

/**
 * Class Options
 */
class Options implements OptionSourceInterface
{

    const CATALOG_PRODUCT = 'catalog_product';

    const CATALOG_CATEGORY = 'catalog_category';

    /**
     * @var array
     */
    protected $options;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory
     */
    protected $attributeFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\Attribute\CollectionFactory
     */
    protected $attributeCategoryFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection
     */
    protected $attributeCollection;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\Attribute\Collection
     */
    protected $attributeCategoryCollection;

    /**
     * @var Product
     */
    protected $productImportModel;

    protected $factory;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Magento\ImportExport\Model\Import\Entity\Factory
     */
    protected $entityFactory;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var \Firebear\ImportExport\Model\Source\Config\CartPrice
     */
    protected $cartPrice;

    protected $coreRegistry;

    protected $importConfig;

    /**
     * @var SourceManager
     */
    protected $sourceManager;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var SourceRepositoryInterface
     */
    protected $sourceRepository;

    /**
     * Options constructor.
     *
     * @param ProductAttributeCollectionFactory $attributeFactory
     * @param CategoryAttributeCollectionFactory $attributeCategoryFactory
     * @param Config $config
     * @param EntityFactory $entityFactory
     * @param CartPrice $cartPrice
     * @param HelperData $helper
     * @param Registry $coreRegistry
     * @param Config $importConfig
     * @param SourceManager $sourceManager
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        ProductAttributeCollectionFactory $attributeFactory,
        CategoryAttributeCollectionFactory $attributeCategoryFactory,
        Config $config,
        EntityFactory $entityFactory,
        CartPrice $cartPrice,
        HelperData $helper,
        Registry $coreRegistry,
        Config $importConfig,
        SourceManager $sourceManager,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->attributeFactory = $attributeFactory;
        $this->attributeCategoryFactory = $attributeCategoryFactory;
        $this->config = $config;
        $this->entityFactory = $entityFactory;
        $this->cartPrice = $cartPrice;
        $this->helper = $helper;
        $this->coreRegistry = $coreRegistry;
        $this->importConfig = $importConfig;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sourceManager = $sourceManager;
    }

    /**
     * @param int $withoutGroup
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function toOptionArray($withoutGroup = 0, $entity = false)
    {
        $newOptions = [];

        if (!$entity) {
            $job = $this->coreRegistry->registry('import_job');
            if ($job->getId()) {
                $entity = $job->getEntity();
            } else {
                return [];
            }
        }

        foreach ($this->config->getEntities() as $key => $items) {
            if ($entity && $entity != $key) {
                continue;
            }
            if (in_array($key, [
                self::CATALOG_PRODUCT
            ])) {
                $newOptions[$key] = $this->getAttributeCatalog($withoutGroup);
            } elseif (in_array($key, [
                self::CATALOG_CATEGORY
            ])) {
                $newOptions[$key] = $this->getAttributeCategories($withoutGroup);
            } else {
                try {
                    $object = $this->entityFactory->create($items['model']);
                    $newOptions[$key] = $this->getAllFields($object);
                } catch (\Exception $e) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Please enter a correct entity model.')
                    );
                }
            }
        }

        $this->options = $newOptions;

        return $this->options;
    }

    /**
     * @return array
     */
    protected function getAttributeCatalog($withoutGroup = 0)
    {
        $attributeCollection = $this->getAttributeCollection();
        $options = [];
        $subOptions = [];

        foreach ($attributeCollection as $attribute) {
            $label = (!$withoutGroup) ?
                $attribute->getAttributeCode() . ' (' . $attribute->getFrontendLabel() . ')' :
                $attribute->getAttributeCode();
            $subOptions[] =
                [
                    'label' => $label,
                    'value' => $attribute->getAttributeCode()
                ];
        }
        unset($attributeCollection);
        if (!$withoutGroup) {
            $options[] = [
                'label' => __('Product Attributes'),
                'optgroup-name' => 'product_attributes',
                'value' => $subOptions
            ];
        } else {
            $options += $subOptions;
        }
        $specialAttributes = \Firebear\ImportExport\Model\Import\Product::$specialAttributes;
        $productTypes = $this->importConfig->getEntityTypes('catalog_product');
        foreach ($productTypes as $productTypeConfig) {
            $model = $productTypeConfig['model'];
            if (property_exists($model, 'specialAttributes')) {
                $specialAttributes = array_merge($specialAttributes, $model::$specialAttributes);
            }
        }
        $subOptions = [];
        foreach ($specialAttributes as $attribute) {
            $subOptions[] = ['label' => $attribute, 'value' => $attribute];
        }
        unset($specialAttributes);
        $AddFields = \Firebear\ImportExport\Model\Import\Product::$addFields;
        foreach ($AddFields as $attribute) {
            $subOptions[] = ['label' => $attribute, 'value' => $attribute];
        }
        unset($AddFields);
        if (!$withoutGroup) {
            $options[] = [
                'label' => __('Special Fields'),
                'optgroup-name' => 'special_attributes',
                'value' => $subOptions
            ];
        } else {
            $options = array_merge($options, $subOptions);
        }
        $subOptions = [];
        $subOptions[] = ['label' => '_category', 'value' => '_category'];
        $subOptions[] = ['label' => '_root_category', 'value' => '_root_category'];
        if (!$withoutGroup) {
            $options[] = [
                'label' => __('Other Fields'),
                'optgroup-name' => 'other_attributes',
                'value' => $subOptions
            ];
        } else {
            $options = array_merge($options, $subOptions);
        }
        if ($this->sourceManager->isEnableMsi()) {
            $msiFields = $this->getAdditionalMsiFields();
            if (!empty($msiFields)) {
                $options[] = [
                    'label' => __('MSI Fields'),
                    'optgroup-name' => 'msi_fields',
                    'value' => $msiFields
                ];
            }
        }

        return $options;
    }

    /**
     * @return \Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection
     */
    public function getAttributeCollection()
    {
        $this->attributeCollection = $this->attributeFactory
            ->create()
            ->addVisibleFilter()
            ->setOrder('attribute_code', AbstractDb::SORT_ORDER_ASC);

        return $this->attributeCollection;
    }

    protected function getAttributeCategories($withoutGroup = 0)
    {
        $attributeCollection = $this->getAttributeCategoryCollection();
        $options = [];
        $subOptions = [
            ['label' => 'entity_id', 'value' => 'entity_id'],
            ['label' => 'parent_id', 'value' => 'parent_id']
        ];
        foreach ($attributeCollection as $attribute) {
            if ($attribute->getFrontendLabel()) {
                $label = (!$withoutGroup) ?
                    $attribute->getAttributeCode() . ' (' . $attribute->getFrontendLabel() . ')' :
                    $attribute->getAttributeCode();
                $subOptions[] =
                    [
                        'label' => $label,
                        'value' => $attribute->getAttributeCode()
                    ];
            }
        }
        $subOptions[] =
            [
                'label' => 'Store View',
                'value' => 'store_view'
            ];
        $subOptions[] =
            [
                'label' =>'Store Name',
                'value' => 'store_name'
            ];
        unset($attributeCollection);
        if (!$withoutGroup) {
            $options[] = [
                'label' => __('Category Attributes'),
                'optgroup-name' => 'product_attributes',
                'value' => $subOptions
            ];
        } else {
            $options += $subOptions;
        }

        return $options;
    }

    public function getAttributeCategoryCollection()
    {
        $this->attributeCategoryCollection = $this->attributeCategoryFactory
            ->create()
            ->setOrder('attribute_code', AbstractDb::SORT_ORDER_ASC);

        return $this->attributeCategoryCollection;
    }

    /**
     * @return array
     */
    protected function getAllFields($object)
    {
        $options = [];
        foreach ($object->getAllFields() as $field) {
            $options[] = is_array($field) ? $field : ['label' => $field, 'value' => $field];
        }

        return $options;
    }

    public function getOptions($entity)
    {
        if (in_array($entity, [
            self::CATALOG_PRODUCT,
            self::CATALOG_CATEGORY
        ])) {
            $options = $this->getAttributeCatalog();
            $newOptions[$entity] = $options;
        } else {
            $configes = $this->config->getEntities();
            if (isset($configes[$entity])) {
                try {
                    $object = $this->entityFactory->create($configes[$entity]['model']);
                    $newOptions[$entity] = $this->getAllFields($object);
                } catch (\Exception $e) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Please enter a correct entity model.')
                    );
                }
            }
        }

        return $newOptions;
    }

    /**
     * Get additional MSI fields to map product source qty and product source status
     *
     * @return array
     */
    protected function getAdditionalMsiFields()
    {
        $msiFields = [];
        if ($this->sourceManager->isEnableMsi()) {
            $this->sourceRepository = ObjectManager::getInstance()->create(SourceRepositoryInterface::class);
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('enabled', 1)
                ->create();

            $sourceData = $this->sourceRepository->getList($searchCriteria);
            $activeSources = $sourceData->getItems();
            foreach ($activeSources as $source) {
                $msiFields[] = [
                    'label' => SourceManager::PREFIX . $source->getSourceCode(),
                    'value' => SourceManager::PREFIX . $source->getSourceCode()
                ];
                $msiFields[] = [
                    'label' => SourceManager::PREFIX . $source->getSourceCode() . SourceManager::QTY_POSTFIX,
                    'value' => SourceManager::PREFIX . $source->getSourceCode() . SourceManager::QTY_POSTFIX
                ];
                $msiFields[] = [
                    'label' => SourceManager::PREFIX . $source->getSourceCode() . SourceManager::STATUS,
                    'value' => SourceManager::PREFIX . $source->getSourceCode() . SourceManager::STATUS
                ];
            }
        }
        return $msiFields;
    }
}
