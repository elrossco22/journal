<?php

use Behat\Gherkin\Node\TableNode;
use eLife\ApiSdk\ApiSdk;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class SearchContext extends Context
{
    private $query = [
        'for' => '',
        'subjects' => [],
    ];
    private $articles = [];

    /**
     * @Given /^there are (\d+) articles about \'([^\']*)\'$/
     * @Given /^there are (\d+) articles about \'([^\']*)\' with the MSA \'([^\']*)\'$/
     */
    public function thereAreArticlesAbout(int $number, string $keyword, string $subject = null)
    {
        $today = (new DateTimeImmutable())->setTime(0, 0, 0);

        if ($subject && !in_array($subject, $this->query)) {
            $this->query['subjects'][] = $subject;
        }

        $existingNumberOfArticles = count($this->articles);

        for ($i = $existingNumberOfArticles + 1; $i <= $number + $existingNumberOfArticles; ++$i) {
            $i = str_pad($i, 5, '0', STR_PAD_LEFT);
            $article = [
                'status' => 'poa',
                'stage' => 'published',
                'id' => "$i",
                'version' => 1,
                'type' => 'research-article',
                'doi' => '10.7554/eLife.'.$i,
                'title' => "Article $i title: $keyword",
                'published' => $today->format(ApiSdk::DATE_FORMAT),
                'versionDate' => $today->format(ApiSdk::DATE_FORMAT),
                'statusDate' => $today->format(ApiSdk::DATE_FORMAT),
                'volume' => 5,
                'elocationId' => 'e'.$i,
                'copyright' => [
                    'license' => 'CC-BY-4.0',
                    'holder' => 'Author et al',
                    'statement' => 'Creative Commons Attribution License.',
                ],
                'authorLine' => 'Foo Bar',
            ];

            if ($subject) {
                $article['subjects'] = [
                    [
                        'id' => $this->createSubjectId($subject),
                        'name' => $subject,
                    ],
                ];
            }

            array_unshift($this->articles, $article);
        }

        $articlesWithKeyword = $this->filterArticlesContainingKeyword($keyword, $this->articles);

        $baseUri = 'http://api.elifesciences.org/search?for=%s&page=%s&per-page=%s&sort=relevance&order=desc';

        $subjectGroups = [[], $this->query['subjects']];

        foreach ($this->query['subjects'] as $querySubject) {
            $subjectGroups[] = [$querySubject];
        }

        $subjectGroups = array_unique($subjectGroups, SORT_REGULAR);

        foreach (['', $keyword] as $thisKeyword) {
            foreach ($subjectGroups as $subjects) {
                $uri = $baseUri.implode('', array_map(function (string $subject) {
                    return '&subject[]='.$this->createSubjectId($subject);
                }, $subjects));

                $articlesWithKeywordAndSubjects = $this->filterArticlesWithASubject($subjects, $articlesWithKeyword);

                $typeFilters = [
                    'correction' => 0,
                    'editorial' => 0,
                    'feature' => 0,
                    'insight' => 0,
                    'research-advance' => 0,
                    'research-article' => 0,
                    'research-exchange' => 0,
                    'retraction' => 0,
                    'registered-report' => 0,
                    'replication-study' => 0,
                    'short-report' => 0,
                    'tools-resources' => 0,
                    'blog-article' => 0,
                    'collection' => 0,
                    'event' => 0,
                    'interview' => 0,
                    'labs-experiment' => 0,
                    'podcast-episode' => 0,
                ];

                foreach (array_keys($typeFilters) as $type) {
                    $typeFilters[$type] = count($this->filterArticlesByType($type, $articlesWithKeyword));
                }

                $subjectFilters = array_map(function (string $subject) use ($articlesWithKeyword) {
                    return [
                        'id' => $this->createSubjectId($subject),
                        'name' => $subject,
                        'results' => count($this->filterArticlesWithSubject($subject, $articlesWithKeyword)),
                    ];
                }, $this->query['subjects']);

                $this->mockApiResponse(
                    new Request(
                        'GET',
                        sprintf($uri, $thisKeyword, 1, 1),
                        ['Accept' => 'application/vnd.elife.search+json; version=1']
                    ),
                    new Response(
                        200,
                        ['Content-Type' => 'application/vnd.elife.search+json; version=1'],
                        json_encode([
                            'total' => count($articlesWithKeywordAndSubjects),
                            'items' => count($articlesWithKeywordAndSubjects) ? [$articlesWithKeywordAndSubjects[0]] : [],
                            'subjects' => $subjectFilters,
                            'types' => $typeFilters,
                        ])
                    )
                );

                $articleChunks = array_chunk($articlesWithKeywordAndSubjects, 6);

                if (empty($articleChunks)) {
                    $articleChunks[] = [];
                }

                foreach ($articleChunks as $i => $articleChunk) {
                    $this->mockApiResponse(
                        new Request(
                            'GET',
                            sprintf($uri, $thisKeyword, $i + 1, 6),
                            ['Accept' => 'application/vnd.elife.search+json; version=1']
                        ),
                        new Response(
                            200,
                            ['Content-Type' => 'application/vnd.elife.search+json; version=1'],
                            json_encode([
                                'total' => count($articlesWithKeywordAndSubjects),
                                'items' => $articleChunk,
                                'subjects' => $subjectFilters,
                                'types' => $typeFilters,
                            ])
                        )
                    );
                }
            }
        }
    }

    /**
     * @Given /^I am reading an article:$/
     */
    public function iAmReadingAnArticle(TableNode $table)
    {
        $subjects = array_map(function (string $subject) {
            return [
                'id' => $this->createSubjectId($subject),
                'name' => $subject,
            ];
        }, explode(', ', $table->getRowsHash()['Subjects']));

        $this->mockApiResponse(
            new Request(
                'GET',
                'http://api.elifesciences.org/articles/00001',
                [
                    'Accept' => [
                        'application/vnd.elife.article-poa+json; version=1',
                        'application/vnd.elife.article-vor+json; version=1',
                    ],
                ]
            ),
            new Response(
                200,
                ['Content-Type' => 'application/vnd.elife.article-poa+json; version=1'],
                json_encode([
                    'status' => 'poa',
                    'stage' => 'published',
                    'id' => '00001',
                    'version' => 1,
                    'type' => 'research-article',
                    'doi' => '10.7554/eLife.00001',
                    'title' => 'Article title',
                    'published' => '2010-01-01T00:00:00Z',
                    'versionDate' => '2010-01-01T00:00:00Z',
                    'statusDate' => '2010-01-01T00:00:00Z',
                    'volume' => 1,
                    'elocationId' => 'e00001',
                    'copyright' => [
                        'license' => 'CC0-1.0',
                        'statement' => 'Copyright statement.',
                    ],
                    'subjects' => $subjects,
                ])
            )
        );

        $this->mockApiResponse(
            new Request(
                'GET',
                'http://api.elifesciences.org/articles/00001/versions',
                [
                    'Accept' => [
                        'application/vnd.elife.article-history+json; version=1',
                    ],
                ]
            ),
            new Response(
                200,
                ['Content-Type' => 'application/vnd.elife.article-history+json; version=1'],
                json_encode([
                    'versions' => [
                        [
                            'status' => 'poa',
                            'stage' => 'published',
                            'id' => '00001',
                            'version' => 1,
                            'type' => 'research-article',
                            'doi' => '10.7554/eLife.00001',
                            'title' => 'Article title',
                            'published' => '2010-01-01T00:00:00Z',
                            'versionDate' => '2010-01-01T00:00:00Z',
                            'statusDate' => '2010-01-01T00:00:00Z',
                            'volume' => 1,
                            'elocationId' => 'e00001',
                            'copyright' => [
                                'license' => 'CC0-1.0',
                                'statement' => 'Copyright statement.',
                            ],
                            'subjects' => $subjects,
                        ],
                    ],
                ])
            )
        );

        $this->visitPath('/content/1/e00001');
    }

    /**
     * @Given /^I am on the search page$/
     */
    public function iAmOnTheSearchPage()
    {
        $this->visitPath('/search');
    }

    /**
     * @When /^I click search$/
     */
    public function iClickSearch()
    {
        $this->assertSession()->elementExists('css', 'a[rel="search"]')->click();
    }

    /**
     * @Given /^I searched for \'([^\']*)\'$/
     * @When /^I search for \'([^\']*)\'$/
     */
    public function iSearchFor(string $keyword)
    {
        $this->iClickSearch();

        $this->getSession()->getPage()->fillField('for', $keyword);

        $this->getSession()->getPage()->pressButton('Search');
    }

    /**
     * @Given /^I filtered by the MSA \'([^\']*)\'$/
     * @When /^I filter by the MSA \'([^\']*)\'$/
     */
    public function iFilteredByTheMSA(string $subject)
    {
        $this->getSession()->getPage()->checkField($subject);

        $this->getSession()->getPage()->pressButton('Refine results');
    }

    /**
     * @When /^I load more results$/
     */
    public function iLoadMoreResults()
    {
        $this->getSession()->getPage()->clickLink('Load more');
    }

    /**
     * @Then /^I should see the option to limit the search to \'([^\']*)\'$/
     */
    public function iShouldSeeTheOptionToLimitTheSearchTo(string $subject)
    {
        $this->spin(function () use ($subject) {
            $this->assertSession()->fieldExists('Limit my search to '.$subject);
        });
    }

    /**
     * @Then /^I should see the (\d+) most relevant results for \'([^\']*)\'$/
     */
    public function iShouldSeeTheMostRelevantResultsFor(int $number, string $keyword)
    {
        $articles = $this->filterArticlesContainingKeyword($keyword, $this->articles);

        $this->spin(function () use ($number, $articles) {
            $this->assertSession()->elementsCount('css', '.message-bar:contains("'.count($articles).' results found") + .list-heading + .listing-list > .listing-list__item', $number);

            for ($i = $number; $i > 0; --$i) {
                $nthChild = ($number - $i + 1);
                $expectedNumber = (count($articles) - $nthChild + 1);

                $this->assertSession()->elementContains(
                    'css',
                    '.message-bar:contains("'.count($articles).' results found") + .list-heading + .listing-list > .listing-list__item:nth-child('.$nthChild.')',
                    'Article '.str_pad($expectedNumber, 5, '0', STR_PAD_LEFT).' title'
                );
            }
        });
    }

    /**
     * @Then /^I should see the (\d+) most relevant results about \'([^\']*)\' with the MSA \'([^\']*)\'$/
     * @Then /^I should see the (\d+) most relevant results about \'([^\']*)\' with the MSA \'([^\']*)\' or \'([^\']*)\'$/
     */
    public function iShouldSeeTheMostRelevantResultsAboutWithTheMSAs(int $number, string $keyword, string $subject1, string $subject2 = null)
    {
        $subjects = array_filter([$subject1, $subject2]);

        $articles = $this->filterArticlesWithASubject($subjects, $this->filterArticlesContainingKeyword($keyword, $this->articles));

        $this->spin(function () use ($number, $articles) {
            $this->assertSession()->elementsCount('css', '.message-bar:contains("'.count($articles).' results found") + .list-heading + .listing-list > .listing-list__item', $number);

            for ($i = $number; $i > 0; --$i) {
                $nthChild = ($number - $i + 1);
                $expectedNumber = (count($articles) - $nthChild + 1);

                $this->assertSession()->elementContains(
                    'css',
                    '.message-bar:contains("'.count($articles).' results found") + .list-heading + .listing-list > .listing-list__item:nth-child('.$nthChild.')',
                    'Article '.str_pad($expectedNumber, 5, '0', STR_PAD_LEFT).' title'
                );
            }
        });
    }

    private function createSubjectId(string $subjectName) : string
    {
        return md5($subjectName);
    }

    private function filterArticlesByType(string $type, array $articles) : array
    {
        return array_values(array_filter($articles, function (array $article) use ($type) {
            return $article['type'] === $type;
        }));
    }

    private function filterArticlesContainingKeyword(string $keyword, array $articles) : array
    {
        return array_values(array_filter($articles, function (array $article) use ($keyword) {
            return $keyword === substr($article['title'], -strlen($keyword));
        }));
    }

    private function filterArticlesWithSubject(string $subject, array $articles) : array
    {
        return array_values(array_filter($articles, function (array $article) use ($subject) {
            foreach ($article['subjects'] ?? [] as $articleSubject) {
                if ($articleSubject['name'] === $subject) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function filterArticlesWithASubject(array $subjects, array $articles) : array
    {
        if (empty($subjects)) {
            return $articles;
        }

        return array_values(array_filter($articles, function (array $article) use ($subjects) {
            foreach ($article['subjects'] ?? [] as $articleSubject) {
                if (in_array($articleSubject['name'], $subjects)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
