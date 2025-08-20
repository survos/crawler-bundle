<?php

namespace Survos\CrawlerBundle\Services;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher;
use Symfony\Component\HttpFoundation\Session\SessionFactory;
use Symfony\Component\HttpKernel\KernelInterface;
use Psr\Log\LoggerInterface;
use Survos\CrawlerBundle\Model\Link;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\TraceableUrlMatcher;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use function Symfony\Component\String\u;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;

class CrawlerService
{
    private ?CrawlerClient $crawlerClient = null;

    public function __construct(
        private array $config,
        private string $baseUrl,
        private ?string $userClass,
        private string $loginPath,
        private string $submitButtonSelector,
        private string $plaintextPassword,
        private string $initialPath,
        /* @todo: User Provider */
        //        private CrawlerClient       $client,
        private RouterInterface $router,
        //        private Client $gouteClient,
        //        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private KernelInterface $kernel,
        protected Security $security,
        protected SessionFactory $sessionStorageFactory,
        private ?ManagerRegistry       $managerRegistry=null,
        private ?TokenStorageInterface $tokenStorage=null,
        ?Profiler                      $profiler = null,
        private array                  $linkList = [],
        private ?string                $username = null,
        private array                  $users = [],
        private int                    $maxDepth = 1,
        private int                    $maxVisits = 3,
        private array                  $routesToIgnore = [],
        private array                  $pathsToIgnore = [],
        private array                  $routeVisits = [],

    ) {
        //        $this->baseUrl = 'https://127.0.0.1:8001';
        if (null !== $profiler) {
            // if it exists, disable the profiler for this particular controller action
            $profiler->disable();
        }
    }

    public function getRouteVisits(): array
    {
        return $this->routeVisits;
    }

    public function getRoutesToIgnore(): array
    {
        return $this->routesToIgnore;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): CrawlerService
    {
        $this->config = $config;
        return $this;
    }

    public function getBaseUrl($removeTrailingSlash=false): string
    {
        return $removeTrailingSlash ? trim($this->baseUrl, '/') :  $this->baseUrl;
    }

    public function getUserClass(): ?string
    {
        return $this->userClass;
    }


    public function getInitialPath(): string
    {
        return $this->initialPath;
    }

    public function getUsernames(): array
    {
        return $this->users;
    }

    public function getUser($username): ?UserInterface
    {
        if (empty($this->userClass) || !class_exists($this->userClass)) {
            return null;
        }

        $user = $this->managerRegistry->getRepository($this->userClass)->findOneBy([
            'email' => $username,
        ]);
        if ($username && !$user) {
            $this->logger->error("user $username is not in the database");
//            dd("user $username is not in the database");
            throw new UserNotFoundException();
        }
        return $user;
    }

    private function linkListKey(?string $username, string $path): string
    {
        return sprintf('%s@%s', $username, $path);
    }

    public function addLink(?string $username, string $path, ?string $foundOn = null,?string $route = null): Link
    {
        //        $key = $this->linkListKey($username, $path);

        if (! array_key_exists($username, $this->linkList)) {
            $this->linkList[$username] = [];
        }
        if (! array_key_exists($path, $this->linkList[$username])) {
            $depth = 0;
            if(isset($this->linkList[$username][$foundOn])) {
                $depth = $this->linkList[$username][$foundOn]->getDepth() + 1;
            }
            $this->linkList[$username][$path] = new Link(username: $username, path: $path,route: $route, foundOn: $foundOn, depth: $depth);
        }
        $link = $this->linkList[$username][$path];
        return $link;
    }

    public function getLinkList(?string $username): array
    {
        return $this->linkList[$username];
    }

    public function getEntireLinkList(): array
    {
        return $this->linkList;
    }

    public function resetLinkList(): void
    {
        $this->linkList = [];
    }

    public function getPendingLinks(?string $username): array
    {
        return array_filter($this->getLinkList($username), fn (Link $link) => $link->isPending());
    }

