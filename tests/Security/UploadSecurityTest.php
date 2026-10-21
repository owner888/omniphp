<?php

declare(strict_types=1);

namespace OmniPHP\Tests\Security;

use OmniPHP\Security\UploadSecurity;
use PHPUnit\Framework\TestCase;

final class UploadSecurityTest extends TestCase
{
    public function testSanitizeFilename(): void
    {
        $unsafe = "../../etc/passwd\0.php.png";
        $safe = UploadSecurity::sanitizeFilename($unsafe);
        $this->assertStringNotContainsString('../', $safe);
        $this->assertStringNotContainsString(chr(0), $safe);
    }

    public function testExtensionValidation(): void
    {
        $this->assertTrue(UploadSecurity::isAllowedExtension('avatar.png', 'image'));
        $this->assertFalse(UploadSecurity::isAllowedExtension('shell.php', 'image'));
        $this->assertFalse(UploadSecurity::isAllowedExtension('script.sh', ['jpg', 'png']));
    }

    public function testMagicBytesValidation(): void
    {
        $tempPng = tempnam(sys_get_temp_dir(), 'test_png');
        file_put_contents($tempPng, hex2bin('89504E470D0A1A0A0000000D49484452'));

        $this->assertTrue(UploadSecurity::verifyMagicBytes($tempPng, 'png'));
        $this->assertFalse(UploadSecurity::verifyMagicBytes($tempPng, 'jpg'));

        @unlink($tempPng);
    }
}
