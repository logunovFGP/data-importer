@extends('layout.v2')
@section('content')

    <!-- another tiny hack to get data from a to b -->
    <span id="data-helper" data-identifier="{{ $identifier }}"></span>

    <div class="container" x-data="index">
        <style>
            .submit-log-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 12px;
            }

            @media (max-width: 991.98px) {
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
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                @include('components.step-navigation', [
                    'backUrl' => $jobBackUrl,
                    'backLabel' => 'Go back to previous step',
                    'identifier' => $identifier,
                    'flow' => $flow,
                    'showDownloadConfig' => true,
                    'currentStep' => 'Submit',
                ])
            </div>
        </div>
        <div id="app">
            <div class="row mt-3">
                <div class="col-lg-10 offset-lg-1">
                    <div class="card">
                        <div class="card-header">
                            Data submission to Firefly III
                        </div>
                        <!-- show start of process button -->
                        <div x-show="showStartButton()" class="card-body">
                            <p>
                                Your converted content will be submitted to your Firefly III installation. Press <strong>Start job</strong> to start.
                            </p>
                            <p>
                                <button class="btn btn-success float-end text-white" type="button" @click="startJobButton">Start job
                                    &rarr;
                                </button>
                            </p>
                        </div>
                        <div x-show="showWaitingButton()" class="card-body">
                            <p><span class="fas fa-cog fa-spin"></span> Please wait for the job to start..</p>
                        </div>
                        <div x-show="showTooManyChecks()" class="card-body">
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
                                <button x-show="manualRefreshAvailable" @click="refreshStatus()" class="btn btn-primary btn-sm">
                                    <span class="fas fa-sync-alt"></span> Refresh Status
                                </button>
                            </div>
                        </div>
                        <div x-show="showPostError()" class="card-body">
                            <p class="text-danger">
                                The submission could not be started, or failed due to an error. Please check the log files.
                                Sorry about this :(
                            </p>
                            <p x-show="'' !== post.result" x-text="post.result"></p>
                            <x-submission-messages />
                            <button class="btn btn-warning mt-2" @click="retrySubmission">Retry submission</button>
                        </div>

                        <div x-show="showWhenRunning()" class="card-body">
                            <p>
                                <span class="fas fa-cog fa-spin"></span> The submission is running, please wait. Messages may appear below the progress bar.
                            </p>
                            <div class="progress">
                                <div aria-valuemax="100" aria-valuemin="0"
                                     :aria-valuenow="getProgressPercentage()"
                                     :class="hasProgressData() ? 'progress-bar' : 'progress-bar progress-bar-striped progress-bar-animated'"
                                     role="progressbar"
                                     :style="'width: ' + getProgressWidth()"></div>
                            </div>
                            <div x-show="hasProgressData()" class="text-center mt-2">
                                <small class="text-muted" x-text="getProgressDisplay()"></small>
                            </div>
                            <div x-show="getSubmissionSummary()" class="text-center mt-2">
                                <small class="text-muted" x-text="getSubmissionSummary()"></small>
                            </div>
                            <div x-show="hasPerformanceData()" class="mt-3">
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
                                            <template x-for="row in getPerformanceRows()" :key="row.key">
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
                            <x-submission-messages />
                        </div>
                        <div x-show="showWhenDone()" class="card-body">
                            <p>
                                The submission routine has finished 🎉. Errors and messages can be seen below.
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
                            <div x-show="hasPerformanceData()" class="mt-3">
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
                                            <template x-for="row in getPerformanceRows()" :key="row.key">
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
                            <x-submission-messages />
                        </div>
                        <div x-show="showIfError()" class="card-body">
                            <p class="text-danger">
                                The submission could not be started, or failed due to an error. Please check the log files.
                                Sorry about this :(
                            </p>
                            <x-submission-messages />
                            <button class="btn btn-warning mt-2" @click="retrySubmission">Retry submission</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

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
                        <pre class="mb-0 p-2" style="max-height: 250px; overflow-y: auto; font-size: 0.8rem; background: var(--bs-body-bg);"
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
                    'currentStep' => 'Submit',
                ])
            </div>
        </div>


    </div>
@endsection
@section('scripts')
    @vite(['src/pages/submit/index.js'])
@endsection