    public function         getUnvisitedLink(?string $username): ?Link
    {
        return current($this->getPendingLinks($username)) ?: null;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    // set the goutte client to an authenticated user
    public function authenticateClient($user): void
    {
        // might be worth checking out: https://github.com/liip/LiipTestFixturesBundle/pull/62#issuecomment-622191412
        static $clients = [];
        $username = $user->getUserIdentifier();
        if (! array_key_exists($username, $clients)) {
            $createClient = $this->createClient();
            $createClient->loginUser($user);
            $clients[$username] = $createClient;
        }
        $this->crawlerClient = $clients[$username];
    }

    public function checkIfCrawlerClient()
    {
        if(!$this->crawlerClient) {
            $this->crawlerClient = $this->createClient();
        }
    }

    private function getKey($path): string
    {
        return sprintf("%s-%s", $this->username, $path);
    }

    public function scrape(Link $link, int $depth = 0): ?Link
    {

        //        $this->logger->info("Scraping " . $link->getPath());
        $link->setSeen(true);

        if ($link->getDepth() > $this->maxDepth) {
            return null;
        }

        if (!in_array('_profiler', $this->pathsToIgnore)) {
            $this->pathsToIgnore[] = '_profiler';
        }
        // check for paths before finding the route
        foreach ($this->pathsToIgnore as $pathPattern) {
            if (preg_match('#'.$pathPattern.'#', $link->getPath())) {
                $link->setLinkStatus($link::STATUS_IGNORED);
                return $link;
            }
        }

        if  ($link->getVisits() >= $this->maxVisits) {
            $link->setLinkStatus($link::STATUS_ALREADY_VISITED);
            return $link;
        }

        // e.g. sais.wip/test-webhook
        if (str_starts_with($link->getPath(), 'http')) {
            $link->setLinkStatus($link::STATUS_IGNORED);
            return $link;
        }

        $this->setRoute($link);

        if (!$link->getRoute()) {
            $link->setLinkStatus($link::STATUS_IGNORED);
            return $link;
            $link->setHtml('missing');
            dd($link);
        }
        assert($link->getRoute(), "missing route  in path " . $link->getPath());

        $routeName = $link->getRoute();
        if (!array_key_exists($routeName, $this->routeVisits)) {
            $this->routeVisits[$routeName] = 0;
        }

        if  ($this->routeVisits[$routeName] > $this->maxVisits) {
            $link->setLinkStatus($link::STATUS_ALREADY_VISITED);
            return $link;
        }
        $this->routeVisits[$routeName]++;

        if ($this->isIgnoredPath($link->getPath())) {
            $link->setLinkStatus($link::STATUS_IGNORED);
            return $link;
        }
        if ($this->isIgnoredRoute($link->getRoute())) {
            $link->setLinkStatus($link::STATUS_IGNORED);
            return $link;
        }

        if ($link->getLinkStatus() === $link::STATUS_IGNORED) {
            return $link;
        }

        // ugh, sloppy
        $url = trim($this->baseUrl, '/') . '/' . trim($link->getPath(), '/');

        assert(is_string($url));
        assert(parse_url($url), "Invalid url: " . $url);
        //        $response = $this->cache->get(md5($url), function(CacheItem $item) use ($url, $info, $path) {

        $crawlerClient = $this->crawlerClient;
        assert($crawlerClient, "no crawlerClient");
        $crawlerClient->followRedirects();
        $startTime = floor(microtime(true) * 1000);
        $crawlerClient->request('GET', $url);
        $endTime = floor(microtime(true) * 1000);
        $link->setDuration($endTime - $startTime);
        $response = $crawlerClient->getResponse();

        //        dd($response->getStatusCode(), $request, $this->goutteClient);
        $status = $response->getStatusCode();
        //$link->setMemory();
        $link
//            ->setDuration($response->getInfo('total_time'))
            ->setStatusCode($status);

        if ($status == 302) {
            die('we should be following redirects, option in the request method?...');
        }

        if ($status <> 200) {
            //echo $response->getContent();exit;
            // @todo: what should we do here?
//            dump($response->getContent());
            $this->logger->error("$url " . $status . " found on \n" . $this->baseUrl . $link->getFoundOn());
            //dd($response->getStatusCode(), $this->baseUrl . $link->getPath(), $link->getFoundOn());
            $html = ''; // false;
        } else {
            $html = $response->getContent();
        }
        // hmm, how should 301's be tracked?

        if (!in_array($status, [200, 302, 301])) {
            $msg = ($link->username ? $link->username . '@' : '') . $this->baseUrl .
                trim($link->getPath(), '/') . ' ' .
                $link->getRoute() . ' caused a ' . $status . ' found on '
                . $link->foundOn;
            $this->logger->error($msg);
        }
        if ($status == 500) {
            // Create detailed 500 error report
            $errorReport = [
                'status' => 500,
                'url' => $url,
                'route' => $link->getRoute(),
                'path' => $link->getPath(),
                'user' => $link->username ?: 'visitor',
                'found_on' => $link->getFoundOn(),
                'duration' => $link->getDuration() . 'ms',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            // Log detailed error information
            $this->logger->critical('500 Internal Server Error detected:', $errorReport);
            
            // Set link status to indicate critical error
            $link->setLinkStatus('500_error');
            
            // Return the link with error info so calling code can decide whether to break
            return $link;
        }


        //        $responseInfo = $response->getInfo();
        //        unset($responseInfo['pause_handler']);
        //            dd($responseInfo);

        //        assert(array_key_exists('info', $info));
        //        $crawler = $client->request('GET',  $base . $path);
        if (! $html) {
            return $link;
        }

        $crawler = new Crawler($html, $url);
        //        $crawler = new \Symfony\Component\DomCrawler\Crawler()
        // Get the latest post in this category and display the titles
        $crawler->filter(' a')->each(
            function ($node) use ($link, $depth) {
                $href = (string) $node->attr('href');
                //ignore node $node if has attribute data-bs-toggle="modal"
                //ignore data-bs-target="#modal-delete"
                if ($node->attr('data-bs-toggle') == 'modal' || $node->attr('data-bs-target') == '#modal-delete') {
                    return null;
                }

                //if $href has / delete , do dd
                if (preg_match('/\/delete/', $href)) {
                    dd($href);
                }


                $cleanHref = str_replace($this->baseUrl, '', $href);
                $cleanHref = u($cleanHref)->before('#')->toString();
                if (empty($cleanHref)) {
                    return null;
                }
                if (str_contains($cleanHref, '/delete/')) { return null; }
//                var_dump($cleanHref);
                if (preg_match('/^\/(_profiler|_wdt|css|images|js)\//i', $cleanHref)) {
//                    echo "====================================";
//                    dd($cleanHref);
                    return null;
                }
                $parts = parse_url($cleanHref);
                if (preg_match('/http/', $cleanHref)) {
                    if ($parts['host'] ?? false) {
                        return null;
                    }
                }
                $pageLink = $this->addLink($link->username, $cleanHref, foundOn: $link->getPath());
                //$pageLink->setDepth($depth + 1);
            }
        );
        return $link;
    }

    private function cleanup(string $href): ?string
    {
        $cleanHref = str_replace($this->baseUrl, '', $href);
        $cleanHref = u($cleanHref)->before('#')->toString();
        if (empty($cleanHref)) {
            return null;
        }
        if (preg_match('{^/(_(profiler|wdt)|css|images|js)/}', $cleanHref)) {
            //                dd($href);
            return null;
        }
        $parts = parse_url($cleanHref);
        if (preg_match('/http/', $cleanHref)) {
            if ($parts['host'] ?? false) {
                return null;
            }
        }

        return $cleanHref;
    }

    public function setRoute(Link $link): void
    {
        $path = $link->getPath();
        if (!$path) {
            return;
        }
            // hack
            $urlPath = parse_url($path, PHP_URL_PATH);
            if (!$urlPath) {
                $this->logger->error("Invalid path: $urlPath from $path");
                return;
            }
                $this->logger->info($urlPath);
                $context = $this->router->getContext();
                $matcher = new UrlMatcher($this->router->getRouteCollection(), $context);
                $matcher = new TraceableUrlMatcher($this->router->getRouteCollection(), $context);
//                foreach ($this->expressionLanguageProviders as $provider) {
//                    $matcher->addExpressionLanguageProvider($provider);
//                }

                $traces = $matcher->getTraces($urlPath);
        $routeName = null;
        foreach ($traces as $trace) {
            if (TraceableUrlMatcher::ROUTE_MATCHES == $trace['level']) {
                $routeName = $trace['name'];
                break;
            }
        }

//        try {
//                $route = $this->router->match($urlPath);
//            } catch (MethodNotAllowedException $exception) {
//                dd(__METHOD__, __FILE__, __LINE__, $exception, $urlPath);
//            } catch (\Exception $exception) {
//                dd($exception, $urlPath);
//            }
            if ($routeName) {
                $route = $this->router->getRouteCollection()->get($routeName);
                $link->setRoute($routeName);
                $link->incVisits();
 //                $controller = $route['_controller'];
                //                $reflection = new \ReflectionMethod($controller);
//                foreach (['_route', '_controller'] as $x) {
//                    unset($route[$x]);
//                }
            }
        try {
        } catch (ResourceNotFoundException $exception) {
            // @todo: check for /public
            $link
                ->setLinkStatus(Link::STATUS_IGNORED);
            return;
        }
        assert($path, "missing path");
    }

    private function createClient() {
        $crawlerClient = new CrawlerClient($this->kernel, $this->tokenStorage, $this->security,
            $this->sessionStorageFactory
        );
        return $crawlerClient;
    }

    private function isIgnoredPath($path)
    {
        foreach ($this->routesToIgnore as $keyword) {
            if (trim($path, '/') == "") {
                return false;
            }

            if (strpos(trim($keyword, '/'), trim($path, '/')) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isIgnoredRoute($route)
    {
        if (in_array($route, $this->routesToIgnore)) {
            return true;
        }
        return false;
    }
}
