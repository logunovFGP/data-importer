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
        flow: '',
        identifier: '',
        nextUrl: '',
        pageStatus: {
            triedToStart: false,
            status: 'init',
        },
        post: {
            result: '',
            errored: false,
            startTimedOut: false,
            running: false,
            done: false,
        },
        transactionCount: -1,
        redirectCountdown: 0,
        redirectTimerHandle: null,
        activityLog: [],
        activityExpanded: true,
        transactionBoard: [],
        transactionBoardTotal: 0,
        transactionBoardHidden: 0,
        boardExpanded: true,
        messages: {
            messages: [],
            warnings: [],
            errors: [],
        },
        pull: {
            checklist: {},
            progress: {
                total: 0,
                done: 0,
                status: '',
            },
        },
        runtime: {
            runningStartedAt: 0,
            lastSeenAt: 0,
        },
        polling: {
            inFlight: false,
            timerHandle: null,
        },
        longRunningNotice: false,
        checkCount: 0,
        maxCheckCount: 600,
        showJobMessages() {
            return Object.values(this.messages.messages).length > 0 || Object.values(this.messages.warnings).length > 0 || Object.values(this.messages.errors).length > 0;
        },
        getPullProgressTotal() {
            return this.pull.progress.total || 0;
        },
        getPullProgressDone() {
            return this.pull.progress.done || 0;
        },
        getPullProgressPercentage() {
            const total = Math.max(1, this.getPullProgressTotal());
            return Math.round((this.getPullProgressDone() / total) * 100);
        },
        getPullProgressWidth() {
            return this.getPullProgressPercentage() + '%';
        },
        getPulledTransactionsTotal() {
            let total = 0;
            const checklist = Object.values(this.pull.checklist || {});
            checklist.forEach((item) => {
                if (!item) {
                    return;
                }
                const metaFetched = Number.parseInt(String(item?.meta?.fetched_total ?? '0'), 10);
                let parsedFromMessage = 0;
                if ('string' === typeof item.message) {
                    const match = item.message.match(/Fetched\s+(\d+)\s+transaction\(s\)/i);
                    if (match && match[1]) {
                        const parsed = Number.parseInt(match[1], 10);
                        if (!Number.isNaN(parsed) && parsed > 0) {
                            parsedFromMessage = parsed;
                        }
                    }
                }
                const best = Math.max(
                    Number.isNaN(metaFetched) ? 0 : metaFetched,
                    parsedFromMessage
                );
                if (best > 0) {
                    total += best;
                }
            });

            return total;
        },
        getPullItemMeta(item) {
            if (!item || 'object' !== typeof item) {
                return {};
            }

            return item.meta && 'object' === typeof item.meta ? item.meta : {};
        },
        hasNestedPullProgress(item) {
            if (!item || 'object' !== typeof item) {
                return false;
            }
            const meta = this.getPullItemMeta(item);
            if (item.status === 'running' || item.status === 'done' || item.status === 'error') {
                return true;
            }

            return Object.keys(meta).length > 0;
        },
        getNestedPullPercent(item) {
            const meta = this.getPullItemMeta(item);
            const explicit = Number.parseInt(String(meta.progress_percent ?? ''), 10);
            if (!Number.isNaN(explicit) && explicit >= 0) {
                return Math.max(0, Math.min(100, explicit));
            }
            const page = Number.parseInt(String(meta.page ?? '0'), 10);
            const maxPages = Number.parseInt(String(meta.max_pages ?? '0'), 10);
            if (!Number.isNaN(page) && !Number.isNaN(maxPages) && page > 0 && maxPages > 0) {
                return Math.max(1, Math.min(99, Math.round((page / maxPages) * 100)));
            }
            if ('done' === item?.status) {
                return 100;
            }
            if ('error' === item?.status) {
                return 100;
            }

            return 100;
        },
        isNestedPullIndeterminate(item) {
            const meta = this.getPullItemMeta(item);
            if (meta.indeterminate === true) {
                return true;
            }
            if ('running' !== item?.status) {
                return false;
            }
            const explicit = Number.parseInt(String(meta.progress_percent ?? ''), 10);
            const page = Number.parseInt(String(meta.page ?? '0'), 10);

            return Number.isNaN(explicit) && (Number.isNaN(page) || page <= 0);
        },
        getNestedPullWidth(item) {
            return this.getNestedPullPercent(item) + '%';
        },
        getNestedPullBarClass(item) {
            const classes = [];
            if ('done' === item?.status) {
                classes.push('bg-success');
            } else if ('error' === item?.status) {
                classes.push('bg-danger');
            } else {
                classes.push('bg-info');
            }
            if (this.isNestedPullIndeterminate(item)) {
                classes.push('progress-bar-striped', 'progress-bar-animated');
            }

            return classes.join(' ');
        },
        getNestedPullText(item) {
            const meta = this.getPullItemMeta(item);
            const stream = String(meta.stream ?? '').trim();
            const streamText = '' !== stream ? stream + ' stream' : 'current stream';
            const fetched = Number.parseInt(String(meta.fetched_total ?? '0'), 10);
            const page = Number.parseInt(String(meta.page ?? '0'), 10);
            const maxPages = Number.parseInt(String(meta.max_pages ?? '0'), 10);
            const parsedRows = Number.parseInt(String(meta.parsed_rows ?? '0'), 10);
            const stage = String(meta.stage ?? '').trim();

            if ('statement_start' === stage) {
                return 'Statement fallback is running.';
            }
            if ('statement_done' === stage) {
                return 'Statement fallback parsed ' + (Number.isNaN(parsedRows) ? 0 : parsedRows) + ' transaction(s).';
            }
            if (!Number.isNaN(page) && page > 0 && !Number.isNaN(maxPages) && maxPages > 0) {
                return streamText + ': page ' + page + ' / ' + maxPages + ', fetched ' + (Number.isNaN(fetched) ? 0 : fetched) + ' transaction(s) so far.';
            }
            if (!Number.isNaN(fetched) && fetched > 0) {
                return streamText + ': fetched ' + fetched + ' transaction(s) so far.';
            }

            return 'Working...';
        },
        hasPullProgressData() {
            return this.getPullProgressTotal() > 0;
        },
        getOverallProgressPercentage() {
            if (this.hasPullProgressData()) {
                return this.getPullProgressPercentage();
            }
            if ('conv_done' === this.pageStatus.status) {
                return 100;
            }
            if ('conv_running' === this.pageStatus.status) {
                return Math.min(95, Math.max(5, Math.round((this.checkCount / this.maxCheckCount) * 95)));
            }
            if ('waiting_to_start' === this.pageStatus.status && true === this.pageStatus.triedToStart) {
                return 3;
            }

            return 0;
        },
        getOverallProgressWidth() {
            return this.getOverallProgressPercentage() + '%';
        },
        isLikelyGatewayTimeout(error) {
            const status = Number(error?.response?.status || 0);
            if (504 === status || 503 === status || 502 === status || 429 === status) {
                return true;
            }
            const message = String(error?.message ?? '').toLowerCase();

            return message.includes('timeout') || message.includes('gateway') || message.includes('econnaborted');
        },
        errorToText(error) {
            if (!error) {
                return '';
            }
            const status = Number(error?.response?.status || 0);
            const responseMessage = error?.response?.data?.message;
            const fallbackMessage = error?.message ?? String(error);
            const message = 'string' === typeof responseMessage ? responseMessage : String(fallbackMessage);
            if (status > 0) {
                return 'HTTP ' + status + ': ' + message;
            }

            return message;
        },
        getElapsedSeconds() {
            if (0 === this.runtime.runningStartedAt) {
                return 0;
            }
            const now = this.runtime.lastSeenAt > 0 ? this.runtime.lastSeenAt : Math.floor(Date.now() / 1000);

            return Math.max(0, now - this.runtime.runningStartedAt);
        },
        formatDuration(totalSeconds) {
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;

            return minutes + 'm ' + String(seconds).padStart(2, '0') + 's';
        },
        getOverallProgressText() {
            if (this.hasPullProgressData()) {
                return 'Progress: ' + this.getPullProgressDone() + ' / ' + this.getPullProgressTotal() + ' account(s) (' + this.getPullProgressPercentage() + '%).';
            }
            if ('conv_running' !== this.pageStatus.status && 'conv_done' !== this.pageStatus.status) {
                return '';
            }
            const elapsed = this.formatDuration(this.getElapsedSeconds());
            if ('conv_done' === this.pageStatus.status) {
                return 'Conversion finished in ' + elapsed + '.';
            }

            return 'Conversion is running for ' + elapsed + '.';
        },
        getPullStatusBadgeClass(status) {
            if ('done' === status) {
                return 'bg-success';
            }
            if ('error' === status) {
                return 'bg-danger';
            }
            if ('running' === status) {
                return 'bg-primary';
            }

            return 'bg-secondary';
        },
        showStartButton() {
            return ('init' === this.pageStatus.status || 'waiting_to_start' === this.pageStatus.status) && false === this.pageStatus.triedToStart && false === this.post.errored;
        },
        showWaitingButton() {
            return 'waiting_to_start' === this.pageStatus.status && true === this.pageStatus.triedToStart && false === this.post.errored;
        },
        showTooManyChecks() {
            return this.longRunningNotice && 'conv_running' === this.pageStatus.status;
        },
        showPostError() {
            return 'conv_errored' === this.pageStatus.status || this.post.errored
        },
        showWhenRunning() {
            return 'conv_running' === this.pageStatus.status;
        },
        showWhenDone() {
            return 'conv_done' === this.pageStatus.status && 0 !== this.transactionCount;
        },
        showWhenDoneEmpty() {
            return 'conv_done' === this.pageStatus.status && 0 === this.transactionCount;
        },
        showIfError() {
            return 'conv_errored' === this.pageStatus.status && !this.post.errored;
        },
        hasAuthError() {
            const errors = Object.values(this.messages.errors || {});
            const authKeywords = ['re-authentication', 'otp', 'session', 'expired', 'login.aspx'];
            return errors.some((msg) => {
                const text = String(msg).toLowerCase();
                return authKeywords.some((kw) => text.includes(kw));
            });
        },
        retryConversion() {
            this.cancelRedirectCountdown();
            this.redirectCountdown = 0;
            this.post.errored = false;
            this.post.done = false;
            this.post.result = '';
            this.post.startTimedOut = false;
            this.transactionCount = -1;
            this.pageStatus.status = 'init';
            this.pageStatus.triedToStart = false;
            this.messages.errors = [];
            this.messages.warnings = [];
            this.messages.messages = [];
            this.longRunningNotice = false;
            this.checkCount = 0;
            this.startJobButton();
        },
        init() {
            this.flow       = document.querySelector('#data-helper').dataset.flow;
            this.identifier = document.querySelector('#data-helper').dataset.identifier;
            this.nextUrl    = document.querySelector('#data-helper').dataset.url;
            console.log('Flow is ' + this.flow);
            console.log('Identifier is ' + this.identifier);
            this.getJobStatus();
        },
        startJobButton() {
            this.stopPolling();
            this.checkCount              = 0;
            this.longRunningNotice       = false;
            this.pageStatus.triedToStart = true;
            this.pageStatus.status       = 'waiting_to_start';
            this.post.result             = '';
            this.post.errored            = false;
            this.post.startTimedOut      = false;
            this.post.done               = false;
            this.postJobStart();
        },
        collectNewAccountData() {
            const newAccountData = {};
            const forms          = document.querySelectorAll('.new-account-form');

            forms.forEach(form => {
                const accountId = form.dataset.accountId;
                const formData  = new FormData(form);

                newAccountData[accountId] = {
                    name: formData.get('account_name'),
                    type: formData.get('account_type'),
                    currency: formData.get('account_currency'),
                    opening_balance: formData.get('opening_balance') || null
                };
            });

            return newAccountData;
        },
        postJobStart() {
            this.post.running = true;
            const jobStartUrl = './data-conversion/' + this.identifier + '/start';

            // Collect new account data for SimpleFIN + Nordigen
            const newAccountData = this.collectNewAccountData();
            const postData       = {
                identifier: this.identifier,
                new_account_data: newAccountData
            };

            window.axios.post(jobStartUrl, postData).then((response) => {
                console.log('POST was OK');
                this.post.result        = '';
                this.post.errored       = false;
                this.post.startTimedOut = false;
                this.getJobStatus(true);
            }).catch((error) => {
                if (this.isLikelyGatewayTimeout(error)) {
                    console.warn('Start request timed out at gateway; continue with status polling.');
                    this.post.result        = this.errorToText(error);
                    this.post.errored       = false;
                    this.post.startTimedOut = true;
                    this.longRunningNotice  = true;

                    return;
                }
                console.error('JOB HAS FAILED :(');
                this.post.result        = this.errorToText(error);
                this.post.errored       = true;
                this.post.startTimedOut = false;
            }).finally(() => {
                           this.post.running = false;
                           this.getJobStatus(true);
                       }
            );
            this.getJobStatus(true);
        },
        stopPolling() {
            if (null !== this.polling.timerHandle) {
                window.clearTimeout(this.polling.timerHandle);
                this.polling.timerHandle = null;
            }
        },
        queueNextStatusPoll() {
            this.stopPolling();
            if (this.post.done || 'conv_done' === this.pageStatus.status || 'conv_errored' === this.pageStatus.status) {
                return;
            }
            const delay = this.longRunningNotice ? 2500 : 1000;
            this.polling.timerHandle = window.setTimeout(function () {
                this.getJobStatus();
            }.bind(this), delay);
        },
        redirectToImport() {
            window.location.href = this.nextUrl;
        },
        startRedirectCountdown() {
            this.cancelRedirectCountdown();
            this.redirectCountdown = 3;
            this.redirectTimerHandle = window.setInterval(function () {
                this.redirectCountdown = this.redirectCountdown - 1;
                if (this.redirectCountdown <= 0) {
                    this.cancelRedirectCountdown();
                    this.redirectToImport();
                }
            }.bind(this), 1000);
        },
        cancelRedirectCountdown() {
            if (null !== this.redirectTimerHandle) {
                window.clearInterval(this.redirectTimerHandle);
                this.redirectTimerHandle = null;
            }
        },
        skipToNextStep() {
            this.cancelRedirectCountdown();
            this.redirectToImport();
        },
        getJobStatus(force = false) {
            if (true === this.polling.inFlight) {
                return;
            }
            const statusUrl = './data-conversion/' + this.identifier + '/status';
            this.polling.inFlight = true;
            window.axios.get(statusUrl).then((response) => {
                this.pageStatus.status = response.data.status;
                this.runtime.lastSeenAt = Math.floor(Date.now() / 1000);
                if ('conv_running' === this.pageStatus.status || 'conv_done' === this.pageStatus.status) {
                    this.post.errored = false;
                }
                console.log('[a] Status is now ' + response.data.status + ' (' + this.checkCount + ')');

                // process messages, warnings and errors:
                this.messages.errors   = response.data.errors;
                this.messages.warnings = response.data.warnings;
                this.messages.messages = response.data.messages;
                this.pull.checklist    = response.data.pull_checklist || {};
                this.pull.progress     = response.data.pull_progress || {};
                if (Array.isArray(response.data.activity_log)) {
                    this.activityLog = response.data.activity_log;
                    this.$nextTick(() => {
                        const el = this.$refs.activityPre;
                        if (el) el.scrollTop = el.scrollHeight;
                    });
                }
                if (Array.isArray(response.data.transaction_board)) {
                    this.transactionBoard = response.data.transaction_board;
                    this.transactionBoardTotal = response.data.transaction_board_total || 0;
                    this.transactionBoardHidden = response.data.transaction_board_hidden || 0;
                }
                if ('conv_running' === this.pageStatus.status && 0 === this.runtime.runningStartedAt) {
                    this.runtime.runningStartedAt = this.runtime.lastSeenAt;
                }

                // job has not started yet. Let's wait.
                if (false === this.pageStatus.triedToStart && 'waiting_to_start' === this.pageStatus.status) {
                    this.pageStatus.status = response.data.status;
                    return;
                }
                // user pressed start, but it takes a moment.
                if (true === this.pageStatus.triedToStart && 'waiting_to_start' === this.pageStatus.status) {
                    //console.log('Job hasn\'t started yet, but its been tried.');
                }

                if (true === this.pageStatus.triedToStart && 'conv_errored' === this.pageStatus.status) {
                    console.error('Job status noticed job failed.');
                    this.status = response.data.status;
                    return;
                }

                if ('conv_running' === this.pageStatus.status) {
                    console.log('Conversion is running...')
                }
                if ('conv_done' === this.pageStatus.status) {
                    console.log('Job is done!');
                    this.post.done = true;
                    this.post.startTimedOut = false;
                    this.longRunningNotice = false;
                    if ('number' === typeof response.data.transaction_count) {
                        this.transactionCount = response.data.transaction_count;
                    }
                    // C4 fix: do not auto-redirect when zero transactions were found.
                    // The user should see the message and decide whether to go back or proceed.
                    if (0 === this.transactionCount) {
                        console.log('Zero transactions found; staying on conversion page.');
                        return;
                    }
                    this.startRedirectCountdown();
                    return;
                }
                if ('conv_errored' === this.pageStatus.status) {
                    console.error('Job is kill.');
                    console.error(response.data);
                    this.post.startTimedOut = false;
                    this.longRunningNotice = false;
                    return;
                }
            }).catch((error) => {
                if (this.isLikelyGatewayTimeout(error)) {
                    console.warn('Status polling hit gateway timeout; keep polling.');
                    this.post.result        = this.errorToText(error);
                    this.post.errored       = false;
                    this.post.startTimedOut = true;
                    this.longRunningNotice  = true;

                    return;
                }
                console.error('JOB HAS FAILED :(');
                this.post.result        = this.errorToText(error);
                this.post.errored       = true;
                this.post.startTimedOut = false;
            }).finally(() => {
                this.polling.inFlight = false;
                this.checkCount++;
                if (this.checkCount >= this.maxCheckCount && 'conv_running' === this.pageStatus.status) {
                    this.longRunningNotice = true;
                }
                this.queueNextStatusPoll();
            });
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
