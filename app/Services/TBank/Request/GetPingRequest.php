<?php

declare(strict_types=1);

namespace App\Services\TBank\Request;

use App\Exceptions\ImporterHttpException;

class GetPingRequest extends SessionJsonRequest
{
    public function __construct(string $sessionId, string $cookieHeader)
    {
        parent::__construct((string)config('tbank.api_url'), $sessionId, $cookieHeader);
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(): bool
    {
        if (true === config('importer.fake_data')) {
            return true;
        }

        $this->getJson((string)config('tbank.accounts_endpoint'), $this->buildBaseQuery());

        return true;
    }
}
