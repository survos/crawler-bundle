<?php

namespace Survos\CrawlerBundle;

use Survos\CrawlerBundle\Command\CrawlCommand;
use Survos\CrawlerBundle\Command\GenerateTestsCommand;
// use Survos\CrawlerBundle\Command\MakeSmokeTestCommand;
use Survos\CrawlerBundle\Controller\CrawlerController;
use Survos\CrawlerBundle\Services\CrawlerService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SurvosCrawlerBundle extends AbstractBundle
{
    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        // if controller class extends AbstractController, the tags and injected services aren't needed.
        $builder->autowire(CrawlerController::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
//            ->addTag('container.service_subscriber')
//            ->addTag('controller.service_arguments')
//            ->setArgument('$bag', new Reference('parameter_bag'))
        ;

        foreach ([CrawlCommand::class, GenerateTestsCommand::class] as $commandClass) {
            $builder->autowire($commandClass)
                ->setAutoconfigured(true)
                ->setPublic(true)
                ->setAutowired(true)
                ->addTag('console.command');;
        }


        $crawler_service_id = 'survos.crawler_service';
        $builder
            ->autowire($crawler_service_id, CrawlerService::class)
            ->setPublic(true)
//            ->setArgument('$entityManager', new Reference('doctrine.orm'))
            ->setAutowired(true)
            // arguably not required, since it can run without checking for users
            ->setArgument('$managerRegistry', new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE))
//            ->setArgument('$managerRegistry', new Reference('doctrine'))
            ->setArgument('$config', $config)
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$loginPath', $config['login_path'])
            ->setArgument('$submitButtonSelector', $config['submit_button'])
            ->setArgument('$plaintextPassword', $config['plaintext_password'])
            ->setArgument('$initialPath', $config['initial_path'])
            ->setArgument('$baseUrl', $config['base_url'])
            ->setArgument('$users', $config['users'])
            ->setArgument('$kernel', new Reference('kernel'))
            ->setArgument('$security', new Reference('security.helper'))
            ->setArgument('$linkList', [])
            ->setArgument('$username', "")
            ->setArgument('$maxDepth', $config['max_depth'])
            ->setArgument('$maxVisits', $config['max_per_route'])
//            ->setArgument('$tokenStorage', new Reference('security.untracked_token_storage'))
            ->setArgument('$routesToIgnore', $config['routes_to_ignore'])
            ->setArgument('$pathsToIgnore', $config['paths_to_ignore'])
            ->setArgument('$sessionStorageFactory', new Reference('session.factory'))
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->setAutowired(true);;
        $container->services()->alias(CrawlerService::class, $crawler_service_id);

        //        $definition->setArgument('$widthFactor', $config['widthFactor']);
        //        $definition->setArgument('$height', $config['height']);
        //        $definition->setArgument('$foregroundColor', $config['foregroundColor']);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        // since the configuration is short, we can add it here
        $definition->rootNode()
            ->children()
//            ->arrayNode('routes_to_skip')->defaultValue(['app_logout'])->end()
            ->arrayNode('users')->prototype('variable')->end()->end()
            ->arrayNode('routes_to_ignore')->prototype('variable')->end()->end()
            ->arrayNode('paths_to_ignore')->prototype('variable')->defaultValue(['/^_profiler/'])->end()->end()
            ->scalarNode('max_per_route')->defaultValue(3)->end()
            ->scalarNode('base_url')->defaultValue('https://127.0.0.1:8000')->end()
            ->scalarNode('initial_path')->defaultValue('/')->end()
            ->scalarNode('user')->defaultValue('juan@tt.com')->end()
            ->scalarNode('login_path')->defaultValue('/login')->end()
            ->scalarNode('username_form_variable')->defaultValue('_username')->end()
            ->scalarNode('password_form_variable')->defaultValue('_password')->end()
            ->scalarNode('plaintext_password')->defaultValue('password')->end()
            ->scalarNode('submit_button')->defaultValue('.btn')->end()
            ->scalarNode('user_class')->defaultValue('App\\Entity\\User')->end()
            ->scalarNode('max_depth')->defaultValue(1)->end()
            ->end();;
    }
}
