<?php

declare(strict_types=1);

namespace OmniPHP;

/**
 * OmniPHP 架构规范守卫 (Architecture Guard)
 *
 * 智能防止业务项目脱离框架独立造轮子，同时全面支持面向对象规范继承与扩展：
 * 1. 允许并鼓励业务团队继承 OmniPHP\Http\Context 或 OmniPHP\WebSocket\Context 扩充业务领域方法；
 * 2. 严厉拦截完全脱离框架官方底座的独立私造包装类，杜绝长常驻内存中的状态污染与会话泄漏。
 */
final class ArchitectureGuard
{
    private const array FORBIDDEN_WRAPPERS = [
        'src/Context.php'         => [
            'bases' => ['OmniPHP\\Http\\Context', 'OmniPHP\\WebSocket\\Context', 'Context'],
            'suggest' => 'OmniPHP\\Http\\Context 或 OmniPHP\\WebSocket\\Context',
        ],
        'src/Database/DB.php'     => [
            'bases' => ['OmniPHP\\Database\\DB', 'DB'],
            'suggest' => 'OmniPHP\\Database\\DB',
        ],
        'src/Database/Redis.php'  => [
            'bases' => ['OmniPHP\\Cache\\Redis', 'Redis'],
            'suggest' => 'OmniPHP\\Cache\\Redis',
        ],
        'app/Context.php'         => [
            'bases' => ['OmniPHP\\Http\\Context', 'OmniPHP\\WebSocket\\Context', 'Context'],
            'suggest' => 'OmniPHP\\Http\\Context 或 OmniPHP\\WebSocket\\Context',
        ],
        'app/Database/DB.php'     => [
            'bases' => ['OmniPHP\\Database\\DB', 'DB'],
            'suggest' => 'OmniPHP\\Database\\DB',
        ],
        'app/Database/Redis.php'  => [
            'bases' => ['OmniPHP\\Cache\\Redis', 'Redis'],
            'suggest' => 'OmniPHP\\Cache\\Redis',
        ],
    ];

    /**
     * 巡检并强制架构约束
     *
     * @param array<string, mixed> $options
     * @throws \LogicException 当检测到违规脱离框架底座的私造包装时
     */
    public static function verify(string $basePath, array $options = []): void
    {
        if (!empty($options['skip_architecture_guard'])) {
            return;
        }

        $base = rtrim($basePath, '/');
        foreach (self::FORBIDDEN_WRAPPERS as $subPath => $rule) {
            $fullPath = $base . '/' . $subPath;
            if (!file_exists($fullPath)) {
                continue;
            }

            // 智能检查：如果该类规范继承自 OmniPHP 官方基类，则属于合法架构扩展，放行！
            $content = (string)file_get_contents($fullPath);
            if (self::isLegitimateExtension($content, $rule['bases'])) {
                continue;
            }

            throw new \LogicException(sprintf(
                "【OmniPHP 架构铁律违规】检测到业务项目脱离框架底座私造包装: '%s'。\n" .
                "严禁孤立重复实现 Context、DB、Redis 等核心底座！\n" .
                "若需扩充领域方法，请必须继承自官方基类: %s。\n" .
                "（如确有遗留历史包袱需跳过，可在 Application::boot() options 中传入 'skip_architecture_guard' => true）。",
                $subPath,
                $rule['suggest']
            ));
        }
    }

    /**
     * 检查类定义是否继承自合法的基类
     *
     * @param list<string> $allowedBases
     */
    private static function isLegitimateExtension(string $content, array $allowedBases): bool
    {
        foreach ($allowedBases as $base) {
            $shortName = basename(str_replace('\\', '/', $base));
            // 匹配 extends \OmniPHP\... 或 extends BaseClass
            if (preg_match('/extends\s+(\\\\?' . preg_quote($base, '/') . '|' . preg_quote($shortName, '/') . ')\b/i', $content)) {
                return true;
            }
        }
        return false;
    }
}
