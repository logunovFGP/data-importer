<?php

/*
 * BasisBankSecretManagerDefaultsTest.php
 * Copyright (c) 2025 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BasisBank\Authentication\SecretManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Override;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class BasisBankSecretManagerDefaultsTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Session::forget(SecretManager::REQUEST_SMS_CODE);
        parent::tearDown();
    }

    public function testRequestSmsCodeCanBePersistedAsTrue(): void
    {
        SecretManager::saveRequestSmsCode(true);

        $this->assertTrue(SecretManager::getRequestSmsCode());
    }

    public function testRequestSmsCodeRespectsSessionOverride(): void
    {
        Config::set('basisbank.request_sms_code', '1');
        Session::put(SecretManager::REQUEST_SMS_CODE, false);

        $this->assertFalse(SecretManager::getRequestSmsCode());
    }
}
