@extends('layout.v2')
@section('content')

    <!-- another tiny hack to get data from a to b -->
    <span id="data-helper" data-flow="{{ $flow }}" data-identifier="{{ $identifier }}" data-url="{{ $nextUrl }}"></span>

    <div class="container" x-data="index">
        <style>
            .submit-log-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 12px;
            }

            @@media (max-width: 991.98px) {
                .submit-log-grid {
                    grid-template-columns: 1fr;
                }
            }

            .submit-log-pane {
                border: 1px solid #dee2e6;
                border-radius: 6px;
                background: #fff;
                min-height: 260px;
                display: flex;
                flex-direction: column;
            }

            .submit-log-pane-header {
                padding: 8px 12px;
                border-bottom: 1px solid #e9ecef;
                font-weight: 700;
                background: #f8f9fa;
            }

            .submit-log-pane-body {
                padding: 10px 12px;
                max-height: 320px;
                overflow-y: auto;
                overflow-x: hidden;
                font-size: 0.92rem;
            }

            .submit-log-entry {
                padding-bottom: 8px;
                margin-bottom: 8px;
                border-bottom: 1px dashed #e9ecef;
            }

            .submit-log-entry:last-child {
                border-bottom: 0;
                margin-bottom: 0;
                padding-bottom: 0;
            }

            .submit-log-line {
                font-weight: 600;
                margin-bottom: 4px;
            }

            .submit-log-entry ul,
            .submit-log-entry ol {
                margin-bottom: 0;
                padding-left: 18px;
            }
        </style>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1>{{ $mainTitle }}</h1>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-10 offset-lg-1">
                @include('components.step-indicator', ['flow' => $flow, 'currentStepNum' => $flow === 'file' ? 5 : 3])
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                @include('components.step-navigation', [
                    'backUrl' => $jobBackUrl,
                    'backLabel' => 'Go back to previous step',
                    'identifier' => $identifier,
                    'flow' => $flow,
                    'showDownloadConfig' => true,
                    'currentStep' => 'Convert',
                ])
            </div>
        </div>
        <div id="app">
            {{-- ============================================================ --}}
            {{-- Conversion card — phase === 'conversion'                     --}}
            {{-- ============================================================ --}}
            <div class="row mt-3" x-show="'conversion' === phase">
                <div class="col-lg-10 offset-lg-1">
                    <div class="card">
                        <div class="card-header">
                            Data conversion
                        </div>
                        <!-- show start of process button -->
                        <div x-show="showStartButton()" class="card-body">
                            <p>
                                The first step in the import process is a <strong>conversion</strong>.
                                <span x-show="'file' === flow">The CSV file you uploaded</span>
                                <span x-show="'file' !== flow">The downloaded transactions </span>
                                will be converted to Firefly III compatible transactions. Please press <strong>Start
                                    job</strong> to start.
                            </p>

                            {{-- Account creation forms (inline, before Start button) --}}
                            @if(config('importer.providers.'.$flow.'.supports_new_accounts') && count($newAccountsToCreate) > 0)
                                <div class="card border-warning-subtle mb-3">
                                    <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center"
                                         role="button"
                                         data-bs-toggle="collapse"
                                         data-bs-target="#newAccountsCollapse"
                                         aria-expanded="true"
                                         aria-controls="newAccountsCollapse">
                                        <h6 class="mb-0">
                                            <span class="fas fa-plus-circle me-1"></span>
                                            New accounts to create
                                            <span class="badge bg-warning text-dark ms-1">{{ count($newAccountsToCreate) }}</span>
                                        </h6>
                                        <span class="fas fa-chevron-down"></span>
                                    </div>
                                    <div id="newAccountsCollapse" class="collapse show">
                                        <div class="card-body">
                                            <p class="text-muted">
                                                The following accounts will be created in Firefly III before importing
                                                transactions. You can customize their settings below or proceed with the
                                                default values.
                                            </p>

                                            @foreach($newAccountsToCreate as $accountId => $accountData)
                                                <div class="card mb-3">
                                                    <div class="card-body">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <h6 class="card-title">{{ $accountData['name'] ?? 'New account' }}</h6>
                                                                <small class="text-muted">Account: {{ $accountId }}</small>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <form class="new-account-form"
                                                                      data-account-id="{{ $accountId }}">
                                                                    <input type="hidden" name="liability_type"
                                                                           value="{{ $accountData['liability_type'] ?? '' }}">
                                                                    <input type="hidden" name="liability_direction"
                                                                           value="{{ $accountData['liability_direction'] ?? '' }}">
                                                                    <div class="form-group mb-2">
                                                                        <label class="form-label">Account name:</label>
                                                                        <input type="text"
                                                                               class="form-control form-control-sm"
                                                                               name="account_name"
                                                                               value="{{ $accountData['name'] ?? '' }}"
                                                                               required>
                                                                    </div>

                                                                    <div class="form-group mb-2">
                                                                        <label class="form-label">Account type:</label>
                                                                        <select class="form-control form-control-sm"
                                                                                name="account_type" required>
                                                                            <option value="asset"
                                                                                    @if($accountData['type'] === 'asset') selected @endif>
                                                                                Asset account
                                                                            </option>
                                                                            <option value="liabilities"
                                                                                    @if($accountData['type'] === 'liabilities') selected @endif>
                                                                                Liability account
                                                                            </option>
                                                                        </select>
                                                                        <small class="form-text text-muted">Smart
                                                                            default: asset account (recommended for most
                                                                            accounts)
                                                                        </small>
                                                                    </div>

                                                                    <div class="form-group mb-2">
                                                                        <label class="form-label">Currency:</label>
                                                                        <input type="text"
                                                                               class="form-control form-control-sm"
                                                                               name="account_currency"
                                                                               value="{{ $accountData['currency'] ?? 'EUR' }}"
                                                                               maxlength="12" required>
                                                                        <small class="form-text text-muted">Currency
                                                                            code
                                                                        </small>
                                                                    </div>

                                                                    <div class="form-group mb-2">
                                                                        <label class="form-label">Opening balance
                                                                            (optional):
                                                                        </label>
                                                                        <input type="number" step="0.01"
                                                                               class="form-control form-control-sm"
                                                                               name="opening_balance"
                                                                               placeholder="0.00">
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach

                                            <div class="alert alert-info mb-0">
                                                <small>
                                                    <span class="fas fa-info-circle"></span>
                                                    These accounts will be created automatically when you start the
                                                    conversion process. You can modify the details above or proceed with
                                                    the defaults.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <p>
                                <button class="btn btn-success float-end text-white" type="button"
                                        @click="startJobButton">Start job
                                    &rarr;
                                </button>
                            </p>
                        </div>
                        <div x-show="showWaitingButton()" class="card-body">
                            <p><span class="fas fa-cog fa-spin"></span> Please wait for the job to start..</p>
                        </div>
                        <div x-show="showTooManyChecks()" class="card-body">
                            <p>
                                <em class="fa-solid fa-hourglass-half"></em>
                                This conversion is taking longer than <span x-text="checkCount"></span> seconds.
                                Polling continues automatically in the background.</p>
                        </div>
                        <div x-show="post.startTimedOut && 'conv_running' === pageStatus.status" class="card-body">
                            <p class="text-warning mb-0">
                                Start request hit gateway timeout (HTTP 504), but conversion is still running.
                                Status polling continues automatically.
                            </p>
                        </div>
                        <div x-show="showPostError()" class="card-body">
                            <p class="text-danger">
                                The conversion could not be started, or failed due to an error. Please check the log
                                files.
                                Sorry about this :(
                            </p>
                            <p x-show="'' !== post.result" x-text="post.result"></p>
                            <template x-if="hasAuthError()">
                                <div class="alert alert-warning">
                                    <strong>Bank session expired.</strong> You need to re-authenticate before retrying.
                                    <a href="{{ route('authenticate-flow.index', [$flow]) }}" class="btn btn-primary btn-sm ms-2">Re-authenticate</a>
                                </div>
                            </template>
                            <x-conversion-messages/>
                            <button class="btn btn-warning mt-2" @click="retryConversion">Retry conversion</button>
                        </div>

                        <div x-show="showWhenRunning()" class="card-body">
                            <p>
                                <span class="fas fa-cog fa-spin"></span> The conversion is running, please wait.
                                Messages may appear below the progress bar.
                            </p>
                            <div x-show="Object.keys(pull.checklist || {}).length > 0 || getPullProgressTotal() > 0">
                                <h6>Remote pull checklist</h6>
                                <div class="progress mb-2">
                                    <div aria-valuemax="100" aria-valuemin="0"
                                         :aria-valuenow="getPullProgressPercentage()"
                                         class="progress-bar progress-bar-striped progress-bar-animated"
                                         role="progressbar" :style="'width: ' + getPullProgressWidth()"></div>
                                </div>
                                <small class="text-muted d-block mb-2">
                                    Pulled data for <span x-text="getPullProgressDone()"></span> of
                                    <span x-text="getPullProgressTotal()"></span> account(s)
                                    (<span x-text="getPullProgressPercentage()"></span>%).
                                    Transactions fetched so far:
                                    <strong><span x-text="getPulledTransactionsTotal()"></span></strong>.
                                </small>

                                <ul class="list-group">
                                    <template x-for="(item, accountId) in pull.checklist" :key="accountId">
                                        <li class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span>
                                                    <strong>Account #<span x-text="accountId"></span></strong>:
                                                    <span x-text="item.message || item.status"></span>
                                                </span>
                                                <span class="badge" :class="getPullStatusBadgeClass(item.status)">
                                                    <span x-text="item.status"></span>
                                                </span>
                                            </div>
                                            <div class="mt-2" x-show="hasNestedPullProgress(item)">
                                                <div class="progress progress-sm">
                                                    <div class="progress-bar"
                                                         role="progressbar"
                                                         :class="getNestedPullBarClass(item)"
                                                         :style="'width: ' + getNestedPullWidth(item)"
                                                         :aria-valuenow="getNestedPullPercent(item)"
                                                         aria-valuemin="0"
                                                         aria-valuemax="100"></div>
                                                </div>
                                                <small class="text-muted d-block mt-1" x-text="getNestedPullText(item)"></small>
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <div class="progress mt-2">
                                <div aria-valuemax="100" aria-valuemin="0"
                                     :aria-valuenow="getOverallProgressPercentage()"
                                     :class="hasPullProgressData() ? 'progress-bar' : 'progress-bar progress-bar-striped progress-bar-animated'"
                                     role="progressbar"
                                     :style="'width: ' + getOverallProgressWidth()"></div>
                            </div>
                            <div class="text-center mt-2">
                                <small class="text-muted" x-text="getOverallProgressText()"></small>
                            </div>
                            <div class="text-center" x-show="'conv_running' === pageStatus.status && !hasPullProgressData()">
                                <small class="text-muted">No account-level checkpoints yet. Large imports may take a few minutes.</small>
                            </div>
                            <x-conversion-messages/>
                        </div>
                        {{-- conv_done with redirect countdown (non-submit nextUrl, e.g. mapping) --}}
                        <div x-show="showWhenDone() && !nextUrlIsSubmit" class="card-body">
                            <p>
                                <span class="fas fa-check-circle text-success"></span>
                                The conversion routine has finished!
                                Redirecting in <strong x-text="redirectCountdown"></strong>&hellip;
                            </p>
                            <x-conversion-messages/>
                            <div class="mt-2">
                                <button class="btn btn-primary" type="button" @click="skipToNextStep">
                                    Skip to next step <span class="fas fa-arrow-right"></span>
                                </button>
                            </div>
                        </div>
                        {{-- conv_done auto-transitioning to submission --}}
                        <div x-show="showWhenDone() && nextUrlIsSubmit" class="card-body">
                            <p>
                                <span class="fas fa-check-circle text-success"></span>
                                The conversion routine has finished! Starting submission&hellip;
                            </p>
                            <x-conversion-messages/>
                        </div>
                        <div x-show="showWhenDoneEmpty()" class="card-body">
                            <div class="alert alert-warning mb-3">
                                <span class="fas fa-exclamation-triangle"></span>
                                <strong>No transactions found.</strong>
                                The conversion completed successfully, but no transactions were returned for the selected date range and wallets.
                            </div>
                            <p>You can go back to adjust your settings (date range, wallets, accounts) and try again, or proceed to the submit step with zero transactions.</p>
                            <x-conversion-messages/>
                            <div class="mt-3">
                                <a href="{{ $jobBackUrl }}" class="btn btn-secondary me-2">
                                    <span class="fas fa-arrow-left"></span> Go back and adjust settings
                                </a>
                                <button class="btn btn-outline-primary" type="button" @click="redirectToImport">
                                    Proceed to submit step <span class="fas fa-arrow-right"></span>
                                </button>
                            </div>
                        </div>
                        <div x-show="showIfError()" class="card-body">
                            <p class="text-danger">
                                The conversion could not be started, or failed due to an error. Please check the log
                                files.
                                Sorry about this :(
                            </p>
                            <template x-if="hasAuthError()">
                                <div class="alert alert-warning">
                                    <strong>Bank session expired.</strong> You need to re-authenticate before retrying.
                                    <a href="{{ route('authenticate-flow.index', [$flow]) }}" class="btn btn-primary btn-sm ms-2">Re-authenticate</a>
                                </div>
                            </template>
                            <x-conversion-messages/>
                            <button class="btn btn-warning mt-2" @click="retryConversion">Retry conversion</button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- Submission card — shown when phase === 'submission'           --}}
            {{-- (auto-transitioned from conversion when nextUrl is submit)    --}}
            {{-- ============================================================ --}}
            <div class="row mt-3" x-show="showSubmissionPhase()">
                <div class="col-lg-10 offset-lg-1">
                    <div class="card">
                        <div class="card-header">
                            Data submission to Firefly III
                        </div>
                        <div x-show="showSubmissionWaiting()" class="card-body">
                            <p><span class="fas fa-cog fa-spin"></span> Please wait for the submission to start..</p>
                        </div>
                        <div x-show="showSubmissionTooManyChecks()" class="card-body">
                            <p>
                                <em class="fa-solid fa-clock"></em>
                                <strong>Job Still Running</strong> - The import submission is taking longer than expected (<span x-text="checkCount"></span> seconds) but is likely still processing in the background.
                            </p>
                            <p>
                                Large imports with many transactions can take 20+ minutes to complete. The automatic status checking has been paused to prevent system overload.
                            </p>
                            <div class="alert alert-info">
                                <strong>What you can do:</strong>
                                <ul class="mb-2">
                                    <li>Click "Refresh Status" below to check if the job has completed</li>
                                    <li>Check your Firefly III installation directly to see if transactions are appearing</li>
                                    <li>Wait a few more minutes and try refreshing this page</li>
                                </ul>
                                <button x-show="manualRefreshAvailable" @click="refreshSubmissionStatus()" class="btn btn-primary btn-sm">
                                    <span class="fas fa-sync-alt"></span> Refresh Status
                                </button>
                            </div>
                        </div>
                        <div x-show="showSubmissionPostError()" class="card-body">
                            <p class="text-danger">
                                The submission could not be started, or failed due to an error. Please check the log files.
                                Sorry about this :(
                            </p>
                            <p x-show="'' !== post.result" x-text="post.result"></p>
                            @include('import.007-convert._submission-messages')
                            <button class="btn btn-warning mt-2" @click="retrySubmission">Retry submission</button>
                        </div>

                        <div x-show="showSubmissionRunning()" class="card-body">
                            <p>
                                <span class="fas fa-cog fa-spin"></span> The submission is running, please wait. Messages may appear below the progress bar.
                            </p>
                            <div class="progress">
                                <div aria-valuemax="100" aria-valuemin="0"
                                     :aria-valuenow="getSubmissionProgressPercentage()"
                                     :class="hasSubmissionProgressData() ? 'progress-bar' : 'progress-bar progress-bar-striped progress-bar-animated'"
                                     role="progressbar"
                                     :style="'width: ' + getSubmissionProgressWidth()"></div>
                            </div>
                            <div x-show="hasSubmissionProgressData()" class="text-center mt-2">
                                <small class="text-muted" x-text="getSubmissionProgressDisplay()"></small>
                            </div>
                            <div x-show="getSubmissionSummary()" class="text-center mt-2">
                                <small class="text-muted" x-text="getSubmissionSummary()"></small>
                            </div>
                            <div x-show="hasSubmissionPerformanceData()" class="mt-3">
                                <div class="card">
                                    <div class="card-header">Submission performance telemetry</div>
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead>
                                            <tr>
                                                <th>Phase</th>
                                                <th class="text-end">Calls</th>
                                                <th class="text-end">Avg ms</th>
                                                <th class="text-end">Total ms</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <template x-for="row in getSubmissionPerformanceRows()" :key="row.key">
                                                <tr>
                                                    <td x-text="row.label"></td>
                                                    <td class="text-end" x-text="row.count"></td>
                                                    <td class="text-end" x-text="row.avg"></td>
                                                    <td class="text-end" x-text="row.total"></td>
                                                </tr>
                                            </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            @include('import.007-convert._submission-messages')
                        </div>
                        <div x-show="showSubmissionDone()" class="card-body">
                            <p>
                                The submission routine has finished. Errors and messages can be seen below.
                            </p>
                            <div class="row mt-3 mb-3">
                                <div class="col-lg-12">
                                    <a href="{{ config('importer.vanity_url', config('importer.url')) }}" class="btn btn-success btn-lg me-2" target="_blank">
                                        View in Firefly III
                                    </a>
                                    <a href="{{ route('index') }}" class="btn btn-primary btn-lg">
                                        Start new import
                                    </a>
                                </div>
                            </div>
                            <p x-show="getSubmissionSummary()" class="text-muted small" x-text="getSubmissionSummary()"></p>
                            <div x-show="hasSubmissionPerformanceData()" class="mt-3">
                                <div class="card">
                                    <div class="card-header">Submission performance telemetry</div>
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead>
                                            <tr>
                                                <th>Phase</th>
                                                <th class="text-end">Calls</th>
                                                <th class="text-end">Avg ms</th>
                                                <th class="text-end">Total ms</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <template x-for="row in getSubmissionPerformanceRows()" :key="row.key">
                                                <tr>
                                                    <td x-text="row.label"></td>
                                                    <td class="text-end" x-text="row.count"></td>
                                                    <td class="text-end" x-text="row.avg"></td>
                                                    <td class="text-end" x-text="row.total"></td>
                                                </tr>
                                            </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            @include('import.007-convert._submission-messages')
                        </div>
                        <div x-show="showSubmissionError()" class="card-body">
                            <p class="text-danger">
                                The submission could not be started, or failed due to an error. Please check the log files.
                                Sorry about this :(
                            </p>
                            @include('import.007-convert._submission-messages')
                            <button class="btn btn-warning mt-2" @click="retrySubmission">Retry submission</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Transaction board + Activity log — live monitoring group --}}
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                @include('components.transaction-board')
            </div>
        </div>

        <div class="row mt-3" x-show="activityLog.length > 0">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Activity log</span>
                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                @click="activityExpanded = !activityExpanded"
                                x-text="activityExpanded ? 'Collapse' : 'Expand'">
                        </button>
                    </div>
                    <div class="card-body p-0" x-show="activityExpanded" x-transition>
                        <pre class="importer-activity-pre"
                             x-ref="activityPre"><template x-for="entry in activityLog"><span class="text-muted" x-text="'[' + entry.time + '] '"></span><span x-text="entry.message + '\n'"></span></template></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                @include('components.step-navigation', [
                    'backUrl' => $jobBackUrl,
                    'backLabel' => 'Go back to previous step',
                    'identifier' => $identifier,
                    'flow' => $flow,
                    'showDownloadConfig' => true,
                    'currentStep' => 'Convert',
                ])
            </div>
        </div>


    </div>
@endsection
@section('scripts')
    @vite(['src/pages/conversion/index.js'])
@endsection
