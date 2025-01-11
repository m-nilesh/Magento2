<?php

namespace Searchanise\SearchAutocomplete\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Searchanise\SearchAutocomplete\Helper\ApiSe as ApiSeHelper;
use Searchanise\SearchAutocomplete\Helper\Logger as SeLogger;
use Searchanise\SearchAutocomplete\Helper\Notification as SeNotification;

class Async extends Template
{
    /**
     * @var ApiSeHelper
     */
    private $apiSeHelper;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var SeLogger
     */
    private $loggerHelper;

    /**
     * @var SeNotification
     */
    private $notificationHelper;

    public function __construct(
        Context $context,
        ApiSeHelper $apiSeHelper,
        StoreManagerInterface $storeManager,
        SeLogger $loggerHelper,
        SeNotification $notificationHelper,
        array $data = []
    ) {
        $this->apiSeHelper = $apiSeHelper;
        $this->storeManager = $storeManager;
        $this->loggerHelper = $loggerHelper;
        $this->notificationHelper = $notificationHelper;

        parent::__construct($context, $data);
    }

    public function isEnabled($storeId = null)
    {
        if (empty($storeId)) {
            $storeId = $this->storeManager->getStore($storeId)->getId();
        }

        return
            ($this->apiSeHelper->getIsAdmin() ||
                empty(ApiSeHelper::$seStoreIds) ||
                in_array($storeId, ApiSeHelper::$seStoreIds)
            ) &&
            $this->apiSeHelper->isHostAvailableForSignup($this->getBaseUrl()) &&
            $this->apiSeHelper->checkStatusModule() &&
            $this->apiSeHelper->checkApiKey($storeId);
    }

    public function checkRegistration()
    {
        if (
            $this->getRequest()->getParam('ajax') ||
            $this->getRequest()->isAjax() ||
            $this->getRequest()->isPost() ||
            !$this->apiSeHelper->getIsAdmin() ||
            !$this->apiSeHelper->checkStatusModule() ||
            !$this->apiSeHelper->isHostAvailableForSignup($this->getBaseUrl())
        ) {
            return false;
        }

        $textNotification = '';

        if ($this->apiSeHelper->checkAutoInstall()) {
            $textNotification = __(
                // phpcs:disable Generic.Files.LineLength.TooLong
                'Searchanise was successfully installed. Catalog indexation in process. <a href="%1">Searchanise Admin Panel</a>.',
                $this->apiSeHelper->getModuleUrl()
            );
            $this->loggerHelper->log(
                __("Start module registration"),
                SeLogger::TYPE_INFO
            );
        } elseif ($this->apiSeHelper->checkModuleIsUpdated()) {
            $this->apiSeHelper->updateInsalledModuleVersion();
            $textNotification = __(
                // phpcs:disable Generic.Files.LineLength.TooLong
                'Searchanise was successfully updated. Catalog indexation in process. <a href="%1">Searchanise Admin Panel</a>.',
                $this->apiSeHelper->getModuleUrl()
            );
        }

        if (!empty($textNotification)) {
            if ($this->apiSeHelper->startSignup()) {
                $this->notificationHelper->setNotification(
                    SeNotification::TYPE_NOTICE,
                    __('Notice'),
                    $textNotification
                );
            } else {
                $this->loggerHelper->log(
                    __("Automatic registration failed"),
                    SeLogger::TYPE_ERROR
                );
            }
        } else {
            $this->apiSeHelper->showNotificationAsyncCompleted();
        }

        return true;
    }

    public function getAsyncUrl($storeId = null)
    {
        return $this->apiSeHelper->getAsyncUrl(false, $storeId);
    }

    public function getIsObjectAsync()
    {
        return $this->apiSeHelper->checkObjectAsync();
    }

    public function getIsAjaxAsync()
    {
        return $this->apiSeHelper->checkAjaxAsync();
    }
}
