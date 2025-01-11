<?php

namespace Searchanise\SearchAutocomplete\Plugins;

use Searchanise\SearchAutocomplete\Helper\ApiSe as ApiSeHelper;
use Searchanise\SearchAutocomplete\Helper\Data as SearchaniseHelper;
use Magento\Search\Helper\Data as SearchDataHelper;

class SearchHelper
{
    /**
     * @var ApiSeHelper
     */
    private $apiSeHelper;

    /**
     * @var SearchaniseHelper
     */
    private $searchaniseHelper;

    public function __construct(
        ApiSeHelper $apiSeHelper,
        SearchaniseHelper $searchaniseHelper
    ) {
        $this->apiSeHelper = $apiSeHelper;
        $this->searchaniseHelper = $searchaniseHelper;
    }

    /**
     * Replace search result url with searchanise one
     *
     * @param SearchDataHelper $instance
     * @param string           $url
     *
     * @return string
     */
    public function afterGetResultUrl(SearchDataHelper $instance, $url)
    {
        if ($this->apiSeHelper->checkParentPrivateKey() && $this->apiSeHelper->getIsResultsWidgetEnabled()) {
            return $this->searchaniseHelper->getResultsFormPath();
        } else {
            return $url;
        }
    }
}
