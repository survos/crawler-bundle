<?php

declare(strict_types=1);

namespace Survos\CrawlerBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\CrawlerBundle\Model\Link;
use Survos\CrawlerBundle\RoutesExtractor;
use Survos\CrawlerBundle\Services\CrawlerService;
use Survos\TuiExtrasBundle\Model\TuiColumn;
use Survos\TuiExtrasBundle\Source\ArrayTableSource;
use Survos\TuiExtrasBundle\Widget\DataTableWidget;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

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
        #[Option('Show a live dashboard (routes visited, current user/link) instead of scrolling log output')] bool $tui = false,
        #[Option('Include every registered route (API/webhook/non-GET/etc.), not just visible/navigable ones')] ?bool $complete = null,
    ): int {
        $complete ??= false;

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

        if ($tui && !class_exists(Tui::class)) {
            $io->warning('--tui requires survos/tui-extras-bundle (composer require survos/tui-extras-bundle). Falling back to normal output.');
            $tui = false;
        }

        $dashboard = $tui ? new CrawlDashboard([null, ...$usernames]) : null;

        try {
            return $this->crawl($io, $table, $dashboard, $startingLink, $limit, $securityFirewall, $skipSmokeRoutes, $usernames, $complete);
        } finally {
            $dashboard?->stop();
        }
    }

    private function crawl(
        SymfonyStyle $io,
        Table $table,
        ?CrawlDashboard $dashboard,
        ?string $startingLink,
        int $limit,
        string $securityFirewall,
        bool $skipSmokeRoutes,
        array $usernames,
        bool $complete,
    ): int {
        $crawlerService = $this->crawlerService;
        $crawlerService->resetLinkList();

        $routesToIgnore = $this->crawlerService->getRoutesToIgnore();
        $initialPath = $this->normalizeInitialPath($startingLink, $crawlerService->getInitialPath());

        $staticLinks = [];
        $skippedNotVisible = 0;
        if (!$skipSmokeRoutes) {
            $routes = RoutesExtractor::extractRoutesFromRouter($this->router);
            foreach ($routes as $route) {
                if (in_array($route["routeName"], $routesToIgnore)) {
                    continue;
                }
                if (!$complete && !$this->isVisibleRoute($route)) {
                    ++$skippedNotVisible;
                    continue;
                }
                $staticLinks[] = $route;
            }
            if ($skippedNotVisible && !$dashboard) {
                $io->writeln(sprintf(
                    '<comment>Skipping %d non-navigable route(s) (API/webhook/non-GET/etc.) — pass --complete to include them.</comment>',
                    $skippedNotVisible,
                ));
            }
        } else {
            $io->info('Skipping generated @smoke routes; crawling only the initial path and links discovered from rendered pages.');
        }

        $linksToCrawl = [];
        foreach ([null, ...$usernames] as $currentUser) {
            $crawlerService->resetRouteVisits();
            $dashboard?->startUser($currentUser ?: 'visitor');
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
            if (!$dashboard) {
                $io->info(sprintf("Crawling %s as %s", $initialPath, $currentUser ?: 'Visitor'));
            }

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

                $crawlerService->setRoute($link);

                if (!$dashboard) {
                    $io->writeln(sprintf(" %s/%d %s%s as %s (from %s)",
                        $link->getRoute(),
                        $link->getVisits(),
                        $crawlerService->getBaseUrl(true),
                        $link->getPath(),
                        $currentUser ?: 'visitor',
                        $link->getFoundOn()
                    ));
                }

                $crawlerService->scrape($link);

                $dashboard?->recordVisit($currentUser ?: 'visitor', $link, $loop, $crawlerService->getRouteVisits());

                // A 404 on a synthetic @smoke route (router path filled with dummy/default
                // parameters) isn't necessarily a real bug — it's just a guessed URL. A 404
                // on a link actually found by rendering a page (or the initial page itself)
                // means a real, clickable link on the site is broken, which is exactly what
                // this crawl exists to catch. 500s are always fatal regardless of source.
                $isExposedLink = '@smoke' !== $link->getFoundOn();
                $isFatal = 500 === $link->getStatusCode() || (404 === $link->getStatusCode() && $isExposedLink);

                if ($isFatal) {
                    $dashboard?->stop();
                    $fullUrl = rtrim($crawlerService->getBaseUrl(), '/') . '/' . ltrim($link->getPath(), '/');
                    $statusLabel = 500 === $link->getStatusCode() ? '500 INTERNAL SERVER ERROR' : '404 NOT FOUND (broken link)';
                    $errorExcerpt = $this->extractErrorExcerpt($link->getHtml() ?? '');
                    $io->error(array_filter([
                        "🚨 $statusLabel DETECTED 🚨",
                        '',
                        '📍 URL: ' . $fullUrl,
                        '🔗 Route: ' . ($link->getRoute() ?: 'unknown'),
                        '👤 User: ' . ($link->username ?: 'visitor'),
                        '📍 Found on: ' . ($link->getFoundOn() ?: 'unknown'),
                        '⏱️  Duration: ' . ($link->getDuration() ? $link->getDuration() . 'ms' : 'unknown'),
                        $errorExcerpt ? '' : null,
                        $errorExcerpt ? '💥 ' . $errorExcerpt : null,
                        '',
                        '🔗 Direct link to test: ' . $fullUrl,
                        ''
                    ], static fn ($line) => null !== $line));
                    $io->warning(sprintf('Crawler stopped due to a %s error. Fix the issue above before continuing.', $link->getStatusCode()));
                    return Command::FAILURE;
                }

                if ($link->getStatusCode() <> 200 && !$dashboard) {
                    $this->logger->warning(sprintf("%s %s (%s)",
                        $link->getPath(), $link->getRoute(), $link->getStatusCode()));
                }
                if (!$link->testable() && !$dashboard) {
                    $io->writeln(" Rejecting " . $link->getPath() . ' ' . $link->getRoute());
                }
                if ($limit && ($loop > $limit)) {
                    break;
                }
            }

            $key = $currentUser . "|" . $crawlerService->getBaseUrl();
            $linksToCrawl[$key] = array_filter($crawlerService->getLinkList($currentUser), fn(Link $link) => $link->testable());
            $table->addRow([$currentUser, count($linksToCrawl[$key]), count($crawlerService->getLinkList($currentUser))]);
            $dashboard?->finishUser($currentUser ?: 'visitor', count($linksToCrawl[$key]));
            if (!$dashboard) {
                $io->success(sprintf("User $currentUser has with %d links", count($linksToCrawl[$key])));
            }
        }

        $dashboard?->finish(count($usernames) + 1);
        $dashboard?->stop();
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

    /**
     * Pulls a human-readable summary out of a raw error-response body. In dev
     * (kernel.debug=true) the body IS Symfony's full exception/debug page, so
     * the crawler's own report can show the real exception instead of just
     * crawl bookkeeping (url/route/user/duration) — that bookkeeping alone
     * doesn't say what actually broke.
     */
    private function extractErrorExcerpt(string $html): string
    {
        if ('' === $html) {
            return '';
        }
        if (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
            $text = trim(html_entity_decode(strip_tags($m[1]), \ENT_QUOTES));
            if ('' !== $text) {
                return $text;
            }
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
    }

    /**
     * "Visible" = a real, GET-navigable HTML page a person could actually click to.
     * Excludes API Platform/JSON endpoints, webhooks, and non-GET-only routes —
     * registered routes that show up in the router but were never meant to be
     * link targets, so a 404/500 on them isn't a real "broken link" finding.
     */
    private function isVisibleRoute(array $route): bool
    {
        if ('GET' !== $route['httpMethod']) {
            return false;
        }

        $name = $route['routeName'];
        $path = $route['routePath'];

        if (str_starts_with($name, '_api_') || str_starts_with($name, 'api_') || str_contains($path, '/api/')) {
            return false;
        }
        if (str_starts_with($name, '_wdt') || str_starts_with($name, '_profiler')) {
            return false;
        }
        if (str_contains($name, 'webhook')) {
            return false;
        }

        return true;
    }
}

