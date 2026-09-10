<?php

declare(strict_types=1);

namespace Survos\CrawlerBundle\Controller;

use Survos\CrawlerBundle\Services\CrawlerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CrawlerController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $env
    ) {
    }

    #[Route(path: '/crawlerdata', name: 'survos_crawler_data', methods: ['GET'])]
    public function results(CrawlerService $crawlerService): Response
    {
        if ($this->env<>'dev') {
            return new Response("survos_crawler_data is only available in dev");
        }

        // hackish -- get the crawldata of the currently logged in user?
        $filename = $this->projectDir . '/tests/crawldata.json';
            if (!file_exists($filename)) {
                throw $this->createNotFoundException("Run survos:crawl to create $filename");
            }

        $crawlData = json_decode(file_get_contents($filename), true);
        // @todo: filter out null status codes, here or in searchhpanes?
        $tableData = [];
        foreach ($crawlData as $header => $data) {
            foreach ($data as $datum) {
                $datum['user'] = $header;
                $tableData[] = $datum;
            }
        }

        return $this->render('@SurvosCrawler/results.html.twig', [
            'crawlerConfig' => $crawlerService->getConfig(),
            'tableData' => $tableData,
            'routeStats' => $this->routeStats($tableData),
            'lastCrawl' => (new \DateTimeImmutable())->setTimestamp((int) filemtime($filename)),
            'crawldata' => $crawlData
        ]);
    }

    /**
     * Per-route rollup for the summary table: how often each route was crawled and how
     * slow it is on average. Durations are only recorded for requests the crawler
     * actually issued, so the average is over those, not over every row.
     *
     * @param list<array<string,mixed>> $tableData
     *
     * @return list<array{route:string, visits:int, avgDuration:float|null, maxDuration:float|null, statuses:list<int>, paths:int}>
     */
    private function routeStats(array $tableData): array
    {
        $byRoute = [];
        foreach ($tableData as $row) {
            $route = $row['route'] ?? '(no route)';
            $byRoute[$route] ??= ['route' => $route, 'visits' => 0, 'durations' => [], 'statuses' => [], 'paths' => []];

            $byRoute[$route]['visits'] += max(1, (int) ($row['visits'] ?? 1));
            $byRoute[$route]['paths'][$row['path'] ?? ''] = true;

            if (null !== ($duration = $row['duration'] ?? null)) {
                $byRoute[$route]['durations'][] = (float) $duration;
            }
            if (null !== ($status = $row['statusCode'] ?? null)) {
                $byRoute[$route]['statuses'][(int) $status] = true;
            }
        }

        $stats = [];
        foreach ($byRoute as $row) {
            $durations = $row['durations'];
            $statuses = array_keys($row['statuses']);
            sort($statuses);

            $stats[] = [
                'route' => $row['route'],
                'visits' => $row['visits'],
                'avgDuration' => $durations ? array_sum($durations) / count($durations) : null,
                'maxDuration' => $durations ? max($durations) : null,
                'statuses' => $statuses,
                'paths' => count($row['paths']),
            ];
        }

        // Slowest first -- that is what someone opening this page is looking for.
        usort($stats, static fn (array $a, array $b) => ($b['avgDuration'] ?? -1) <=> ($a['avgDuration'] ?? -1));

        return $stats;
    }
}
