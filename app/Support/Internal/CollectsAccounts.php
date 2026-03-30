<?php

/*
 * CollectsAccounts.php
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

namespace App\Support\Internal;

use App\Exceptions\ImporterHttpException;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Model\ImportServiceAccount;
use App\Services\Spectre\Authentication\SecretManager as SpectreSecretManager;
use App\Services\LunchFlow\Authentication\SecretManager as LunchFlowSecretManager;
use App\Services\BasisBank\Authentication\SecretManager as BasisBankSecretManager;
use App\Services\TBank\Authentication\SecretManager as TBankSecretManager;
use App\Services\TRC20\Authentication\SecretManager as TRC20SecretManager;
use App\Services\Spectre\Request\GetAccountsRequest as SpectreGetAccountsRequest;
use App\Services\LunchFlow\Request\GetAccountsRequest as LunchFlowGetAccountsRequest;
use App\Services\BasisBank\Request\GetAccountsRequest as BasisBankGetAccountsRequest;
use App\Services\TBank\Request\GetAccountsRequest as TBankGetAccountsRequest;
use App\Services\TRC20\Request\GetWalletsRequest as TRC20GetWalletsRequest;
use App\Services\Spectre\Response\GetAccountsResponse;
use GuzzleHttp\Exception\GuzzleException;

trait CollectsAccounts
{
    /**
     * @throws GuzzleException
     * @throws ImporterHttpException
     */
    protected function getSpectreAccounts(Configuration $configuration): array
    {
        $return                  = [];
        $url                     = config('spectre.url');
        $appId                   = SpectreSecretManager::getAppId();
        $secret                  = SpectreSecretManager::getSecret();
        $spectreList             = new SpectreGetAccountsRequest($url, $appId, $secret);
        $spectreList->setTimeOut(config('importer.connection.timeout'));
        $spectreList->connection = $configuration->getConnection();

        /** @var GetAccountsResponse $spectreAccounts */
        $spectreAccounts         = $spectreList->get();
        foreach ($spectreAccounts as $account) {
            $return[] = $account;
        }

        return $return;
    }

    /**
     * @throws GuzzleException
     * @throws ImporterHttpException
     */
    protected function getLunchFlowAccounts(Configuration $configuration): array
    {
        $return            = [];
        $apiKey            = LunchFlowSecretManager::getApiKey($configuration);
        $lunchFlowList     = new LunchFlowGetAccountsRequest($apiKey);
        $lunchFlowList->setTimeOut(config('importer.connection.timeout'));

        /** @var GetAccountsResponse $lunchFlowAccounts */
        $lunchFlowAccounts = $lunchFlowList->get();
        foreach ($lunchFlowAccounts as $account) {
            $return[] = $account;
        }

        return $return;
    }

    /**
     * @throws GuzzleException
     * @throws ImporterHttpException
     */
    protected function getBasisBankAccounts(Configuration $configuration): array
    {
        $return       = [];
        $apiToken     = BasisBankSecretManager::getApiToken($configuration);
        $consentId    = BasisBankSecretManager::getConsentId($configuration);
        $sessionArtifact = BasisBankSecretManager::getSessionArtifact($configuration);
        $accountsReq     = new BasisBankGetAccountsRequest($apiToken, $consentId, $sessionArtifact);
        $accountsReq->setTimeOut((float)config('importer.connection.timeout'));
        $accounts     = $accountsReq->get();
        foreach ($accounts as $account) {
            $return[] = $account;
        }

        return $return;
    }

    /**
     * @throws GuzzleException
     * @throws ImporterHttpException
     */
    protected function getTBankAccounts(Configuration $configuration): array
    {
        $return       = [];
        $sessionId    = TBankSecretManager::getSessionId($configuration);
        $cookieHeader = TBankSecretManager::getCookieHeader($configuration);
        $accountsReq  = new TBankGetAccountsRequest($sessionId, $cookieHeader);
        $accountsReq->setTimeOut((float)config('importer.connection.timeout'));
        $accounts     = $accountsReq->get();
        foreach ($accounts as $account) {
            $return[] = $account;
        }

        return $return;
    }

    /**
     * @throws GuzzleException
     * @throws ImporterHttpException
     */
    protected function getTRC20Accounts(Configuration $configuration): array
    {
        $wallets = array_keys($configuration->getAccounts());
        if (0 === count($wallets)) {
            return [];
        }

        $return      = [];
        $apiKey      = TRC20SecretManager::getApiKey($configuration);
        $walletsReq  = new TRC20GetWalletsRequest($apiKey, $wallets);
        $walletsReq->setTimeOut((float)config('importer.connection.timeout'));
        $walletsResp = $walletsReq->get();

        foreach ($walletsResp as $account) {
            $return[] = ImportServiceAccount::normalizeToArray($account);
        }

        return $return;
    }
}
