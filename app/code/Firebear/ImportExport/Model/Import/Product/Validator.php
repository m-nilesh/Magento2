<?php
/**
 * @copyright: Copyright © 2019 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Import\Product;

use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\RowValidatorInterface;

/**
 * Class Validator
 *
 * @api
 * @since 100.0.2
 */
class Validator extends \Magento\CatalogImportExport\Model\Import\Product\Validator
{
    /**
     * @var array
     */
    protected $parameters = [];

    public function setParameters($params)
    {
        $this->parameters = $params;
        return $this;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    public function getContext()
    {
        return $this->context;
    }

    /**
     * {@inheritdoc}
     */
    public function isValid($rowData)
    {
        $isValid = parent::isValid($rowData);
        if (!$isValid && !empty($this->_messages) && $rowData['sku']) {
            $message = array_pop($this->_messages);
            $message = ($this->context->retrieveMessageTemplate($message)) ?: $message;
            $this->_addMessages([$message . '. For SKU: "' . $rowData['sku'] . '"']);
        }
        return $isValid;
    }

    /**
     * Is valid attributes
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function isValidAttributes()
    {
        $this->_clearMessages();
        $this->setInvalidAttribute(null);
        if (!isset($this->_rowData['product_type'])) {
            return false;
        }
        $entityTypeModel = $this->context->retrieveProductTypeByName($this->_rowData['product_type']);
        if ($entityTypeModel) {
            foreach ($this->_rowData as $attrCode => $attrValue) {
                $attrParams = $entityTypeModel->retrieveAttributeFromCache($attrCode);
                if ($attrCode === Product::COL_CATEGORY && $attrValue) {
                    $this->isCategoriesValid($attrValue);
                } elseif ($attrParams) {
                    $this->isAttributeValid($attrCode, $attrParams, $this->_rowData);
                }
            }
            if ($this->getMessages()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate category names
     *
     * @param string $value
     * @return bool
     */
    private function isCategoriesValid(string $value): bool
    {
        $result = true;
        if ($value) {
            if (method_exists($this->context, 'getMultipleCategorySeparator')) {
                $separator = $this->context->getMultipleCategorySeparator();
            } else {
                $separator = $this->context->getMultipleValueSeparator();
            }
            $values = explode($separator, $value);
            foreach ($values as $categoryName) {
                if ($result === true) {
                    $result = $this->string->strlen($categoryName) < Product::DB_MAX_VARCHAR_LENGTH;
                }
            }
        }
        if ($result === false) {
            $this->_addMessages([RowValidatorInterface::ERROR_EXCEEDED_MAX_LENGTH]);
            $this->setInvalidAttribute(Product::COL_CATEGORY);
        }
        return $result;
    }
}
