# CrawlerBundle

One of the most basic ways to test a website is to simply go to the starting page and click on every link to make sure there are no pages broken.

Then repeat the process, but logged in as different users (e.g. as an administrator).

That's what this bundle does.  Combine with code coverage, it's a fast and easy way to test.  This can also be run in --dev

@todo: check out https://github.com/mvdbos/php-spider

```bash
composer req survos/crawler-bundle
# configure the users in packages/survos_crawler.yaml
bin/console survos:crawl
```

These are the steps

* Crawl the site and create a list of all the routes and paths visited by each user (slow)
* Generate a phpunit test with those routes
* Run the phpunit test (very fast)
* Check coverage with the test.

```bash
c d:sc:update --env=test --force


bin/console survos:crawl --env=test
```


# Working example (without API Platform)

```bash
symfony new smoketest-demo --webapp && cd smoketest-demo
composer config extra.symfony.allow-contrib true
composer require --dev orm-fixtures pierstoval/smoke-testing 
echo "DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db" > .env.local
echo "DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db" > .env.test
echo "title,string,80,no," | sed "s/,/\n/g"  | bin/console make:entity Product
echo "description,text,yes," | sed "s/,/\n/g"  | bin/console make:entity Product
bin/console doctrine:schema:update --force --complete

echo ",," | sed "s/,/\n/g"  | bin/console make:crud Product --with-tests 

sed -i "s|'app_app'|'app_homepage'|" src/Controller/ProductController.php --with-tests


bin/console make:controller AppController
sed -i "s|Route('/app'|Route('/'|" src/Controller/AppController.php
sed -i "s|'app_app'|'app_homepage'|" src/Controller/AppController.php
sed -i "s|</php>|<server name=\"SMOKE_TESTING_ROUTES_METHODS\" value=\"off\" />\n</php>|" phpunit.xml.dist

cat > templates/app/index.html.twig <<END
{% extends 'base.html.twig' %}
{% block body %}
    <h1>A simple CRUD</h1>
    <a href="{{ path('app_product_index') }}">Listing</a>
{% endblock %}
END

cat > src/DataFixtures/AppFixtures.php <<'END'
<?php

namespace App\DataFixtures;

use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $url = 'https://dummyjson.com/products';
        $json = file_get_contents($url);
        foreach (json_decode($json)->products as $record) {
           $product = (new Product)
              ->setTitle($record->title)
              ->setDescription($record->description)
              ;
            $manager->persist($product);
        }
    $manager->flush();
    }
}
END

# setup the test

cat > tests/SmokeTest.php <<'END'
<?php

namespace App\Tests;

use Pierstoval\SmokeTesting\SmokeTestStaticRoutes;

class SmokeTest extends SmokeTestStaticRoutes
{
    // That's all!
}
END

bin/console d:fixtures:load -n
symfony server:start -d
symfony open:local

composer require stenope/stenope
bin/console -e prod cache:clear
bin/console -e prod stenope:build ./public/static/ --base-url=/static

```

Start the server.  Until proxy is working (@todo) you need to use the IP address of the server if you're using the Symfony CLI.

To set default values (@todo: install recipe)
```yaml
# config/packages/survos_crawler.yaml
survos_crawler:
  base_url: 'https://127.0.0.1:8000'
```

## The process

```bash
bin/console survos:crawl
```

To crawl only links visible from the rendered pages, skip the generated `@smoke` route seed:

```bash
bin/console survos:crawl /my --skip-smoke-routes
```

By default, the command crawls the visitor plus every username configured in `survos_crawler.users`. To crawl only one configured user, pass `--username`; the username must already be in the configured list. No password is needed for the internal Symfony browser login.

```bash
bin/console survos:crawl /my --skip-smoke-routes --username=ana@scanstation.ai
```

Note that the first time this runs, it will create a BaseVisitLinksTest.php in the tests directory, so that phpunit works.  

The command visits every link and stores the results in crawldata.json. This is then used by the tests to make sure they're right.

This is particularly good when different users have permissions to different routes, so if you've secured an /admin route and accidentally left the link open, you'll get an error.





```bash

symfony new --demo crawler_bundle_demo


```
