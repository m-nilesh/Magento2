<?php

namespace Searchanise\SearchAutocomplete\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Helper\Data as CatalogDataHelper;
use Magento\Catalog\Helper\ImageFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as CatalogProductAttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as ProductAttribute;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\Layer\Filter\DataProvider\Price as DataProviderPrice;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Model\Configuration as CatalogInventoryConfiguration;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Store as StoreModel;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Tax\Helper\Data as TaxDataHelper;
use Magento\Review\Model\ReviewFactory;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\GroupedProduct\Model\Product\Type\Grouped as ProductTypeGrouped;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ProductTypeConfigurable;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Bundle\Model\Product\Price as BundleProductPrice;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\Customer\Model\Group as CustomerGroupModel;
use Magento\Customer\Model\GroupManagement as CustomerGroupManagement;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;

use Searchanise\SearchAutocomplete\Model\Configuration;
use Searchanise\SearchAutocomplete\Helper\Logger as SeLogger;
use Searchanise\SearchAutocomplete\Helper\ApiSe as ApiSeHelper;

/**
 * Products helper for searchanise
 */
class ApiProducts extends AbstractHelper
{
    const WEIGHT_SHORT_TITLE         = 100;
    const WEIGHT_SHORT_DESCRIPTION   = 40;
    const WEIGHT_DESCRIPTION         = 40;
    const WEIGHT_DESCRIPTION_GROUPED = 30;

    const WEIGHT_TAGS              = 60;
    const WEIGHT_CATEGORIES        = 60;

    // <if_isSearchable>
    const WEIGHT_META_TITLE        =  80;
    const WEIGHT_META_KEYWORDS     = 100;
    const WEIGHT_META_DESCRIPTION  =  40;

    const WEIGHT_SELECT_ATTRIBUTES    = 60;
    const WEIGHT_TEXT_ATTRIBUTES      = 60;
    const WEIGHT_TEXT_AREA_ATTRIBUTES = 40;
    // </if_isSearchable>

    const IMAGE_SIZE = 300;
    const THUMBNAIL_SIZE = 70;

    const GROUPED_PREFIX = 'se_grouped_';

    // Product types which as children
    public $hasChildrenTypes = [
        ProductType::TYPE_BUNDLE,
        ProductTypeGrouped::TYPE_CODE,
        ProductTypeConfigurable::TYPE_CODE
    ];

    public $flWithoutTags = false;
    public $isGetProductsByItems = false;
    public $fixDuplicatedItems = false;

    /**
     * System attributes
     * These attributes are excluded from processing
     */
    public $systemAttributes = [
        'has_options',
        'required_options',
        'custom_layout_update',
        'tier_price',
        'image_label',
        'small_image_label',
        'thumbnail_label',
        'tax_class_id',
        'url_key',
        'group_price',
        'category_ids',
        'categories',
    ];

    public static $imageTypes = [
        'image',
        'small_image',
        'thumbnail',
    ];

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CustomerGroupCollectionFactory
     */
    private $customerGroupCollectionFactory;

    /**
     * @var CatalogProductAttributeCollectionFactory
     */
    private $catalogProductAttributeCollectionFactory;

    /**
     * @var TaxDataHelper
     */
    private $taxHelper;

    /**
     * @var CatalogDataHelper
     */
    private $catalogHelper;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var SeLogger
     */
    private $loggerHelper;

    /**
     * @var ImageFactory
     */
    private $catalogImageFactory;

    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var ReviewFactory
     */
    private $reviewFactory;

