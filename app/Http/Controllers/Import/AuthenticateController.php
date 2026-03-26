<?php

/*
 * AuthenticateController.php
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

namespace App\Http\Controllers\Import;

use App\Exceptions\ImporterErrorException;
use App\Http\Controllers\Controller;
use App\Services\BasisBank\Auth\BasisBankSessionState;
use App\Services\BasisBank\Auth\BasisBankWebAuthClient;
use App\Services\BasisBank\Authentication\SecretManager;
use App\Services\TBank\Authentication\SecretManager as TBankSecretManager;
use App\Services\TBank\OAuthClient as TBankOAuthClient;
use App\Services\TRC20\Authentication\SecretManager as TRC20SecretManager;
use App\Services\Enums\AuthenticationStatus;
use App\Services\LunchFlow\AuthenticationValidator as LunchFlowValidator;
use App\Services\Nordigen\AuthenticationValidator as NordigenValidator;
use App\Services\Shared\Authentication\AuthenticationValidatorInterface;
use App\Services\Sophtron\AuthenticationValidator as SophtronValidator;
use App\Services\Spectre\AuthenticationValidator as SpectreValidator;
use App\Services\BasisBank\AuthenticationValidator as BasisBankValidator;
use App\Services\TBank\AuthenticationValidator as TBankValidator;
use App\Services\TRC20\AuthenticationValidator as TRC20Validator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Session;

/**
 * Class AuthenticateController
 */
class AuthenticateController extends Controller
{
    private const string AUTH_ROUTE = 'authenticate-flow.index';
    private const string BASISBANK_TRUST_OFFER_PENDING = 'basisbank_trust_offer_pending';

    public function __construct()
    {
        parent::__construct();
        Log::debug('Now in AuthenticateController, calling middleware.');
    }

    /**
     * @return Application|Factory|Redirector|RedirectResponse|View
     *
     * @throws ImporterErrorException
     */
    public function index(Request $request, string $flow)
    {
        // variables for page:
        $mainTitle = 'Authentication';
        $pageTitle = 'Authentication';
        $flow ??= 'file';
        $subTitle  = ucfirst($flow);
        $error     = Session::get('error');
        Log::debug(sprintf('Now in AuthenticateController::index (/authenticate) with flow "%s"', $flow));

        // if the flow is actually validated, or not validateable (like the "file" flow),
        // give a friendly error page.
        // FIXME needs to be a factory.
        $validator = $this->getValidator($flow);
        if (null === $validator) {
            return view('import.002-authenticate.already-authenticated')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle'));
        }

        if ('basisbank' === $flow) {
            $basisBankState = SecretManager::getAuthState();
            if (BasisBankSessionState::AUTHENTICATED === $basisBankState) {
                if ($this->isBasisBankTrustOfferPending()) {
                    $data = $this->collectBasisBankStateAsData();

                    return view('import.002-authenticate.index')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle', 'data', 'error'));
                }
                return view('import.002-authenticate.already-authenticated')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle'));
            }

            if (in_array($basisBankState, [BasisBankSessionState::OTP_REQUIRED, BasisBankSessionState::TRUST_DEVICE_REQUIRED], true)) {
                $data = $this->collectBasisBankStateAsData();

                return view('import.002-authenticate.index')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle', 'data', 'error'));
            }
        }

        $result = $validator->validate();

        if (AuthenticationStatus::NODATA === $result) {
            // need to get and present the auth data in the system (yes it is always empty).
            $data = $validator->getData();

            return view('import.002-authenticate.index')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle', 'data', 'error'));
        }

        if (AuthenticationStatus::AUTHENTICATED === $result) {
            Log::debug('[a] Return redirect to already authenticated view');

            return view('import.002-authenticate.already-authenticated')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle'));
        }

        Log::debug(sprintf('Throwing ImporterErrorException for flow "%s"', $flow ?? 'NULL'));

        throw new ImporterErrorException(sprintf('[b] Impossible flow exception. Unexpected flow "%s" encountered.', $flow ?? 'NULL'));
    }

