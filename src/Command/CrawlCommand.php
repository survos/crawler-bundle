<?php

declare(strict_types=1);

namespace Survos\CrawlerBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\CrawlerBundle\Model\Link;
use Survos\CrawlerBundle\RoutesExtractor;
use Survos\CrawlerBundle\Services\CrawlerService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

#[AsCommand('survos:crawl', 'Crawl a website with different users')]
class CrawlCommand
{
    public function __construct(
        private LoggerInterface $logger,
        private ParameterBagInterface $bag,
        private CrawlerService $crawlerService,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Link to start crawling')] ?string $startingLink = null,
        #[Option('Limit the number of links to process')] int $limit = 0,
        #[Option('Crawl only given locale url')] string $locale = 'en',
        #[Option('Firewall name')] string $securityFirewall = 'secured_area',
        #[Option('Regex to ignore routes')] ?string $ignoreRouteKeyword = null,
        #[Option('Skip generated @smoke routes and only crawl discovered links')] bool $skipSmokeRoutes = false,
        #[Option('Configured username to crawl; repeat to crawl more than one')] array $username = [],
    ): int {
        $table = new Table($io);
        $table->setHeaders(['User', '#Testable', '#Found']);

        $crawlerService = $this->crawlerService;

        $configuredUsernames = $this->crawlerService->getUsernames();
        $unknownUsernames = array_diff($username, $configuredUsernames);
        if ($unknownUsernames) {
            $io->error(sprintf(
                'Unknown crawler username(s): %s. Allowed configured username(s): %s',
                implode(', ', $unknownUsernames),
                $configuredUsernames ? implode(', ', $configuredUsernames) : '(none)'
            ));
            return Command::FAILURE;
        }

        $usernames = $username ?: $configuredUsernames;

        $crawlerService->resetLinkList();

        $routesToIgnore = $this->crawlerService->getRoutesToIgnore();
        $initialPath = $this->normalizeInitialPath($startingLink, $crawlerService->getInitialPath());

        $staticLinks = [];
        if (!$skipSmokeRoutes) {
            $routes = RoutesExtractor::extractRoutesFromRouter($this->router);
            foreach ($routes as $route) {
                if (in_array($route["routeName"], $routesToIgnore)) {
                    continue;
                }
                $staticLinks[] = $route;
            }
        } else {
            $io->info('Skipping generated @smoke routes; crawling only the initial path and links discovered from rendered pages.');
        }

        $linksToCrawl = [];
        foreach ([null, ...$usernames] as $currentUser) {
            $user = null;
            try {
                if ($currentUser && ($user = $this->crawlerService->getUser($currentUser))) {
                    $currentUser = $user->getUserIdentifier();
                    $crawlerService->authenticateClient($user);
                }
            } catch (UserNotFoundException $e) {
                $io->error(sprintf("User %s not found", $currentUser));
            } catch (\Exception $e) {
                dd($e->getMessage(), $e->getTraceAsString(), $e->getPrevious());
            }
            if ($currentUser && !$user) {
                $io->error(sprintf("User %s not found", $currentUser));
                return Command::FAILURE;
            }
            $this->crawlerService->checkIfCrawlerClient();
            $io->info(sprintf("Crawling %s as %s", $initialPath, $currentUser ?: 'Visitor'));

            foreach ($staticLinks as $route) {
                $crawlerService->addLink($currentUser, $route["routePath"], foundOn: '@smoke', route: $route["routeName"]);
            }

            $link = $crawlerService->addLink($currentUser, $initialPath, foundOn: '@initial');
            $link->username = $currentUser;
            assert(count($crawlerService->getLinkList($currentUser)), "No links for $currentUser");
            assert($crawlerService->getUnvisitedLink($currentUser));

            $loop = 0;
            while ($link = $crawlerService->getUnvisitedLink($currentUser)) {
                $loop++;
                $link->incVisits();

                if (preg_match('/javascript/', $link->getPath())) {
                    $io->info("Rejecting " . $link->getPath() . ' ' . $link->getRoute());
                    dd($link);
                    continue;
                }

                $crawlerService->setRoute($link);

                $io->info(sprintf("%s/%d %s%s as %s (from %s)",
                    $link->getRoute(),
                    $link->getVisits(),
                    $crawlerService->getBaseUrl(true),
                    $link->getPath(),
                    $currentUser ?: 'visitor',
                    $link->getFoundOn()
                ));

                $crawlerService->scrape($link);

                if ($link->getStatusCode() === 500) {
                    $fullUrl = rtrim($crawlerService->getBaseUrl(), '/') . '/' . ltrim($link->getPath(), '/');
                    $io->error([
                        '🚨 500 INTERNAL SERVER ERROR DETECTED 🚨',
                        '',
                        '📍 URL: ' . $fullUrl,
                        '🔗 Route: ' . ($link->getRoute() ?: 'unknown'),
                        '👤 User: ' . ($link->username ?: 'visitor'),
                        '📍 Found on: ' . ($link->getFoundOn() ?: 'unknown'),
                        '⏱️  Duration: ' . ($link->getDuration() ? $link->getDuration() . 'ms' : 'unknown'),
                        '',
                        '🔗 Direct link to test: ' . $fullUrl,
                        ''
                    ]);
                    $io->warning('Crawler stopped due to 500 error. Fix the issue above before continuing.');
                    return Command::FAILURE;
                }

                if ($link->getStatusCode() <> 200) {
                    $this->logger->warning(sprintf("%s %s (%s)",
                        $link->getPath(), $link->getRoute(), $link->getStatusCode()));
                }
                if (!$link->testable()) {
                    $io->info("Rejecting " . $link->getPath() . ' ' . $link->getRoute());
                }
                if ($limit && ($loop > $limit)) {
                    break;
                }
            }

            $key = $currentUser . "|" . $crawlerService->getBaseUrl();
            $linksToCrawl[$key] = array_filter($crawlerService->getLinkList($currentUser), fn(Link $link) => $link->testable());
            $table->addRow([$currentUser, count($linksToCrawl[$key]), count($crawlerService->getLinkList($currentUser))]);
            $io->success(sprintf("User $currentUser has with %d links", count($linksToCrawl[$key])));
        }
        $table->render();

        $outputFilename = $this->bag->get('kernel.project_dir') . '/tests/crawldata.json';
        file_put_contents($outputFilename, json_encode($linksToCrawl, JSON_UNESCAPED_LINE_TERMINATORS + JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));

        $table2 = new Table($io);
        $table2->setHeaders(['route', 'visits']);
        foreach ($crawlerService->getRouteVisits() as $routeName => $visits) {
            $table2->addRow([$routeName, $visits]);
        }
        $table2->render();
        $io->success(sprintf("File $outputFilename written with %d usernames", count($linksToCrawl)));

        return Command::SUCCESS;
    }

    private function normalizeInitialPath(?string $startingLink, string $defaultInitialPath): string
    {
        if (!$startingLink) {
            return $defaultInitialPath;
        }

        if (!str_starts_with($startingLink, 'http')) {
            return $startingLink;
        }

        $path = parse_url($startingLink, PHP_URL_PATH) ?: '/';
        $query = parse_url($startingLink, PHP_URL_QUERY);

        return $query ? sprintf('%s?%s', $path, $query) : $path;
    }
}
