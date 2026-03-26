<?php

/*
 * BasisBankOtpService.php
 */

declare(strict_types=1);

namespace App\Services\BasisBank\Auth;

use App\Exceptions\ImporterErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class BasisBankOtpService
{
    private const string OTP_REQUEST_PATH = '/Handlers/SendSms.ashx?Module=BankOnlineTransfer';
    private const string DEFAULT_REFERER_PATH = '/Login.aspx';
    private const float  TIMEOUT_SECONDS = 8.0;
    private const string BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    public function requestOtpCode(?string $cookieHeader = null, string $refererPath = self::DEFAULT_REFERER_PATH): void
    {
        $client  = $this->createClient($cookieHeader);
        $normalizedRefererPath = str_starts_with($refererPath, '/') ? $refererPath : ('/' . ltrim($refererPath, '/'));
        $options = [
            'headers' => [
                'Accept'       => '*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Content-Type' => 'text/plain',
                'Referer'      => sprintf('https://www.bankonline.ge%s', $normalizedRefererPath),
                'Origin'       => 'https://www.bankonline.ge',
                'X-Requested-With' => 'XMLHttpRequest',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
            ],
        ];
        if (null !== $cookieHeader && '' !== $cookieHeader) {
            $options['headers']['Cookie'] = $cookieHeader;
            $dtpc = $this->extractCookieValue($cookieHeader, 'dtPC');
            if (null !== $dtpc) {
                $options['headers']['x-dtpc'] = $dtpc;
            }
            Log::debug(
                sprintf(
                    'BasisBank OTP request context: has ASP.NET_SessionId=%s, has dtPC=%s.',
                    str_contains($cookieHeader, 'ASP.NET_SessionId=') ? 'yes' : 'no',
                    null !== $dtpc ? 'yes' : 'no'
                )
            );
        }

        try {
            $response = $client->request('POST', self::OTP_REQUEST_PATH, $options);
        } catch (TransferException $e) {
            throw new ImporterErrorException(sprintf('BasisBank OTP request failed: %s', $e->getMessage()));
        }

        Log::info(
            sprintf(
                'BasisBank OTP request sent via referer "%s" (HTTP %d).',
                $normalizedRefererPath,
                $response->getStatusCode()
            )
        );
        $this->assertStatus($response);
    }

    private function assertStatus(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        throw new ImporterErrorException(sprintf('BasisBank OTP request endpoint returned HTTP %d.', $statusCode));
    }

    private function createClient(?string $cookieHeader = null): Client
    {
        $headers = [
            'User-Agent' => self::BROWSER_USER_AGENT,
        ];
        if (null !== $cookieHeader && '' !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
        }

        return new Client(
            [
                'base_uri'        => 'https://www.bankonline.ge',
                'connect_timeout' => self::TIMEOUT_SECONDS,
                'timeout'         => self::TIMEOUT_SECONDS,
                'verify'          => config('importer.connection.verify'),
                'headers'         => $headers,
            ]
        );
    }

    private function extractCookieValue(string $cookieHeader, string $cookieName): ?string
    {
        $parts = preg_split('/;\s*/', $cookieHeader);
        if (!is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            if (!is_string($part) || '' === $part || !str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $part, 2));
            if (strcasecmp($name, $cookieName) === 0 && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
