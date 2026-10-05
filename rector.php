<?php
/**
 * 一次性 PHP 8.4 语法升级配置——跑完 apply 后删除本文件并 composer remove --dev rector/rector
 *
 *   composer require --dev rector/rector
 *   vendor/bin/rector process --dry-run     # 看 diff
 *   vendor/bin/rector process               # apply
 *   vendor/bin/phpunit                      # 回归
 *   composer remove --dev rector/rector && rm rector.php
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withSets([LevelSetList::UP_TO_PHP_84])
    ->withImportNames(importDocBlockNames: false, removeUnusedImports: true);
