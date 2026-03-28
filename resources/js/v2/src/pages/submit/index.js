/*
 * index.js
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

import '../../boot/bootstrap.js';


let index = function () {
    return {
        identifier: '',
        pageStatus: {
            triedToStart: false,
            status: 'init',
        },
        post: {
            result: '',
            errored: false,
            running: false,
            done: false,
        },
        messages: {
            messages: [],
            warnings: [],
            errors: [],
        },
        progress: {
            currentTransaction: 0,
            totalTransactions: 0,
            progressPercentage: 0,
            uniqueTransactions: 0,
            duplicateTransactions: 0,
        },
        performance: {},
        activityLog: [],
        activityExpanded: true,
        checkCount: 0,
        maxCheckCount: 1200,
        longRunningNotice: false,
        manualRefreshAvailable: false,
        functionName() {

        },
        getProgressPercentage() {
            return this.progress.progressPercentage;
        },
        getProgressWidth() {
            return this.progress.progressPercentage + '%';
        },
        getProgressDisplay() {
            if (this.progress.totalTransactions === 0) {
                return '';
            }
            return this.progress.currentTransaction + ' / ' + this.progress.totalTransactions + ' transactions';
        },
        getSubmissionSummary() {
            if (this.progress.totalTransactions === 0 && this.progress.uniqueTransactions === 0 && this.progress.duplicateTransactions === 0) {
                return '';
            }
            return 'Imported ' + this.progress.uniqueTransactions + ' / ' + this.progress.totalTransactions + ' transactions, skipped ' + this.progress.duplicateTransactions + ' duplicate(s).';
        },
        hasProgressData() {
            return this.progress.totalTransactions > 0;
        },
        hasPerformanceData() {
            return this.getPerformanceRows().length > 0;
        },
        getPerformanceRows() {
            const labels = {
                duplicate_checks: 'Duplicate checks',
                duplicate_index_preload: 'Duplicate index preload',
                firefly_submissions: 'Firefly submissions',
                tag_updates: 'Tag updates',
                disk_saves: 'Status saves',
            };
            const rows = [];
            Object.keys(this.performance || {}).forEach((bucket) => {
                const item = this.performance[bucket] || {};
                const count = Number(item.count || 0);
                const totalMs = Number(item.milliseconds || 0);
                const avgMs = Number(item.average_ms || 0);
                if (count <= 0 && totalMs <= 0) {
                    return;
                }
                rows.push({
                    key: bucket,
                    label: labels[bucket] || bucket,
                    count: count,
                    total: totalMs.toFixed(1),
                    avg: avgMs.toFixed(1),
                    meta: item.meta || {},
                });
            });
            return rows;
        },
        showJobMessages() {
            return this.messages.messages.length > 0 || this.messages.warnings.length > 0 || this.messages.errors.length > 0;
        },
        normalizeMessageBuckets(rawBuckets) {
            if (!rawBuckets || 'object' !== typeof rawBuckets) {
                return [];
            }
            const buckets = Object.entries(rawBuckets).map(([line, messageList], index) => {
                const parsedLine = Number.parseInt(line, 10);
                return {
                    key: line + '-' + index,
                    line: line,
                    lineNumber: Number.isNaN(parsedLine) ? -1 : parsedLine,
                    messages: Array.isArray(messageList) ? [...messageList].reverse() : [String(messageList)],
                    index: index,
                };
            });

            buckets.sort((a, b) => {
                if (a.lineNumber !== b.lineNumber) {
                    return b.lineNumber - a.lineNumber;
                }
                return b.index - a.index;
            });

            return buckets;
        },
        showStartButton() {
            return('init' === this.pageStatus.status || 'waiting_to_start' === this.pageStatus.status) && false === this.pageStatus.triedToStart && false === this.post.errored;
        },
        showWaitingButton() {
            return 'waiting_to_start' === this.pageStatus.status && true === this.pageStatus.triedToStart && false === this.post.errored;
        },
        showTooManyChecks() {
            return this.longRunningNotice && 'submission_running' === this.pageStatus.status;
        },
        refreshStatus() {
            console.log('Manual refresh triggered');
            this.checkCount = 0;
            this.manualRefreshAvailable = false;
            this.pageStatus.status = 'submission_running';
            this.getJobStatus();
        },
        showPostError() {
            return 'submission_errored' === this.pageStatus.status || this.post.errored
        },
        showWhenRunning() {
            return 'submission_running' === this.pageStatus.status;
        },
        showWhenDone() {
            return 'submission_done' === this.pageStatus.status;
        },
        showIfError() {
            return 'submission_errored' === this.pageStatus.status && !this.post.errored;
        },
        retrySubmission() {
            this.post.errored = false;
            this.post.done = false;
            this.post.result = '';
            this.post.running = false;
            this.pageStatus.status = 'init';
            this.pageStatus.triedToStart = false;
            this.messages.errors = [];
            this.messages.warnings = [];
            this.messages.messages = [];
            this.longRunningNotice = false;
            this.checkCount = 0;
            this.manualRefreshAvailable = false;
            this.startJobButton();
        },
        init() {
            this.identifier = document.querySelector('#data-helper').dataset.identifier;
            console.log('Identifier is ' + this.identifier);
            this.getJobStatus();
        },
        startJobButton() {
            if (this.post.running || this.pageStatus.triedToStart) {
                return;
            }
            this.pageStatus.triedToStart = true;
            this.pageStatus.status = 'waiting_to_start';
            this.postJobStart();
        },
        postJobStart() {
            console.log('postJobStart');
            this.post.running = true;
            const jobStartUrl = './submit-data/' + this.identifier + '/start';
            window.axios.post(jobStartUrl, null).then((response) => {
                console.log('POST was OK');
            }).catch((error) => {
                if(error.response) {
                    // The request was made and the server responded with a status code
                    // that falls out of the range of 2xx
                    console.error('Error response:', error.response.data);
                    console.error('Status code:', error.response.status);

                    // respond to some different error codes:
                    if(524 === error.response.status) {
                        console.error('Error 524: A timeout occurred. The job is probably still running.');
                        this.pageStatus.triedToStart = true;
                        this.getJobStatus();
                    }
                } else {
                    // Network error (timeout, connection reset) — the job may still be running
                    // in the PHP-FPM worker even though nginx dropped the connection.
                    // Don't mark as errored — keep polling for status.
                    console.warn('Job start request failed (network error). The job may still be running. Will keep checking status.');
                    this.pageStatus.triedToStart = true;
                }
            }).finally(() => {
                    this.post.running = false;
                    this.getJobStatus();
                    this.pageStatus.triedToStart = true;
                }
            );
        },
        getJobStatus() {
            const submitUrl = './submit-data/' + this.identifier + '/status';
            window.axios.get(submitUrl, {
                params: {'_': Date.now()},
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache',
                },
            }).then((response) => {
                this.pageStatus.status = response.data.status;
                console.log('Status is now ' + response.data.status + ' (' + this.checkCount + ') ('+this.pageStatus.triedToStart+')');

                // process messages, warnings and errors:
                this.messages.errors = this.normalizeMessageBuckets(response.data.errors);
                this.messages.warnings = this.normalizeMessageBuckets(response.data.warnings);
                this.messages.messages = this.normalizeMessageBuckets(response.data.messages);

                // process progress data:
                this.progress.currentTransaction = response.data.currentTransaction || 0;
                this.progress.totalTransactions = response.data.totalTransactions || 0;
                this.progress.progressPercentage = response.data.progressPercentage || 0;
                this.progress.uniqueTransactions = response.data.uniqueTransactions || 0;
                this.progress.duplicateTransactions = response.data.duplicateTransactions || 0;
                this.performance = response.data.performance || {};
                if (Array.isArray(response.data.activity_log)) {
                    this.activityLog = response.data.activity_log;
                    this.$nextTick(() => {
                        const el = this.$refs.activityPre;
                        if (el) el.scrollTop = el.scrollHeight;
                    });
                }

                // job has not started yet. Let's wait.
                if (false === this.pageStatus.triedToStart && 'waiting_to_start' === this.pageStatus.status) {
                    this.pageStatus.status = response.data.status;
                    return;
                }
                // user pressed start, but it takes a moment.
                if (true === this.pageStatus.triedToStart && 'waiting_to_start' === this.pageStatus.status) {
                    //console.log('Job hasn\'t started yet, but it\'s been tried.');
                }

                if (true === this.pageStatus.triedToStart && 'submission_errored' === this.pageStatus.status) {
                    console.error('Job status noticed job failed.');
                    this.status = response.data.status;
                    return;
                }

                if ('submission_running' === this.pageStatus.status) {
                    console.log('Submission is running...')
                }
                if ('submission_done' === this.pageStatus.status) {
                    console.log('Job is done!');
                    this.post.done = true;
                    return;
                }
                if ('submission_errored' === this.pageStatus.status) {
                    console.error('Job is kill.');
                    this.status = response.data.status;
                    this.pageStatus.triedToStart = true;
                    this.pageStatus.status = this.status;
                    this.post.errored = true;
                }
            }).catch((error) => {
                if (!error.response) {
                    // Network error — keep polling (job may still be running)
                    console.warn('Status poll network error, retrying...');
                } else if ([502, 503, 504].includes(error.response.status)) {
                    // Gateway timeout — keep polling
                    console.warn('Gateway timeout during status poll, retrying...');
                } else {
                    // Real HTTP error — stop polling
                    console.error('Status poll error:', error.response.status);
                    this.post.result = error;
                    this.post.errored = true;
                }
            });
            this.checkCount++;
            if (this.checkCount >= this.maxCheckCount && !this.longRunningNotice) {
                this.longRunningNotice = true;
                this.manualRefreshAvailable = true;
            }
            if (!this.post.errored && !this.post.done) {
                setTimeout(function () {
                    this.getJobStatus();
                }.bind(this), 1000);
            }
        }
    }
}


function loadPage() {
    Alpine.data('index', () => index());
    Alpine.start();
}

// wait for load until bootstrapped event is received.
document.addEventListener('data-importer-bootstrapped', () => {
    console.log('Loaded through event listener.');
    loadPage();
});
// or is bootstrapped before event is triggered.
if (window.bootstrapped) {
    console.log('Loaded through window variable.');
    loadPage();
}
