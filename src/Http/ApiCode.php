<?php
declare(strict_types=1);

namespace OmniPHP\Http;

/**
 * 框架层通用错误码（10xxx 段）
 *
 * 形态：5 位十进制，前两位 = 模块，后三位 = 模块内序号；0 = 成功。10 段归框架，
 * 业务模块（20 账号 / 30 订单 …）由应用层自己的 ApiCode 定义，并以常量别名继承本类的 10 段，
 * 保证 error.code 全局唯一且不重复。
 *
 * 信封：Context::error() / ResponseMiddleware 在 handler 未传业务码时，按 HTTP 状态
 * 经 {@see fromHttpStatus()} 映射到本段——error.code 永远是整数。
 */
final class ApiCode
{
    public const OK = 0;

    public const INTERNAL_ERROR        = 10000; // 500
    public const BAD_REQUEST           = 10001; // 400 参数缺失/非法（details.field 指出哪个）
    public const UNAUTHORIZED          = 10002; // 401 未登录 / token 无效
    public const TOKEN_EXPIRED         = 10003; // 401 token 过期（客户端走 refresh）
    public const FORBIDDEN             = 10004; // 403
    public const NOT_FOUND             = 10005; // 404
    public const RATE_LIMITED          = 10006; // 429
    public const PAYLOAD_TOO_LARGE     = 10007; // 413
    public const CONFLICT              = 10008; // 409
    public const STORAGE_UNAVAILABLE   = 10009; // 500 服务端磁盘/存储不可用

    /** HTTP 状态 → 通用段整数码；未列出的状态一律 INTERNAL_ERROR */
    public static function fromHttpStatus(int $status): int
    {
        return match ($status) {
            400 => self::BAD_REQUEST,
            401 => self::UNAUTHORIZED,
            403 => self::FORBIDDEN,
            404 => self::NOT_FOUND,
            409 => self::CONFLICT,
            413 => self::PAYLOAD_TOO_LARGE,
            429 => self::RATE_LIMITED,
            default => self::INTERNAL_ERROR,
        };
    }
}
