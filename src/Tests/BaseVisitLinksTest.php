<?php

declare(strict_types=1);

namespace Survos\CrawlerBundle\Tests;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\LoggerInterface;
use Survos\CrawlerBundle\Services\CrawlerService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Panther\Client as PantherClient;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Contracts\Cache\CacheInterface;
use Zenstruck\Browser\Test\HasBrowser;
use function Symfony\Component\String\u;

class BaseVisitLinksTest extends WebTestCase
{
    use HasBrowser;
    const URL_BASE='http://localhost';
    private array $visibleLinks = [];
    private KernelBrowser $client;
    private ?string $username=null;
    private ?string $userClassName = null;

    public function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        /** @var CrawlerService $crawlerService */
//        $crawlerService = $this->getContainer()->get('survos.crawler_service');
        $crawlerService = $this->getContainer()->get(CrawlerService::class);
        $this->userClassName = $crawlerService->getUserClass();
    }

    protected function createAuthenticatedClient(?string $username=null): KernelBrowser
    {

        static $client;
        static $container;
        if (!isset($client)) {
            $client = static::createClient();
        } else {

        }
        if (!isset($container)) {
            $container = static::getContainer();
        }


//        $client = self::createPantherClient();
        $this->username = $username;
        return $client;
    }

//    #[DataProvider('linksToVisit')]
//    #[TestDox('$username $url should return $expected')]
    public function loginAsUserAndVisit(?string $username, string $url, int|string|null $expected): void
    {
        static $users = [];
//        assert(is_int($expected), $expected);

        $browser = $this->browser();
//        $client = static::createClient();

//        dump(username: $username);
        if ($username && $username != "") {
            if (!array_key_exists($username, $users)) {
                $container = static::getContainer();
                $users[$username] = $container->get('doctrine')->getRepository(
                    $this->userClassName
                )->findOneBy(['email' => $username]);
            }

            $user = $users[$username];
            assert($user, "Invalid user $username, not in user database");
            $browser->actingAs($user);

//            $client->loginUser($user);
        }
        // Do NOT read ->content() before asserting the status: zenstruck/browser rethrows the
        // kernel exception there, so every route that legitimately returns 403 (or any other
        // error the crawl recorded) failed as an exception instead of being asserted.
        $browser->visit($url);
//        dd($content);
//        dump($content);
//        dd($browser);
//        $browser->dd();
        $browser->assertStatus($expected);
        return;

//        $client = static::createClient();
        $client->request('GET', $url);
        $reponse = $client->getResponse();
        //dd(substr($reponse->getContent(),0,1024));
        $this->assertEquals($expected, $reponse->getStatusCode(), sprintf('The %s@%s expected %d', $username, $url, $expected));
        dd($reponse->getContent(), $reponse->getStatusCode());

//        $this->assertResponseStatusCodeSame($expected, sprintf('The %s@%s expected %d', $username, $url, $expected));
    }

    static public function linksToVisit()
    {
//        $text = [
//            ['admin','/', 200],
//            ['user', '/', 200],
//            ['admin', '/admin', 200],
//            ['user', '/admin', 403],
//            [null, '/admin', 302]
//        ];

        $x = [];

        self::bootKernel();

        /** @var RouterInterface $router */
//        $router = self::getContainer()->get(RouterInterface::class);
        $kernel = self::getContainer()->get('kernel');
        $crawldataFilename = $kernel->getProjectDir(). '/tests/crawldata.json';
        //assert(file_exists($crawldataFilename));
        if(!file_exists($crawldataFilename)) {
            return [];
        }
        $crawldata = json_decode(file_get_contents($crawldataFilename));

        foreach ($crawldata as $username => $linksToCrawl) {
            [$username, $startingLink] = explode("|",$username);
            foreach ($linksToCrawl as $path=>$info) {
                yield $x[$username . '@' . $info->path] = [$username, $info->path, 200];
            }
        }
//        return $x;
    }

    private function getKey($path): string
    {
        return sprintf("%s-%s", $this->username, $path);
    }

    private function scrape(string $path, int $depth=0)
    {
        $key = $this->getKey($path);
        printf("%s %s\n", $this->username, $key);

        if (!array_key_exists($key, $this->visibleLinks)) {
            $info = [
                'user' => $this->username,
                'href' => $path,
                'depth' => $depth,
                'foundOn' => null, // root
            ];
            $this->visibleLinks[$key] = $info;
        } else {
            $info = $this->visibleLinks[$key]; // starting info
        }
        $info['seen'] = true;

        assert(array_key_exists('depth', $info));

        if ($depth > 4) {
            return;
//            return [];
        }


        if (0)
            try {
                // if ($route = $this->router->match($path)) {
                //     $controller = $route['_controller'];
                //     $info['_route'] = $route['_route'];
                //     foreach (['_route', '_controller'] as $x) {
                //         unset($route[$x]);
                //     }
                //     $info['_route_parameters'] = $route;
                // }
            } catch (ResourceNotFoundException $exception) {
                // @todo: check for /public

                $info['seen'] = true;
                $info['error'] = 'not a symfony route';
                $this->visibleLinks[$this->getKey($path)] = $info;
                return;
            }

        assert($path, "missing path");

//        $base = 'https://127.0.0.1:8000';
//        $url = $base . $path;
//        $response = $this->cache->get(md5($url), function(CacheItem $item) use ($url, $info, $path)
        {
            $pageCrawler = $this->client->request('GET', $path, [
                'max_redirects' => 0,
            ]);
            $response = $this->client->getResponse();
            $this->assertTrue(in_array($response->getStatusCode(), [200, 302]), "Invalid status code on $path: " . $response->getStatusCode());

            if ($response->getStatusCode() == 302) {
                // presumably this is redirecting to login, and that's okay.
                // we could add this to the stack to crawl..
            } elseif ($response->getStatusCode() === 200)
            {
                $links = $pageCrawler->filter('a')->links();
                // add new links
                foreach ($links as $link) {
                    // hack

                    $linkPath = $this->cleanup($link->getUri());
                    if (!$linkPath) {
                        continue; // skip
                    }
                    if (!array_key_exists($this->getKey($path), $this->visibleLinks)) {
                            $linkInfo = [
                            'href' => $linkPath,
                            'seen' => false,
                            'user' => $this->username,
                            'depth' => $depth + 1,
                            'foundOn' => $path
                        ];
                            dump($linkInfo);
                        $this->visibleLinks[$this->getKey($path)] = $linkInfo;

                    }
                }
//                $this->assertEquals(count($this->visibleLinks), 3, json_encode($this->visibleLinks));
                $html = $response->getContent();
            } else {
                // handle other codes
            }
        }
        // update with any changes
        $this->visibleLinks[$this->getKey($path)] = $info;

    }


    private function cleanup(string $href): ?string
    {
            $cleanHref = str_replace(self::URL_BASE, '', $href);
            $cleanHref = u($cleanHref)->before('#')->toString();
            if (empty($cleanHref)) {
                return null;
            }
            if (preg_match('{^/(_(profiler|wdt)|css|images|js)/}', $cleanHref )) {
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

}
