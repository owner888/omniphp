<?php

declare(strict_types=1);

namespace OmniPHP\Security;

/**
 * 通用文件上传安全防护组件
 *
 * 提供多层防御：
 * 1. 扩展名高危黑名单拦截 (php, phtml, phar, sh, exe, dll 等绝对禁止)
 * 2. 预设白名单校验 (image, audio, video, document, archive)
 * 3. 严格文件名安全清洗 (防路径遍历 ../ 与 null-byte 注入)
 * 4. 二进制文件头 Magic Bytes 深度真伪校验 (防脚本伪装图片)
 */
class UploadSecurity
{
    public const array BLACKLIST_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar',
        'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'exe', 'dll', 'so',
        'dylib', 'msi', 'com', 'htaccess', 'htpasswd', 'ini', 'conf',
        'tpl', 'blade', 'smarty', 'jsp', 'asp', 'aspx', 'cgi',
    ];

    public const array PRESETS = [
        'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        'audio'    => ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
        'video'    => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
        'document' => ['txt', 'md', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'archive'  => ['zip', 'tar', 'gz', '7z', 'rar'],
    ];

    /**
     * 文件首部魔数特征表 (Hex)
     */
    private const array MAGIC_NUMBERS = [
        'jpg'  => ['FFD8FF'],
        'jpeg' => ['FFD8FF'],
        'png'  => ['89504E470D0A1A0A'],
        'gif'  => ['474946383761', '474946383961'],
        'pdf'  => ['25504446'],
        'zip'  => ['504B0304', '504B0506', '504B0708'],
    ];

    /**
     * 安全清洗文件名，剥离路径穿越与非法字符
     */
    public static function sanitizeFilename(string $filename): string
    {
        // 剥离 null 字节
        $filename = str_replace(chr(0), '', $filename);
        $filename = basename($filename);

        // 移除特殊非法字符
        $filename = preg_replace('/[^\p{L}\p{N}\.\-_]/u', '_', $filename) ?: 'file';

        // 避免以点开头隐藏文件
        return ltrim($filename, '.');
    }

    /**
     * 校验文件扩展名合法性
     *
     * @param string|list<string> $allowedTypes 预设分类 (如 'image') 或自定义扩展名数组
     */
    public static function isAllowedExtension(string $filename, string|array $allowedTypes): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, self::BLACKLIST_EXTENSIONS, true)) {
            return false;
        }

        $whitelist = [];
        if (is_string($allowedTypes)) {
            $whitelist = self::PRESETS[$allowedTypes] ?? [];
        } elseif (is_array($allowedTypes)) {
            $whitelist = array_map('strtolower', $allowedTypes);
        }

        return in_array($ext, $whitelist, true);
    }

    /**
     * 校验文件真实二进制魔数是否匹配声明的扩展名
     */
    public static function verifyMagicBytes(string $filepath, string $extension): bool
    {
        $ext = strtolower($extension);
        if (!isset(self::MAGIC_NUMBERS[$ext])) {
            return true; // 未定义魔数特征的格式放行
        }

        $handle = @fopen($filepath, 'rb');
        if ($handle === false) {
            return false;
        }

        $bytes = (string)fread($handle, 16);
        fclose($handle);

        $hex = strtoupper(bin2hex($bytes));
        foreach (self::MAGIC_NUMBERS[$ext] as $magic) {
            if (str_starts_with($hex, $magic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成安全唯一文件名
     */
    public static function generateSafeName(string $originalFilename): string
    {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        return sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(8)), $ext ?: 'bin');
    }
}
