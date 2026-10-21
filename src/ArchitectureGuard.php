<?php

declare(strict_types=1);

namespace OmniPHP;

/**
 * OmniPHP 架构规范守卫 (Architecture Guard)
 *
 * 强制防止业务项目重复造轮子 / 私自封装 Context、DB、Redis 等核心基础设施。
 * 若业务目录中存在反模式的私自包装类，将在启动时抛出严正架构违规指引，杜绝会话泄漏与状态污染。
 */
final class ArchitectureGuard
{
    private const array FORBIDDEN_WRAPPERS = [
        'src/Context.php'         => 'OmniPHP\\Http\\Context 或 OmniPHP\\WebSocket\\Context',
        'src/Database/DB.php'     => 'OmniPHP\\Database\\DB',
        'src/Database/Redis.php'  => 'OmniPHP\\Cache\\Redis',
        'app/Context.php'         => 'OmniPHP\\Http\\Context 或 OmniPHP\\WebSocket\\Context',
        'app/Database/DB.php'     => 'OmniPHP\\Database\\DB',
        'app/Database/Redis.php'  => 'OmniPHP\\Cache\\Redis',
    ];

    /**
     * 巡检并强制架构约束
     *
     * @param array<string, mixed> $options
     * @throws \LogicException 当检测到违规私造基础包装时
     */
    public static function verify(string $basePath, array $options = []): void
    {
        if (!empty($options['skip_architecture_guard'])) {
            return;
        }

        $base = rtrim($basePath, '/');
        foreach (self::FORBIDDEN_WRAPPERS as $subPath => $suggestedClass) {
            $fullPath = $base . '/' . $subPath;
            if (file_exists($fullPath)) {
                throw new \LogicException(sprintf(
                    "【OmniPHP 架构铁律违规】检测到业务项目私自封装基础设施: '%s'。\n" .
                    "严禁重复封装 Context、DB、Redis 等核心底座！请直接使用原生门面: %s。\n" .
                    "（如确有遗留历史包袱需跳过，可在 Application::boot() options 中传入 'skip_architecture_guard' => true）。",
                    $subPath,
                    $suggestedClass
                ));
            }
        }
    }
}
