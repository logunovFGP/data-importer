<?php

/*
 * BasisBankAuthenticationContextRegressionTest.php
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

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Session\Constants;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Conversion\AccountMapper;
use App\Services\Shared\Model\ImportServiceAccount;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Override;
use ReflectionClass;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class BasisBankAuthenticationContextRegressionTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Session::forget(Constants::SESSION_BASE_URL);
        Session::forget(Constants::SESSION_ACCESS_TOKEN);
        parent::tearDown();
    }

    public function testParseImportJobHydratesAccessTokenFromSessionWhenConfigurationTokenIsEmpty(): void
    {
        Session::put(Constants::SESSION_ACCESS_TOKEN, 'session-access-token');

        $importJob = ImportJob::createNew();
        $importJob->setFlow('unsupported-flow');
        $importJob->setConfiguration(
            Configuration::fromArray(
                [
                    'flow'         => 'unsupported-flow',
                    'access_token' => '',
                ]
            )
        );
        $importJob->setApplicationAccounts(
            [
                Constants::ASSET_ACCOUNTS => ['seed-account'],
                Constants::LIABILITIES    => [],
            ]
        );
        $importJob->setCurrencies(['USD' => 'USD']);

        $repository = new class extends ImportJobRepository
        {
            public function saveToDisk(ImportJob $importJob): void
            {
                // no-op for unit test
            }
        };

        $repository->parseImportJob($importJob);

        $this->assertSame('session-access-token', $importJob->getConfiguration()?->getAccessToken());
    }

    public function testFindMatchingAccountFailsFastWithoutAuthenticationContext(): void
    {
        Session::forget(Constants::SESSION_BASE_URL);
        Session::forget(Constants::SESSION_ACCESS_TOKEN);
        Config::set('importer.url', '');
        Config::set('importer.access_token', '');

        $mapper  = new AccountMapper();
        $account = ImportServiceAccount::fromArray(
            [
                'id'            => 'basis-account-1',
                'name'          => 'BasisBank account',
                'currency_code' => 'GEL',
                'iban'          => '',
                'bban'          => '',
                'status'        => 'active',
                'extra'         => [],
            ]
        );

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('Authentication context not available for account loading');

        $mapper->findMatchingFireflyIIIAccount($account);
    }

    public function testAccountMapperUsesConstructorOverridesForAuthenticationContext(): void
    {
        Session::put(Constants::SESSION_BASE_URL, 'http://session-base-url');
        Session::put(Constants::SESSION_ACCESS_TOKEN, 'session-token');

        $mapper            = new AccountMapper('http://override-base-url', 'override-token');
        $reflection        = new ReflectionClass($mapper);
        $resolveBaseUrl    = $reflection->getMethod('resolveBaseUrl');
        $resolveAccessToken = $reflection->getMethod('resolveAccessToken');
        $resolveBaseUrl->setAccessible(true);
        $resolveAccessToken->setAccessible(true);

        $this->assertSame('http://override-base-url', $resolveBaseUrl->invoke($mapper));
        $this->assertSame('override-token', $resolveAccessToken->invoke($mapper));
    }
}

