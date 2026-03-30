<?php

/*
 * BasisBankSessionState.php
 */

declare(strict_types=1);

namespace App\Services\BasisBank\Auth;

/**
 * Data carrier for BasisBank web authentication state.
 *
 * @mutable This class is intentionally mutable. It is passed by reference across
 *          the multi-step auth flow (login -> OTP -> trust device). Making it
 *          immutable would require rewriting the entire auth state machine.
 *          See BasisBankWebAuthClient for the mutation call-sites.
 */
class BasisBankSessionState
{
    public const string AUTHENTICATED         = 'AUTHENTICATED';
    public const string OTP_REQUIRED          = 'OTP_REQUIRED';
    public const string TRUST_DEVICE_REQUIRED = 'TRUST_DEVICE_REQUIRED';
    public const string AUTH_FAILED           = 'AUTH_FAILED';
    public const string UNKNOWN               = 'UNKNOWN';

    private string $status = self::UNKNOWN;
    private string $login = '';
    private string $password = '';
    private string $otpCode = '';
    private string $sessionArtifact = '';
    private string $authState = '';
    private bool $requestSmsCode = true;
    private bool $trustDevice = false;
    private string $errorMessage = '';

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @mutable Mutates in-place — callers hold the same reference. */
    public function setStatus(string $status): self
    {
        $this->status = trim($status);

        return $this;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function setLogin(string $login): self
    {
        $this->login = trim($login);

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = trim($password);

        return $this;
    }

    public function getOtpCode(): string
    {
        return $this->otpCode;
    }

    public function setOtpCode(string $otpCode): self
    {
        $this->otpCode = trim($otpCode);

        return $this;
    }

    public function getSessionArtifact(): string
    {
        return $this->sessionArtifact;
    }

    /** @mutable Mutates in-place — callers hold the same reference. */
    public function setSessionArtifact(string $sessionArtifact): self
    {
        $this->sessionArtifact = trim($sessionArtifact);

        return $this;
    }

    public function getAuthState(): string
    {
        return $this->authState;
    }

    /** @mutable Mutates in-place — callers hold the same reference. */
    public function setAuthState(string $authState): self
    {
        $this->authState = trim($authState);

        return $this;
    }

    public function isRequestSmsCode(): bool
    {
        return $this->requestSmsCode;
    }

    public function setRequestSmsCode(bool $requestSmsCode): self
    {
        $this->requestSmsCode = $requestSmsCode;

        return $this;
    }

    public function isTrustDevice(): bool
    {
        return $this->trustDevice;
    }

    public function setTrustDevice(bool $trustDevice): self
    {
        $this->trustDevice = $trustDevice;

        return $this;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /** @mutable Mutates in-place — callers hold the same reference. */
    public function setErrorMessage(string $errorMessage): self
    {
        $this->errorMessage = trim($errorMessage);

        return $this;
    }

    public function clearErrorMessage(): self
    {
        $this->errorMessage = '';

        return $this;
    }

    public function hasOtpCode(): bool
    {
        return '' !== $this->otpCode;
    }

    public function requiresOtp(): bool
    {
        return self::OTP_REQUIRED === $this->status;
    }

    public function requiresTrustedDevice(): bool
    {
        return self::TRUST_DEVICE_REQUIRED === $this->status;
    }

    public function isAuthenticated(): bool
    {
        return self::AUTHENTICATED === $this->status;
    }

    public function toArray(): array
    {
        return [
            'status'           => $this->status,
            'auth_state'       => $this->authState,
            'login'            => $this->login,
            'password'         => $this->password,
            'otp_code'         => $this->otpCode,
            'session_artifact' => $this->sessionArtifact,
            'request_sms_code' => $this->requestSmsCode,
            'trust_device'     => $this->trustDevice,
            'error_message'    => $this->errorMessage,
        ];
    }
}
