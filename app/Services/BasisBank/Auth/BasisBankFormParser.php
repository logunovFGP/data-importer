<?php

/*
 * BasisBankFormParser.php
 */

declare(strict_types=1);

namespace App\Services\BasisBank\Auth;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;

class BasisBankFormParser
{
    public const string LOGIN_PAGE_URL            = '/Login.aspx';
    public const string LOGIN_FIELD               = 'ctl00$ContentPlaceHolder1$UTXT';
    public const string PASSWORD_FIELD            = 'ctl00$ContentPlaceHolder1$PTXT';
    public const string LOGIN_EVENT_TARGET        = 'ctl00$ContentPlaceHolder1$LoginLBTN';
    public const string OTP_FIELD                 = 'ctl00$ContentPlaceHolder1$OptCodeTxt';
    public const string TRUST_FIRST_CONFIRM_FIELD  = 'ctl00$Content$ctl03';
    public const string TRUST_OTP_FIELD           = 'ctl00$Content$TrustedDeviceOTP$TxtOtpCode';
    public const string TRUST_SECOND_CONFIRM_FIELD = 'ctl00$Content$ctl06';
    public const string EVENT_TARGET_FIELD        = '__EVENTTARGET';
    public const string EVENT_ARGUMENT_FIELD      = '__EVENTARGUMENT';

    // Device fingerprint defaults matching ZenPlugins reference (fillDeviceInfoFields).
    private const string DEVICE_BROWSER_TYPE     = 'Chrome';
    private const string DEVICE_BROWSER_VERSION  = '145.0';
    private const string DEVICE_PLATFORM_TYPE    = 'Windows';
    private const string DEVICE_PLATFORM_VERSION = '10';
    private const string DEVICE_THREAD_COUNT     = '8';
    private const string DEVICE_GPU_INFO         = 'ANGLE (Intel, Intel(R) UHD Graphics 620 Direct3D11 vs_5_0 ps_5_0, D3D11)';
    private const string DEVICE_ID_FILE           = 'basisbank_device_id';

    private static ?string $cachedDeviceId = null;

    public function getFormFieldsFromLoginPage(string $html): array
    {
        $fields = $this->extractLoginFormFields($html);
        if (0 === count($fields)) {
            return [];
        }

        return $fields;
    }


    public function isLoginForm(string $html): bool
    {
        $fields = $this->extractLoginFormFields($html);
        return isset($fields[self::LOGIN_FIELD], $fields[self::PASSWORD_FIELD]);
    }

    public function isOtpChallenge(string $html): bool
    {
        if (preg_match('/id=["\']ContentPlaceHolder1_OTPP["\']/i', $html) === 1
            || preg_match('/id=["\']ContentPlaceHolder1_OTPBoXFieldP["\']/i', $html) === 1) {
            return true;
        }
        if (str_contains($html, 'OptCodeTxt')) {
            return true;
        }

        $fields = $this->extractFields($html);
        return isset($fields[self::OTP_FIELD]);
    }

    public function isTrustedDeviceChallenge(string $html): bool
    {
        // Quick substring pre-check (matches ZenPlugins ensureTrustedDevice guard).
        if (!str_contains($html, 'TrustedDevice') && !str_contains($html, self::TRUST_FIRST_CONFIRM_FIELD)) {
            return false;
        }

        // Confirm via actual form field extraction to avoid false positives
        // from TrustedDevice appearing in scripts, viewstate, or comments.
        $fields = $this->extractFields($html);
        return isset($fields[self::TRUST_FIRST_CONFIRM_FIELD])
            || $this->findField($fields, '/TrustedDeviceOTP\\$TxtOtpCode$/i') !== null
            || $this->findField($fields, '/\\$ctl06$/') !== null;
    }

    public function extractError(string $html): ?string
    {
        $fields = ['errorfield', 'ContentPlaceHolder1_errorfield', 'validation-summary-errors', 'error', 'errortext'];
        $document = $this->toDomDocument($html);
        if (null === $document) {
            return null;
        }
        $xpath  = new DOMXPath($document);
        foreach ($fields as $field) {
            $nodes = $xpath->query(sprintf('//*[contains(@class, "%s") or @id = "%s"]', $field, $field));
            foreach ($nodes as $node) {
                $text = trim((string)$node->textContent);
                if ('' !== $text) {
                    return $text;
                }
            }
        }

        return null;
    }

