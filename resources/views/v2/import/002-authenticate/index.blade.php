@extends('layout.v2')
@section('content')
    @php
        $basisBankStep = $data['ui_step'] ?? 'credentials';
        $basisBankTrustOfferPending = '1' === (string)($data['trust_offer_pending'] ?? '0');
        $basisBankOtpEmpty = '' === trim((string)($data['otp_code'] ?? ''));
        $basisBankOtpStep = in_array($basisBankStep, ['otp', 'trust_device'], true);
        $basisBankSessionGuidance = match($basisBankStep) {
            'otp' => 'OTP required. Enter the one-time code from SMS or authenticator.',
            'trust_device' => 'Trusted-device setup in progress. Enter the trusted-device code from SMS.',
            'trust_offer' => 'Authentication completed. You can optionally enable trusted device now, or skip to continue import.',
            default => '',
        };
        $basisBankErrorText = strtolower((string)($error ?? ''));
        $basisBankErrorHint = '';

        if ('' !== ($error ?? '')) {
            if (str_contains($basisBankErrorText, 'otp') || str_contains($basisBankErrorText, 'one-time')) {
                $basisBankErrorHint = trans('import.basisbank_guidance_invalid_otp');
            }
            if ('' === $basisBankErrorHint && (str_contains($basisBankErrorText, 'trusted') || str_contains($basisBankErrorText, 'trust'))) {
                $basisBankErrorHint = trans('import.basisbank_guidance_trust_device_required');
            }
            if ('' === $basisBankErrorHint && (str_contains($basisBankErrorText, 'expired') || str_contains($basisBankErrorText, 'session'))) {
                $basisBankErrorHint = trans('import.basisbank_guidance_session_expired');
            }
            if ('' === $basisBankErrorHint && (str_contains($basisBankErrorText, 'password') || str_contains($basisBankErrorText, 'login') || str_contains($basisBankErrorText, 'credential'))) {
                $basisBankErrorHint = trans('import.basisbank_guidance_invalid_credentials');
            }
            if ('' === $basisBankErrorHint && '' !== $basisBankErrorText) {
                $basisBankErrorHint = trans('import.basisbank_guidance_generic');
            }
        }
    @endphp
    <div class="container">
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1>{{ $mainTitle }}</h1>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        {{ $subTitle }}
                    </div>
                    <div class="card-body">
                        <p>In order to import using {{ config('importer.providers.' . $flow . '.title') }} you must enter the authentication data you received from this provider. You can read how to get the necessary codes in the <a target="_blank" href="https://docs.firefly-iii.org/how-to/data-importer/import/third-party-providers/">documentation</a></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Form
                    </div>
                    <div class="card-body">
                        @if('' !== ($error ?? ''))
                            <p class="text-danger">{{ $error }}</p>
                        @endif
                        @if('' !== (string)session('status', ''))
                            <p class="text-success">{{ session('status') }}</p>
                        @endif
                        @if($basisBankSessionGuidance !== '')
                            <div class="alert alert-info">{{ $basisBankSessionGuidance }}</div>
                        @endif
                        @if($basisBankErrorHint !== '')
                            <div class="alert alert-warning">{{ $basisBankErrorHint }}</div>
                        @endif

                        <form method="post" action="{{ route('authenticate-flow.post', [$flow]) }}" accept-charset="UTF-8">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>

                            @php
                                $isBasisBank = ('basisbank' === $flow);
                                $basisBankHiddenFields = ['trust_device', 'trust_offer_pending'];
                            @endphp
                            @if($isBasisBank && 'trust_offer' === $basisBankStep)
                                <input type="hidden" name="basisbank_action" value="skip_trust_device">
                                <input type="hidden" name="basisbank_request_sms_code" value="1">
                                <div class="alert alert-info">
                                    Trusted Device is optional and handled as a separate challenge. Choose one action:
                                </div>
                                <div class="btn-group float-end">
                                    <button type="submit" class="btn btn-outline-secondary" name="basisbank_action" value="skip_trust_device">
                                        Skip and continue
                                    </button>
                                    <button type="submit" class="btn btn-primary" name="basisbank_action" value="enable_trust_device">
                                        Enable trusted device
                                    </button>
                                </div>
                            @elseif('tbank' === $flow)
                                @php
                                    $tbankDevicePin = (string)($data['device_pin'] ?? '');
                                    $tbankAuthState = strtoupper(trim((string)($data['auth_state'] ?? '')));
                                    $tbankSessionReady = in_array($tbankAuthState, ['AUTHENTICATED', 'CLIENT', 'FULL'], true);
                                @endphp
                                <div class="alert alert-info">
                                    {{ trans('import.tbank_auth_intro') }}
                                </div>
                                @if($tbankSessionReady)
                                    <div class="alert alert-success">
                                        {{ trans('import.tbank_session_detected') }}
                                    </div>
                                @else
                                    <div class="alert alert-warning">
                                        {{ trans('import.tbank_session_missing') }}
                                    </div>
                                @endif
                                <div class="form-group row">
                                    <label for="tbank_device_pin" class="col-sm-3 col-form-label">
                                        {{ trans('import.label_tbank_device_pin') }}
                                    </label>
                                    <div class="col-sm-9">
                                        <input type="text"
                                               name="tbank_device_pin"
                                               class="form-control"
                                               id="tbank_device_pin"
                                               placeholder="{{ trans('import.placeholder_tbank_device_pin') }}"
                                               value="{{ $tbankDevicePin }}"
                                               required
                                               aria-describedby="tbank_device_pin_help">
                                        <small id="tbank_device_pin_help" class="form-text text-muted">
                                            {{ trans('import.help_tbank_device_pin') }}
                                        </small>
                                    </div>
                                </div>
                                <div class="float-end">
                                    <button type="submit" class="btn btn-primary">
                                        {{ trans('import.tbank_button_start_web_login') }} &rarr;
                                    </button>
                                </div>
                            @else
                                @foreach($data as $key => $value)
                                    @php
                                        $normalizedBasisBankField = $key;
                                        if ($isBasisBank) {
                                            $normalizedBasisBankField = match($key) {
                                                'api_token' => 'login',
                                                'consent_id' => 'password',
                                                default => $key,
                                            };
                                        }
                                        if ('tbank' === $flow) {
                                            $normalizedBasisBankField = match($key) {
                                                'login' => 'api_token',
                                                default => $normalizedBasisBankField,
                                            };
                                        }
                                        $fieldName = sprintf('%s_%s', $flow, $normalizedBasisBankField);
                                        if ($isBasisBank && in_array($key, ['api_token', 'consent_id'], true)) {
                                            $fieldName = sprintf('%s_%s', $flow, $key);
                                        }
                                        $basisBankFieldVisible = match ($basisBankStep) {
                                            'credentials' => in_array($normalizedBasisBankField, ['login', 'password', 'request_sms_code'], true),
                                            default => in_array($normalizedBasisBankField, ['otp_code'], true),
                                        };
                                        if ($isBasisBank && !$basisBankFieldVisible) {
                                            continue;
                                        }
                                        $isCheckbox = $isBasisBank && in_array($normalizedBasisBankField, ['request_sms_code'], true);
                                        $isMandatorySmsCheckbox = $isBasisBank && ('request_sms_code' === $normalizedBasisBankField);
                                        $isSecret = $isBasisBank && in_array($normalizedBasisBankField, ['password', 'otp_code'], true);
                                        $translationKey = $normalizedBasisBankField;
                                    @endphp

                                    @if($isBasisBank && in_array($normalizedBasisBankField, $basisBankHiddenFields, true))
                                        <input type="hidden" name="{{ $fieldName }}" value="{{ $value }}">
                                        @continue
                                    @endif

                                    @if($isCheckbox)
                                        <div class="form-group row">
                                            <div class="col-sm-9 offset-sm-3">
                                                <div class="form-check">
                                                    @if($isMandatorySmsCheckbox)
                                                        <input type="hidden" name="{{ $fieldName }}" value="1">
                                                    @else
                                                        <input type="hidden" name="{{ $fieldName }}" value="0">
                                                    @endif
                                                    <input class="form-check-input"
                                                           type="checkbox"
                                                           name="{{ $fieldName }}"
                                                           id="{{ sprintf('%s_%s', $flow, $normalizedBasisBankField) }}"
                                                           value="1"
                                                           @if($isMandatorySmsCheckbox || '1' === (string)$value || 'true' === strtolower((string)$value) || 'on' === (string)$value) checked @endif
                                                           @if($isMandatorySmsCheckbox) disabled @endif
                                                           aria-describedby="{{ sprintf('%s_%s', $flow, $normalizedBasisBankField) }}_help">
                                                    <label class="form-check-label"
                                                           for="{{ sprintf('%s_%s', $flow, $normalizedBasisBankField) }}">
                                                        {{ trans(sprintf('import.label_%s_%s', $flow, $translationKey)) }}
                                                        @if($isMandatorySmsCheckbox)
                                                            <span class="text-muted">(required)</span>
                                                        @endif
                                                    </label>
                                                    <small id="{{ sprintf('%s_%s', $flow, $normalizedBasisBankField) }}_help"
                                                           class="form-text text-muted">
                                                        <br>{{ trans(sprintf('import.help_%s_%s', $flow, $translationKey)) }}
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        @continue
                                    @endif

                                    <div class="form-group row">
                                        <label for="{{ $fieldName }}" class="col-sm-3 col-form-label">
                                            {{ trans(sprintf('import.label_%s_%s', $flow, $translationKey)) }}
                                        </label>
                                        <div class="col-sm-9">
                                            <input type="{{ $isSecret ? 'password' : 'text' }}"
                                                   name="{{ $fieldName }}"
                                                   class="form-control"
                                                   id="{{ sprintf('%s_%s', $flow, $normalizedBasisBankField) }}"
                                                   placeholder="{{ trans(sprintf('import.placeholder_%s_%s', $flow, $translationKey)) }}"
                                                   value="{{ $value }}"
                                                   aria-describedby="{{ sprintf('%s_%s', $flow, $normalizedBasisBankField) }}_help">
                                            <small id="{{ sprintf('%s_%s', $flow, $normalizedBasisBankField) }}_help" class="form-text text-muted">
                                                {{ trans(sprintf('import.help_%s_%s', $flow, $translationKey)) }}
                                            </small>
                                        </div>
                                        @if($isBasisBank && 'otp_code' === $normalizedBasisBankField)
                                            <small class="form-text text-muted mt-1">You may get this code from SMS or a time-based authenticator.</small>
                                            @if($basisBankOtpStep)
                                                <small class="form-text text-muted mt-1">Need a new code? Use the <strong>Request SMS code again</strong> button.</small>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                                <div class="float-end">
                                    @if($isBasisBank && $basisBankOtpStep)
                                        <button type="submit"
                                                class="btn btn-outline-secondary me-2"
                                                name="basisbank_action"
                                                value="request_sms_code_only">
                                            Request SMS code again
                                        </button>
                                    @endif
                                    <button type="submit"
                                            id="basisbank_authenticate_button"
                                            class="btn @if($isBasisBank && $basisBankOtpStep && $basisBankOtpEmpty) btn-secondary @else btn-primary @endif"
                                            @if($isBasisBank) name="basisbank_action" value="authenticate" @endif
                                            @if($isBasisBank && $basisBankOtpStep && $basisBankOtpEmpty) disabled @endif>
                                        Authenticate &rarr;
                                    </button>
                                </div>
                            @endif
                        </form>
                        @php
                            $isProviderWithSecrets = in_array($flow, ['basisbank', 'tbank', 'trc20'], true);
                            $forgetAction = route('authenticate-flow.post', [$flow]);
                        @endphp
                        @if($isProviderWithSecrets)
                            <form method="post" action="{{ $forgetAction }}" accept-charset="UTF-8" class="mt-3">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                <input type="hidden" name="provider_action" value="forget_credentials"/>
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    Forget saved credentials
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-body">
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('index') }}" class="btn btn-secondary"><span
                                    class="fas fa-arrow-left"></span> Go back to index</a>
                            @if($isProviderWithSecrets)
                                <form method="post" action="{{ $forgetAction }}" accept-charset="UTF-8" class="d-inline">
                                    <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                    <input type="hidden" name="provider_action" value="forget_credentials"/>
                                    <input type="hidden" name="flush_after_forget" value="1"/>
                                    <button type="submit" class="btn btn-danger text-white btn-sm">
                                        <span class="fas fa-redo-alt"></span> Start over
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('flush') }}" class="btn btn-danger text-white btn-sm"><span
                                        class="fas fa-redo-alt"></span> Start over</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @if('basisbank' === $flow && $basisBankOtpStep)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const otpInput = document.getElementById('basisbank_otp_code');
                const authButton = document.getElementById('basisbank_authenticate_button');
                if (!otpInput || !authButton) {
                    return;
                }
                const syncAuthButton = function () {
                    const hasOtp = (otpInput.value || '').trim().length > 0;
                    authButton.disabled = !hasOtp;
                    authButton.classList.toggle('btn-secondary', !hasOtp);
                    authButton.classList.toggle('btn-primary', hasOtp);
                };
                otpInput.addEventListener('input', syncAuthButton);
                otpInput.addEventListener('change', syncAuthButton);
                syncAuthButton();
            });
        </script>
    @endif
@endsection

