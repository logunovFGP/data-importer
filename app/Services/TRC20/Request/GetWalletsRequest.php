<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\LunchFlow\Response\GetAccountsResponse;
use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;
use Illuminate\Support\Facades\Log;

class GetWalletsRequest extends BearerJsonRequest
{
    public function __construct(
        private readonly string $apiKey,
        private readonly array $wallets,
    ) {
        parent::__construct((string)config('trc20.api_url'), $this->apiKey);
    }

    public function get(): GetAccountsResponse
    {
        $wallets = TRC20AddressValidator::normalizeAndValidate($this->wallets);
        if (0 === count($wallets)) {
            return new GetAccountsResponse([]);
        }

        $this->setTimeOut((float)config('importer.connection.timeout'));

        if (true === config('importer.fake_data')) {
            $first = $wallets[0];
            $request = new GetWalletRequest($this->apiKey, $first);

            return new GetAccountsResponse($request->getAccounts());
        }

        // Each wallet returns multiple accounts (one per token type)
        $allAccounts = [];
        foreach ($wallets as $wallet) {
            $request  = new GetWalletRequest($this->apiKey, $wallet);
            $request->setTimeOut((float)config('importer.connection.timeout'));
            $accounts = $request->getAccounts();
            foreach ($accounts as $account) {
                $allAccounts[] = $account;
                Log::debug(sprintf('TRC20: discovered account [%s] %s on wallet %s.', $account['currency'] ?? '?', $account['id'] ?? '?', $wallet));
            }
        }

        Log::debug(sprintf('TRC20 collectAccounts: fetched %d token account(s) across %d wallet(s).', count($allAccounts), count($wallets)));

        return new GetAccountsResponse($allAccounts);
    }
}
