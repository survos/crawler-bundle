<?php

namespace Survos\CrawlerBundle\Command;

use Nette\PhpGenerator\PhpNamespace;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use Survos\CrawlerBundle\Tests\BaseVisitLinksTest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function Symfony\Component\String\u;

#[AsCommand('survos:make:crawl-tests', 'Generate crawler tests for a visitor and authenticated users')]
final class GenerateTestsCommand
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/tests/')] private string $testRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Test class dir')] string $testDir = 'Crawl',
    ): int {
        $testPath = $this->testRoot . $testDir . '/';
        if (!file_exists($testPath)) {
            mkdir($testPath, 0777, true);
        }
        if (!class_exists(PhpNamespace::class)) {
            $io->writeln("Missing dependency:\n\ncomposer req nette/php-generator --dev");
            return Command::FAILURE;
        }
        $fn = $testPath . '/../crawldata.json';
        assert(file_exists($fn), "$fn is missing, run bin/console survos:crawl first");
        $routes = json_decode(file_get_contents($fn), true);
        foreach ($routes as $key => $links) {
            [$user, $startUrl] = explode('|', $key);
            $namespace = new PhpNamespace('App\\Tests\\' . $testDir);
            foreach ([
                WebTestCase::class,
                TestDox::class,
                TestWith::class,
                KernelBrowser::class,
                BaseVisitLinksTest::class,
            ] as $useClass) {
                $namespace->addUse($useClass);
            }

            $className = sprintf('CrawlAs%sTest', ucfirst($user ? u($user)->before('@')->toString() : 'Visitor'));
            $className = str_replace('.', '', $className);
            $class = $namespace->addClass($className);
            $class->setExtends(BaseVisitLinksTest::class);
            $namespace->add($class);

            $method = $class->addMethod('testRoute');
            $method->setReturnType('void');
            $method->addAttribute(TestDox::class, ['/$method $url ($route)']);
            foreach ($links as $link) {
                $method->addAttribute(TestWith::class, [[
                    $user,
                    $link['path'],
                    $link['statusCode'] ?? 200,
                ]]);
            }

            array_map(fn($param) => $method->addParameter($param)->setType('string'), [
                'username', 'url',
            ]);
            $method->addParameter('expected')->setType('string|int|null');
            $method->setBody(<<<'END'
        parent::loginAsUserAndVisit($username, $url, (int)$expected);

END
            );

            $filename = $testPath . $className . '.php';
            file_put_contents($filename, "<?php\n\n" . $namespace);
            $io->writeln(sprintf('<info>%s</info> written.', $filename));
        }
        return Command::SUCCESS;
    }
}
