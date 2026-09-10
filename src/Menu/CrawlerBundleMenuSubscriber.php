<?php

declare(strict_types=1);

namespace Survos\CrawlerBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Puts the crawl results under the admin navbar, the same way command-bundle
 * exposes its command runner. The results route is dev-only (the controller
 * returns a notice in any other environment), which is also where crawling
 * happens, so the link is only added in dev.
 */
class CrawlerBundleMenuSubscriber
{
    public function __construct(private readonly string $env = 'dev')
    {
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        if ('dev' !== $this->env) {
            return;
        }

        $menu = $event->getMenu();
        $submenu = $menu->addChild('Crawler');
        $submenu->addChild('Crawl Results', ['route' => 'survos_crawler_data']);
    }
}
