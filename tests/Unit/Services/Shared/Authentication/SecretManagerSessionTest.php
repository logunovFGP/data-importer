<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Authentication;

use App\Services\Shared\Authentication\SecretManager;
use Tests\TestCase;

/**
 * Tests for Fix #47: saveValueInSession accepts only allowlisted keys.
 *
 * @internal
 * @coversDefaultClass \App\Services\Shared\Authentication\SecretManager
 */
final class SecretManagerSessionTest extends TestCase
{
    public function testSaveAllowedKeySophtronUserId(): void
    {
        SecretManager::saveValueInSession('sophtron_user_id', 'test-user-id');
        $this->assertSame('test-user-id', session()->get('sophtron_user_id'));
    }

    public function testSaveAllowedKeySophtronAccessKey(): void
    {
        SecretManager::saveValueInSession('sophtron_access_key', 'test-access-key');
        $this->assertSame('test-access-key', session()->get('sophtron_access_key'));
    }

    public function testSaveDisallowedKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Session key "evil_key" is not in the allowed list.');

        SecretManager::saveValueInSession('evil_key', 'some-value');
    }

    public function testSaveArbitraryKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecretManager::saveValueInSession('access_token', 'hijack-attempt');
    }
}
