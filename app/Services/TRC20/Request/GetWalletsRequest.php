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
    private const string CURRENCY = 'USDT';

    public function __construct(
        private readonly string $apiKey,
        private readonly array $wallets,
    ) {
        parent::__construct((string)config('trc20.api_url'), $this->apiKey);
    }

    public function get(): GetAccountsResponse
    {
        $wallets = $this->normalizeWallets($this->wallets);
        if (0 === count($wallets)) {
            return new GetAccountsResponse([]);
        }

        $this->setTimeOut((float)config('importer.connection.timeout'));

        if (true === config('importer.fake_data')) {
            $first = $wallets[0];

            return new GetAccountsResponse(
                [
                    [
                        'id'                => $first,
                        'name'              => $first,
                        'institution_name'  => 'TRC20',
                        'institution_logo'  => '',
                        'provider'          => 'trc20',
                        'currency'          => self::CURRENCY,
                        'status'            => 'active',
                    ],
                ]
            );
        }

        // TronGrid requires per-wallet queries (address in path)
        $resultWallets = [];
        foreach ($wallets as $wallet) {
            $request = new GetWalletRequest($this->apiKey, $wallet);
            $request->setTimeOut((float)config('importer.connection.timeout'));
            $single  = $request->get();
            if ([] !== $single) {
                $resultWallets[] = $single;
                Log::debug(sprintf('TRC20: wallet %s fetched successfully.', $wallet));
            } else {
                Log::warning(sprintf('TRC20: wallet %s returned empty from TronGrid.', $wallet));
            }
        }

        Log::debug(sprintf('TRC20 collectAccounts: fetched %d account(s).', count($resultWallets)));

        return new GetAccountsResponse($resultWallets);
    }

    private function normalizeWallets(array $wallets): array
    {
        $normalized = [];
        $invalid    = [];
        foreach ($wallets as $wallet) {
            $value = trim((string)$wallet);
            if (!TRC20AddressValidator::isValid($value)) {
                $invalid[] = $value;
                continue;
            }
            if ('' !== $value && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if (0 !== count($invalid)) {
            $invalid = array_values(array_unique(array_filter($invalid, static fn(string $value): bool => '' !== $value)));
            if (0 !== count($invalid)) {
                throw new ImporterHttpException(sprintf('Invalid TRC20 wallet format(s): %s', implode(', ', $invalid)));
            }
        }

        return $normalized;
    }
}