    /**
     * @var ProductStatus
     */
    private $productStatus;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var ProductVisibility
     */
    private $productVisibility;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        CustomerGroupCollectionFactory $customerGroupCollectionFactory,
        CatalogProductAttributeCollectionFactory $catalogProductAttributeCollectionFactory,
        TaxDataHelper $taxHelper,
        CatalogDataHelper $catalogHelper,
        Configuration $configuration,
        SeLogger $loggerHelper,
        ImageFactory $catalogImageFactory,
        StockRegistryInterface $stockRegistry,
        DateTime $dateTime,
        ReviewFactory $reviewFactory,
        ResourceConnection $resourceConnection,
        ProductStatus $productStatus,
        ProductVisibility $productVisibility
    ) {
        $this->storeManager = $storeManager;
        $this->configuration = $configuration;
        $this->customerGroupCollectionFactory = $customerGroupCollectionFactory;
        $this->catalogProductAttributeCollectionFactory = $catalogProductAttributeCollectionFactory;
        $this->loggerHelper = $loggerHelper;
        $this->taxHelper = $taxHelper;
        $this->catalogHelper = $catalogHelper;
        $this->catalogImageFactory = $catalogImageFactory;
        $this->stockRegistry = $stockRegistry;
        $this->dateTime = $dateTime;
        $this->reviewFactory = $reviewFactory;
        $this->resourceConnection = $resourceConnection;
        $this->productStatus = $productStatus;
        $this->productVisibility = $productVisibility;

        parent::__construct($context);
    }

    /**
     * Returns \Searchanise\SearchAutocomplete\Helper\ApiSe
     *
     * @return \Searchanise\SearchAutocomplete\Helper\ApiSe
     */
    public function getApiSeHelper()
    {
        static $apiSeHelper = null;

        if (!$apiSeHelper) {
            $apiSeHelper = ObjectManager::getInstance()
                ->get(ApiSeHelper::class);
        }

        return $apiSeHelper;
    }

    /**
     * Returns Msrp helper
     *
     * @return \Magento\Msrp\Helper\Data|null
     */
    public function getMsrpHelper()
    {
        static $msrpHelper = null;

        if (!$msrpHelper && $this->getModuleManager()->isEnabled('Magento_Msrp')) {
            $msrpHelper = ObjectManager::getInstance()->get(\Magento\Msrp\Helper\Data::class);
        }

        return $msrpHelper;
    }

    /**
     * Returns module Manager class
     *
     * @return \Magento\Framework\Module\Manager
     */
    public function getModuleManager()
    {
        if (property_exists($this, 'moduleManager')) {
            $moduleManager = $this->moduleManager;
        } else {
            $moduleManager = ObjectManager::getInstance()
                ->get(\Magento\Framework\Module\Manager::class);
        }

        return $moduleManager;
    }

    /**
     * Checks if magento version is more than
     *
     * @param string $version Version for check
     *
     * @return bool
     */
    public function isVersionMoreThan($version)
    {
        $magentoVersion = ObjectManager::getInstance()
            ->get(ApiSeHelper::class)
            ->getMagentoVersion();

        return version_compare($magentoVersion, $version, '>=');
    }

    /**
     * Returns products collection
     *
     * @return ProductCollection
     */
    public function getProductCollection()
    {
        $objectManager = ObjectManager::getInstance();
        $version = $objectManager
            ->get(ApiSeHelper::class)
            ->getMagentoVersion();

        if ($this->isVersionMoreThan('2.2')) {
            static $collectionFactory = null;

            if (!$collectionFactory) {
                $collectionFactory = $objectManager
                    ->create(\Magento\Catalog\Model\ResourceModel\Product\CollectionFactory::class);
            }

            $collection = $collectionFactory
                ->create()
                ->clear();
        } else {
            static $catalogProductFactory = null;

            if (!$catalogProductFactory) {
                $catalogProductFactory = $this->getApiSeHelper()->getProductFactory();
            }

            $collection = $catalogProductFactory
                ->create()
                ->getCollection();
        }

        return $collection;
    }

    /**
     * Returns image base url for store
     *
     * @param StoreModel $store        Store data
     * @param bool       $removePubDir Remove pub directory from image base
     *
     * @return string
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function getImageBaseUrl(StoreModel $store, $removePubDir = false)
    {
        return ObjectManager::getInstance()
            ->get(ApiSeHelper::class)
            ->getStoreUrl($store->getId())
            ->getUrl($removePubDir ? 'media/catalog' : 'pub/media/catalog', [
                '_scope' => $store->getId(),
                '_nosid' => true,
            ]) . 'product';
    }

    /**
     * Returns frontend url model
     *
     * @param StoreModel $store
     *
     * @return mixed
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function getUrlInstance(StoreModel $store)
    {
        return ObjectManager::getInstance()
            ->get(ApiSeHelper::class)
            ->getStoreUrl($store->getId());
    }

    /**
     * Loads single product by id
     *
     * @param int $productId    Product identifier
     * @param StoreModel $store Store object
     *
     * @return ProductModel|null
     */
    public function loadProductById($productId, $store = null)
    {
        // TODO: Load() method is deprected here since 2.2.1. Should be replaced in future
        $product = $this->getApiSeHelper()->getProductFactory()
            ->create()
            ->load($productId);

        return $product;
    }

    /**
     * Sets isGetProductsByItems value
     *
     * @param bool $value
     */
    public function setIsGetProductsByItems($value = false)
    {
        $this->isGetProductsByItems = $value;
    }

    /**
     * Returns required attributes list
     *
     * @return array
     */
    private function getRequiredAttributes()
    {
        return [
            'name',
            'short_description',
            'sku',
            'status',
            'visibility',
            'price',
        ];
    }

    /**
     * Generate product feed for searchanise api
     *
     * @param array      $productIds Product ids
     * @param StoreModel $store      Store object
     * @param string     $checkData
     *
     * @return array
     */
    public function generateProductsFeed(
        array $productIds = [],
        StoreModel $store = null,
        $checkData = true
    ) {
        $items = [];

        $startTime = microtime(true);

        $products = $this->getProducts($productIds, $store, null, true, $this->getApiSeHelper()->hasReviewsProvider($store));

        if (!empty($products)) {
            $this->generateChildrenProducts($products, $store);

            foreach ($products as $product) {
                if ($item = $this->generateProductFeed($product, $store, $checkData)) {
                    $items[] = $item;
                }
            }
        }

        $endTime = microtime(true);

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->loggerHelper->log(sprintf("===== ApiProducts::generateProductsFeed() for %d products takes %0.2f ms =====", count($productIds), $endTime - $startTime), SeLogger::TYPE_DEBUG);

        return $items;
    }

    /**
     * Get product minimal price without "Tier Price" (quantity discount) and with tax (if it is need)
     *
     * @param ProductModel      $product           Product model
     * @param StoreModel        $store             Store model
     * @param ProductCollection $childrenProducts  United products
     * @param int               $customerGroupId   Customer group identifier
     * @param bool              $applyTax          if true, tax will be applied
     *
     * @return float
     */
    private function getProductMinimalPrice(
        ProductModel $product,
        StoreModel $store,
        $childrenProducts = null,
        $customerGroupId = null,
        $applyTax = true
    ) {
        $minimalPrice = false;
        $minTierPrice = $this->getMinimalTierPrice($product, $store, $customerGroupId);

        if ($product->getTypeId() == ProductType::TYPE_BUNDLE) {
            $product->setCustomerGroupId(0);
            $minimalPrice = $product->getPriceModel()->getTotalPrices($product, 'min', null, false);

            if ($minTierPrice != null) {
                $minimalPrice = min($minimalPrice, $minTierPrice);
            }
        } elseif (!empty($childrenProducts) &&
            ($product->getTypeId() == ProductTypeGrouped::TYPE_CODE
                || $product->getTypeId() == ProductTypeConfigurable::TYPE_CODE
            )
        ) {
            $prices = [];
            foreach ($childrenProducts as $childrenProduct) {
                if ($childrenProduct->getStatus() != ProductStatus::STATUS_ENABLED ||
                    $this->getProductQty($childrenProduct, $store) == 0
                ) {
                    continue;
                }

                $prices[] = $this->getProductMinimalPrice(
                    $childrenProduct,
                    $store,
                    null,
                    $customerGroupId,
                    false
                );
            }

            if (!empty($prices)) {
                $minimalPrice = min($prices);
            }
        } elseif ($product->getTypeId() == 'giftcard' &&
            $product->getData('allow_open_amount') == 1 &&
            $product->getData('open_amount_min')
        ) {
            $minimalPrice = $product->getData('open_amount_min');
        }

        if ($minimalPrice === false) {
            $minimalPrice = $product->getFinalPrice();

            if ($minTierPrice !== null) {
                $minimalPrice = min($minimalPrice, $minTierPrice);
            }
        }

        if ($minimalPrice && $applyTax) {
            $minimalPrice = $this->getProductShowPrice($product, $minimalPrice);
        }

        return (float) $minimalPrice;
    }

    /**
     * Get product price with tax if it is need
     *
     * @param ProductModel $product Product data
     * @param float        $price   Product price
     *
     * @return float
     */
    public function getProductShowPrice(ProductModel $product, $price)
    {
        static $taxHelper;
        static $showPricesTax;

        if (!isset($taxHelper)) {
            $taxHelper = $this->taxHelper;
            $showPricesTax = ($taxHelper->displayPriceIncludingTax() || $taxHelper->displayBothPrices());
        }

        // TODO: Test taxes
        $finalPrice = $this->catalogHelper->getTaxPrice($product, $price, $showPricesTax);

        return (float)$finalPrice;
    }

    /**
     * Generate product attributes
     *
     * @param array        $item             Product data
     * @param ProductModel $product          Product model
     * @param array        $childrenProducts List of the children products
     * @param array        $unitedProducts   Unit products
     * @param StoreModel   $store            Store object
     *
     * @return array
     * phpcs:disable Generic.Metrics.NestingLevel
     * phpcs:disable Generic.Metrics.NestingLevel.TooHigh
     */
    private function generateProductAttributes(
        array &$item,
        ProductModel $product,
        $childrenProducts = null,
        $unitedProducts = null,
        StoreModel $store = null
    ) {
        $startTime = microtime(true);
        $attributes = $this->getProductAttributes();

        if (!empty($attributes)) {
            $requiredAttributes = $this->getRequiredAttributes();

            foreach ($attributes as $attribute) {
                $attributeCode = $attribute->getAttributeCode();
                $value = $product->getData($attributeCode);

                // unitedValues - main value + childrens values
                $unitedValues = $this->getIdAttributesValues((array) $unitedProducts, $attributeCode);
                $childrenValues = $this->getIdAttributesValues((array) $childrenProducts, $attributeCode);

                $inputType = $attribute->getData('frontend_input');
                $isSearchable = $attribute->getIsSearchable();
                $isVisibleInAdvancedSearch = $attribute->getIsVisibleInAdvancedSearch();
                $usedForSortBy = $attribute->getUsedForSortBy();
                $isFilterable = $attribute->getIsFilterable();

                $isNecessaryAttribute = $isSearchable
                    || $isVisibleInAdvancedSearch
                    || $usedForSortBy
                    || $isFilterable
                    || in_array($attributeCode, $requiredAttributes);

                if (!$isNecessaryAttribute || empty($unitedValues)) {
                    continue;
                }

                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                if ($attributeCode == 'price') {
                    // already defined in the '<cs:price>' field
                } elseif ($attributeCode == 'status' || $attributeCode == 'visibility') {
                    $item[$attributeCode] = $value;
                    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                } elseif (in_array($attributeCode, $this->systemAttributes)) {
                    // nothing
                    // fixme in the future if need
                } elseif ($attributeCode == 'short_description'
                    || $attributeCode == 'name'
                    || $attributeCode == 'sku'
                ) {
                    if (count($unitedValues) > 1) {
                        $item[self::GROUPED_PREFIX . $attributeCode] = $childrenValues;
                    }
                } elseif ($attributeCode == 'description') {
                    $item['full_description'] = $this->getApiSeHelper()->stripContentTags($value, ['script', 'style']);

                    if (count($unitedValues) > 1) {
                        $item[self::GROUPED_PREFIX . 'full_description'] = array_map(function ($v) {
                            return $this->getApiSeHelper()->stripContentTags($v, ['script', 'style']);
                        }, $childrenValues);
                    }
                } elseif ($attributeCode == 'meta_title'
                    || $attributeCode == 'meta_keyword'
                ) {
                    $item[$attributeCode] = $unitedValues;
                } elseif ($attributeCode == 'meta_description') {
                    $item[$attributeCode] = array_map(function ($v) {
                        return $this->getApiSeHelper()->stripContentTags($v, ['script', 'style']);
                    }, $unitedValues);
                } elseif ($inputType == 'price') {
                    // Other attributes with type 'price'.
                    $item[$attributeCode] = $unitedValues;
                } elseif ($inputType == 'select' || $inputType == 'multiselect') {
                    // <text_values>
                    $unitedTextValues = $this->getProductAttributeTextValues(
                        $unitedProducts,
                        $attributeCode,
                        $inputType,
                        $store
                    );
                    $item[$attributeCode] = $unitedTextValues;
                } elseif ($inputType == 'text' || $inputType == 'textarea') {
                    $item[$attributeCode] = $unitedValues;
                } elseif ($inputType == 'date') {
                    //Magento's timestamp function makes a usage of timezone and converts it to timestamp
                    $item[$attributeCode] = $this->dateTime->timestamp(strtotime($value));
                } elseif ($inputType == 'media_image') {
                    if ($this->configuration->getIsUseDirectImagesLinks()) {
                        if (empty($store)) {
                            $store = $this->storeManager->getStore();
                        }

                        $imageUrl = self::getImageBaseUrl(
                            $store,
                            $this->configuration->getIsRemovePubDirFromImageLinks()
                        ) . $attribute->getImage($attributeCode);
                    } else {
                        $imageUrl = $this->generateImageUrl($product, $attributeCode, true, 0, 0);
                    }

                    if (!empty($imageUrl)) {
                        $item[$attributeCode] = $imageUrl;
                    }
                    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                } elseif ($inputType == 'gallery') {
                    // Nothing.
                } elseif ($inputType == 'boolean') {
                    if ($this->configuration->getIsResultsWidgetEnabled($store->getId())) {
                        $item[$attributeCode] = $value ? __('Yes')->getText() : __('No')->getText();
                    } else {
                        $item[$attributeCode] = $value;
                    }
                }
            }
        }

        $endTime = microtime(true);

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->loggerHelper->log(sprintf("===== ApiProducts::generateProductAttributes() takes %0.2f ms =====", $endTime - $startTime), SeLogger::TYPE_DEBUG);

        return $item;
    }

    /**
     * Generate text values for product attributes for products
     *
     * @param array      $unitedProducts Unit products
     * @param string     $attributeCode  Attribute code
     * @param string     $inputType      Input type (seelct, textarea, multiselect and etc)
     * @param StoreModel $store
     *
     * @return array
     */
    private function getProductAttributeTextValues(
        array $products,
        $attributeCode,
        $inputType,
        StoreModel $store = null
    ) {
        $arrTextValues = [];

        foreach ($products as $p) {
            if ($values = $this->getTextAttributeValues($p, $attributeCode, $inputType, $store)) {
                foreach ($values as $key => $value) {
                    $trimValue = trim($value);
                    if ($trimValue != '' && !in_array($trimValue, $arrTextValues)) {
                        $arrTextValues[] = $value;
                    }
                }
            }
        }

        return $arrTextValues;
    }

    /**
     * Returns text attribute values for product
     *
     * @param ProductModel $product       Product model
     * @param string       $attributeCode Attribute code
     * @param string       $inputType     Input type (seelct, textarea, multiselect and etc)
     * @param StoreModel   $store         Store
     *
     * @return array
     */
    private function getTextAttributeValues(
        ProductModel $product,
        $attributeCode,
        $inputType,
        StoreModel $store = null
    ) {
        $arrTextValues = [];

        if ($product->getData($attributeCode) !== null) {
            $values = [];

            // Dependency of store already exists
            $textValues = $product
                ->getResource()
                ->getAttribute($attributeCode)
                ->setStoreId($store->getId())
                ->getFrontend();

            $use_text_values = $this->configuration->getIsResultsWidgetEnabled($store->getId());

            if ($inputType == 'multiselect') {
                $v = $product->getData($attributeCode);
                $values = $use_text_values
                    ? (array) $this->clearTextAttributeValues($textValues->getOption($v))
                    : explode(',', $v);
            } else {
                $v = $use_text_values
                    ? $textValues->getValue($product) // Black, White
                    : $product->getData($attributeCode); // 10, 20

                if (!empty($v)) {
                    $values[] = $use_text_values
                        ? $this->clearTextAttributeValues($v)
                        : $v;
                }
            }

            $arrTextValues = $values;
        }

        return $arrTextValues;
    }

    /**
     * Clear text attribute values
     *
     * @param mixed $values Attribute values
     *
     * @return mixed
     */
    private function clearTextAttributeValues($values)
    {
        if (empty($values)) {
            $values = '';
        } elseif (is_array($values)) {
            foreach ($values as $k => $v) {
                $values[$k] = $this->clearTextAttributeValues($v);
            }
        } else {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $values = html_entity_decode(trim($values));
        }

        return $values;
    }

    /**
     * Returns attribute values
     *
     * @param array  $unitedProducts Unit products
     * @param string $attributeCode  Attribute code
     *
     * @return array
     */
    private function getIdAttributesValues(array $products, $attributeCode)
    {
        $values = [];

        foreach ($products as $productKey => $product) {
            $value = $product->getData($attributeCode);

            if (!empty($value) && !in_array($value, $values)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Returns product image
     *
     * @param ProductModel $product
     *
     * @return string
     */
    public function getProductImage(ProductModel $product)
    {
        $image = '';

        foreach (self::$imageTypes as $imageType) {
            $_image = $product->getData($imageType);

            if (!empty($_image) && $_image != 'no_selection') {
                $image = $_image;
                break;
            }
        }

        return $image;
    }

    /**
     * Selects product from group for product image.
     *
     * @param array      $unitedProducts Group products
     * @param StoreModel $store          Store
     *
     * @return ProductModel|null
     */
    public function selectProductWithImage(array $unitedProducts, StoreModel $store = null)
    {
        if (empty($unitedProducts)) {
            return null;
        }

        $product = $zeroProduct = null;

        foreach ($unitedProducts as $unitProduct) {
            $unitProductImageUrl = $this->getProductImage($unitProduct);

            if (!empty($unitProductImageUrl) && $unitProductImageUrl != 'no_selection') {
                // Image exists
                $quantity = $this->getProductQty($unitProduct, $store);

                if ($quantity > 0) {
                    $product = $unitProduct;
                    break;
                } elseif (!$zeroProduct) {
                    $zeroProduct = $unitProduct;
                }
            }
        }

        return $product ?? $zeroProduct ?? current($unitedProducts);
    }

    /**
     * Returns thumbnail of product image
     *
     * @param ProductModel $product
     * @param array|null   $childrenProducts
     * @param bool         $flagKeepFrame
     * @param bool         $isThumbnail
     * @param StoreModel   $store
     *
     * @return string
     * phpcs:disable Generic.Metrics.NestingLevel
     * phpcs:disable Generic.Metrics.NestingLevel.TooHigh
     */
    public function getProductImageLink(
        ProductModel $product,
        $childrenProducts = null,
        $flagKeepFrame = true,
        $isThumbnail = true,
        StoreModel $store = null
    ) {
        $imageUrl = '';
        $imageProduct = $this->selectProductWithImage([$product], $store);

        if (empty($imageProduct) && $childrenProducts) {
            $imageProduct = $this->selectProductWithImage($childrenProducts, $store);
        }

        if (!empty($imageProduct)) {
            if ($this->configuration->getIsUseDirectImagesLinks()) {
                if (empty($store)) {
                    $store = $this->storeManager->getStore();
                }

                $imageUrl = ltrim($this->getProductImage($imageProduct), '/');
                if (!empty($imageUrl)) {
                    $imageUrl = self::getImageBaseUrl(
                        $store,
                        $this->configuration->getIsRemovePubDirFromImageLinks()
                    ) . '/' . $imageUrl;
                }
            } else {
                $this->storeManager->setCurrentStore($store);
                $imageType = $isThumbnail ? 'se_thumbnail' : 'se_image';
                $imageUrl = $this->generateImageUrl($imageProduct, $imageType, false, false, false);

                if (empty($imageUrl)) {
                    // Outdated code, should be removed in future
                    if ($isThumbnail) {
                        $width = $height = self::THUMBNAIL_SIZE;
                    } else {
                        $width = $height = self::IMAGE_SIZE;
                    }

                    foreach (self::$imageTypes as $imageType) {
                        $imageUrl = $this->generateImageUrl($imageProduct, $imageType, $flagKeepFrame, $width, $height);

                        if (!empty($imageUrl)) {
                            break;
                        }
                    }
                }
            }
        }

        return $imageUrl;
    }

    /**
     * Genereate image for product
     *
     * @param ProductModel $product
     * @param string       $imageType
     * @param bool         $flagKeepFrame
     * @param int          $width
     * @param int          $height
     *
     * @return string
     */
    private function generateImageUrl(
        ProductModel $product,
        $imageType = 'small_image',
        $flagKeepFrame = true,
        $width = 70,
        $height = 70
    ) {
        $image = null;
        $objectImage = $product->getData($imageType);

        if (in_array($imageType, ['se_image', 'se_thumbnail']) ||
            !empty($objectImage) && $objectImage != 'no_selection'
        ) {
            try {
                $image = $this->catalogImageFactory
                    ->create()
                    ->init($product, $imageType)
                    ->setImageFile($this->getProductImage($product));

                if ($width || $height) {
                    $image
                        // Guarantee, that image picture will not be bigger, than it was.
                        ->constrainOnly(true)
                        // Guarantee, that image picture width/height will not be distorted.
                        ->keepAspectRatio(true)
                        // Guarantee, that image picture width/height will not be distorted.
                        ->keepFrame($flagKeepFrame)
                        // Guarantee, that image will have dimensions, set in $width/$height
                        ->resize($width, $height);
                }
            } catch (\Exception $e) {
                // image not exists
                $image = null;
            }
        }

        $imageUrl = is_object($image)
            ? $image->getUrl()
            : ($image != null ? $image : '');

        if (!empty($imageUrl) && $this->configuration->getIsRemovePubDirFromImageLinks()) {
            $imageUrl = str_replace('/pub/', '/', $imageUrl);
        }

        return $imageUrl;
    }

    /**
     * Genereate children products
     *
     * @param mixed      $products Products array or collection
     * @param StoreModel $store    Store model
     */
    public function generateChildrenProducts(
        &$products,
        StoreModel $store = null
    ) {
        $childrenIdsGrouped = $childrenIds = [];

        foreach ($products as $product) {
            $childrenIdsGrouped[$product->getId()] = $this->getChildrenProductIds($product, $store);
            // phpcs:ignore Magento2.Performance.ForeachArrayMerge
            $childrenIds = array_merge($childrenIds, $childrenIdsGrouped[$product->getId()]);
        }

        if (!empty($childrenIds)) {
            $childrenProducts = [];
            $childrenIds = array_unique($childrenIds);
            $childrenIds = array_chunk($childrenIds, 100);

            foreach ($childrenIds as $ids) {
                $childrenProductsCollection = $this->getProducts($ids, $store, null, false, false);

                // Convert collection object to array
                foreach ($childrenProductsCollection as $child) {
                    $childrenProducts[$child->getId()] = $child;
                }

                unset($childrenProductsCollection);
            }

            if (!empty($childrenProducts)) {
                foreach ($childrenIdsGrouped as $parentId => $chidrenProducts) {
                    $currentChildrenProducts = array_intersect_key($childrenProducts, array_flip($chidrenProducts));

                    if (!empty($currentChildrenProducts)) {
                        if (is_array($products)) {
                            foreach ($products as &$rootProduct) {
                                $rootFound = false;

                                if ($rootProduct->getId() == $parentId) {
                                    $rootProduct->setData('seChildrenProducts', $currentChildrenProducts);
                                    $rootFound = true;
                                    break;
                                }

                                if (!$rootFound) {
                                    // phpcs:disable Generic.Files.LineLength.TooLong
                                    $this->loggerHelper->log(__('Warning: Root product id: %1 not found', $parentId), SeLogger::TYPE_WARNING);
                                }
                            }
                            unset($rootProduct);
                        } else {
                            $products->getItemById($parentId)->setData('seChildrenProducts', $currentChildrenProducts);
                        }
                    }
                }
            }
        }
    }

    /**
     * Return children product ids
     *
     * @param ProductModel $product
     * @param StoreModel   $store
     *
     * @return array
     */
    public function getChildrenProductIds(
        ProductModel $product,
        StoreModel $store = null
    ) {
        $childrenIds = [];

        if (empty($product)) {
            return $childrenIds;
        }

        // if CONFIGURABLE OR GROUPED OR BUNDLE
        if (in_array($product->getData('type_id'), $this->hasChildrenTypes)) {
            if ($typeInstance = $product->getTypeInstance()) {
                $requiredChildrenIds = $typeInstance->getChildrenIds($product->getId(), true);
                if ($requiredChildrenIds) {
                    foreach ($requiredChildrenIds as $groupedChildrenIds) {
                        // phpcs:ignore Magento2.Performance.ForeachArrayMerge
                        $childrenIds = array_merge($childrenIds, $groupedChildrenIds);
                    }
                }
            }
        }

        return $childrenIds;
    }

    /**
     * Get product minimal tier price
     *
     * @param ProductModel $product         Product data
     * @param StoreModel   $store           Store model
     * @param int          $customerGroupId Usergroup
     * @param bool         $min             If true, min price will be returned
     *
     * @return null|int
     */
    private function getMinimalTierPrice(
        ProductModel $product,
        StoreModel $store,
        $customerGroupId = null,
        $min = true
    ) {
        $price = null;

        if ($customerGroupId) {
            $product->setCustomerGroupId($customerGroupId);
        }

        // Load tier prices
        $tierPrices = $product->getTierPrices();
        if (empty($tierPrices)) {
            if ($attribute = $product->getResource()->getAttribute('tier_price')) {
                $attribute->getBackend()->afterLoad($product);
                $tierPrices = $product->getTierPrices();
            }
        }

        // Detect discount type: fixed or percent (available for bundle products)
        $priceType = 'fixed';
        if ($product->getTypeId() == ProductType::TYPE_BUNDLE) {
            $priceType = $product->getPriceType();

            if ($priceType !== null && $priceType != BundleProductPrice::PRICE_TYPE_FIXED) {
                $priceType = 'percent';
            }

            $min = $priceType == 'percent' ? !$min : $min;
        }

        // Calculate minimum discount value
        if (!empty($tierPrices) && is_array($tierPrices)) {
            $prices = [];

            foreach ($tierPrices as $priceInfo) {
                if ($priceInfo->getCustomerGroupId() == $customerGroupId) {
                    if ($priceType == 'percent') {
                        if (!empty($priceInfo['extension_attributes'])) {
                            $priceValue = $priceInfo->getExtensionAttributes()->getPercentageValue();
                        } else {
                            $priceValue = $priceInfo->getValue();
                        }
                    } else {
                        $priceValue = $priceInfo->getValue();
                    }

                    $prices[] = $priceValue;
                }
            }

            if (!empty($prices)) {
                $price = $min ? min($prices) : max($prices);
            }
        }

        // Calculate discounted price
        if ($price && $priceType == 'percent') {
            $regularPrice = $this->getProductMinimalRegularPrice($product, $store, null, false);
            $price = $regularPrice * (1 - $price / 100.0);
        }

        return $price;
    }

    /**
     * Calculate minimal list price
     *
     * @param ProductModel $product          Product model
     * @param StoreModel   $Store            Store view
     * @param array        $childrenProducts List of the children products
     * @param int          $price            Calculated price
     * @param bool         $applyTax         If true tax will be applied
     *
     * @return float
     */
    private function getProductMinimalRegularPrice(
        ProductModel $product,
        StoreModel $store = null,
        $childrenProducts = null,
        $price = null,
        $applyTax = true
    ) {
        $msrpHelper = $this->getMsrpHelper();

        $regularPrice = $product
            ->getPriceInfo()
            ->getPrice(RegularPrice::PRICE_CODE)
            ->getAmount()
            ->getBaseAmount();

        if ($product->getTypeId() == 'giftcard' &&
            $product->getData('allow_open_amount') == 1
            && $product->getData('open_amount_min')
        ) {
            $regularPrice = $product->getData('open_amount_min');
        }

        if ($msrpHelper &&
            $msrpHelper->isShowPriceOnGesture($product) &&
            $product->getMsrp() != null &&
            ($price == null || $price != null && $price < $product->getMsrp())
        ) {
            // Checks if msrp more than price / special price
            $regularPrice = $product->getMsrp();
        }

        if (!empty($childrenProducts)) {
            $maxChildrenPrices = [];

            foreach ($childrenProducts as $childrenProduct) {
                if ($childrenProduct->getStatus() != ProductStatus::STATUS_ENABLED ||
                    $this->getProductQty($childrenProduct, $store) == 0
                ) {
                    continue;
                }

                $childRegularPrice = $childrenProduct
                    ->getPriceInfo()
                    ->getPrice(RegularPrice::PRICE_CODE)
                    ->getAmount()
                    ->getBaseAmount();

                if ($msrpHelper
                    && $msrpHelper->isShowPriceOnGesture($childrenProduct) &&
                    $childrenProduct->getMsrp() != null &&
                    ($price == null || $price != null && $price < $childrenProduct->getMsrp())
                ) {
                    // Checks if msrp more than price / special price
                    $childRegularPrice = $childrenProduct->getMsrp();
                }

                $maxChildrenPrices[] = $childRegularPrice;
            }

            if (!empty($maxChildrenPrices)) {
                $regularPrice = max($maxChildrenPrices); // Use maximum list price of all children
            }
        }

        if ($regularPrice && $applyTax) {
            $regularPrice = $this->getProductShowPrice($product, $regularPrice);
        }

        return (float)$regularPrice;
    }

    /**
     * Generate prices for product
     *
     * @param array        $item             Product data
     * @param ProductModel $product          Product model
     * @param array        $childrenProducts List of the children products
     * @param StoreModel   $store            Store object
     *
     * @return boolean
     */
    private function generateProductPrices(
        array &$item,
        ProductModel $product,
        $childrenProducts = null,
        StoreModel $store = null
    ) {
        $startTime = microtime(true);

        if ($customerGroups = $this->getCustomerGroups()) {
            foreach ($customerGroups as $customerGroupId => $customerGroup) {
                // It is needed because the 'setCustomerGroupId' function works only once.
                $productCurrentGroup = clone $product;

                if ($customerGroupId == CustomerGroupModel::NOT_LOGGED_IN_ID
                    || !isset($equalPriceForAllGroups)
                ) {
                    $price = $this->getProductMinimalPrice(
                        $productCurrentGroup,
                        $store,
                        $childrenProducts,
                        $customerGroupId
                    );

                    if ($price !== false) {
                        $price = round($price, ApiSeHelper::getFloatPrecision());
                    }

                    if ($customerGroupId == CustomerGroupModel::NOT_LOGGED_IN_ID) {
                        $item['price'] = $price;
                        $item['list_price'] = round(
                            $this->getProductMinimalRegularPrice($product, $store, $childrenProducts, $price, true),
                            ApiSeHelper::getFloatPrecision()
                        );

                        if (!empty($item['list_price'])) {
                            $item['list_price'] = max($item['list_price'], $item['price']);
                            $item['max_discount'] = round(
                                (1.0 - $item['price'] / $item['list_price']) * 100,
                                ApiSeHelper::getFloatPrecision()
                            );
                        } else {
                            $item['max_discount'] = '0.0';
                        }

                        $tierPrices = $product->getTierPrices();
                        if (empty($tierPrices)) {
                            $equalPriceForAllGroups = $price;
                        }
                    }
                } else {
                    $price = $equalPriceForAllGroups ?: 0;
                }

                $priceLabel = ApiSeHelper::getLabelForPricesUsergroup() . $customerGroupId;
                $item[$priceLabel] = $price;
                unset($productCurrentGroup);
            }
        }

        $endTime = microtime(true);

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->loggerHelper->log(sprintf("===== ApiProducts::generateProductPrices() takes %0.2f ms =====", $endTime - $startTime), SeLogger::TYPE_DEBUG);

        return true;
    }

    /**
     * Get storefront url for product
     *
     * @param ProductModel $product    Product data
     * @param StoreModel   $store      Store object
     * @param int          $categoryId Category identifier
     *
     * @return string
     */
    public function getStorefrontUrl(
        ProductModel $product,
        StoreModel $store = null,
        $categoryId = null
    ) {
        $routeParams = [
            '_nosid'  => true,
            '_secure' => $this->configuration->getIsUseSecureUrlsInFrontend(),
            //'_query' => ['___store' => $store->getCode()],
        ];
        $urlDataObject = $product->getData('url_data_object');
        $storeId = $product->getStoreId();
        $urlFinder = ObjectManager::getInstance()->get(\Magento\UrlRewrite\Model\UrlFinderInterface::class);

        if ($urlDataObject !== null) {
            $requestPath = $urlDataObject->getUrlRewrite();
            $routeParams['_scope'] = $urlDataObject->getStoreId();
        } else {
            $requestPath = $product->getRequestPath();

            if (empty($requestPath)) {
                $filterData = [
                    UrlRewrite::ENTITY_ID   => $product->getId(),
                    UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                    UrlRewrite::STORE_ID    => $storeId,
                ];

                if ($categoryId) {
                    $filterData[UrlRewrite::METADATA]['category_id'] = $categoryId;
                }

                $rewrite = $urlFinder->findOneByData($filterData);

                if ($rewrite) {
                    $requestPath = $rewrite->getRequestPath();
                    $product->setRequestPath($requestPath);
                } else {
                    $product->setRequestPath(false);
                }
            }
        }

        if (isset($routeParams['_scope'])) {
            $storeId = $this->storeManager->getStore($routeParams['_scope'])->getId();
        } elseif ($store) {
            $routeParams['_scope'] = $storeId = $store->getId();
        }

        if ($storeId != $this->storeManager->getStore()->getId()) {
            $routeParams['_scope_to_url'] = true;
        }

        if ($requestPath) {
            $routeParams['_direct'] = $requestPath;
        } else {
            $routeParams['id'] = $product->getId();
            $routeParams['s'] = $product->getUrlKey();

            if ($categoryId) {
                $routeParams['category'] = $categoryId;
            }
        }

        if (!isset($routeParams['_query'])) {
            $routeParams['_query'] = [];
        }

        if (isset($routeParams['_direct'])) {
            // Build direct url
            $direct = $routeParams['_direct'];
            unset($routeParams['_direct']);
            $url = self::getUrlInstance($store)->getBaseUrl($routeParams) . $direct;
        } else {
            $url = self::getUrlInstance($store)->getUrl('catalog/product/view', $routeParams);
        }

        return $url;
    }

    /**
     * Generate feed for product
     *
     * @param ProductModel $product   Product object
     * @param StoreModel   $store     Store object
     * @param string       $checkData If true, the additional checks will be perform on the product
     *
     * @return array
     */
    public function generateProductFeed(
        ProductModel $product,
        StoreModel $store = null,
        $checkData = true
    ) {
        $item = [];

        if ($checkData
            && (!$product || !$product->getId() || !$product->getName())
        ) {
            return $item;
        }

        if (!empty($store)) {
            $product->setStoreId($store->getId());
            $this->storeManager->setCurrentStore($store);
        } else {
            $product->setStoreId(0);
        }

        $unitedProducts = [$product]; // current product + childrens products (if exists)
        $childrenProducts = $product->getData('seChildrenProducts');

        if ($childrenProducts) {
            foreach ($childrenProducts as $childrenProductsKey => $childrenProduct) {
                $unitedProducts[] = $childrenProduct;
            }
        }

        $item['id'] = $product->getId();
        $item['title'] = $product->getName();
        $item['link'] = $this->getStorefrontUrl($product, $store);
        $item['product_code'] = $product->getSku();
        $item['created'] = strtotime($product->getCreatedAt());
        $item['type_id'] = $product->getTypeId();

        $summaryAttr = $this->configuration->getSummaryAttr();
        $item['summary'] = $product->getData($summaryAttr);

        $this->generateProductPrices($item, $product, $childrenProducts, $store);

        $quantity = $this->getProductQty($product, $store, $unitedProducts);
        $item['quantity'] = ceil($quantity);
        $item['is_in_stock'] = $quantity > 0;

        // Show images without white field
        // Example: image 360 x 535 => 47 х 70
        if ($this->configuration->getIsResultsWidgetEnabled($store->getId())) {
            $item['image_link'] = $this->getProductImageLink($product, $childrenProducts, false, false, $store);
        } else {
            $item['image_link'] = $this->getProductImageLink($product, $childrenProducts, false, true, $store);
        }

        $this->generateProductAttributes($item, $product, $childrenProducts, $unitedProducts, $store);

        // Add product categories
        $item['category_ids'] = $item['categories'] = [];

        $categoryCollection = $product
            ->getCategoryCollection()
            ->addAttributeToFilter('path', ['like' => "1/{$store->getRootCategoryId()}/%"])
            ->addAttributeToSelect(['entity_id', 'name']);

        $categoryCollection->load();

        foreach ($categoryCollection as $category) {
            $item['category_ids'][] = $category->getId();
            $item['categories'][] = $category->getName();
        }

        // Add review data
        if ($this->getApiSeHelper()->hasReviewsProvider($store) && $product->getRatingSummary()) {
            $item['total_reviews'] = $product->getRatingSummary()->getReviewsCount();
            $item['reviews_average_score'] = $product->getRatingSummary()->getRatingSummary() / 20.0;
        }

        // Add sales data
        $item['sales_amount'] = (int)$product->getData('se_sales_amount');
        $item['sales_total'] = $item['sales_total'] = round(
            (float)$product->getData('se_sales_total'),
            ApiSeHelper::getFloatPrecision()
        );

        $item['related_product_ids'] = $item['up_sell_product_ids'] = $item['cross_sell_product_ids'] = [];

        // Add related products
        $relatedProducts = $product->getRelatedProducts();
        if (!empty($relatedProducts)) {
            foreach ($relatedProducts as $relatedProduct) {
                $item['related_product_ids'][] = $relatedProduct->getId();
            }
        }

        // Add upsell products
        $upsellProducts = $product->getUpSellProducts();
        if (!empty($upsellProducts)) {
            foreach ($upsellProducts as $upsellProduct) {
                $item['up_sell_product_ids'][]  = $upsellProduct->getId();
            }
        }

        // Add crosssell products
        $crossSellProducts = $product->getCrossSellProducts();
        if (!empty($crossSellProducts)) {
            foreach ($crossSellProducts as $crossSellProduct) {
                $item['cross_sell_product_ids'][] = $crossSellProduct->getId();
            }
        }

        return $item;
    }

    /**
     * Returns stock item
     *
     * @param ProductModel $product Product model
     * @param StoreModel   $store   Object store
     *
     * @return mixed
     */
    public function getStockItem(
        ProductModel $product,
        StoreModel $store = null
    ) {
        $stockItem = null;

        if (!empty($product)) {
            $stockItem = $this->stockRegistry->getStockItem($product->getId(), $store->getWebsiteId());
        }

        return $stockItem;
    }

    /**
     * Returns product quantity
     *
     * @param ProductModel $product
     * @param StoreModel   $store
     * @param array        $unitedProducts - Current product + childrens products (if exists)
     *
     * @return int
     */
    private function getProductQty(
        ProductModel $product,
        StoreModel $store,
        array $unitedProducts = []
    ) {
        $quantity = 1;
        $stockItem = $this->getStockItem($product, $store);

        if (!empty($stockItem)) {
            $manageStock = null;

            if ($stockItem->getData(StockItemInterface::USE_CONFIG_MANAGE_STOCK)) {
                $manageStock = $this->configuration
                    ->getValue(CatalogInventoryConfiguration::XML_PATH_MANAGE_STOCK);
            } else {
                $manageStock = $stockItem->getData(StockItemInterface::MANAGE_STOCK);
            }

            if (empty($manageStock)) {
                $quantity = 1;
            } else {
                $isInStock = $product->isSalable();

                if (!$isInStock) {
                    $quantity = 0;
                } else {
                    // Returns total quantities from all sources
                    try {
                        if ($this->getModuleManager()->isEnabled('Magento_InventorySalesApi')) {
                            $getProductSalableQty = ObjectManager::getInstance()->get(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class);
                            $quantity = (int) $getProductSalableQty->execute($product->getSku(), $store->getWebsiteId());
                        }

                        $quantity = $getProductSalableQty ? (int) $getProductSalableQty->execute($product->getSku(), $store->getWebsiteId()) : (int) $stockItem->getQty();

                    } catch (\Exception $e) {
                        $quantity = (int) $stockItem->getQty();
                    }

                    if ($quantity <= 0) {
                        $backorders = StockItemInterface::BACKORDERS_NO;

                        if ($stockItem->getData(StockItemInterface::USE_CONFIG_BACKORDERS) == 1) {
                            $backorders = $this->configuration
                                ->getValue(CatalogInventoryConfiguration::XML_PATH_BACKORDERS);
                        } else {
                            $backorders = $stockItem->getData(StockItemInterface::BACKORDERS);
                        }

                        if ($backorders != StockItemInterface::BACKORDERS_NO) {
                            $quantity = 1;
                        }
                    }

                    if (!empty($unitedProducts)) {
                        $quantity = 0;

                        foreach ($unitedProducts as $itemProductKey => $itemProduct) {
                            $quantity += $this->getProductQty($itemProduct, $store);
                        }
                    }
                }
            }
        }

        return max(-1, min(1, $quantity));
    }

    /**
     * Returns header for api request
     *
     * @param StoreModel $store Store object
     *
     * @return array
     */
    public function getHeader(StoreModel $store = null)
    {
        $url = '';

        if (empty($store)) {
            $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\Url::URL_TYPE_WEB);
        } else {
            $url = self::getUrlInstance($store)->getBaseUrl([
                '_nosid' => true,
                '_scope' => $store->getId(),
            ]);
        }
        $date = date('c');

        return [
            'id'      => $url,
            'updated' => $date,
        ];
    }

    /**
     * Adds required attributes to collection
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $products
     */
    private function addRequiredAttributes($products)
    {
        $products
            ->addFinalPrice()
            ->addMinimalPrice()
            ->addAttributeToSelect('special_price')
            ->addAttributeToSelect('special_from_date')
            ->addAttributeToSelect('special_to_date')
            ->addAttributeToSelect('visibility')
            ->addAttributeToSelect('status')
            ->addUrlRewrite();
    }

    private function getAdditionalAttributes()
    {
        $additionalAttrs = [];
        $attributes = $this->getProductAttributes();
        $requiredAttributes = $this->getRequiredAttributes();

        foreach ($attributes as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $isSearchable = $attribute->getIsSearchable();
            $isVisibleInAdvancedSearch = $attribute->getIsVisibleInAdvancedSearch();
            $usedForSortBy = $attribute->getUsedForSortBy();
            $isFilterable = $attribute->getIsFilterable();
            $isRequired = $attribute->getIsRequired();

            $isNecessaryAttribute = $isSearchable
                || $isVisibleInAdvancedSearch
                || $usedForSortBy
                || $isFilterable
                || $isRequired
                || in_array($attributeCode, $requiredAttributes);

            if ($isNecessaryAttribute) {
                $additionalAttrs[] = $attributeCode;
            }
        }

        return $additionalAttrs;
    }

    private function getOtherAttributes()
    {
        $attributes = [
            'msrp',
            'msrp_enabled',
            'categories',
            'categories_without_path',
            'ordered_qty',
            'total_ordered',
            'stock_qty',
            'rating_summary',
            'media_gallery',
            'in_stock',
        ];

        return array_merge($attributes, self::$imageTypes);
    }

    /**
     * Return list of the products
     *
     * @param array      $productIds         List of the product ids
     * @param StoreModel $store              Store object
     * @param int        $customerGroupId    Customer group id
     * @param bool       $generateSalesData  If true, sales data will be generated
     * @param bool       $generateReviewData If true, reviews data will be generated
     *
     * @return ProductCollection
     * phpcs:disable Generic.Metrics.NestingLevel
     * phpcs:disable Generic.Metrics.NestingLevel.TooHigh
     */
    public function getProducts(
        array $productIds = [],
        StoreModel $store = null,
        $customerGroupId = null,
        $generateSalesData = true,
        $generateReviewData = true
    ) {
        $resultProducts = [];

        if (empty($productIds)) {
            return $resultProducts;
        }

        $startTime = microtime(true);

        static $arrProducts = [];

        $keyProducts = '';

        if (!empty($productIds)) {
            if (is_array($productIds)) {
                $keyProducts .= implode('_', $productIds);
            } else {
                $keyProducts .= $productIds;
            }
        }

        $keyProducts .= ':' .  ($store ? $store->getId() : '0');
        $keyProducts .= ':' .  $customerGroupId;
        $keyProducts .= ':' .  ($this->isGetProductsByItems ? '1' : '0');

        if (!isset($arrProducts[$keyProducts])) {
            $products = [];

            if ($this->isGetProductsByItems) {
                $products = $this->getProductsByItems($productIds, $store, $generateReviewData);
            } else {
                $products = $this->getProductCollection()
                    ->distinct(true);

                if ($this->fixDuplicatedItems) {
                    $products->groupByAttribute('entity_id');
                }

                if (!empty($store)) {
                    $products
                        ->setStoreId($store->getId())
                        ->addStoreFilter($store);
                }

                // Adds attributes to select
                $this->addRequiredAttributes($products);

                $additionalAttributes = $this->getAdditionalAttributes();
                $otherAttributes = $this->getOtherAttributes();

                $allAttributes = array_unique(array_merge($additionalAttributes, $otherAttributes));

                $products->addAttributeToSelect($allAttributes);

                if (!empty($customerGroupId)) {
                    if (!empty($store)) {
                        $products->addPriceData($customerGroupId, $store->getWebsiteId());
                    } else {
                        $products->addPriceData($customerGroupId);
                    }
                }

                if ($productIds !== \Searchanise\SearchAutocomplete\Model\Queue::NOT_DATA) {
                    // Already exist automatic definition 'one value' or 'array'.
                    $products->addIdFilter($productIds);
                }

                $products->load();

                // Fix: Disabled product not comming in product collection in version 2.2.2 or highter,
                // so try to reload them directly
                if ($productIds !== \Searchanise\SearchAutocomplete\Model\Queue::NOT_DATA &&
                    $this->isVersionMoreThan('2.2.2')
                ) {
                    $skippedProductIds = array_diff($productIds, $products->getLoadedIds());

                    if (!empty($skippedProductIds)) {
                        $reloadedItems = $this->getProductsByItems($skippedProductIds, $store, $generateReviewData);

                        if (!empty($reloadedItems)) {
                            foreach ($reloadedItems as $item) {
                                try {
                                    $products->addItem($item);
                                    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
                                } catch (\Exception $e) {
                                    // Workaround if item already exist in collection. See se-5148
                                }
                            }
                        }
                    }
                }
            }

            // Fixme in the future
            // Maybe create cache without customerGroupId and setCustomerGroupId after using cache.
            if (count($products) > 0 && (!empty($store) || $customerGroupId != null)) {
                foreach ($products as $key => &$product) {
                    if (!empty($product)) {
                        if (!empty($store)) {
                            $product->setWebsiteId($store->getWebsiteId());
                        }

                        if (!empty($customerGroupId)) {
                            $product->setCustomerGroupId($customerGroupId);
                        }
                    }
                }
            }
            // end fixme

            if ($generateReviewData &&
                $products instanceof DataCollection &&
                $this->getModuleManager()->isEnabled('Magento_Review')
            ) {
                $this->reviewFactory->create()->appendSummary($products);
            }

            if ($generateSalesData) {
                $this->generateSalesData($products, $store);
            }

            $arrProducts[$keyProducts] = $products;
        } // End isset

        $endTime = microtime(true);

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->loggerHelper->log(sprintf("===== ApiProducts::getProducts() for %d products takes %0.2f ms =====", count($productIds), $endTime - $startTime), SeLogger::TYPE_DEBUG);

        return $arrProducts[$keyProducts];
    }

    /**
     * Attach sales data to products
     *
     * @param ProductCollection|array $products
     * @param StoreModel              $store    Store object
     *
     * @return bool
     */
    private function generateSalesData(&$products, StoreModel $store = null)
    {
        if ($products instanceof ProductCollection) {
            $product_ids = $products->getAllIds();
        } elseif (is_array($products)) {
            $product_ids = array_map(function ($product) {
                return $product->getId();
            }, $products);
        }

        $product_ids = array_filter($product_ids);

        if (empty($product_ids)) {
            return false;
        }

        $startTime = microtime(true);
        $ordersTableName = $this->resourceConnection->getTableName('sales_order_item');

        try {
            $salesConnection = $this->resourceConnection->getConnectionByName('sales');
        } catch (\Exception $e) {
            $salesConnection = $this->resourceConnection->getConnection();
        }

        $salesSelect = $salesConnection->select()
            ->from($ordersTableName, [])
            ->columns('product_id')
            ->columns(['sales_amount' => new \Zend_Db_Expr('SUM(qty_ordered)')])
            ->columns(['sales_total' => new \Zend_Db_Expr('SUM(row_total)')])
            ->where('product_id IN (?)', $product_ids)
            ->group('product_id');

        if (!empty($store)) {
            $salesSelect->where('product_id IN (?)', (array) $store->getId());
        }

        $salesData = $salesConnection->fetchAll(
            $salesSelect,
            [],
            \PDO::FETCH_GROUP | \PDO::FETCH_ASSOC | \PDO::FETCH_UNIQUE
        );

        foreach ($products as &$product) {
            $productId = $product->getId();

            if (isset($salesData[$productId])) {
                $product->setData('se_sales_amount', $salesData[$productId]['sales_amount']);
                $product->setData('se_sales_total', $salesData[$productId]['sales_total']);
            }
        }

        $endTime = microtime(true);
        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->loggerHelper->log(sprintf("===== ApiProducts::generateSalesData() for %d products takes %0.2f ms =====", count($product_ids), $endTime - $startTime), SeLogger::TYPE_DEBUG);

        return true;
    }

    /**
     * Return product ids for specific range. Used by full import
     *
     * @param int        $start        Start range
     * @param int        $end          End range
     * @param int        $step         Step
     * @param StoreModel $store        Store object
     * @param bool       $isOnlyActive If true, finds only active produts
     *
     * @return array
     */
    public function getProductIdsFromRange(
        $start,
        $end,
        $step,
        StoreModel $store = null,
        $isOnlyActive = false
    ) {
        $arrProducts = [];

        $startTime = microtime(true);

        $products = $this->getProductCollection()
            ->clear()
            ->distinct(true)
            ->addAttributeToSelect('entity_id')
            ->addFieldToFilter('entity_id', ['from' => $start, 'to' => $end])
            ->setPageSize($step);

        if (!empty($store)) {
            $products->addStoreFilter($store);
        }

        if ($isOnlyActive) {
            $products
                ->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()])
                ->addAttributeToFilter(
                    'visibility',
                    ['in' => $this->productVisibility->getVisibleInSearchIds()]
                );
        }

        $arrProducts = $products->getAllIds();
        // It is necessary for save memory.
        unset($products);

        $endTime = microtime(true);

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->loggerHelper->log(sprintf("===== ApiProducts::getProductIdsFromRange() for %d products takes %0.2f ms =====", count($arrProducts), $endTime - $startTime), SeLogger::TYPE_DEBUG);

        return $arrProducts;
    }

    /**
     * Get minimum and maximum product ids from store
     *
     * @param StoreModel $store
     *
     * @return number[]
     */
    public function getMinMaxProductId(StoreModel $store = null)
    {
        $startId = $endId = 0;

        $productCollection = $this->getProductCollection()
            ->clear()
            ->addAttributeToSelect('entity_id')
            ->setPageSize(1);

        if (!empty($store)) {
            $productCollection
                ->setStoreId($store->getId())
                ->addStoreFilter($store);
        }

        $productCollection
            ->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns(['MIN(`e`.`entity_id`) as min_entity_id', 'MAX(`e`.`entity_id`) as max_entity_id']);

        $minMaxArray = $productCollection->load()->toArray(['min_entity_id', 'max_entity_id']);

        if (!empty($minMaxArray)) {
            $firstItem = reset($minMaxArray);
            $startId = (int) $firstItem['min_entity_id'];
            $endId = (int) $firstItem['max_entity_id'];
        }

        return [$startId, $endId];
    }

    /**
     * Get products with items
     *
     * @param array      $productIds  List of product ids
     * @param StoreModel $store       Store object
     * @param bool       $loadReviews If true, product review will be loaded
     *
     * @return array
     */
    private function getProductsByItems(array $productIds, StoreModel $store = null, $loadReviews = false)
    {
        $products = [];

        $productIds = $this->validateProductIds($productIds, $store);

        if (!empty($productIds)) {
            foreach ($productIds as $key => $productId) {
                if (empty($productId)) {
                    continue;
                }

                // It can use various types of data.
                if (is_array($productId)) {
                    if (isset($productId['entity_id'])) {
                        $productId = $productId['entity_id'];
                    }
                }

                try {
                    $product = $this->loadProductById($productId, $store);
                } catch (\Exception $e) {
                    $this->loggerHelper->log(__("Error: Script couldn't get product'"));
                    continue;
                }

                if (!empty($product)) {
                    if ($loadReviews) {
                        $this->reviewFactory->create()->getEntitySummary($product, $store ? $store->getId() : 0);
                    }

                    $products[] = $product;
                }
            }
        }

        return $products;
    }

    /**
     * Validate list of the products
     *
     * @param array      $productIds List of the products
     * @param StoreModel $store      Store object
     *
     * @return array
     */
    private function validateProductIds(array $productIds, StoreModel $store = null)
    {
        $validProductIds = [];

        if (!empty($store)) {
            $this->storeManager->setCurrentStore($store);
        } else {
            $this->storeManager->setCurrentStore(0);
        }

        $products = $this->getProductCollection()
            ->addAttributeToSelect('entity_id');

        if (!empty($store)) {
            $products->addStoreFilter($store);
        }

        // Already exist automatic definition 'one value' or 'array'.
        $products->addIdFilter($productIds);
        $products->load();

        if (count($products) > 0) {
            // Not used because 'arrProducts' comprising 'stock_item' field and is 'array(array())'
            // $arrProducts = $products->toArray(array('entity_id'));
            foreach ($products as $product) {
                $validProductIds[] = $product->getId();
            }
        }

        if (count($validProductIds) != count($productIds) && $this->isVersionMoreThan('2.2.2')) {
            // Fix : Disabled product not coming in product collection in version 2.2.2 or highter
            // So we have to modify SQL query directly and try to reload them
            $updatedfromAndJoin = $updatedWhere = [];

            $fromAndJoin = $products->getSelect()->getPart('FROM');
            $where = $products->getSelect()->getPart('WHERE');
            $products->clear();

            foreach ($fromAndJoin as $key => $index) {
                if ($key == 'stock_status_index' || $key == 'price_index') {
                    $index['joinType'] = 'LEFT JOIN';
                }
                $updatedfromAndJoin[$key] = $index;
            }

            foreach ($where as $key => $condition) {
                if (strpos($condition, 'stock_status_index.stock_status = 1') !== false) {
                    $updatedWhere[] = str_replace('stock_status_index.stock_status = 1', '1', $condition);
                } else {
                    $updatedWhere[] = $condition;
                }
            }

            if (!empty($updatedfromAndJoin)) {
                $products->getSelect()->setPart('FROM', $updatedfromAndJoin);
            }

            if (!empty($updatedWhere)) {
                $products->getSelect()->setPart('WHERE', $updatedWhere);
            }

            $products->load();

            if (count($products) > 0) {
                // Not used because 'arrProducts' comprising 'stock_item' field and is 'array(array())'
                // $arrProducts = $products->toArray(array('entity_id'));
                foreach ($products as $product) {
                    $validProductIds[] = $product->getId();
                }
            }
        }

        // It is necessary for save memory.
        unset($products);

        return array_unique($validProductIds);
    }

    /**
     * Get customer group prices for getSchema()
     *
     * @return array
     */
    public function getSchemaCustomerGroupsPrices()
    {
        $items = [];

        if ($customerGroups = $this->getCustomerGroups()) {
            foreach ($customerGroups as $keyCustomerGroup => $customerGroup) {
                $label = ApiSeHelper::getLabelForPricesUsergroup() . $customerGroup['customer_group_id'];
                $items[] = [
                    'name'  => $label,
                    'title' => 'Price for ' .  $customerGroup['customer_group_code'],
                    'type'  => 'float',
                ];
            }
        }

        return $items;
    }

    /**
     * Returns customer groups
     *
     * @return array
     */
    private function getCustomerGroups()
    {
        static $customerGroups;

        if (!isset($customerGroups)) {
            $_customerGroups = $this->customerGroupCollectionFactory->create();

            if (!$this->configuration->getIsCustomerUsergroupsEnabled()) {
                $_customerGroups->addFieldToFilter('customer_group_id', CustomerGroupModel::NOT_LOGGED_IN_ID);
            }

            $_customerGroups->load();

            foreach ($_customerGroups as $group) {
                $customerGroups[$group->getId()] = $group->toArray();
            }

            $customerGroups[CustomerGroupManagement::CUST_GROUP_ALL] = [
                'customer_group_id' => CustomerGroupManagement::CUST_GROUP_ALL,
                'customer_group_code' => __('ALL GROUPS')
            ];
        }

        return $customerGroups;
    }

    /**
     * Generate custom facet for getSchema()
     *
     * @param string $title
     * @param int    $position
     * @param string $attribute
     * @param string $type
     *
     * @return array
     */
    private function generateFacetFromCustom($title = '', $position = 0, $attribute = '', $type = '')
    {
        $facet = [];

        $facet['title'] = $title;
        $facet['position'] = $position;
        $facet['attribute'] = $attribute;
        $facet['type'] = $type;

        return $facet;
    }

    /**
     * Return product attributes
     *
     * @return ProductAttributeCollection
     */
    public function getProductAttributes()
    {
        static $allAttributes = null;

        if (empty($allAttributes)) {
            $allAttributes = $this->catalogProductAttributeCollectionFactory
                ->create()
                ->setItemObjectClass(ProductAttribute::class)
                ->load();
        }

        return $allAttributes;
    }

    /**
     * Get product schema for searchanise
     *
     * @param StoreModel $store Store object
     *
     * @return array
     */
    public function getSchema(StoreModel $store)
    {
        static $schemas;

        if (!isset($schemas[$store->getId()])) {
            $this->storeManager->setCurrentStore($store);

            $schema = $this->getSchemaCustomerGroupsPrices();

            if ($this->configuration->getIsResultsWidgetEnabled($store->getId())) {
                $schema[] = [
                    'name'        => 'categories',
                    'title'       => __('Category')->getText(),
                    'type'        => 'text',
                    'weight'      => self::WEIGHT_CATEGORIES,
                    'text_search' => 'Y',
                    'facet'       => $this->generateFacetFromCustom(
                        __('Category')->getText(),
                        10,
                        'categories',
                        'select'
                    ),
                ];

                $schema[] = [
                    'name'        => 'category_ids',
                    'title'       => __('Category')->getText() . ' - IDs',
                    'type'        => 'text',
                    'weight'      => 0,
                    'text_search' => 'N',
                ];
            } else {
                $schema[] = [
                    'name'        => 'categories',
                    'title'       => __('Category')->getText(),
                    'type'        => 'text',
                    'weight'      => self::WEIGHT_CATEGORIES,
                    'text_search' => 'Y',
                ];

                $schema[] = [
                    'name'        => 'category_ids',
                    'title'       => __('Category')->getText() . ' - IDs',
                    'type'        => 'text',
                    'weight'      => 0,
                    'text_search' => 'N',
                    'facet'       => $this->generateFacetFromCustom(
                        __('Category')->getText(),
                        10,
                        'category_ids',
                        'select'
                    ),
                ];
            }

            $schema = array_merge($schema, [
                [
                    'name'        => 'max_discount',
                    'title'       => __('Max discount')->getText(),
                    'type'        => 'float',
                    'text_search' => 'N',
                    'sorting'     => 'Y',
                ],
                [
                    'name'        => 'type_id',
                    'title'       => __('Product type')->getText(),
                    'type'        => 'text',
                    'filter_type' => 'none',
                ],
                [
                    'name'        => 'is_in_stock',
                    'title'       => __('Stock Availability')->getText(),
                    'type'        => 'text',
                    'weight'      => 0,
                    'text_search' => 'N',
                ],
                [
                    'name'        => 'sales_amount',
                    'title'       => __('Bestselling')->getText(),
                    'type'        => 'int',
                    'sorting'     => 'Y',
                    'weight'      => 0,
                    'text_search' => 'N',
                ],
                [
                    'name'        => 'sales_total',
                    'title'       => __('Sales total')->getText(),
                    'type'        => 'float',
                    'filter_type' => 'none',
                ],
                [
                    'name'        => 'created',
                    'title'       => __('Created')->getText(),
                    'type'        => 'int',
                    'sorting'     => 'Y',
                    'weight'      => 0,
                    'text_search' => 'N',
                ],
                [
                    'name'        => 'related_product_ids',
                    'title'       => __('Related Products')->getText() . ' - IDs',
                    'filter_type' => 'none',
                ],
                [
                    'name'        => 'up_sell_product_ids',
                    'title'       => __('Up-Sell Products')->getText() . ' - IDs',
                    'filter_type' => 'none',
                ],
                [
                    'name'        => 'cross_sell_product_ids',
                    'title'       => __('Cross-Sell Products')->getText() . ' - IDs',
                    'filter_type' => 'none',
                ],
            ]);

            if ($attributes = $this->getProductAttributes()) {
                foreach ($attributes as $attribute) {
                    if ($items = $this->getSchemaAttribute($attribute)) {
                        foreach ($items as $keyItem => $item) {
                            $schema[] = $item;
                        }
                    }
                }
            }

            $schemas[$store->getId()] = $schema;
        }

        return $schemas[$store->getId()];
    }

    /**
     * Get schema attribute
     *
     * @param ProductAttribute $attribute Product attribute
     *
     * @return array
     */
    public function getSchemaAttribute(ProductAttribute $attribute)
    {
        $items = [];

        $requiredAttributes = $this->getRequiredAttributes();

        $attributeCode = $attribute->getAttributeCode();
        $inputType = $attribute->getData('frontend_input');
        $isSearchable = $attribute->getIsSearchable();
        $isVisibleInAdvancedSearch = $attribute->getIsVisibleInAdvancedSearch();
        $usedForSortBy = $attribute->getUsedForSortBy();
        $isFilterable = $attribute->getIsFilterable();

        $isNecessaryAttribute = $isSearchable
            || $isVisibleInAdvancedSearch
            || $usedForSortBy
            || $isFilterable
            || in_array($attributeCode, $requiredAttributes);

        if (!$isNecessaryAttribute) {
            return $items;
        }

        $type = '';
        $name = $attribute->getAttributeCode();
        $title = $attribute->getStoreLabel();
        $sorting = $usedForSortBy ? 'Y' : 'N';
        $textSearch = $isSearchable ? 'Y' : 'N';
        $attributeWeight = 0;

        // <system_attributes>
        if ($attributeCode == 'price') {
            $type = 'float';
            $textSearch = 'N';
        } elseif ($attributeCode == 'status' || $attributeCode == 'visibility') {
            $type = 'text';
            $textSearch = 'N';
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } elseif (in_array($attributeCode, $this->systemAttributes)) {
            // <system_attributes>
        } elseif ($attributeCode == 'name' || $attributeCode == 'sku' || $attributeCode == 'short_description') {
            //for original
            if ($attributeCode == 'short_description') {
                $name    = 'description';
                $sorting = 'N';
                $weight  = self::WEIGHT_SHORT_DESCRIPTION;
            } elseif ($attributeCode == 'name') {
                $name    = 'title';
                $sorting = 'Y'; //always (for search results widget)
                $weight  = self::WEIGHT_SHORT_TITLE;
            } elseif ($attributeCode == 'sku') {
                $name    = 'product_code';
                $sorting = $sorting;
                $weight  = self::WEIGHT_SHORT_TITLE;
            }

            $items[] = [
                'name'    => $name,
                'title'   => $title,
                'type'    => 'text',
                'sorting' => $sorting,
                'weight'  => $weight,
                'text_search' => $textSearch,
            ];

            // for grouped
            $type = 'text';
            $name  = self::GROUPED_PREFIX . $attributeCode;
            $sorting = 'N';
            $title = $attribute->getStoreLabel() . ' - Grouped';
            $attributeWeight = ($attributeCode == 'short_description')
                ? self::WEIGHT_SHORT_DESCRIPTION
                : self::WEIGHT_SHORT_TITLE;
        } elseif ($attributeCode == 'short_description'
            || $attributeCode == 'description'
            || $attributeCode == 'meta_title'
            || $attributeCode == 'meta_description'
            || $attributeCode == 'meta_keyword'
        ) {
            if ($isSearchable) {
                if ($attributeCode == 'description') {
                    $attributeWeight = self::WEIGHT_DESCRIPTION;
                } elseif ($attributeCode == 'meta_title') {
                    $attributeWeight = self::WEIGHT_META_TITLE;
                } elseif ($attributeCode == 'meta_description') {
                    $attributeWeight = self::WEIGHT_META_DESCRIPTION;
                } elseif ($attributeCode == 'meta_keyword') {
                    $attributeWeight = self::WEIGHT_META_KEYWORDS;
                }
            }

            $type = 'text';

            if ($attributeCode == 'description') {
                $name = 'full_description';
                $items[] = [
                    'name'   => self::GROUPED_PREFIX . 'full_description',
                    'title'  => $attribute->getStoreLabel() . ' - Grouped',
                    'type'   => $type,
                    'weight' => $isSearchable ? self::WEIGHT_DESCRIPTION_GROUPED : 0,
                    'text_search' => $textSearch,
                ];
            }
        } elseif ($inputType == 'price') {
            $type = 'float';
        } elseif ($inputType == 'select' || $inputType == 'multiselect') {
            $type = 'text';
            $attributeWeight = $isSearchable ? self::WEIGHT_SELECT_ATTRIBUTES : 0;
        } elseif ($inputType == 'text' || $inputType == 'textarea') {
            if ($isSearchable) {
                if ($inputType == 'text') {
                    $attributeWeight = self::WEIGHT_TEXT_ATTRIBUTES;
                } elseif ($inputType == 'textarea') {
                    $attributeWeight = self::WEIGHT_TEXT_AREA_ATTRIBUTES;
                }
            }
            $type = 'text';
        } elseif ($inputType == 'date') {
            $type = 'int';
        } elseif ($inputType == 'media_image') {
            $type = 'text';
        } elseif ($inputType == 'boolean') {
            $type    = 'text';
            $textSearch = 'N';
        }

        if (!empty($type)) {
            $item = [
                'name'   => $name,
                'title'  => $title,
                'type'   => $type,
                'sorting' => $sorting,
                'weight' => $attributeWeight,
                'text_search' => $textSearch,
            ];

            $facet = $this->generateFacetFromFilter($attribute);

            if (!empty($facet)) {
                $item['facet'] = $facet;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Checks if attribute is the facet
     *
     * @param ProductAttribute $attribute
     *
     * @return boolean
     */
    public function isFacet(ProductAttribute $attribute)
    {
        return $attribute->getIsFilterable() ||
            $attribute->getIsFilterableInSearch() ||
            $attribute->getIsVisibleInAdvancedSearch();
    }

    /**
     * Returns price navigation step
     *
     * @param StoreModel $store
     *
     * @return mixed
     */
    private function getPriceNavigationStep(StoreModel $store = null)
    {
        // TODO: Unused?
        $store = !empty($store) ? $store : $this->storeManager->getStore(0);

        $priceRangeCalculation = $this->configuration->getValue(DataProviderPrice::XML_PATH_RANGE_CALCULATION);

        if ($priceRangeCalculation == DataProviderPrice::RANGE_CALCULATION_MANUAL) {
            return $this->configuration->getValue(DataProviderPrice::XML_PATH_RANGE_STEP);
        }

        return null;
    }

    /**
     * Generates facet from filter
     *
     * @param ProductAttribute $attribute
     * @param StoreModel       $store
     *
     * @return array
     */
    private function generateFacetFromFilter(
        ProductAttribute $attribute,
        StoreModel $store = null
    ) {
        $item = [];

        if ($this->isFacet($attribute)) {
            $inputType = $attribute->getData('frontend_input');

            // "Can be used only with catalog input type Dropdown, Multiple Select and Price".
            if ($inputType == 'select' || $inputType == 'multiselect' || $inputType == 'boolean') {
                $item['type'] = 'select';
            } elseif ($inputType == 'price') {
                $item['type'] = 'dynamic';
                $step = $this->getPriceNavigationStep($store);

                if (!empty($step)) {
                    $item['min_range'] = $step;
                }
            }

            if (isset($item['type'])) {
                $item['title'] = $attribute->getStoreLabel();
                $item['position']  = ($inputType == 'price')
                    ? $attribute->getPosition()
                    : $attribute->getPosition() + 20;
                $item['attribute'] = $attribute->getAttributeCode();
            }

            if (!empty($item) &&
                !$attribute->getIsFilterable() &&
                !$attribute->getIsFilterableInSearch() &&
                $attribute->getIsVisibleInAdvancedSearch()
            ) {
                $item['status'] = 'H';
            }
        }

        return $item;
    }
}