    public function buildSubmitPayload(
        array $fields,
        string $login,
        string $password,
        ?string $otpCode = null,
        bool $enableTrust = false,
        bool $requestSms = false
    ): array {
        $payload        = $fields;
        $payload[self::LOGIN_FIELD]    = $login;
        $payload[self::PASSWORD_FIELD] = $password;
        $payload[self::EVENT_TARGET_FIELD]    = self::LOGIN_EVENT_TARGET;
        $payload[self::EVENT_ARGUMENT_FIELD]  = '';

        if ($otpCode !== null && '' !== $otpCode) {
            if (isset($payload[self::OTP_FIELD])) {
                $payload[self::OTP_FIELD] = $otpCode;
            } else {
                $otpField = $this->findField($payload, '/Opt(Code)?Txt$/i');
                if (null !== $otpField) {
                    $payload[$otpField] = $otpCode;
                }
            }
        }

        if ($enableTrust && isset($payload[self::TRUST_FIRST_CONFIRM_FIELD])) {
            $payload[self::TRUST_FIRST_CONFIRM_FIELD] = 'Yes';
        }

        if ($requestSms === false && isset($payload['IsNewLogin'])) {
            $payload['IsNewLogin'] = 'false';
        }

        // Trust device fields are only relevant when the trust flow is active.
        // Setting them during regular login leaks the login OTP into device binding fields.
        if ($enableTrust) {
            $trustedOtpField = $this->findField($payload, '/TrustedDeviceOTP\\$TxtOtpCode$/i');
            if (null !== $trustedOtpField && $otpCode !== null && '' !== $otpCode) {
                $payload[$trustedOtpField] = $otpCode;
            }
            $trustedConfirmField = $this->findField($payload, '/\\$ctl06$/');
            if (null !== $trustedConfirmField) {
                $payload[$trustedConfirmField] = 'Yes';
            }
        }

        return $payload;
    }

    /**
     * Populate device fingerprint fields in form data.
     *
     * BasisBank injects hidden inputs matching patterns like *deviceInfoBrowserType,
     * *deviceInfoPlatformType, *device*(id|uuid|guid|fingerprint)*, etc.
     * If these are submitted empty, the server may silently reject device binding.
     * Mirrors ZenPlugins fillDeviceInfoFields() from fetchApi.ts.
     */
    public function fillDeviceInfoFields(array $fields): array
    {
        $result = $fields;
        foreach (array_keys($result) as $key) {
            $keyStr = (string)$key;
            if ('' !== $result[$key]) {
                continue;
            }
            if (preg_match('/deviceInfoBrowserType$/i', $keyStr) === 1) {
                $result[$key] = self::DEVICE_BROWSER_TYPE;
            } elseif (preg_match('/deviceInfoBrowserVersion$/i', $keyStr) === 1) {
                $result[$key] = self::DEVICE_BROWSER_VERSION;
            } elseif (preg_match('/deviceInfoPlatformType$/i', $keyStr) === 1) {
                $result[$key] = self::DEVICE_PLATFORM_TYPE;
            } elseif (preg_match('/deviceInfoPlatformVersion$/i', $keyStr) === 1) {
                $result[$key] = self::DEVICE_PLATFORM_VERSION;
            } elseif (preg_match('/deviceInfoThreadCount$/i', $keyStr) === 1) {
                $result[$key] = self::DEVICE_THREAD_COUNT;
            } elseif (preg_match('/deviceInfoGPUInfo$/i', $keyStr) === 1) {
                $result[$key] = self::DEVICE_GPU_INFO;
            } elseif (preg_match('/(device.*(id|uuid|guid|fingerprint)|fingerprint.*device)/i', $keyStr) === 1) {
                $result[$key] = self::getOrCreateDeviceId();
            }
        }

        return $result;
    }

