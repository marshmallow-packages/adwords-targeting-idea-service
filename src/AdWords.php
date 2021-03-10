<?php

namespace SchulzeFelix\AdWords;

use Illuminate\Support\Collection;
use Google\AdsApi\Common\Util\MapEntries;
use SchulzeFelix\AdWords\Responses\Keyword;
use Google\AdsApi\AdWords\v201809\o\RequestType;
use Google\AdsApi\AdWords\v201809\o\AttributeType;
use SchulzeFelix\AdWords\Responses\ServiceCategory;
use SchulzeFelix\AdWords\Responses\MonthlySearchVolume;
use Google\AdsApi\AdWords\v201809\o\TargetingIdeaService;

class AdWords
{
    /**
     * @var TargetingIdeaService
     */
    private $service;

    /** @var int */
    protected $chunkSize = 700;

    /** @var bool */
    protected $withTargetedMonthlySearches = false;

    /** @var bool */
    protected $withServiceCategories = false;

    /** @var bool */
    protected $convertNullToZero = false;

    /** @var int|null */
    protected $language = null;

    /** @var int|null */
    protected $location = null;

    /** @var array|null */
    protected $include = null;

    /** @var array|null */
    protected $exclude = null;

    /**
     * AdWords constructor.
     *
     * @param AdWordsService $service
     */
    public function __construct(AdWordsService $service)
    {
        $this->service = $service;
    }

    /**
     * @param array $keywords
     *
     * @return Collection
     */
    public function searchVolumes(array $keywords)
    {
        $keywords = $this->prepareKeywords($keywords);

        $requestType = RequestType::STATS;

        $searchVolumes = new Collection();
        $chunks = array_chunk($keywords, $this->chunkSize);
        foreach ($chunks as $index => $keywordChunk) {

            $results = $this->service->performQuery($keywordChunk, $requestType, $this->language, $this->location, $this->withTargetedMonthlySearches, $this->withServiceCategories, $this->include, $this->exclude,  $this->chunkSize);
            if ($results->getEntries() !== null) {
                foreach ($results->getEntries() as $targetingIdea) {
                    $keyword = $this->extractKeyword($targetingIdea);
                    $searchVolumes->push($keyword);
                }
            }
        }

        $missingKeywords = array_diff($keywords, $searchVolumes->pluck('keyword')->toArray());

        foreach ($missingKeywords as $missingKeyword) {
            $missingKeywordInstance = new Keyword([
                'keyword'       => $missingKeyword,
                'search_volume' => $this->convertNullToZero ? 0 : null,
                'cpc'           => $this->convertNullToZero ? 0 : null,
                'competition'   => $this->convertNullToZero ? 0 : null,
            ]);

            if ($this->withTargetedMonthlySearches) {
                $missingKeywordInstance->targeted_monthly_searches = $this->convertNullToZero ? collect() : null;
            }

            $searchVolumes->push($missingKeywordInstance);
        }

        return $searchVolumes;
    }

    public function keywordIdeas($keyword)
    {
        $keyword = $this->prepareKeywords([$keyword]);
        $requestType = RequestType::IDEAS;

        $keywordIdeas = new Collection();

        $results = $this->service->performQuery($keyword, $requestType, $this->language, $this->location, $this->withTargetedMonthlySearches, $this->withServiceCategories,  $this->include, $this->exclude, $this->chunkSize);

        if ($results->getEntries() !== null) {
            foreach ($results->getEntries() as $targetingIdea) {
                $keyword = $this->extractKeyword($targetingIdea);
                $keywordIdeas->push($keyword);
            }
        }

        return $keywordIdeas;
    }

    /**
     * Include Targeted Monthly Searches.
     *
     * @return $this
     */
    public function withTargetedMonthlySearches()
    {
        $this->withTargetedMonthlySearches = true;

        return $this;
    }

    /**
     * Include Targeted Service Categories.
     *
     * @return $this
     */
    public function withServiceCategories()
    {
        $this->withServiceCategories = true;

        return $this;
    }