/**
 * Manually-driven (non-interactive) TUI dashboard for `survos:crawl`.
 *
 * The crawl loop is synchronous and CPU/DB-bound (each link is a real
 * in-process kernel request), so this drives symfony/tui's render cycle
 * imperatively (start()/requestRender()/processRender()) rather than via
 * Tui::run()'s Revolt event loop — there's no async work to interleave with.
 * Rendering is throttled to ~10/sec so terminal writes don't add overhead to
 * the crawl itself.
 */
final class CrawlDashboard
{
    private Tui $tui;
    private TextWidget $header;
    private TextWidget $usersPanel;
    private ContainerWidget $tableSlot;

    /** @var array<string,array<string,array{visits:int,status:int|string|null}>> username => route => stat */
    private array $routeStats = [];

    /** @var array<string,string> username => status line */
    private array $userLines = [];

    private ?string $currentUser = null;
    private float $startedAt;
    private float $lastRenderAt = 0.0;

    public function __construct(array $allUsers)
    {
        foreach ($allUsers as $u) {
            $this->userLines[$u ?: 'visitor'] = '  ' . ($u ?: 'visitor') . ' — pending';
        }

        $this->startedAt = microtime(true);

        $this->header = new TextWidget('Starting crawl…');
        $this->usersPanel = new TextWidget($this->renderUsersPanel());
        $this->usersPanel->setStyle(new Style(maxColumns: 30));

        $this->tableSlot = new ContainerWidget();
        $this->tableSlot->expandVertically(true);
        $this->tableSlot->add($this->buildTable(null));

        $split = (new ContainerWidget())->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $split->add($this->usersPanel);
        $split->add($this->tableSlot);

        $root = (new ContainerWidget())->setStyle(new Style(direction: Direction::Vertical));
        $root->add($this->header);
        $root->add($split);

        $this->tui = new Tui();
        $this->tui->add($root);
        $this->tui->start();

        // Tui::start() puts the terminal in full raw mode (stty raw -echo), which
        // disables ISIG — Ctrl+C no longer generates SIGINT at all, it's just a
        // discarded input byte. That's fine for symfony/tui's own run() loop
        // (which reads and reacts to it as a keybinding), but this dashboard is
        // driven manually (see class docblock) and never reads stdin, so without
        // the fix below Ctrl+C would do *nothing* and the process becomes
        // unkillable short of closing the terminal window entirely.
        // Restoring isig lets the kernel deliver SIGINT/SIGTERM again; the signal
        // handlers below make sure that actually restores the terminal instead of
        // leaving it in raw/no-echo/hidden-cursor state.
        if (\function_exists('shell_exec')) {
            @shell_exec('stty isig');
        }
        $this->installSignalHandlers();
    }

