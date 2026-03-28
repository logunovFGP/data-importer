<?php

/*
 * BasisBankAuthenticationRoutesTest.php
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

namespace Tests\Feature;

use App\Services\BasisBank\Authentication\SecretManager;
use Illuminate\Support\Facades\Session;
use Override;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class BasisBankAuthenticationRoutesTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Session::flush();
        parent::tearDown();
    }

    public function testBasisBankForgetRouteSupportsGetAndClearsSessionSecrets(): void
    {
        Session::put(SecretManager::LOGIN, 'basis-login');
        Session::put(SecretManager::PASSWORD, 'basis-password');
        Session::put(SecretManager::AUTH_STATE, 'OTP_REQUIRED');
        Session::put(SecretManager::SESSION_ARTIFACT, 'artifact');
        Session::put(SecretManager::REQUEST_SMS_CODE, true);
        Session::put(SecretManager::TRUST_DEVICE, true);

        $response = $this->get(route('authenticate-flow.index', ['flow' => 'basisbank']) . '/forget');

        $response->assertRedirect(route('authenticate-flow.index', ['flow' => 'basisbank']));
        $response->assertSessionHas('status');
        $response->assertSessionMissing(SecretManager::LOGIN);
        $response->assertSessionMissing(SecretManager::PASSWORD);
        $response->assertSessionMissing(SecretManager::AUTH_STATE);
        $response->assertSessionMissing(SecretManager::SESSION_ARTIFACT);
    }

    public function testBasisBankForgetAndFlushRouteSupportsGetAndClearsSessionSecrets(): void
    {
        Session::put(SecretManager::LOGIN, 'basis-login');
        Session::put(SecretManager::PASSWORD, 'basis-password');
        Session::put(SecretManager::AUTH_STATE, 'OTP_REQUIRED');
        Session::put(SecretManager::SESSION_ARTIFACT, 'artifact');

        $response = $this->get(route('authenticate-flow.index', ['flow' => 'basisbank']) . '/forget-and-flush');

        $response->assertRedirect(route('flush'));
        $response->assertSessionHas('status');
        $response->assertSessionMissing(SecretManager::LOGIN);
        $response->assertSessionMissing(SecretManager::PASSWORD);
        $response->assertSessionMissing(SecretManager::AUTH_STATE);
        $response->assertSessionMissing(SecretManager::SESSION_ARTIFACT);
    }

    public function testForgetRouteForUnknownFlowRedirectsWithError(): void
    {
        $response = $this->get(route('authenticate-flow.index', ['flow' => 'unknown']) . '/forget');

        $response->assertRedirect(route('authenticate-flow.index', ['flow' => 'unknown']));
        $response->assertSessionHas('error');
    }

    public function testOtpStepForcesTriggerAuthCheckboxOnForRecovery(): void
    {
        Session::put(SecretManager::AUTH_STATE, 'OTP_REQUIRED');
        Session::put(SecretManager::REQUEST_SMS_CODE, false);
        Session::put(SecretManager::LOGIN, 'basis-login');
        Session::put(SecretManager::PASSWORD, 'basis-password');

        $response = $this->get(route('authenticate-flow.index', ['flow' => 'basisbank']));

        $response->assertStatus(200);
        $response->assertSee('name="basisbank_request_sms_code"', false);
        $response->assertSee('checked', false);
        $response->assertSee('Trigger AUTH and receive OTP', false);
    }
}