    /**
     * Convert Null Values To Zero.
     *
     * @return $this
     */
    public function convertNullToZero()
    {
        $this->convertNullToZero = true;

        return $this;
    }

    /**
     * Set chunk Size.
     *
     * @return $this
     */
    public function setChunkSize($size)
    {
        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Add Language Search Parameter.
     *
     * @return $this
     */
    public function language($language = null)
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Add Location Search Parameter.
     *
     * @return $this
     */
    public function location($location = null)
    {
        $this->location = $location;

        return $this;
    }

    public function include(array $words)
    {
        $this->include = $this->prepareKeywords($words);

        return $this;
    }

    public function exclude(array $words)
    {
        $this->exclude = $this->prepareKeywords($words);

        return $this;
    }

    /**
     * @return TargetingIdeaService
     */
    public function getTargetingIdeaService(): TargetingIdeaService
    {
        return $this->service->getTargetingIdeaService();
    }

    /**
     * Private Functions.
     */
    private function prepareKeywords(array $keywords)
    {
        $keywords = array_map('trim', $keywords);
        $keywords = array_map('mb_strtolower', $keywords);
        $keywords = array_filter($keywords);
        $keywords = array_unique($keywords);
        $keywords = array_values($keywords);

        return $keywords;
    }

    /**
     * @param $targetingIdea
     *
     * @return Keyword
     */
    private function extractKeyword($targetingIdea)
    {
        $data = MapEntries::toAssociativeArray($targetingIdea->getData());
        $keyword = $data[AttributeType::KEYWORD_TEXT]->getValue();
        $search_volume =
            ($data[AttributeType::SEARCH_VOLUME]->getValue() !== null)
            ? $data[AttributeType::SEARCH_VOLUME]->getValue() : 0;

        $average_cpc =
            ($data[AttributeType::AVERAGE_CPC]->getValue() !== null)
            ? $data[AttributeType::AVERAGE_CPC]->getValue()->getMicroAmount() : 0;
        $competition =
            ($data[AttributeType::COMPETITION]->getValue() !== null)
            ? $data[AttributeType::COMPETITION]->getValue() : 0;

        $webpage = $data[AttributeType::EXTRACTED_FROM_WEBPAGE]->getValue();
        $idea_type = $data[AttributeType::IDEA_TYPE]->getValue();

        $result = new Keyword([
            'keyword'                   => $keyword,
            'search_volume'             => $search_volume,
            'cpc'                       => $average_cpc,
            'competition'               => $competition,
            'targeted_monthly_searches' => null,
            'categories'                => null,
            'webpage'                   => $webpage,
            'idea_type'                 => $idea_type,
        ]);

        if ($this->withServiceCategories) {
            $category_products_and_services =
                ($data[AttributeType::CATEGORY_PRODUCTS_AND_SERVICES]->getValue() !== null)
                ? $data[AttributeType::CATEGORY_PRODUCTS_AND_SERVICES]->getValue() : 0;
            $categories = collect($category_products_and_services)
                ->transform(function ($item, $key) {
                    return new ServiceCategory([
                        'google_id'  => $item,
                    ]);
                });

            $result->categories = $categories;
        }

        if ($this->withTargetedMonthlySearches) {
            $targeted_monthly_searches =
                ($data[AttributeType::TARGETED_MONTHLY_SEARCHES]->getValue() !== null)
                ? $data[AttributeType::TARGETED_MONTHLY_SEARCHES]->getValue() : 0;
            $targetedMonthlySearches = collect($targeted_monthly_searches)
                ->transform(function ($item, $key) {
                    return new MonthlySearchVolume([
                        'year'  => $item->getYear(),
                        'month' => $item->getMonth(),
                        'count' => $item->getCount(),
                    ]);
                });

            $result->targeted_monthly_searches = $targetedMonthlySearches;
        }

        return $result;
    }
}
