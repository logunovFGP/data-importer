<div class="d-flex align-items-start p-3 rounded mb-2 border border-secondary">
    <div class="form-check me-3">
        <input
            id="do_import_{{ $account['import_account']->id }}"
            type="checkbox"
            name="do_import[{{ $account['import_account']->id }}]"
            value="1"
            class="form-check-input"
            aria-describedby="accountsHelp"
            @if('disabled' === $account['import_account']->status) disabled="disabled" @endif
            @php
                $accountId = $account['import_account']->id;
                $configuredValue = $configuration->getAccounts()[$accountId] ?? null;
                $allAccounts = $configuration->getAccounts();
                $mappedTo = $account['mapped_to'] ?? null;

                // Check if account should be checked:
                // 1. If configured explicitly (any non-null value including 0 for "create new")
                // 2. If no configuration exists yet - use sensible defaults
                $shouldCheck = false;

                if ($configuredValue !== null && $configuredValue !== '') {
                    // Explicitly configured (including 0 for "create new")
                    $shouldCheck = true;
                } elseif (empty($allAccounts)) {
                    // No configuration yet - use sensible defaults
                    // Check if there's an automatic mapping
                    $shouldCheck = true;
//                    if ($mappedTo !== null) {
//                        $shouldCheck = true; // Auto-mapped accounts should be checked
//                    } else {
//                        $shouldCheck = true; // Default to checked for user convenience
//                    }
                }
            @endphp
            @if($shouldCheck) checked="checked" @endif
        />
    </div>

    <div class="flex-grow-1">
        <label
            class="form-check-label d-block mb-2"
            for="do_import_{{ $account['import_account']->id }}"
            @if('' !== $account['import_account']->iban) title="IBAN: {{ $account['import_account']->iban }}" @endif
        >
            <div class="d-flex align-items-center mb-1">
                <span class="fw-bold fs-6">{{ $account['import_account']->name ?? 'Unnamed account' }}</span>
            </div>
            @if(isset($account['import_account']->org) && is_array($account['import_account']->org) && !empty($account['import_account']->org['name']))
                <div class="text-muted small">
                    <i class="fas fa-building me-1"></i>
                    {{ $account['import_account']->org['name'] }}
                </div>
            @endif
        </label>

        @if(isset($account['import_account']->balance))
        <div class="mb-2">
            <i class="fas fa-coins me-1"></i>
            <span class="badge bg-secondary text-light px-3 py-1 fw-bold">
                {{ number_format((float)$account['import_account']->balance, 2) }} {{ $account['import_account']->currency ?? '' }}
            </span>
            @if(isset($account['import_account']->balance_date) && $account['import_account']->balance_date)
                <small class="text-muted ms-2">({{ date('M j, Y', (int)$account['import_account']->balance_date) }})</small>
            @endif
        </div>
        @endif

        @if(isset($account['import_account']->available_balance) && $account['import_account']->available_balance !== ($account['import_account']->balance ?? null))
        <div class="mb-2">
            <i class="fas fa-wallet me-1"></i>
            <span class="badge bg-secondary text-light px-3 py-1 fw-bold">
                {{ number_format((float)$account['import_account']->available_balance, 2) }} {{ $account['import_account']->currency ?? '' }}
            </span>
        </div>
        @endif

        <div class="d-flex align-items-center justify-content-between">
            <small class="text-muted">
                <i class="fas fa-id-card me-1"></i>
                <code class="text-muted">{{ $account['import_account']->id ?? 'N/A' }}</code>
            </small>
            @if('disabled' === $account['import_account']->status)
                <small class="text-warning">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Disabled
                </small>
            @endif
        </div>
        @php
            $accountIban = trim((string)($account['import_account']->iban ?? ''));
            if ('' === $accountIban) {
                $accountIban = trim((string)(($account['import_account']->extra['IBAN'] ?? '') ?: ''));
            }
            $accountNumber = trim((string)($account['import_account']->bban ?? ''));
            if ('' === $accountNumber) {
                $accountNumber = trim((string)(($account['import_account']->extra['BBAN'] ?? '') ?: ''));
            }
        @endphp
        @if('' !== $accountIban || '' !== $accountNumber)
            <div class="mt-2 small text-muted">
                @if('' !== $accountIban)
                    <div><strong>IBAN:</strong> <code>{{ $accountIban }}</code></div>
                @endif
                @if('' !== $accountNumber)
                    <div><strong>Account number:</strong> <code>{{ $accountNumber }}</code></div>
                @endif
            </div>
        @endif

        {{-- Display 'mapped_to' if available --}}
        {{-- Display 'extra' fields if any --}}
        @php
            $extraData = (array)($account['import_account']->extra ?? []);
            $mergedSourcesRaw = $extraData['Merged sources'] ?? [];
            $mergedSources = [];
            if (is_array($mergedSourcesRaw)) {
                foreach ($mergedSourcesRaw as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $mergedSources[] = [
                        'id'       => trim((string)($entry['id'] ?? '')),
                        'base_id'  => trim((string)($entry['base_id'] ?? '')),
                        'kind'     => strtolower(trim((string)($entry['kind'] ?? 'account'))),
                        'currency' => strtoupper(trim((string)($entry['currency'] ?? ''))),
                        'name'     => trim((string)($entry['name'] ?? '')),
                        'iban'     => trim((string)($entry['iban'] ?? '')),
                        'bban'     => trim((string)($entry['bban'] ?? '')),
                    ];
                }
            }
        @endphp
        @if(count($mergedSources) > 1)
            <div class="mt-2 pt-2 border-top border-secondary">
                <div class="small fw-bold text-muted mb-2">Combined source group</div>
                <div class="border rounded p-2">
                    @foreach($mergedSources as $source)
                        <div class="border rounded bg-light p-2">
                            <div class="small fw-semibold">
                                {{ 'card' === ($source['kind'] ?? '') ? 'Card source' : 'Account source' }}
                                @if('' !== ($source['currency'] ?? ''))
                                    ({{ $source['currency'] }})
                                @endif
                            </div>
                            @if('' !== ($source['name'] ?? ''))
                                <div class="small text-muted">{{ $source['name'] }}</div>
                            @endif
                            <div class="small text-muted">
                                @if('' !== ($source['base_id'] ?? ''))
                                    Base ID: <code>{{ $source['base_id'] }}</code>
                                @elseif('' !== ($source['id'] ?? ''))
                                    ID: <code>{{ $source['id'] }}</code>
                                @endif
                                @if('' !== ($source['iban'] ?? ''))
                                    <span class="ms-2">IBAN: <code>{{ $source['iban'] }}</code></span>
                                @elseif('' !== ($source['bban'] ?? ''))
                                    <span class="ms-2">BBAN: <code>{{ $source['bban'] }}</code></span>
                                @endif
                            </div>
                        </div>
                        @if(!$loop->last)
                            <div class="text-center fw-bold my-1">+</div>
                        @endif
                    @endforeach
                </div>
                <div class="small text-muted mt-1">
                    Sources above are grouped and mapped as one import stream to the Firefly III account on the right.
                </div>
            </div>
        @endif
        @if(count($extraData) > 0)
            <div class="mt-2 pt-2 border-top border-secondary">
                @foreach($extraData as $key => $item)
                    @if(!in_array((string)$key, ['Merged sources', 'Merged source count'], true) && !empty($item) && is_scalar($item))
                    <div class="d-flex justify-content-between align-items-center small text-muted mb-1">
                        <span>{{ ucfirst(str_replace(['_', '-'], ' ', $key)) }}:</span>
                        <span>{{ $item }}</span>
                    </div>
                    @endif
                @endforeach
            </div>
        @endif

        @php
            $preflightMap = is_array($currencyPreflight ?? null) ? $currencyPreflight : [];
            $preflight = $preflightMap[$accountId] ?? null;
        @endphp
        @if(is_array($preflight))
            @php
                $preflightStatus = (string)($preflight['status'] ?? 'ok');
                $preflightMessage = trim((string)($preflight['message'] ?? ''));
                $preflightHint = trim((string)($preflight['api_hint'] ?? ''));
                $migrationStatus = trim((string)($preflight['migration_status'] ?? 'none'));
                $migrationMessage = trim((string)($preflight['migration_message'] ?? ''));
                $migrationLegacyKey = trim((string)($preflight['migration_legacy_key'] ?? ''));
                $selectedCode = strtoupper(trim((string)($preflight['source_currency'] ?? '')));
                $manualDetails = (string)old('currency_preflight_details.' . $accountId, (string)($preflight['manual_details'] ?? ''));
                $manualCustomCode = strtoupper(trim((string)old('currency_preflight_code_custom.' . $accountId, '')));
                $manualSelectedCode = strtoupper(trim((string)old('currency_preflight_code.' . $accountId, $selectedCode)));
                $currencyOptions = is_array($currencyPreflightCodes ?? null) ? $currencyPreflightCodes : [];
                foreach (($preflight['sample_currencies'] ?? []) as $sampleCurrency) {
                    $sample = strtoupper(trim((string)$sampleCurrency));
                    if ('' !== $sample && !in_array($sample, $currencyOptions, true)) {
                        $currencyOptions[] = $sample;
                    }
                }
                if ('' !== $selectedCode && !in_array($selectedCode, $currencyOptions, true)) {
                    $currencyOptions[] = $selectedCode;
                }
                sort($currencyOptions);
                $alertClass = 'alert-info';
                if ('mismatch' === $preflightStatus) {
                    $alertClass = 'alert-danger';
                }
                if ('needs_currency' === $preflightStatus) {
                    $alertClass = 'alert-warning';
                }
            @endphp

            @if('none' !== $migrationStatus)
                <div class="alert @if('success' === $migrationStatus) alert-success @else alert-warning @endif mt-3 mb-0 p-2">
                    <div class="small fw-bold">
                        Mapping migration
                        @if('success' === $migrationStatus)
                            <span class="badge bg-success ms-1">auto-mapped</span>
                        @else
                            <span class="badge bg-warning text-dark ms-1">needs review</span>
                        @endif
                    </div>
                    @if('' !== $migrationMessage)
                        <div class="small">{{ $migrationMessage }}</div>
                    @endif
                    @if('' !== $migrationLegacyKey)
                        <div class="small mt-1">Legacy source key: <code>{{ $migrationLegacyKey }}</code></div>
                    @endif
                </div>
            @endif

            @if('ok' !== $preflightStatus)
                <div class="alert {{ $alertClass }} mt-3 mb-0 p-2">
                    <div class="small fw-bold">Currency preflight</div>
                    @if('' !== $preflightMessage)
                        <div class="small">{{ $preflightMessage }}</div>
                    @endif
                    @if('' !== $preflightHint)
                        <div class="small mt-1">
                            Suggested API update: <code>{{ $preflightHint }}</code>
                        </div>
                    @endif
                    @if('needs_currency' === $preflightStatus)
                        <div class="mt-2">
                            <label class="form-label form-label-sm mb-1" for="currency-preflight-code-{{ $accountId }}">Select account currency</label>
                            <select
                                class="form-control form-control-sm"
                                id="currency-preflight-code-{{ $accountId }}"
                                name="currency_preflight_code[{{ $accountId }}]"
                            >
                                <option value="">Select currency</option>
                                @foreach($currencyOptions as $currencyCode)
                                    @php $normalizedOption = strtoupper(trim((string)$currencyCode)); @endphp
                                    @if('' !== $normalizedOption)
                                        <option value="{{ $normalizedOption }}" @if($manualSelectedCode === $normalizedOption) selected @endif>{{ $normalizedOption }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="mt-2">
                            <label class="form-label form-label-sm mb-1" for="currency-preflight-custom-{{ $accountId }}">Or enter custom ISO code</label>
                            <input
                                type="text"
                                class="form-control form-control-sm"
                                id="currency-preflight-custom-{{ $accountId }}"
                                name="currency_preflight_code_custom[{{ $accountId }}]"
                                value="{{ $manualCustomCode }}"
                                maxlength="3"
                                placeholder="e.g. GEL"
                            />
                        </div>
                        <div class="mt-2">
                            <label class="form-label form-label-sm mb-1" for="currency-preflight-details-{{ $accountId }}">Currency details</label>
                            <input
                                type="text"
                                class="form-control form-control-sm"
                                id="currency-preflight-details-{{ $accountId }}"
                                name="currency_preflight_details[{{ $accountId }}]"
                                value="{{ $manualDetails }}"
                                maxlength="255"
                                placeholder="Optional details (symbol, precision, provider note)"
                            />
                        </div>
                    @endif
                    <input type="hidden" name="currency_preflight_fingerprint[{{ $accountId }}]" value="{{ (string)($preflight['fingerprint'] ?? '') }}"/>
                </div>
            @endif
        @endif
    </div>
</div>
