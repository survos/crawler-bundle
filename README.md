# CrawlerBundle

The simplest useful test of a website: start at the homepage, follow every link, and check
that nothing is broken. Then do it again as each of your users — because the interesting
bugs are the ones where an `/admin` link is visible to someone who gets a 403 when they
click it, or where a route quietly starts 500'ing after a refactor.

The crawl is slow. So it runs once and records what it saw; a generator turns that
recording into ordinary PHPUnit tests that run in seconds and can go in CI.

```bash
composer req survos/crawler-bundle
```

## The two commands

```bash
bin/console survos:crawl              # crawl, record to tests/crawldata.json
bin/console survos:make:crawl-tests   # generate tests/Crawl/CrawlAs*Test.php from that recording
```

That order matters — the generator reads the recording, so a crawl always comes first.
Re-run both when you add or change routes, and commit `tests/crawldata.json` with the
change: it is the baseline the tests assert against.

`survos:crawl` **stops at the first 500** and prints the exception, the URL, the route, and
the page it was found on. That makes it the fastest way to find drift in an app nobody has
run in a while.

The generated tests extend `Survos\CrawlerBundle\Tests\BaseVisitLinksTest` (which lives in
this bundle — nothing is written into your `tests/` directory except the generated classes
and the recording). Each link becomes one `#[TestWith]` row asserting the status the crawl
actually observed, so a route that returns 403 for a regular user keeps returning 403.

## Configuration

```yaml
# config/packages/survos_crawler.yaml
survos_crawler:
    base_url: 'https://127.0.0.1:8000'   # must be reachable; the crawler makes real requests
    user_class: App\Entity\User          # ← check this one, see below
    login_path: /login
    plaintext_password: 'password'       # the shared dev password for every user below
    users:
        - user@example.com
    initial_path: '/'
    max_depth: 1                         # how far to follow links from the starting page
    max_per_route: 3                     # stop after N URLs matching the same route
    paths_to_ignore: ['/^_profiler/']
    routes_to_ignore: ['app_logout']
```

**`user_class` defaults to `App\Entity\User`.** If your user entity lives anywhere else the
crawl cannot log anyone in, and you get a visitor-only crawl that looks like it worked.

`routes_to_ignore` matches either a route *name* (`app_logout`) or a *path* fragment
(`ost/subtitle/import`). Use it for routes that are not real link targets — a JSON endpoint
that requires a query parameter, say — and not to paper over a route that is genuinely
broken. All users share `plaintext_password`; login goes through the internal Symfony
browser, so no real password is involved.

Route registration follows the usual kit convention: the results page is mounted at
`/crawler`, controlled by `routes_enabled` and `route_prefix`.

## Results page

`/crawler/crawlerdata` shows the last crawl: when it ran, and a per-route table of
frequency, distinct URLs, average and max response time, and the status codes seen — sorted
slowest first. Below that is every link, with the page it was found on.

When `survos/tabler-bundle` is installed the page is linked from the admin navbar under
**Crawler → Crawl Results**. Both the route and the menu entry are **dev-only**.

## Useful options

```bash
bin/console survos:crawl /my --skip-smoke-routes     # only links discovered by following <a>, no route seeding
bin/console survos:crawl --username=ana@example.com  # one configured user (repeatable)
bin/console survos:crawl --complete                  # include API/webhook/non-GET routes too
bin/console survos:crawl --tui                       # live dashboard instead of scrolling output
bin/console survos:crawl --limit=50                  # stop after N links
```

By default the crawl seeds itself from every registered GET route that looks navigable
(`@smoke`) and then follows the links it finds. API Platform routes, webhooks and non-GET
routes are skipped unless you pass `--complete`.

## Notes

- The recording lands in `tests/crawldata.json`, and the results page reads it from there.
- A generated suite that runs in ~0.01s with one assertion per test is asserting nothing —
  check that the tests are really being executed rather than skipped by a `#[RequiresPhpunit]`
  gate or an early `return`.
- @todo: compare with https://github.com/mvdbos/php-spider
