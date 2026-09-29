# OmniPHP

**The universal PHP framework.** A lightweight, long-running application core built on
[Workerman](https://github.com/walkor/workerman): the process model of a daemon, the
ergonomics of a classic web framework.

> Status: extracted from a production Workerman application and published as a standalone
> package. The API is stable in practice but not yet frozen — expect `0.x` releases.

## What's inside

| Namespace | Purpose |
|---|---|
| `OmniPHP\Http` | `Kernel` (Workerman `onMessage` entry), `Router` (static, grouped, typed params), `Context` (request/response helper), onion `Middleware` pipeline, `Session`, `ExceptionHandler`, `ApiCode` error-code envelope, built-in CORS / rate-limit / JSON-response middlewares |
| `OmniPHP\Database` | PDO `DB` facade, fluent `QueryBuilder`, `Schema` blueprint + `MigrationRunner`, `SoftDeletes`, per-worker connection bootstrap |
| `OmniPHP\Model` | Thin active-record style base class on top of `QueryBuilder` |
| `OmniPHP\Queue` | Redis Stream job queue: `Producer` / `Consumer` / `RedisQueue` with priorities, delayed jobs, retries, dead-letter queue |
| `OmniPHP\View` | Compiled template engine with plugins, attribute parser and output caching |
| `OmniPHP\Cache` | `Redis` and `FileCache` drivers behind one `Cache` facade |
| `OmniPHP\Config` / `Lang` | PHP-array config loader; `.ini` based i18n with locale fallback |
| `OmniPHP\Logger` | Level-based logger with pluggable engines (`FileEngine`, `ElasticsearchEngine`, `TelegramEngine`) and `LogRotator` |
| `OmniPHP\Requests` | Minimal cURL HTTP client, `requests`-style |

Everything runs inside Workerman worker processes: code is loaded once, connections are
opened once per worker, and nothing assumes PHP-FPM's request-scoped lifecycle.

## Requirements

- PHP 8.4+
- ext-curl, ext-json, ext-mbstring, ext-pdo
- ext-redis (for the Redis cache driver, the queue and the rate limiter)
- workerman/workerman ^5.1

## Install

```bash
composer require omniphp/framework
```

## Minimal server

```php
<?php
// server.php
require __DIR__ . '/vendor/autoload.php';

define('BASE_PATH', __DIR__);
define('CONFIG_PATH', BASE_PATH . '/config');   // Config::get('app.xxx') reads config/app.php
define('RUNTIME_PATH', BASE_PATH . '/runtime'); // logs, cache and compiled views live here

use OmniPHP\Http\Kernel;
use OmniPHP\Http\Router;
use OmniPHP\Http\Context;
use OmniPHP\Http\Middlewares\ResponseMiddleware;
use Workerman\Worker;

Router::group('/api', function () {
    Router::get('/hello/{name}', fn(Context $ctx) => ['hello' => $ctx->param('name')]);
}, [new ResponseMiddleware()]);

$http = new Worker('http://0.0.0.0:8080');
$http->count = 4;
$http->onMessage = [Kernel::class, 'handle']; // dispatch → send → access log

Worker::runAll();
```

```bash
php server.php start      # foreground
php server.php start -d   # daemon
php server.php reload     # re-fork workers after a code change
```

## Conventions the framework expects from the application

- The application defines `BASE_PATH`, `CONFIG_PATH` and `RUNTIME_PATH` before using
  `Config`, `Logger`, `LogRotator`, `FileCache` or `ViewEngine`; they throw if a constant is missing.
- `Lang::init(['lang_dir' => ...])` must be given the language directory explicitly.
- Queue job handlers implement `OmniPHP\Queue\HandlerInterface` and are registered by job
  name in `config/queue.php` under `handlers`.
- Application-level hooks are registered at bootstrap rather than discovered:
  `Logger::registerEngine()` for extra log sinks, `RedisQueue::setDlqListener()` for
  dead-letter notifications, `Router::setExceptionHandler()` for error rendering.

## License

MIT — see [LICENSE](LICENSE). Query builder guide: [docs/database.md](docs/database.md).
