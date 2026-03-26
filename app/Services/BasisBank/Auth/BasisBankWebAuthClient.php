<?php

/*
 * BasisBankWebAuthClient.php
 */

declare(strict_types=1);

namespace App\Services\BasisBank\Auth;

use App\Exceptions\ImporterErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class BasisBankWebAuthClient
{
    private const string BASE_URL      = 'https://www.bankonline.ge';
    private const string LOGIN_PATH    = '/Login.aspx';
    private const string BALANCE_PATH  = '/Balance.aspx';
    private const string BTOOLKIT_LOGIN_PATH = '/Handlers/BToolkit.ashx?Action=GetSessionId&Type=Login';
    private const string BTOOLKIT_DEVICE_BINDING_PATH = '/Handlers/BToolkit.ashx?Action=GetSessionId&Type=DeviceBinding';
    private const string CARD_MODULE_CHECK_SESSION_PATH = '/Handlers/CardModule.ashx?funq=checksession';
    private const string BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
    private const float DEFAULT_CONNECT_TIMEOUT = 15.0;
    private const float DEFAULT_REQUEST_TIMEOUT = 45.0;
    private const int DEFAULT_TIMEOUT_RETRIES   = 2;
    private const int DEFAULT_RETRY_DELAY_MS    = 500;

    public function __construct(
        private readonly BasisBankFormParser $formParser = new BasisBankFormParser(),
        private readonly BasisBankOtpService $otpService = new BasisBankOtpService()
    ) {
    }

    public function start(string $login, string $password, bool $requestSmsCode = false, bool $trustDevice = false, ?string $sessionArtifact = null): BasisBankSessionState
    {
        $state = new BasisBankSessionState();
        $state->setLogin($login)
            ->setPassword($password)
            ->setRequestSmsCode($requestSmsCode)
            ->setTrustDevice($trustDevice)
            ->setSessionArtifact((string)$sessionArtifact)
            ->setStatus(BasisBankSessionState::UNKNOWN);

        return $this->continueFlow($state);
    }

    public function submitOtp(BasisBankSessionState $state, string $otpCode): BasisBankSessionState
    {
        $state->setOtpCode($otpCode)
            ->setStatus(BasisBankSessionState::OTP_REQUIRED)
            ->setAuthState(BasisBankSessionState::OTP_REQUIRED);

        return $this->continueFlow($state);
    }

    public function submitTrustedDeviceCode(BasisBankSessionState $state, string $otpCode): BasisBankSessionState
    {
        $state->setOtpCode($otpCode)
            ->setStatus(BasisBankSessionState::TRUST_DEVICE_REQUIRED)
            ->setAuthState(BasisBankSessionState::TRUST_DEVICE_REQUIRED);

        return $this->continueFlow($state);
    }

    public function refreshOtpChallenge(BasisBankSessionState $state): BasisBankSessionState
    {
        $state->setRequestSmsCode(true)
            ->setOtpCode('');
        $cookies = $this->decodeArtifact($state->getSessionArtifact());

        if (BasisBankSessionState::TRUST_DEVICE_REQUIRED === $state->getStatus()) {
            Log::info('BasisBank refreshOtpChallenge: re-triggering trusted-device SMS challenge.');
            return $this->sendTrustDeviceConfirmation($state, $cookies);
        }

        Log::info('BasisBank refreshOtpChallenge: re-triggering login OTP challenge.');
        return $this->sendCredentialLogin($state, $cookies);
    }

    public function continueFlow(BasisBankSessionState $state): BasisBankSessionState
    {
        $state->clearErrorMessage();
        $requestedStatus = $state->getStatus();
        $cookies = $this->decodeArtifact($state->getSessionArtifact());

        if (BasisBankSessionState::AUTHENTICATED !== $state->getStatus()) {
            $cookieHeader = $this->buildCookieHeader($cookies);
            $state        = $this->maybeCheckExistingSession($state, $cookies, $cookieHeader);
            $cookies      = $this->decodeArtifact($state->getSessionArtifact());
            if ($state->isAuthenticated() && BasisBankSessionState::TRUST_DEVICE_REQUIRED !== $requestedStatus) {
                return $state;
            }
            if (BasisBankSessionState::TRUST_DEVICE_REQUIRED === $requestedStatus) {
                $state->setStatus(BasisBankSessionState::TRUST_DEVICE_REQUIRED)
                    ->setAuthState(BasisBankSessionState::TRUST_DEVICE_REQUIRED);
            }
        }

        if (BasisBankSessionState::OTP_REQUIRED === $state->getStatus()) {
            return $this->sendCredentialsWithOtp($state, $cookies);
        }

        if (BasisBankSessionState::TRUST_DEVICE_REQUIRED === $state->getStatus()) {
            return $this->sendTrustDeviceConfirmation($state, $cookies);
        }

        return $this->sendCredentialLogin($state, $cookies);
    }

    private function maybeCheckExistingSession(
        BasisBankSessionState $state,
        array $cookies,
        ?string $cookieHeader
    ): BasisBankSessionState {
        if ([] === $cookies) {
            return $state;
        }

        // Lightweight checksession probe before the heavier Balance.aspx fetch.
        // Matches ZenPlugins checkCardSessionAlive() pattern.
        if (!$this->probeCheckSession($cookieHeader)) {
            Log::info('BasisBank: checksession probe reports dead session, skipping Balance.aspx check.');
            return $state;
        }

        $balanceResponse = $this->requestGet(self::BALANCE_PATH, $cookieHeader);
        if (!isset($balanceResponse['status'])) {
            return $state;
        }
        $balanceHtml = (string)$balanceResponse['body'];
        $balanceCookies = $this->collectSetCookieHeaders($balanceResponse['response'] ?? null);
        $cookies = $this->mergeCookies($cookies, $balanceCookies);

        if ($balanceResponse['status'] < 400 && !$this->formParser->isLoginForm($balanceHtml)) {
            if (BasisBankSessionState::TRUST_DEVICE_REQUIRED === $state->getStatus()) {
                return $state->setSessionArtifact($this->encodeArtifact($cookies));
            }
            return $state->setStatus(BasisBankSessionState::AUTHENTICATED)
                ->setSessionArtifact($this->encodeArtifact($cookies));
        }

        return $state->setSessionArtifact($this->encodeArtifact($cookies));
    }

    private function sendCredentialLogin(BasisBankSessionState $state, array $cookies): BasisBankSessionState
    {
        $cookieHeader = $this->buildCookieHeader($cookies);
        $loginResponse = $this->requestGet(self::LOGIN_PATH, $cookieHeader);
        $cookies       = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($loginResponse['response'] ?? null));
        $loginHtml     = (string)$loginResponse['body'];
        $state->setAuthState(BasisBankSessionState::UNKNOWN);

        if (!$this->formParser->isLoginForm($loginHtml)) {
            $error = $this->formParser->extractError($loginHtml);
            if (null === $error) {
                $error = 'BasisBank login page did not return expected form fields.';
            }
            return $state->setStatus(BasisBankSessionState::AUTH_FAILED)
                ->setErrorMessage($error)
                ->setSessionArtifact($this->encodeArtifact($cookies));
        }

        $fields = $this->formParser->getFormFieldsFromLoginPage($loginHtml);
        $this->formParser->fillDeviceInfoFields($fields);
        $payload = $this->formParser->buildSubmitPayload($fields, $state->getLogin(), $state->getPassword());
        $this->requestLoginSessionId($this->buildCookieHeader($cookies));
        // Recorder-backed parity: BasisBank web flow performs login toolkit bootstrap twice
        // before posting credentials. Keep both calls to match server-side session priming.
        $this->requestLoginSessionId($this->buildCookieHeader($cookies));

        $postResponse = $this->requestPost(self::LOGIN_PATH, $payload, $this->buildCookieHeader($cookies), self::LOGIN_PATH);
        $cookies      = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($postResponse['response'] ?? null));
        $postHtml     = (string)$postResponse['body'];
        $location     = (string)($postResponse['location'] ?? '');
        $statusCode   = (int)($postResponse['status'] ?? 0);

        // BasisBank can redirect back to Login.aspx and render OTP challenge there.
        // Follow that redirect explicitly so OTP challenge detection and SMS trigger stay deterministic.
        if ($statusCode >= 300 && $statusCode < 400 && '' !== trim($location) && str_contains(strtolower($location), '/login.aspx')) {
            $redirectPath = $this->normalizeRedirectPath($location, self::LOGIN_PATH);
            Log::info(
                sprintf(
                    'BasisBank credential login redirected to "%s"; loading redirected login page to inspect OTP challenge.',
                    $location
                )
            );
            $redirectResponse = $this->requestGet($redirectPath, $this->buildCookieHeader($cookies));
            $cookies          = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($redirectResponse['response'] ?? null));
            $postHtml         = (string)($redirectResponse['body'] ?? '');
            $statusCode       = (int)($redirectResponse['status'] ?? 0);
            $location         = (string)($redirectResponse['response']?->getHeaderLine('Location') ?? '');
        }

        return $this->evaluateResult(
            $state,
            $postHtml,
            $location,
            $cookies,
            $statusCode
        );
    }

    private function sendCredentialsWithOtp(BasisBankSessionState $state, array $cookies): BasisBankSessionState
    {
        if (!$state->hasOtpCode()) {
            $state->setStatus(BasisBankSessionState::OTP_REQUIRED);
            $state->setAuthState(BasisBankSessionState::OTP_REQUIRED);
            if (true === $state->isRequestSmsCode()) {
                // Re-trigger credential login to bootstrap a fresh OTP challenge/session.
                return $this->sendCredentialLogin($state, $cookies);
            }
            return $state->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage('OTP code is required to complete BasisBank login.');
        }

        $cookieHeader = $this->buildCookieHeader($cookies);
        $loginResponse = $this->requestGet(self::LOGIN_PATH, $cookieHeader);
        $cookies       = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($loginResponse['response'] ?? null));
        $loginHtml     = (string)$loginResponse['body'];
        $state->setAuthState(BasisBankSessionState::UNKNOWN);

        $fields = $this->formParser->getFormFieldsFromLoginPage($loginHtml);
        $this->formParser->fillDeviceInfoFields($fields);
        if (!$this->formParser->isOtpChallenge($loginHtml) && !$this->formParser->isLoginForm($loginHtml)) {
            return $state->setStatus(BasisBankSessionState::AUTH_FAILED)
                ->setErrorMessage('BasisBank OTP flow could not be continued.')
                ->setSessionArtifact($this->encodeArtifact($cookies));
        }

        $payload = $this->formParser->buildSubmitPayload(
            $fields,
            $state->getLogin(),
            $state->getPassword(),
            $state->getOtpCode()
        );
        $postResponse = $this->requestPost(self::LOGIN_PATH, $payload, $this->buildCookieHeader($cookies), self::LOGIN_PATH);
        $cookies      = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($postResponse['response'] ?? null));
        $postHtml     = (string)$postResponse['body'];
        $location     = (string)($postResponse['location'] ?? '');

        return $this->evaluateResult($state, $postHtml, $location, $cookies, $postResponse['status']);
    }

    private function sendTrustDeviceConfirmation(BasisBankSessionState $state, array $cookies): BasisBankSessionState
    {
        $cookieHeader = $this->buildCookieHeader($cookies);
        $balanceResponse = $this->requestGetManual(self::BALANCE_PATH, $cookieHeader);
        $cookies         = $this->mergeCookies($cookies, (array)($balanceResponse['redirect_cookies'] ?? []));
        $cookies         = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($balanceResponse['response'] ?? null));
        $workingHtml     = (string)$balanceResponse['body'];
        $state->setAuthState(BasisBankSessionState::UNKNOWN);

        // If the page is a login form (not trust device), the session has changed.
        if (!$this->formParser->isTrustedDeviceChallenge($workingHtml) && $this->formParser->isLoginForm($workingHtml)) {
            return $state->setStatus(BasisBankSessionState::OTP_REQUIRED)
                ->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage('Trust-device was requested but session state changed.');
        }

        $fields = $this->formParser->extractFormFieldsFromLoginPage($workingHtml);
        $trustedOtpField = $this->formParser->findField($fields, '/TrustedDeviceOTP\\$TxtOtpCode$/i');

        // ── STEP 1: Click "register device" button (ctl03) ──
        // Only when ctl03 is present and the OTP input has not appeared yet.
        // Mirrors ZenPlugins ensureTrustedDevice() step 1.
        if (isset($fields[BasisBankFormParser::TRUST_FIRST_CONFIRM_FIELD]) && null === $trustedOtpField) {
            $this->requestDeviceBindingSessionId($this->buildCookieHeader($cookies));

            $firstPayload = $fields;
            $this->formParser->fillDeviceInfoFields($firstPayload);
            $firstPayload[BasisBankFormParser::TRUST_FIRST_CONFIRM_FIELD] = 'Yes';
            // Do NOT override __EVENTTARGET — ASP.NET submit buttons expect the button
            // name as a form key with value, not in __EVENTTARGET. Matches ZenPlugins pattern.

            $firstResponse = $this->requestPost(self::BALANCE_PATH, $firstPayload, $this->buildCookieHeader($cookies), self::BALANCE_PATH);
            $cookies       = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($firstResponse['response'] ?? null));
            // Use Step 1 response HTML directly for Step 2 (critical: maintains ViewState continuity).
            $workingHtml   = (string)$firstResponse['body'];
            $fields        = $this->formParser->extractFormFieldsFromLoginPage($workingHtml);
            $trustedOtpField = $this->formParser->findField($fields, '/TrustedDeviceOTP\\$TxtOtpCode$/i');
        }

        // If page is no longer a trust challenge or login form, we're authenticated.
        if (!$this->formParser->isTrustedDeviceChallenge($workingHtml) && !$this->formParser->isLoginForm($workingHtml)) {
            return $state->setStatus(BasisBankSessionState::AUTHENTICATED)
                ->setAuthState(BasisBankSessionState::AUTHENTICATED)
                ->setSessionArtifact($this->encodeArtifact($cookies))
                ->clearErrorMessage();
        }

        // ── Await OTP from user ──
        if (!$state->hasOtpCode()) {
            $state->setStatus(BasisBankSessionState::TRUST_DEVICE_REQUIRED);
            $state->setAuthState(BasisBankSessionState::TRUST_DEVICE_REQUIRED);
            if (true === $state->isRequestSmsCode()) {
                $this->otpService->requestOtpCode($this->buildCookieHeader($cookies), self::BALANCE_PATH);
                return $state->setSessionArtifact($this->encodeArtifact($cookies))
                    ->setErrorMessage('Trusted-device code requested. Enter the one-time code and submit again.');
            }
            return $state->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage('Trusted-device confirmation code is required.');
        }

        // ── STEP 2: Submit trust device OTP code (ctl06) ──
        // $fields and $trustedOtpField are already set from Step 1's response (or initial GET if OTP field was already present).
        // No need to re-extract or re-do Step 1.

        $payload = $fields;
        $this->formParser->fillDeviceInfoFields($payload);
        if (null !== $trustedOtpField) {
            $payload[$trustedOtpField] = $state->getOtpCode();
        } else {
            $payload[BasisBankFormParser::TRUST_OTP_FIELD] = $state->getOtpCode();
        }
        $trustedConfirmField = $this->formParser->findField($payload, '/\\$ctl06$/');
        if (null !== $trustedConfirmField) {
            $payload[$trustedConfirmField] = 'Yes';
        } else {
            $payload[BasisBankFormParser::TRUST_SECOND_CONFIRM_FIELD] = 'Yes';
        }
        // Do NOT override __EVENTTARGET — matches ZenPlugins pattern for submit buttons.

        $postResponse = $this->requestPost(self::BALANCE_PATH, $payload, $this->buildCookieHeader($cookies), self::BALANCE_PATH);
        $cookies      = $this->mergeCookies($cookies, $this->collectSetCookieHeaders($postResponse['response'] ?? null));
        $postHtml     = (string)$postResponse['body'];
        $location     = (string)($postResponse['location'] ?? '');

        return $this->evaluateResult($state, $postHtml, $location, $cookies, $postResponse['status']);
    }

    private function evaluateResult(
        BasisBankSessionState $state,
        string $html,
        string $location,
        array $cookies,
        int $statusCode
    ): BasisBankSessionState {
        $location = strtolower($location);
        if ('' !== trim($location) && $statusCode >= 300 && $statusCode < 400) {
            if (str_contains($location, 'balance.aspx') || str_contains($location, 'info.aspx')) {
                return $state->setStatus(BasisBankSessionState::AUTHENTICATED)
                    ->setAuthState(BasisBankSessionState::AUTHENTICATED)
                    ->setSessionArtifact($this->encodeArtifact($cookies))
                    ->clearErrorMessage();
            }

            return $state->setStatus(BasisBankSessionState::AUTH_FAILED)
                ->setAuthState(BasisBankSessionState::AUTH_FAILED)
                ->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage(sprintf('BasisBank auth returned redirect "%s" without success marker.', $location));
        }

        if ($statusCode >= 400) {
            return $state->setStatus(BasisBankSessionState::AUTH_FAILED)
                ->setAuthState(BasisBankSessionState::AUTH_FAILED)
                ->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage(sprintf('BasisBank auth request failed with HTTP %d.', $statusCode));
        }

        if ($this->formParser->isOtpChallenge($html)) {
            if (true === $state->isRequestSmsCode() && '' === $state->getOtpCode()) {
                Log::info('BasisBank OTP challenge detected, requesting SMS code.');
                $this->otpService->requestOtpCode($this->buildCookieHeader($cookies));
            }
            return $state->setStatus(BasisBankSessionState::OTP_REQUIRED)
                ->setAuthState(BasisBankSessionState::OTP_REQUIRED)
                ->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage('OTP code is required.');
        }

        if ($this->formParser->isTrustedDeviceChallenge($html)) {
            return $state->setStatus(BasisBankSessionState::TRUST_DEVICE_REQUIRED)
                ->setAuthState(BasisBankSessionState::TRUST_DEVICE_REQUIRED)
                ->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage('Trusted-device confirmation is required.');
        }

        if ($this->formParser->isLoginForm($html)) {
            $message = $this->formParser->extractError($html) ?? 'BasisBank authentication failed.';
            return $state->setStatus(BasisBankSessionState::AUTH_FAILED)
                ->setAuthState(BasisBankSessionState::AUTH_FAILED)
                ->setSessionArtifact($this->encodeArtifact($cookies))
                ->setErrorMessage($message);
        }

        return $state->setStatus(BasisBankSessionState::AUTHENTICATED)
            ->setAuthState(BasisBankSessionState::AUTHENTICATED)
            ->setSessionArtifact($this->encodeArtifact($cookies))
            ->clearErrorMessage();
    }

    /**
     * Lightweight session probe via CardModule checksession.
     * Returns true if session appears alive, false if dead.
     * Matches ZenPlugins checkCardSessionAlive() pattern.
     */
    private function probeCheckSession(?string $cookieHeader): bool
    {
        if (null === $cookieHeader || '' === $cookieHeader) {
            return false;
        }
        $headers = $this->ajaxSessionHeaders($cookieHeader, self::BALANCE_PATH);
        try {
            $client   = $this->createClient();
            $response = $client->request(
                'POST',
                self::CARD_MODULE_CHECK_SESSION_PATH,
                [
                    'headers'          => $headers,
                    'allow_redirects'  => false,
                ]
            );
            $body = (string)$response->getBody();
            // checksession returns JSON with resultCode. A dead session returns an error.
            if (str_contains($body, '"resultCode"') && !str_contains(strtolower($body), 'error')) {
                return true;
            }
            // If the response is a redirect to login or an error, session is dead.
            if ($response->getStatusCode() >= 300) {
                return false;
            }

            return true;
        } catch (TransferException) {
            return false;
        }
    }

    private function requestDeviceBindingSessionId(?string $cookieHeader): void
    {
        $headers = $this->ajaxSessionHeaders($cookieHeader, self::BALANCE_PATH);
        try {
            $client = $this->createClient();
            $client->request(
                'POST',
                self::BTOOLKIT_DEVICE_BINDING_PATH,
                [
                    'headers' => $headers,
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException) {
            // Best-effort request; trusted-device flow can continue even if this pre-call is unavailable.
        }
    }

    private function requestLoginSessionId(?string $cookieHeader): void
    {
        $headers = $this->ajaxSessionHeaders($cookieHeader, self::LOGIN_PATH);
        try {
            $client = $this->createClient();
            $client->request(
                'POST',
                self::BTOOLKIT_LOGIN_PATH,
                [
                    'headers' => $headers,
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException) {
            // Best-effort request; login can proceed without it.
        }
    }

    private function requestGet(string $path, ?string $cookieHeader): array
    {
        $headers = $this->defaultHeaders();
        if (null !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
        }
        $maxAttempts = $this->getTimeoutRetryAttempts();
        $delayMs     = $this->getTimeoutRetryDelayMs();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $client   = $this->createClient();
                $response = $client->request(
                    'GET',
                    $path,
                    [
                        'headers' => $headers,
                        'allow_redirects' => true,
                    ]
                );
                return [
                    'status'   => $response->getStatusCode(),
                    'body'     => (string)$response->getBody(),
                    'response' => $response,
                ];
            } catch (TransferException $e) {
                if (!$this->isTimeoutException($e) || $attempt >= $maxAttempts) {
                    throw new ImporterErrorException(sprintf('BasisBank GET %s failed: %s', $path, $e->getMessage()));
                }
                Log::warning(
                    sprintf(
                        'BasisBank GET %s timed out, retrying (%d/%d).',
                        $path,
                        $attempt,
                        $maxAttempts
                    )
                );
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
        throw new ImporterErrorException(sprintf('BasisBank GET %s failed after retries.', $path));
    }

    /**
     * GET with manual redirect handling to preserve Set-Cookie headers from intermediate 302s.
     * Used for Balance.aspx in trust device flow where cookie loss breaks the session.
     */
    private function requestGetManual(string $path, ?string $cookieHeader): array
    {
        $headers = $this->defaultHeaders();
        if (null !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
        }
        $maxRedirects = 5;
        $accumulatedCookies = [];

        for ($i = 0; $i < $maxRedirects; $i++) {
            try {
                $client   = $this->createClient();
                $response = $client->request(
                    'GET',
                    $path,
                    [
                        'headers'          => $headers,
                        'allow_redirects'  => false,
                    ]
                );
            } catch (TransferException $e) {
                throw new ImporterErrorException(sprintf('BasisBank GET %s failed: %s', $path, $e->getMessage()));
            }

            $status = $response->getStatusCode();

            // Return result array that includes cookies from this response.
            if ($status < 300 || $status >= 400) {
                return [
                    'status'            => $status,
                    'body'              => (string)$response->getBody(),
                    'response'          => $response,
                    'redirect_cookies'  => $accumulatedCookies,
                ];
            }

            // Follow redirect manually, preserving Set-Cookie from each hop.
            $location = $response->getHeaderLine('Location');
            if ('' === $location) {
                return [
                    'status'            => $status,
                    'body'              => (string)$response->getBody(),
                    'response'          => $response,
                    'redirect_cookies'  => $accumulatedCookies,
                ];
            }

            // Update cookies from redirect response.
            $redirectCookies = $this->collectSetCookieHeaders($response);
            if ([] !== $redirectCookies) {
                $accumulatedCookies = $this->mergeCookies($accumulatedCookies, $redirectCookies);
                $existingCookies = null !== $cookieHeader ? $this->parseCookieHeader($cookieHeader) : [];
                $existingCookies = $this->mergeCookies($existingCookies, $redirectCookies);
                $cookieHeader    = $this->buildCookieHeader($existingCookies);
                $headers['Cookie'] = $cookieHeader;
            }

            $path = $this->normalizeRedirectPath($location, $path);
        }

        throw new ImporterErrorException(sprintf('BasisBank GET %s exceeded redirect limit.', $path));
    }

    private function parseCookieHeader(string $cookieHeader): array
    {
        $cookies = [];
        $parts   = preg_split('/;\s*/', $cookieHeader);
        if (!is_array($parts)) {
            return [];
        }
        foreach ($parts as $part) {
            if (!is_string($part) || !str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $part, 2));
            if ('' !== $name) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    private function requestPost(string $path, array $form, ?string $cookieHeader, string $refererPath): array
    {
        $headers = $this->defaultHeaders();
        $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        $headers['Referer']     = sprintf('%s%s', self::BASE_URL, $refererPath);
        if (null !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
        }

        $maxAttempts = $this->getTimeoutRetryAttempts();
        $delayMs     = $this->getTimeoutRetryDelayMs();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $client   = $this->createClient();
                $response = $client->request(
                    'POST',
                    $path,
                    [
                        'headers'      => $headers,
                        'form_params'  => $form,
                        'allow_redirects' => false,
                    ]
                );
                return [
                    'status'   => $response->getStatusCode(),
                    'body'     => (string)$response->getBody(),
                    'location' => $response->getHeaderLine('Location'),
                    'response' => $response,
                ];
            } catch (TransferException $e) {
                if (!$this->isTimeoutException($e) || $attempt >= $maxAttempts) {
                    throw new ImporterErrorException(sprintf('BasisBank POST %s failed: %s', $path, $e->getMessage()));
                }
                Log::warning(
                    sprintf(
                        'BasisBank POST %s timed out, retrying (%d/%d).',
                        $path,
                        $attempt,
                        $maxAttempts
                    )
                );
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
        throw new ImporterErrorException(sprintf('BasisBank POST %s failed after retries.', $path));
    }

    private function collectSetCookieHeaders(?ResponseInterface $response): array
    {
        if (null === $response) {
            return [];
        }

        $headers = $response->getHeader('Set-Cookie');
        $cookies = [];
        foreach ($headers as $header) {
            $parsed = $this->parseSetCookieHeader((string)$header);
            if (null === $parsed) {
                continue;
            }
            $name    = $parsed['name'];
            $value   = $parsed['value'];
            $expired = $parsed['expired'];
            if (true === $expired) {
                unset($cookies[$name]);
                continue;
            }
            $cookies[$name] = $value;
        }

        return $cookies;
    }

    private function parseSetCookieHeader(string $header): ?array
    {
        $segments = preg_split('/;\s*/', $header);
        if (!is_array($segments) || [] === $segments) {
            return null;
        }

        $pair = array_shift($segments);
        if (false === str_contains($pair, '=')) {
            return null;
        }
        [$name, $value] = array_map('trim', explode('=', $pair, 2));
        if ('' === $name) {
            return null;
        }

        $isExpired = false;
        $expiresAt = null;
        foreach ($segments as $segment) {
            if (false === str_contains((string)$segment, '=')) {
                continue;
            }
            [$segmentName, $segmentValue] = array_map('trim', explode('=', (string)$segment, 2));
            if ('max-age' === strtolower($segmentName)) {
                if ('0' === $segmentValue || '' === $segmentValue) {
                    $isExpired = true;
                    break;
                }
                continue;
            }
            if ('expires' === strtolower($segmentName)) {
                $time = strtotime($segmentValue);
                if (false === $time || $time <= time()) {
                    $isExpired = true;
                }
                $expiresAt = $time;
            }
        }

        if (null !== $expiresAt && $expiresAt <= time()) {
            $isExpired = true;
        }

        return ['name' => $name, 'value' => $value, 'expired' => $isExpired];
    }

    private function mergeCookies(array $baseCookies, array $updateCookies): array
    {
        foreach ($updateCookies as $name => $value) {
            $baseCookies[$name] = $value;
        }

        return $baseCookies;
    }

    private function buildCookieHeader(array $cookies): ?string
    {
        if ([] === $cookies) {
            return null;
        }

        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = sprintf('%s=%s', $name, (string)$value);
        }

        return implode('; ', $parts);
    }

    private function encodeArtifact(array $cookies): string
    {
        return base64_encode((string)json_encode($cookies));
    }

    private function decodeArtifact(string $artifact): array
    {
        if ('' === trim($artifact)) {
            return [];
        }

        $decoded = base64_decode($artifact, true);
        if (false === $decoded) {
            $decoded = $artifact;
        }

        $data = json_decode((string)$decoded, true);
        if (!is_array($data)) {
            return [];
        }

        $cookies = [];
        foreach ($data as $name => $value) {
            if ('' === trim((string)$name) || !is_string($value)) {
                continue;
            }
            $cookies[trim((string)$name)] = (string)$value;
        }

        return $cookies;
    }

    private function defaultHeaders(): array
    {
        return [
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
        ];
    }

    private function ajaxSessionHeaders(?string $cookieHeader, string $refererPath): array
    {
        $normalizedReferer = str_starts_with($refererPath, '/') ? $refererPath : ('/' . ltrim($refererPath, '/'));
        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Content-Type' => 'text/plain',
            'Origin' => self::BASE_URL,
            'Referer' => sprintf('%s%s', self::BASE_URL, $normalizedReferer),
            'X-Requested-With' => 'XMLHttpRequest',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => self::BROWSER_USER_AGENT,
        ];

        if (null !== $cookieHeader && '' !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
            $dtpc = $this->extractCookieValue($cookieHeader, 'dtPC');
            if (null !== $dtpc) {
                $headers['x-dtpc'] = $dtpc;
            }
        }

        return $headers;
    }

    private function createClient(): Client
    {
        $options = [
            'base_uri'        => self::BASE_URL,
            'connect_timeout' => $this->getConnectTimeout(),
            'timeout'         => $this->getRequestTimeout(),
            // Bank connections must always verify TLS. The importer.connection.verify toggle
            // is for internal Firefly III API connections only — never disable TLS for bankonline.ge.
            'verify'          => true,
        ];
        return new Client($options);
    }

    private function getConnectTimeout(): float
    {
        $timeout = (float) config('basisbank.auth_connect_timeout', self::DEFAULT_CONNECT_TIMEOUT);
        return $timeout > 0 ? $timeout : self::DEFAULT_CONNECT_TIMEOUT;
    }

    private function getRequestTimeout(): float
    {
        $timeout = (float) config('basisbank.auth_request_timeout', self::DEFAULT_REQUEST_TIMEOUT);
        return $timeout > 0 ? $timeout : self::DEFAULT_REQUEST_TIMEOUT;
    }

    private function getTimeoutRetryAttempts(): int
    {
        $retries = (int) config('basisbank.auth_timeout_retries', self::DEFAULT_TIMEOUT_RETRIES);
        $retries = max(0, $retries);
        return 1 + $retries;
    }

    private function getTimeoutRetryDelayMs(): int
    {
        $delay = (int) config('basisbank.auth_retry_delay_ms', self::DEFAULT_RETRY_DELAY_MS);
        return max(0, $delay);
    }

    private function isTimeoutException(TransferException $e): bool
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'curl error 28') || str_contains($message, 'operation timed out') || str_contains($message, 'timed out')) {
            return true;
        }
        if (method_exists($e, 'getHandlerContext')) {
            $context = $e->getHandlerContext();
            if (is_array($context)) {
                $errno = (int) ($context['errno'] ?? 0);
                $timedOut = (bool) ($context['timed_out'] ?? false);
                if (28 === $errno || true === $timedOut) {
                    return true;
                }
            }
        }

        return false;
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

    private function normalizeRedirectPath(string $location, string $fallback): string
    {
        $location = trim($location);
        if ('' === $location) {
            return $fallback;
        }

        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            $path  = (string)(parse_url($location, PHP_URL_PATH) ?? '');
            $query = (string)(parse_url($location, PHP_URL_QUERY) ?? '');
            if ('' === $path) {
                return $fallback;
            }

            return '' === $query ? $path : sprintf('%s?%s', $path, $query);
        }

        return str_starts_with($location, '/') ? $location : ('/' . ltrim($location, '/'));
    }
}
