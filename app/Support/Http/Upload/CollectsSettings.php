<?php

declare(strict_types=1);
/*
 * CollectsSettings.php
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

namespace App\Support\Http\Upload;

use App\Services\TBank\Authentication\SecretManager as TBankSecretManager;

trait CollectsSettings
{
    protected function getSimpleFINSettings(): array
    {
        return [
            'token' => old('simplefin_token') ?? config('simplefin.token'),
        ];
    }

    protected function getBasisBankSettings(): array
    {
        return [
            'api_token'  => old('basisbank_api_token') ?? session()->get('basisbank_api_token') ?? config('basisbank.api_token'),
            'consent_id' => old('basisbank_consent_id') ?? session()->get('basisbank_consent_id') ?? config('basisbank.consent_id'),
        ];
    }

    protected function getTBankSettings(): array
    {
        $sessionId    = TBankSecretManager::getSessionId();
        $cookieHeader = TBankSecretManager::getCookieHeader();
        $sessionReady = '' !== trim($sessionId) && '' !== trim($cookieHeader);
        $sessionPreview = '';
        if ('' !== trim($sessionId)) {
            $sessionPreview = strlen($sessionId) <= 16
                ? $sessionId
                : sprintf('%s...%s', substr($sessionId, 0, 8), substr($sessionId, -4));
        }

        return [
            'session_ready' => $sessionReady,
            'session_id_preview' => $sessionPreview,
            'auth_state' => TBankSecretManager::getAuthState(),
            'device_pin' => TBankSecretManager::getDevicePin(),
        ];
    }

    protected function getTRC20Settings(): array
    {
        return [
            'api_key' => old('trc20_api_key') ?? session()->get('trc20_api_key') ?? config('trc20.api_key'),
            'wallets' => old('trc20_wallets') ?? session()->get('trc20_wallets') ?? config('trc20.wallets'),
        ];
    }
}