    private function installSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (int $signal): void {
            $this->tui->stop();
            exit(128 + $signal);
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    public function startUser(string $username): void
    {
        $this->currentUser = $username;
        $this->userLines[$username] = '▶ ' . $username . ' — crawling…';
        $this->render($username, force: true);
    }

    public function finishUser(string $username, int $testableCount): void
    {
        $this->userLines[$username] = '✓ ' . $username . ' — ' . $testableCount . ' links';
        $this->render($username, force: true);
    }

    public function recordVisit(string $username, Link $link, int $loop, array $routeVisits): void
    {
        $route = $link->getRoute() ?: '(no route)';
        $this->routeStats[$username][$route] = [
            'visits' => $routeVisits[$route] ?? ($this->routeStats[$username][$route]['visits'] ?? 0),
            'status' => $link->getStatusCode(),
        ];

        $elapsed = microtime(true) - $this->startedAt;
        $this->header->setText(sprintf(
            ' Showing: %s  |  loop %d  |  %.1fs  |  %s %s (%s)',
            $username,
            $loop,
            $elapsed,
            $link->getRoute() ?: '-',
            $link->getPath(),
            $link->getStatusCode() ?? '-',
        ));

        // Bad statuses always force a redraw so they're never missed between throttled frames.
        $isNotable = $link->getStatusCode() && $link->getStatusCode() >= 400;
        $this->render($username, force: $isNotable);
    }

    /**
     * Renders a final, unmissable "done" frame (merged across all users) before
     * stop() hands the terminal back — otherwise the dashboard just vanishes and
     * it's unclear whether the crawl finished or was killed.
     */
    public function finish(int $totalUsers): void
    {
        $totalVisits = 0;
        foreach ($this->routeStats as $routes) {
            foreach ($routes as $stat) {
                $totalVisits += $stat['visits'];
            }
        }

        $elapsed = microtime(true) - $this->startedAt;
        $this->header->setText(sprintf(
            ' ✅ CRAWL COMPLETE — %d user%s, %d requests, %.1fs',
            $totalUsers,
            1 === $totalUsers ? '' : 's',
            $totalVisits,
            $elapsed,
        ));
        $this->usersPanel->setText($this->renderUsersPanel());
        $this->tableSlot->clear();
        $this->tableSlot->add($this->buildTable(null));
        $this->tui->requestRender();
        $this->tui->processRender();
    }

    public function stop(): void
    {
        $this->tui->stop();
    }

    private function render(string $forUser, bool $force): void
    {
        $now = microtime(true);
        if (!$force && ($now - $this->lastRenderAt) < 0.1) {
            return;
        }
        $this->lastRenderAt = $now;

        $this->usersPanel->setText($this->renderUsersPanel());
        $this->tableSlot->clear();
        $this->tableSlot->add($this->buildTable($forUser));

        $this->tui->requestRender();
        $this->tui->processRender();
    }

    private function renderUsersPanel(): string
    {
        $lines = ['Users', ''];
        foreach ($this->userLines as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function buildTable(?string $forUser): DataTableWidget
    {
        $rows = [];
        if (null !== $forUser) {
            foreach ($this->routeStats[$forUser] ?? [] as $route => $stat) {
                $rows[] = [
                    'route' => $route,
                    'visits' => $stat['visits'],
                    'status' => $stat['status'] ?? '-',
                    'user' => $forUser,
                ];
            }
        } else {
            $merged = [];
            foreach ($this->routeStats as $forUser => $routes) {
                foreach ($routes as $route => $stat) {
                    $merged[$route] ??= ['visits' => 0, 'status' => '-', 'users' => []];
                    $merged[$route]['visits'] += $stat['visits'];
                    $merged[$route]['status'] = $stat['status'] ?? $merged[$route]['status'];
                    $merged[$route]['users'][] = $forUser;
                }
            }
            foreach ($merged as $route => $stat) {
                $rows[] = [
                    'route' => $route,
                    'visits' => $stat['visits'],
                    'status' => $stat['status'],
                    'user' => implode(',', array_unique($stat['users'])),
                ];
            }
        }
        usort($rows, static fn (array $a, array $b) => $b['visits'] <=> $a['visits']);

        $columns = [
            new TuiColumn(key: 'route', label: 'Route', sortable: true),
            new TuiColumn(key: 'visits', label: 'Visits', width: 8, sortable: true),
            new TuiColumn(key: 'status', label: 'Status', width: 8),
            new TuiColumn(key: 'user', label: 'User', width: 24),
        ];

        return new DataTableWidget(new ArrayTableSource($rows, $columns));
    }
}