    private function getValidator(string $flow): ?AuthenticationValidatorInterface
    {
        // need a switch here to validate all possible flows.
        switch ($flow) {
            case 'spectre':
                return new SpectreValidator();

            case 'nordigen':
                return new NordigenValidator();

            case 'lunchflow':
                return new LunchFlowValidator();

            case 'basisbank':
                return new BasisBankValidator();

            case 'tbank':
                return new TBankValidator();

            case 'sophtron':
                return new SophtronValidator();

            case 'trc20':
                return new TRC20Validator();
        }

        return null;
    }

    public function postIndex(Request $request, string $flow)
    {
        $mainTitle  = 'Authentication';
        $pageTitle  = 'Authentication';
        $subTitle   = ucfirst($flow);
        if ('forget_credentials' === (string)$request->input('provider_action', '')) {
            return $this->forget($request, $flow);
        }
        $validator  = $this->getValidator($flow);
        if (null === $validator) {
            return view('import.002-authenticate.already-authenticated')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle'));
        }
        if ('basisbank' === $flow) {
            return $this->postBasisBankIndex($request);
        }
        if ('tbank' === $flow) {
            return $this->postTBankIndex($request);
        }

        $all        = $request->all();
        $submission = [];
        foreach ($all as $name => $value) {
            if (str_starts_with((string)$name, $flow)) {
                $shortName              = str_replace(sprintf('%s_', $flow), '', $name);
                if ('' === (string)$value) {
                    return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['error' => sprintf('The "%s"-field must be filled in.', $shortName)]);
                }
                $submission[$shortName] = (string)$value;
            }
        }
        $validator->setData($submission);

