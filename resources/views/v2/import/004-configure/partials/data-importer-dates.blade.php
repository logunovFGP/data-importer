<div class="row mt-3">
    <div class="col-lg-10 offset-lg-1">
        <div class="card">
            <div class="card-header">
                Date range import options
            </div>
            <div class="card-body">
                <div class="form-group row mb-3">
                    <label for="default_account" class="col-sm-3 col-form-label">Date range</label>
                    <div class="col-sm-9">
                        <div class="form-check">
                            <input class="form-check-input date-range-radio" id="date_range_all"
                                   type="radio" name="date_range" value="all" x-model="dateRange"
                                   @if('all' === $configuration->getDateRange()) checked @endif
                                   aria-describedby="rangeHelp"/>
                            <label class="form-check-label" for="date_range_all">Import
                                everything</label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input date-range-radio" id="date_range_partial"
                                   type="radio" name="date_range" x-model="dateRange"
                                   value="partial"
                                   @if('partial' === $configuration->getDateRange()) checked @endif
                                   aria-describedby="rangeHelp"/>
                            <label class="form-check-label" for="date_range_partial">Go back some
                                time</label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input date-range-radio" id="date_range_range"
                                   type="radio" name="date_range" value="range" x-model="dateRange"
                                   @if('range' === $configuration->getDateRange()) checked @endif
                                   aria-describedby="rangeHelp"/>
                            <label class="form-check-label" for="date_range_range">Import a specific
                                range</label>
                            <small id="rangeHelp" class="form-text text-muted">
                                <br>What range to grab from your bank through
                                @if('nordigen' === $flow)
                                    GoCardless?
                                @endif
                                @if('spectre' === $flow)
                                    Spectre?
                                @endif
                                @if('basisbank' === $flow)
                                    BasisBank?
                                @endif
                                @if('tbank' === $flow)
                                    TBank?
                                @endif
                                @if('trc20' === $flow)
                                    TRC-20?
                                @endif
                            </small>
                        </div>


                    </div>
                </div>

                <div class="form-group row mb-3" id="date_range_partial_settings_one" x-show="'partial' === dateRange">
                    <div class="col-sm-3">
                        Go back this period
                    </div>
                    <div class="col-sm-3">
                        <input
                            name="date_range_number"
                            id="date_range_number"
                            class="form-control" value="{{ $configuration->getDateRangeNumber() }}"
                            type="number" step="1" min="1" max="365">
                    </div>
                    <div class="col-sm-6">
                        <select class="form-control"
                                name="date_range_unit"
                                id="date_range_unit">
                            <option
                                @if('d' === $configuration->getDateRangeUnit()) selected @endif
                            value="d" label="days">days
                            </option>
                            <option
                                @if('w' === $configuration->getDateRangeUnit()) selected @endif
                            value="w" label="weeks">weeks
                            </option>
                            <option
                                @if('m' === $configuration->getDateRangeUnit()) selected @endif
                            value="m" label="months">months
                            </option>
                            <option
                                @if('y' === $configuration->getDateRangeUnit()) selected @endif
                            value="y" label="years">years
                            </option>
                        </select>
                    </div>
                </div>
                <div class="form-group row mb-3" id="date_range_partial_settings_two" x-show="'partial' === dateRange">
                    <div class="col-sm-3">
                        Stop when you reach transactions newer than
                    </div>
                    <div class="col-sm-3">
                        <input
                            name="date_range_not_after_number"
                            id="date_range_not_after_number"
                            class="form-control" value="{{ $configuration->getDateRangeNotAfterNumber() }}"
                            type="number" step="1" min="0" max="365">
                    </div>
                    <div class="col-sm-6">
                        <select class="form-control"
                                name="date_range_not_after_unit"
                                id="date_range_not_after_unit">
                            <option
                                @if('' === $configuration->getDateRangeNotAfterUnit()) selected @endif
                            value="" label="(import all transactions)">(import all transactions)
                            </option>
                            <option
                                @if('d' === $configuration->getDateRangeNotAfterUnit()) selected @endif
                            value="d" label="days ago">days ago
                            </option>
                            <option
                                @if('w' === $configuration->getDateRangeNotAfterUnit()) selected @endif
                            value="w" label="weeks ago">weeks ago
                            </option>
                            <option
                                @if('m' === $configuration->getDateRangeNotAfterUnit()) selected @endif
                            value="m" label="months ago">months ago
                            </option>
                            <option
                                @if('y' === $configuration->getDateRangeNotAfterUnit()) selected @endif
                            value="y" label="years ago">years ago
                            </option>
                        </select>
                        <p class="text-muted">
                            Leave the number to "0" and the unit to "(import all transactions)" to import all known transactions.<br>
                            Example: If you set it to "5" and "days ago", the import will NOT import transactions that are newer than 5 days ago.
                        </p>
                    </div>
                </div>

                <div class="form-group row mb-3" id="date_range_range_settings" x-show="'range' === dateRange">
                    <div class="col-sm-3">
                        Date range settings (from, to)
                    </div>
                    <div class="col-sm-4">
                        <input type="date" name="date_not_before" class="form-control"
                               value="{{ $configuration->getDateNotBefore() }}">
                    </div>
                    <div class="col-sm-4">
                        <input type="date" name="date_not_after" class="form-control"
                               value="{{ $configuration->getDateNotAfter() }}">
                    </div>
                </div>

                @if('basisbank' === $flow || 'tbank' === $flow || 'trc20' === $flow)
                    <div class="form-group row mt-4 mb-3">
                        <div class="col-sm-3">
                            Incremental sync
                        </div>
                        <div class="col-sm-9">
                            <div class="form-check">
                                <input type="hidden" name="incremental_sync_enabled" value="0">
                                <input
                                    id="incremental_sync_enabled"
                                    type="checkbox"
                                    name="incremental_sync_enabled"
                                    value="1"
                                    class="form-check-input"
                                    @if($configuration->isIncrementalSyncEnabled()) checked @endif
                                >
                                <label class="form-check-label" for="incremental_sync_enabled">Enable incremental sync</label>
                            </div>
                            <div class="form-text text-muted">
                                Enabled: each successful import remembers the last pulled date per account and uses it as
                                the next "from" date (minus the lookback below).
                            </div>
                            <div class="mt-2">
                                <label class="form-label" for="incremental_lookback_days">Lookback days</label>
                                <input
                                    class="form-control"
                                    id="incremental_lookback_days"
                                    name="incremental_lookback_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    value="{{ $configuration->getIncrementalLookbackDays() }}">
                                <div class="form-text text-muted">
                                    The importer remembers the last successful pulled date per account and subtracts this value for the next run.
                                    <br>Examples (if last successful pulled date is <strong>2026-02-16</strong>):
                                    <br><strong>0</strong> = next "from" date is <strong>2026-02-16</strong> (no overlap)
                                    <br><strong>1</strong> = next "from" date is <strong>2026-02-15</strong> (1-day overlap)
                                    <br><strong>3</strong> = next "from" date is <strong>2026-02-13</strong> (safer overlap for delayed postings)
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
<!-- end of date range options -->



