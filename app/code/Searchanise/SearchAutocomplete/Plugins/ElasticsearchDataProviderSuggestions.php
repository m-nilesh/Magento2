<?php

namespace Searchanise\SearchAutocomplete\Plugins;

use Magento\Elasticsearch\Model\DataProvider\Base\Suggestions as ElasticsearchSuggestions;
use Magento\Search\Model\QueryInterface;
use Magento\Search\Model\QueryResultFactory;
use Searchanise\SearchAutocomplete\Helper\Data as SearchaniseHelper;
use Searchanise\SearchAutocomplete\Helper\ApiSe as ApiSeHelper;

class ElasticsearchDataProviderSuggestions
{
    /**
     * @var SearchaniseHelper
     */
    private $searchaniseHelper;

    /**
     * QueryResultFactory
     */
    private $queryResultFactory;

    public function __construct(
        QueryResultFactory $queryResultFactory,
        SearchaniseHelper $searchaniseHelper
    ) {
        $this->searchaniseHelper = $searchaniseHelper;
        $this->queryResultFactory = $queryResultFactory;
    }

    /**
     * Returns search suggestions
     *
     * @param ElasticsearchSuggestions $instance
     * @param callable                 $fn
     * @param QueryInterface           $query
     *
     * @return array
     */
    public function aroundGetItems(ElasticsearchSuggestions $instance, callable $fn, QueryInterface $query)
    {
        $suggestionsMaxResults = ApiSeHelper::getSuggestionsMaxResults();

        if ($suggestionsMaxResults > 0 &&
            $this->searchaniseHelper->getSearchaniseRequest() !== null &&
            $this->searchaniseHelper->getSearchaniseRequest()->getTotalProducts() == 0
        ) {
            $count_sug = 0;
            $result = [];
            $rawSuggestions = $this->searchaniseHelper->getRawSuggestions();

            foreach ($rawSuggestions as $k => $sug) {
                $result[] = $this->queryResultFactory->create([
                    'queryText'    => $sug,
                    'resultsCount' => $k,
                ]);
                $count_sug++;

                if ($count_sug >= $suggestionsMaxResults) {
                    break;
                }
            }

            return $result;
        }

        return $fn($query);
    }
}
