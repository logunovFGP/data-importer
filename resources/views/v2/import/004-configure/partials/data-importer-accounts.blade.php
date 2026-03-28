<div class="row mt-3">
    <div class="col-lg-10 offset-lg-1">
        <div class="card">
            <div class="card-header">
                @if('trc20' === $flow)
                    TRC-20 Wallet configuration
                @else
                    {{ config('importer.providers.' . $flow . '.title') }} account configuration
                @endif
            </div>
            <div class="card-body">
                @if('trc20' === $flow)
                    <p>Map your TRC-20 wallets to Firefly III accounts. You can link to existing accounts or create new ones during import.</p>
                @else
                    <p>Map your {{ config('importer.providers.' . $flow . '.title') }} accounts to Firefly III accounts. You can link to existing accounts or create new ones during import.</p>
                @endif

                @if(count($accounts) > 0)
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless">
                            <thead>
                            <tr>
                                @if('trc20' === $flow)
                                    <th style="width:45%">TRC-20 Wallet</th>
                                @else
                                    <th style="width:45%">{{ config('importer.providers.' . $flow . '.title') }} Account</th>
                                @endif
                                <th style="width:10%"></th>
                                <th style="width:45%">Firefly III Account</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($accounts as $information)
                                <x-importer-account :account="$information" :configuration="$configuration" :currencies="$currencies" :flow="$flow" :currencyPreflight="$currencyPreflight ?? []" :currencyPreflightCodes="$currencyPreflightCodes ?? []"/>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="alert alert-warning">
                        @if('trc20' === $flow)
                            <strong>No TRC-20 wallets found.</strong> Please ensure your settings are valid and try again.
                        @else
                            <strong>No {{ config('importer.providers.' . $flow . '.title') }} accounts found.</strong> Please ensure your settings are valid and try again.
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