    /**
     * Get or create a persistent per-installation device UUID.
     * Stored in storage/app/basisbank_device_id. Matches ZenPlugins' Auth.deviceId pattern.
     */
    private static function getOrCreateDeviceId(): string
    {
        if (null !== self::$cachedDeviceId) {
            return self::$cachedDeviceId;
        }

        $path = storage_path('app/' . self::DEVICE_ID_FILE);
        if (file_exists($path)) {
            $id = trim((string)file_get_contents($path));
            if ('' !== $id) {
                self::$cachedDeviceId = $id;
                return $id;
            }
        }

        // Generate UUID v4
        $id = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $written = file_put_contents($path, $id);
        if (false === $written) {
            Log::warning(sprintf('BasisBank: could not write device ID file: %s', $path));
        } else {
            chmod($path, 0600);
        }

        self::$cachedDeviceId = $id;
        return $id;
    }

    public function findField(array $fields, string $regex): ?string
    {
        foreach (array_keys($fields) as $name) {
            if (preg_match($regex, (string)$name) === 1) {
                return (string)$name;
            }
        }

        return null;
    }

    private function extractLoginFormFields(string $html): array
    {
        $forms = $this->extractForms($html);
        if (empty($forms)) {
            return [];
        }

        $best = [];
        foreach ($forms as $form) {
            $fields = $this->extractFieldsFromForm($form);
            if (isset($fields[self::LOGIN_FIELD], $fields[self::PASSWORD_FIELD])) {
                return $fields;
            }
            if (count($fields) > count($best)) {
                $best = $fields;
            }
        }

        return $best;
    }

    private function extractForms(string $html): array
    {
        $document = $this->toDomDocument($html);
        if (null === $document) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form');
        if (false === $forms || 0 === $forms->count()) {
            return [];
        }

        $result = [];
        foreach ($forms as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }
            $result[] = $form;
        }

        return $result;
    }

    private function extractFieldsFromForm(DOMElement $form): array
    {
        $fields = [];
        $dom    = $form->ownerDocument;
        if (!$dom instanceof DOMDocument) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $form);
        if (false === $elements) {
            return [];
        }

        foreach ($elements as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = trim((string)$node->getAttribute('name'));
            if ('' === $name) {
                continue;
            }

            $type = strtolower((string)$node->getAttribute('type'));
            // ASP.NET WebForms: match real browser behavior — only send checked checkboxes. Verified via HAR recordings.
            if (in_array($type, ['checkbox', 'radio'], true) && !$node->hasAttribute('checked')) {
                continue;
            }
            $value = trim((string)$node->getAttribute('value'));
            if ('select' === strtolower((string)$node->nodeName) && '' === $value) {
                foreach ($node->getElementsByTagName('option') as $option) {
                    $selected = $option->getAttribute('selected');
                    if ('' !== (string)$selected) {
                        $value = trim((string)$option->getAttribute('value'));
                        break;
                    }
                }
            }
            $fields[$name] = $value;
        }

        return $fields;
    }

    private function extractFields(string $html): array
    {
        $document = $this->toDomDocument($html);
        if (null === $document) {
            return [];
        }
        $xpath   = new DOMXPath($document);
        $fields = [];
        $elements = $xpath->query('//input[@name] | //select[@name] | //textarea[@name]');
        if (false === $elements) {
            return [];
        }

        foreach ($elements as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = trim((string)$node->getAttribute('name'));
            if ('' === $name) {
                continue;
            }
            $type = strtolower((string)$node->getAttribute('type'));
            // ASP.NET WebForms: match real browser behavior — only send checked checkboxes. Verified via HAR recordings.
            if (in_array($type, ['checkbox', 'radio'], true) && !$node->hasAttribute('checked')) {
                continue;
            }
            $fields[$name] = trim((string)$node->getAttribute('value'));
        }

        return $fields;
    }

    private function toDomDocument(string $html): ?DOMDocument
    {
        $useInternalErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded   = @ $document->loadHTML($html);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
            return null;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        return $document;
    }
}