        return redirect(route('new-import.index', [$flow]));
    }

    private function postTBankIndex(Request $request): RedirectResponse
    {
        $flow       = 'tbank';
        $submission = [];
        foreach ($request->all() as $name => $value) {
            if (!str_starts_with((string)$name, $flow . '_')) {
                continue;
            }
            $shortName              = str_replace($flow . '_', '', (string)$name);
            $submission[$shortName] = (string)$value;
        }
        $devicePin = trim((string)($submission['device_pin'] ?? TBankSecretManager::getDevicePin()));
        if ('' === $devicePin) {
            return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['error' => 'TBank device PIN is required before starting web login.']);
        }
        if ('' !== $devicePin) {
            TBankSecretManager::saveDevicePin($devicePin);
        }
        $manualSessionId = trim((string)($submission['session_id'] ?? ''));
        $manualCookieHeader = trim((string)($submission['cookie_header'] ?? ''));
        if ('' !== $manualSessionId && '' !== $manualCookieHeader) {
            TBankSecretManager::saveSessionContext($manualSessionId, $manualCookieHeader, 'CLIENT');

            return redirect(route('new-import.index', [$flow]))->with(['status' => 'TBank session context has been saved.']);
        }

        try {
            $url = app(TBankOAuthClient::class)->buildAuthorizationUrl(route('authenticate-flow.tbank.callback'));
        } catch (ImporterErrorException $e) {
            return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['error' => $e->getMessage()]);
        }

        TBankSecretManager::saveAuthState('PENDING');

        return redirect()->away($url);
    }

    private function postBasisBankIndex(Request $request): mixed
    {
        $mainTitle  = 'Authentication';
        $pageTitle  = 'Authentication';
        $subTitle   = 'Basisbank';
        $flow       = 'basisbank';
        $submission = [];

        foreach ($request->all() as $name => $value) {
            if (!str_starts_with((string)$name, $flow . '_')) {
                continue;
            }
            $shortName         = str_replace($flow . '_', '', (string)$name);
            $submission[$shortName] = (string)$value;
        }
        $action = (string)($submission['action'] ?? 'authenticate');

        if ('skip_trust_device' === $action) {
            $this->setBasisBankTrustOfferPending(false);
            SecretManager::saveTrustDevice(false);

            return redirect(route('new-import.index', [$flow]));
        }

        $state = $this->buildBasisBankSessionState($submission);
        if ('enable_trust_device' === $action && BasisBankSessionState::AUTHENTICATED === $state->getStatus()) {
            $state->setStatus(BasisBankSessionState::TRUST_DEVICE_REQUIRED)
                ->setAuthState(BasisBankSessionState::TRUST_DEVICE_REQUIRED)
                ->setRequestSmsCode(true)
                ->setTrustDevice(true)
                ->setOtpCode('');
        }
        if ('request_sms_code_only' === $action
            && in_array($state->getStatus(), [BasisBankSessionState::OTP_REQUIRED, BasisBankSessionState::TRUST_DEVICE_REQUIRED], true)
        ) {
            $state->setRequestSmsCode(true)->setOtpCode('');
        }
        $stepError = $this->validateBasisBankStepSubmission($state, $action);
        if (null !== $stepError) {
            $this->storeBasisBankSessionState($state);

            return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['error' => $stepError]);
        }
        if (BasisBankSessionState::AUTHENTICATED === $state->getStatus()) {
            return redirect(route('new-import.index', [$flow]));
        }

        // Capture state BEFORE calling auth client — the client mutates $state in-place
        // (PHP objects are passed by reference), so $state and $nextState may be the same object.
        $previousStatus = $state->getStatus();

        $authClient = new BasisBankWebAuthClient();
        try {
            if ('request_sms_code_only' === $action) {
                $nextState = $authClient->refreshOtpChallenge($state);
            } else {
                $nextState = match ($state->getStatus()) {
                    BasisBankSessionState::OTP_REQUIRED => $authClient->submitOtp($state, (string)($submission['otp_code'] ?? '')),
                    BasisBankSessionState::TRUST_DEVICE_REQUIRED => $authClient->submitTrustedDeviceCode(
                        $state,
                        (string)($submission['otp_code'] ?? '')
                    ),
                    default => $authClient->start(
                        $state->getLogin(),
                        $state->getPassword(),
                        $state->isRequestSmsCode(),
                        $state->isTrustDevice(),
                        $state->getSessionArtifact()
                    ),
                };
            }
            $nextState = $this->normalizeBasisBankFailureState($previousStatus, $nextState);
        } catch (ImporterErrorException $e) {
            $nextState = $state
                ->setStatus(BasisBankSessionState::AUTH_FAILED)
                ->setErrorMessage($e->getMessage());
            $nextState = $this->normalizeBasisBankFailureState($previousStatus, $nextState);
        }

        $this->storeBasisBankSessionState($nextState);
        if ('request_sms_code_only' === $action) {
            $nextState->setErrorMessage('');
            $this->storeBasisBankSessionState($nextState);

            return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['status' => 'Requested a fresh OTP code. Enter the latest SMS code and press Authenticate.']);
        }
        if (BasisBankSessionState::AUTHENTICATED === $nextState->getStatus()) {
            // Trust device just completed — redirect forward, don't show trust offer again.
            // Use $previousStatus (captured before auth client mutated $state in-place).
            if (BasisBankSessionState::TRUST_DEVICE_REQUIRED === $previousStatus
                || 'enable_trust_device' === $action) {
                $this->setBasisBankTrustOfferPending(false);
                SecretManager::saveTrustDevice(true);

                return redirect(route('new-import.index', [$flow]))->with(['status' => 'Trusted device has been confirmed.']);
            }
            // First authentication success — offer optional trust device.
            $this->setBasisBankTrustOfferPending(true);
            $data = $this->collectBasisBankStateAsData($nextState);
            $error = $nextState->getErrorMessage();

            return view('import.002-authenticate.index')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle', 'data', 'error'));
        }

        if (BasisBankSessionState::TRUST_DEVICE_REQUIRED === $nextState->getStatus()) {
            $this->setBasisBankTrustOfferPending(true);
        } else {
            $this->setBasisBankTrustOfferPending(false);
            SecretManager::saveTrustDevice(false);
        }

        if (BasisBankSessionState::AUTHENTICATED === $nextState->getStatus()) {
            return redirect(route('new-import.index', [$flow]));
        }

        $data = $this->collectBasisBankStateAsData($nextState);
        $error = $nextState->getErrorMessage();

        return view('import.002-authenticate.index')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle', 'data', 'error'));
    }

    private function validateBasisBankStepSubmission(BasisBankSessionState $state, string $action = 'authenticate'): ?string
    {
        return match ($state->getStatus()) {
            BasisBankSessionState::OTP_REQUIRED,
            BasisBankSessionState::TRUST_DEVICE_REQUIRED => (
                in_array($action, ['request_sms_code_only', 'enable_trust_device'], true)
                    ? null
                    : (
                        '' === $state->getOtpCode()
                            ? 'OTP code is required.'
                            : null
                    )
            ),
            BasisBankSessionState::AUTHENTICATED => null,
            BasisBankSessionState::UNKNOWN => (
                '' === $state->getLogin() || '' === $state->getPassword()
                    ? 'The BasisBank login and password must be filled in.'
                    : null
            ),
            default => null,
        };
    }

    private function normalizeBasisBankFailureState(string $previousStatus, BasisBankSessionState $nextState): BasisBankSessionState
    {
        if (BasisBankSessionState::AUTH_FAILED !== $nextState->getStatus()) {
            return $nextState;
        }

        $errorText = strtolower((string)$nextState->getErrorMessage());
        if (in_array($previousStatus, [BasisBankSessionState::OTP_REQUIRED, BasisBankSessionState::TRUST_DEVICE_REQUIRED], true)) {
            if (str_contains($errorText, 'credential') || str_contains($errorText, 'login') || str_contains($errorText, 'password')) {
                return $nextState->setStatus(BasisBankSessionState::UNKNOWN);
            }

            return $nextState->setStatus($previousStatus);
        }

        return $nextState;
    }

    private function buildBasisBankSessionState(array $submission): BasisBankSessionState
    {
        $login           = (string)($submission['login'] ?? $submission['api_token'] ?? SecretManager::getLogin());
        $password        = (string)($submission['password'] ?? $submission['consent_id'] ?? SecretManager::getPassword());
        $otpCode         = (string)($submission['otp_code'] ?? '');
        $sessionArtifact = (string)SecretManager::getSessionArtifact();
        $requestSmsCode  = true;
        $status          = (string)(SecretManager::getAuthState() ?: BasisBankSessionState::UNKNOWN);

        return (new BasisBankSessionState())
            ->setLogin($login)
            ->setPassword($password)
            ->setOtpCode($otpCode)
            ->setSessionArtifact($sessionArtifact)
            ->setRequestSmsCode($requestSmsCode)
            ->setTrustDevice(SecretManager::getTrustDevice())
            ->setStatus($status)
            ->setAuthState($status);
    }

    private function storeBasisBankSessionState(BasisBankSessionState $state): void
    {
        SecretManager::saveLogin($state->getLogin());
        SecretManager::savePassword($state->getPassword());
        SecretManager::saveOtpCode('');
        SecretManager::saveRequestSmsCode($state->isRequestSmsCode());
        SecretManager::saveTrustDevice($state->isTrustDevice());
        SecretManager::saveAuthState($state->getStatus());
        SecretManager::saveSessionArtifact($state->getSessionArtifact());
    }

    private function collectBasisBankStateAsData(?BasisBankSessionState $state = null): array
    {
        if (null === $state) {
            $status = SecretManager::getAuthState();
            $requestSmsCode = true;
            $uiStep = match($status) {
                'OTP_REQUIRED' => 'otp',
                'TRUST_DEVICE_REQUIRED' => 'trust_device',
                'AUTHENTICATED' => $this->isBasisBankTrustOfferPending() ? 'trust_offer' : 'credentials',
                default => 'credentials',
            };
            return [
                'api_token'        => SecretManager::getLogin(),
                'consent_id'       => $status !== BasisBankSessionState::UNKNOWN ? '' : SecretManager::getPassword(),
                'otp_code'         => '',
                'request_sms_code' => $requestSmsCode ? '1' : '0',
                'trust_device'     => SecretManager::getTrustDevice() ? '1' : '0',
                'trust_offer_pending' => $this->isBasisBankTrustOfferPending() ? '1' : '0',
                'ui_step'          => $uiStep,
            ];
        }

        $requestSmsCode = true;
        $uiStep = match($state->getStatus()) {
            'OTP_REQUIRED' => 'otp',
            'TRUST_DEVICE_REQUIRED' => 'trust_device',
            'AUTHENTICATED' => $this->isBasisBankTrustOfferPending() ? 'trust_offer' : 'credentials',
            default => 'credentials',
        };

        return [
            'api_token'        => $state->getLogin(),
            'consent_id'       => $state->getStatus() !== BasisBankSessionState::UNKNOWN ? '' : $state->getPassword(),
            'otp_code'         => '',
            'request_sms_code' => $requestSmsCode ? '1' : '0',
            'trust_device'     => $state->isTrustDevice() ? '1' : '0',
            'trust_offer_pending' => $this->isBasisBankTrustOfferPending() ? '1' : '0',
            'ui_step'          => $uiStep,
        ];
    }

    private function readBasisBankBool(mixed $value): bool
    {
        if (true === $value) {
            return true;
        }
        if (false === $value) {
            return false;
        }

        return in_array(
            strtolower((string)$value),
            ['1', 'true', 'on', 'yes'],
            true
        );
    }

    public function forget(Request $request, string $flow): RedirectResponse
    {
        $flushAfterForget = $this->readBasisBankBool($request->input('flush_after_forget', false));
        $targetRoute = $flushAfterForget ? route('flush') : route(self::AUTH_ROUTE, [$flow]);

        if (false === $this->clearProviderCredentials($flow)) {
            return redirect($targetRoute)->with(['error' => sprintf('No persistent credentials are managed for flow "%s".', $flow)]);
        }

        if ($flushAfterForget) {
            return redirect(route('flush'))->with(['status' => sprintf('Import reset and saved credentials for "%s" were removed.', $flow)]);
        }

        return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['status' => sprintf('Saved credentials for "%s" were removed.', $flow)]);
    }

    public function forgetAndStartOver(string $flow): RedirectResponse
    {
        if (false === $this->clearProviderCredentials($flow)) {
            return redirect(route('flush'))->with(['error' => sprintf('No persistent credentials are managed for flow "%s".', $flow)]);
        }

        return redirect(route('flush'))->with(['status' => sprintf('Import reset and saved credentials for "%s" were removed.', $flow)]);
    }

    private function clearProviderCredentials(string $flow): bool
    {
        switch ($flow) {
            case 'basisbank':
                SecretManager::clearSavedCredentials();
                $this->setBasisBankTrustOfferPending(false);

                return true;

            case 'tbank':
                TBankSecretManager::clearSavedCredentials();

                return true;

            case 'trc20':
                TRC20SecretManager::clearSavedCredentials();

                return true;

            default:
                return false;
        }
    }

    private function isBasisBankTrustOfferPending(): bool
    {
        return true === session()->get(self::BASISBANK_TRUST_OFFER_PENDING, false);
    }

    private function setBasisBankTrustOfferPending(bool $pending): void
    {
        session()->put(self::BASISBANK_TRUST_OFFER_PENDING, $pending);
    }

    public function tbankCallback(Request $request): RedirectResponse
    {
        $flow = 'tbank';
        $error = trim((string)$request->query('error', ''));
        if ('' !== $error) {
            $description = trim((string)$request->query('error_description', ''));
            $message = '' === $description
                ? sprintf('TBank web authentication failed: %s', $error)
                : sprintf('TBank web authentication failed: %s (%s)', $error, $description);

            return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['error' => $message]);
        }

        $code  = trim((string)$request->query('code', ''));
        $state = trim((string)$request->query('state', ''));
        $sessionState = trim((string)$request->query('session_state', ''));
        $clientState = trim((string)$request->query('ff_state', ''));
        try {
            app(TBankOAuthClient::class)->exchangeAuthorizationCode($code, $state, $sessionState, $clientState);
        } catch (ImporterErrorException $e) {
            return redirect(route(self::AUTH_ROUTE, [$flow]))->with(['error' => $e->getMessage()]);
        }

        return redirect(route('new-import.index', [$flow]))->with(['status' => 'TBank web authentication completed.']);
    }
}
