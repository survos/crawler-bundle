<?php

namespace Survos\CrawlerBundle\Command;

//use App\Entity\User;
use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Survos\CrawlerBundle\Model\Link;
use Survos\CrawlerBundle\RoutesExtractor;
use Survos\CrawlerBundle\Services\CrawlerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'survos:crawl',
    description: 'Crawl a website with different users',
)]
class CrawlCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private ParameterBagInterface $bag,
        private CrawlerService $crawlerService,
        private RouterInterface $router,
        #[Autowire('%kernel.environment%')] private string $env,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var string
     */
    protected $domain = null;

    /**
     * @var string
     */
    protected $username = null;

    /**
     * @var string
     */
    protected $startingLink = null;

    /**
     * @var string
     */
    protected $locale = 'en';

    /**
     * @var string
     */
    protected $securityFirewall = 'secured_area';

    /**
     * @var integer
     */
    protected $searchLimit;

    /**
     * index routes containing these keywords only once
     * @var array
     */
    protected $ignoredRouteKeywords = ['_profiler'];

    /**
     * @var array
     */
    protected $domainLinks = [];

    /**
     * @var array
     */
    protected $linksToProcess = [];

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description');
        $this->setDefinition([
            new InputArgument('startingLink', InputArgument::OPTIONAL, 'Link to start crawling'),
            new InputArgument('username', InputArgument::OPTIONAL, 'Username', 'o'),
            new InputArgument('password', InputArgument::OPTIONAL, 'Password', 'o'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of links to process, prevents infinite crawling', 0),
            new InputOption('locale', null, InputOption::VALUE_REQUIRED, 'Crawler will crawl only given locale url', 'en'),
            new InputOption('security-firewall', null, InputOption::VALUE_REQUIRED, 'Firewall name', 'secured_area'),
            new InputOption('ignore-route-keyword', null, InputOption::VALUE_REQUIRED, 'regex to ignore routes'),
//            new InputOption('ignore-route-keyword', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Skip routes containing this string', []),

        ]);
    }

    /**
     * Execute
     *
     * @author  Joe Sexton <joe@webtipblog.com
     * @todo    use product sitemap to crawl product pages
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $this->locale = $input->getOption('locale');

        $io = new SymfonyStyle($input, $output);

        $table = new Table($output);
        $table
            ->setHeaders(['User', '#Testable', '#Found']);

        $crawlerService = $this->crawlerService;

        //
        $usernames = $this->crawlerService->getUsernames();

        $crawlerService->resetLinkList();

        $routesToIgnore = $this->crawlerService->getRoutesToIgnore();


        $routes = RoutesExtractor::extractRoutesFromRouter($this->router);
        foreach ($routes as $route) {
            if (in_array($route["routeName"], $routesToIgnore)) {
                continue;
            }
            $staticLinks[] = $route;
        }


        // start with null, so that it is logged out.  Otherwise, it gets the last user!  BUG
        foreach ([null, ...$usernames] as $username) {
            //        foreach ($usernames as $user) {
            try {
                if ($username  && ($user = $this->crawlerService->getUser($username))) {
                    $username = $user->getUserIdentifier(); //assert that they're the same?
                    $crawlerService->authenticateClient($user);
                }
            } catch (UserNotFoundException $e) {
                $io->error(sprintf("User %s not found", $username));
            } catch (\Exception $e) {
                dd($e->getMessage(), $e->getTraceAsString(), $e->getPrevious());
            }
            if ($username && !$user) {
                $io->error(sprintf("User %s not found", $username));
                return Command::FAILURE;
            }
            $this->crawlerService->checkIfCrawlerClient();
            $io->info(sprintf("Crawling %s as %s", $crawlerService->getInitialPath(), $username ?: 'Visitor'));

            foreach ($staticLinks as $route) {
                $link = $crawlerService->addLink($username, $route["routePath"], foundOn: '@smoke', route: $route["routeName"]);
            }

            $link = $crawlerService->addLink($username, $crawlerService->getInitialPath(), foundOn: '@initial');


            $link->username = $username;
            assert(count($crawlerService->getLinkList($username)), "No links for $username");
            assert($crawlerService->getUnvisitedLink($username));

            $loop = 0;
            while ($link = $crawlerService->getUnvisitedLink($username)) {
                $loop++;
                $link->incVisits();

                // we need to configure ignored patterns
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
                    $username ?: 'visitor',
                    $link->getFoundOn()
                ));

                $crawlerService->scrape($link);
                
                // Handle 500 errors with detailed reporting and break
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
                
                // Handle other non-200 status codes
                if ($link->getStatusCode() <> 200) {
                    $this->logger->warning(sprintf("%s %s (%s)",
                        $link->getPath(), $link->getRoute(), $link->getStatusCode()));
                }
                if (! $link->testable()) {
                    $io->info("Rejecting " . $link->getPath() . ' ' . $link->getRoute());
                }
                if (($limit = $input->getOption('limit')) && ($loop > $limit)) {
                    break;
                }
            }

            $key = $username ."|".$crawlerService->getBaseUrl();
            $linksToCrawl[$key] = array_filter($crawlerService->getLinkList($username), fn (Link $link) => $link->testable());
            //            $userLinks = array_merge($userLinks, array_values($linksToCrawl));
            $table->addRow([$username, count($linksToCrawl[$key]), count($crawlerService->getLinkList($username))]);

            //            $userLinks += array_values($linksToCrawl);
            $io->success(sprintf("User $username has with %d links", count($linksToCrawl[$key])));
        }
        $table->render();

        $outputFilename = $this->bag->get('kernel.project_dir') . '/tests/crawldata.json';
        //        foreach ($crawlerService->getEntireLinkList() as $user=>$userLinks) {
        //            $testableLink =
        //        }

        file_put_contents($outputFilename, json_encode($linksToCrawl, JSON_UNESCAPED_LINE_TERMINATORS + JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));

        $table = new Table($output);
        $table->setHeaders(['route','visits']);
        foreach ($crawlerService->getRouteVisits() as $routeName=>$visits) {
            $table->addRow([$routeName, $visits]);
        }
        $table->render();
        $io->success(sprintf("File $outputFilename written with %d usernames", count($linksToCrawl)));

        return self::SUCCESS;

        // user input
        $this->startingLink = $input->getArgument('startingLink');
        $this->username = $input->getArgument('username');
        $this->searchLimit = $input->getOption('limit');
        $this->securityFirewall = $input->getOption('security-firewall');
        //array_push($this->ignoredRouteKeywords,$input->getOption('ignore-route-keyword'));
        $this->output = $output;

        if (! $this->startingLink) {
            $this->startingLink = $defaultStart;
        }
        if (! $this->ignoredRouteKeywords) {
            $this->ignoredRouteKeywords = [
            ];
        }
        $this->domain = parse_url($this->startingLink, PHP_URL_HOST);

        $kernel = $this->createKernel();


        $client = $this->httpClient;
        $crawler = $client->request('GET', $this->startingLink);

        dump(startingLink: $this->startingLink);

        // Get the latest post in this category and display the titles
        $crawler->filter('h2 > a')->each(function ($node) {
            print $node->text() . "\n";
        });

        // could follow the login form, too.

        $this->authenticate($kernel, $client);
        $stopwatch = new Stopwatch();

        // start crawling
        $output->writeln(sprintf('Dominating <comment>%s</comment>, starting at <comment>%s</comment>.
At most, <comment>%s</comment> pages will be crawled.', $this->domain, $this->startingLink, $this->searchLimit));

        // crawl starting link
        $stopwatch->start('request');
        $crawler = $client->request('GET', $url = $this->startingLink);

        // redirect if necessary
        while ($client->getResponse() instanceof RedirectResponse) {
            $crawler = $client->followRedirect();
        }
        //        $this->domainLinks[$url]['duration'] = $stopwatch->stop('request')->getDuration();

        $this->processLinksOnPage($crawler, $uri = $crawler->getUri());
        $index = 0;

        // crawl links found
        while (! empty($this->linksToProcess) && ++$index < $this->searchLimit) {
            $client->getHistory()->clear(); // prevent out of memory errors...

            $url = array_pop($this->linksToProcess);

            // ignore certain routes
            if (preg_match('{quick|copy-and-import|go-to-observe|docs|_profiler}', $url)) {
                $output->writeln('IGNORING: ' . $url);
                continue;
            }

            $output->writeln('Processing: ' . $url);

            try {
                $stopwatch->start($url);
                $crawler = $client->request('GET', $url);
                // redirect if necessary
                while ($client->getResponse() instanceof RedirectResponse) {
                    $crawler = $client->followRedirect();
                }
                $event = $stopwatch->stop($url);
            } catch (\Exception $e) {
                $output->writeln('<warning>' . $e->getMessage() . '</warning>');
                die("stopped");
            }

            $this->domainLinks[$url]['duration'] = $event->getDuration();
            // dump($this->domainLinks[$url]); die();

            $this->processLinksOnPage($crawler, $url);
        }

        // boom, done
        $output->writeln('All Links Found:');
        $unique_routes = [];
        foreach ($this->domainLinks as $link => $linkDetails) {
            $output->writeln('    ' . $link . ' : ' . ($route = $linkDetails['route']));
            if ($route && ! $this->isIgnored($route) && ! in_array($route, $unique_routes)) {
                $unique_routes[$route] =
                    [
                        'link' => $link,
                        'referrer' => $linkDetails['referrer'],
                        'duration' => $linkDetails['duration'] ?? -1,
                    ];
            };
        }

        $fn = dirname($this->getContainer()->get('kernel')->getRootDir()) . '/links.json';
        $results = [
            'unique_routes' => $unique_routes,
            'links' => $this->domainLinks,
        ];
        file_put_contents($fn, json_encode($results, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));
        $output->writeln(sprintf("%d links searched, %s written with %d links.", $index, $fn, count($unique_routes)));

        if ($this->env !== 'test') {
            $output->writeln('WARNING: Environment is not set to test!');
        }

        return self::SUCCESS;
    }

    /**
     * Interact
     *
     * @author  Joe Sexton <joe@webtipblog.com
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (! $this->startingLink) {
            $defaultStart = 'jardin.wip';
            $defaultStart .= '/project';
            /*
            $helper = $this->getHelper( 'question' );
            $question = new ConfirmationQuestion('Please enter the link to start at(including the locale):', false);
            if ($startingLink = $helper->ask($input, $output, $question)) {

            } else {
                throw new \Exception('starting link can not be empty');
            }
            */
            $input->setArgument('startingLink', $defaultStart);
        }

        if (! $input->getArgument('username')) {
            $username = $this->getHelper('dialog')->askAndValidate(
                $output,
                'Please choose a username:',
                function ($username) {
                    if (empty($username)) {
                        throw new \Exception('Username can not be empty');
                    }

                    return $username;
                }
            );
            $input->setArgument('username', $username);
        }
    }

    //@phpstan-ignore-next-line
    protected function createKernel(): Kernel
    {
        //        $rootDir = $this->bag->get('kernel.project_dir');
        //        require_once($rootDir . '/Kernel.php');
        //@phpstan-ignore-next-line
        $kernel = new Kernel('test', true);
        return $kernel;
    }

    /**
     * authenticate with a user account to access secured urls
     *
     * @author  Joe Sexton <joe@webtipblog.com
     */
    protected function XXauthenticate($kernel, $client)
    {
        //
        $crawler = $client->request('GET', 'https://github.com/');
        $crawler = $client->click($crawler->selectLink('Sign in')->link());
        $form = $crawler->selectButton('Sign in')->form();
        $crawler = $client->submit($form, [
            'login' => 'fabpot',
            'password' => 'xxxxxx',
        ]);
        $crawler->filter('.flash-error')->each(function ($node) {
            print $node->text() . "\n";
        });

        // @todo: this assumes a local user, it should be a proper login to the endpoint
        // code? Username? S.b. configurable.
        if (! $user =  $this->crawlerService->getUser($this->username)) {
            throw new \Exception("Unable to authenticate member " . $this->username);
        }
        // $token = new UsernamePasswordToken($login, $password, $firewall);
        $token = new UsernamePasswordToken($user, $this->securityFirewall, $user->getRoles());

        /* we do this, not sure if it'll help
        */
        $client->getContainer()->get('security.token_storage')->setToken($token);


        $session = $client->getContainer()->get('session');
        $session->set('_security_' . $this->securityFirewall, serialize($token));
        $session->save();


        $cookie = new Cookie($session->getName(), $session->getId());
        $client->getCookieJar()->set($cookie);
    }

    /**
     * get all links on the page as an array of urls
     *
     * @return  array
     * @author  Joe Sexton <joe@webtipblog.com
     */
    protected function getLinksOnCurrentPage(Crawler $crawler)
    {
        static $seen = [];

        $links = $crawler->filter('a')->each(function (Crawler $node, $i) {
            // todo: look for rel="nofollow"
            // dump($i, $node->link()); die();
            $attr = $node->attr('rel');
            if ($attr <> 'nofollow') {
                return $node->link()->getUri();
            }
        });
        // $seen = [];

        // remove outbound links and links with spaces
        foreach ($links as $key => $link) {
            if (isset($this->domainLinks[$link]) || in_array($link, $seen)) {
                unset($links[$key]);
                continue;
            }
            $seen[] = $link;
            $linkParts = parse_url($link);

            // our project-specific links to not check.  The API requires a different login, we should create those in a separate file
            if (strpos($link, ' ') || strpos($link, '_profiler') || strpos($link, 'api1.0')) {
                unset($links[$key]);
                continue;
            }

            if (strpos($link, '.html')) {
                unset($links[$key]);
                continue;
            }

            if (empty($linkParts['host']) || $linkParts['host'] !== $this->domain || $linkParts['scheme'] !== 'http') {
                unset($links[$key]);
                continue;
            }

            $this->output->writeln(sprintf("\t%s", $link));
        }

        return array_values($links);
    }

    /**
     * process all links on a page
     *
     * @param string $currentUrl
     * @author  Joe Sexton <joe@webtipblog.com
     */
    protected function processLinksOnPage(Crawler $crawler, $currentUrl)
    {
        $links = $this->getLinksOnCurrentPage($crawler);

        // process each link
        foreach ($links as $key => $link) {
            $this->processSingleLink($link, $currentUrl);
        }
    }

    /**
     * process a single link
     *
     * @param string $link
     * @param string $currentUrl
     * @author  Joe Sexton <joe@webtipblog.com
     */
    protected function processSingleLink($link, $currentUrl)
    {
        $link = preg_replace('/#.*/', '', $link); // strip off URL fragment
        if (empty($this->domainLinks[$link])) {
            dd($link, $currentUrl);
            // check for routes that should only be indexed once
            // do this before we add the link to the domainLinks array since we check that array for duplicates...
            if (! $this->isDuplicateIgnoredRoute($link)) {
                // exclude any links with blanks
                if (false === strpos($link, ' ')) {
                    $this->linksToProcess[] = $link;
                }
            }

            // add details to the domainLinks array
            $route = $this->getRouteInfo($link);
            $this->domainLinks[$link]['route'] = (! empty($route['_route'])) ? $route['_route'] : '';
            $this->domainLinks[$link]['referrer'] = $currentUrl;
        }
    }

    /**
     * @param string $url
     * @return array|null
     */
    protected function getRouteInfo($url)
    {
        // @todo: remove app_*.php if it exists
        try {
            return $this->router->match(parse_url($url, PHP_URL_PATH));
        } catch (\Exception $e) {
            print "Can't find route for $url: " . $e->getMessage();

            return null;
        }
    }

    /**
     * routeIsInQueue
     *
     * @param string $routeName
     * @return  boolean
     * @author  Joe Sexton <joe@webtipblog.com
     */
    protected function routeIsInQueue($routeName)
    {
        // check each existing link for a similar match
        $allLinks = $this->domainLinks;
        foreach ($allLinks as $existingLink) {
            // does the url contain app name?
            if ($existingLink['route'] === $routeName) {
                return true;
            }
        }

        return false;
    }

    /**
     * isDuplicateIgnoredRoute
     *
     * @param string $newLink
     * @return  boolean
     * @author  Joe Sexton <joe@webtipblog.com
     */
    protected function isDuplicateIgnoredRoute($newLink)
    {
        $route = $this->getRouteInfo($newLink);
        if (! $route) {
            return true;
        }
        $routeName = (! empty($route['_route'])) ? $route['_route'] : '';

        return $this->isIgnored($routeName) || $this->routeIsInQueue($routeName);
    }

    protected function isIgnored($routeName)
    {
        foreach ($this->ignoredRouteKeywords as $keyword) {
            $keyword = '/' . $keyword . '/'; // add delimiters

            if (preg_match($keyword, $routeName) === 1) {
                return true;
            }
        }

        return false;
    }
}
