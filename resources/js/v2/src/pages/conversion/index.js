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

/**
 * Unified conversion + submission component.
 *
 * Lifecycle:
 *   conversion (poll /data-conversion/{id}/status)
 *     -> conv_done with transactions > 0 AND nextUrl points to submit
 *     -> auto-start submission (POST /submit-data/{id}/start)
 *     -> submission (poll /submit-data/{id}/status)
 *     -> submission_done  => show "View in Firefly III" link
 *
 * When nextUrl does NOT point to submit (e.g. mapping), the old redirect
 * behaviour is preserved.
 */
let index = function () {
    return {
        // ── shared identifiers ──────────────────────────────────────
        flow: '',
        identifier: '',
        nextUrl: '',

        // ── lifecycle phase: 'conversion' | 'submission' ────────────
        phase: 'conversion',

        // ── whether nextUrl points to the submit page ───────────────
        nextUrlIsSubmit: false,

        // ── page-level status (covers both phases) ──────────────────
        pageStatus: {
            triedToStart: false,
            status: 'init',          // init | waiting_to_start | conv_running | conv_done | conv_errored
                                     //       | submission_running | submission_done | submission_errored
        },
        post: {
            result: '',
            errored: false,
            startTimedOut: false,
            running: false,
            done: false,
        },

        // ── conversion-specific state ───────────────────────────────
        transactionCount: -1,
        redirectCountdown: 0,
        redirectTimerHandle: null,
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

        // ── submission-specific state ───────────────────────────────
        submissionProgress: {
            currentTransaction: 0,
            totalTransactions: 0,
            progressPercentage: 0,
            uniqueTransactions: 0,
            duplicateTransactions: 0,
        },
        submissionPerformance: {},
        submissionStatus: 'idle',    // idle | running | done | errored
        submissionMessages: {
            messages: [],
            warnings: [],
            errors: [],
        },
        manualRefreshAvailable: false,

        // ── shared UI state ─────────────────────────────────────────
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

        // ── polling ─────────────────────────────────────────────────
        polling: {
            inFlight: false,
            timerHandle: null,
        },
        longRunningNotice: false,
        checkCount: 0,
        maxCheckCount: 600,

        // ─────────────────────────────────────────────────────────────
        // Conversion-phase helpers (pull checklist, progress, etc.)
        // ─────────────────────────────────────────────────────────────
        showJobMessages() {
            if ('submission' === this.phase) {
                return this.submissionMessages.messages.length > 0
                    || this.submissionMessages.warnings.length > 0
                    || this.submissionMessages.errors.length > 0;
            }
            return Object.values(this.messages.messages).length > 0
                || Object.values(this.messages.warnings).length > 0
                || Object.values(this.messages.errors).length > 0;
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

        // ─────────────────────────────────────────────────────────────
        // Shared utility helpers
        // ─────────────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────────────
        // Submission-phase helpers (progress, performance, messages)
        // ─────────────────────────────────────────────────────────────
        getSubmissionProgressPercentage() {
            return this.submissionProgress.progressPercentage;
        },
        getSubmissionProgressWidth() {
            return this.submissionProgress.progressPercentage + '%';
        },
        getSubmissionProgressDisplay() {
            if (this.submissionProgress.totalTransactions === 0) {
                return '';
            }
            return this.submissionProgress.currentTransaction + ' / ' + this.submissionProgress.totalTransactions + ' transactions';
        },
        getSubmissionSummary() {
            if (this.submissionProgress.totalTransactions === 0 && this.submissionProgress.uniqueTransactions === 0 && this.submissionProgress.duplicateTransactions === 0) {
                return '';
            }
            return 'Imported ' + this.submissionProgress.uniqueTransactions + ' / ' + this.submissionProgress.totalTransactions + ' transactions, skipped ' + this.submissionProgress.duplicateTransactions + ' duplicate(s).';
        },
        hasSubmissionProgressData() {
            return this.submissionProgress.totalTransactions > 0;
        },
        hasSubmissionPerformanceData() {
            return this.getSubmissionPerformanceRows().length > 0;
        },
        getSubmissionPerformanceRows() {
            const labels = {
                duplicate_checks: 'Duplicate checks',
                duplicate_index_preload: 'Duplicate index preload',
                firefly_submissions: 'Firefly submissions',
                tag_updates: 'Tag updates',
                disk_saves: 'Status saves',
            };
            const rows = [];
            Object.keys(this.submissionPerformance || {}).forEach((bucket) => {
                const item = this.submissionPerformance[bucket] || {};
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

        // ─────────────────────────────────────────────────────────────
        // Visibility predicates — conversion phase
        // ─────────────────────────────────────────────────────────────
        showStartButton() {
            return 'conversion' === this.phase
                && ('init' === this.pageStatus.status || 'waiting_to_start' === this.pageStatus.status)
                && false === this.pageStatus.triedToStart
                && false === this.post.errored;
        },
        showWaitingButton() {
            return 'conversion' === this.phase
                && 'waiting_to_start' === this.pageStatus.status
                && true === this.pageStatus.triedToStart
                && false === this.post.errored;
        },
        showTooManyChecks() {
            return 'conversion' === this.phase
                && this.longRunningNotice
                && 'conv_running' === this.pageStatus.status;
        },
        showPostError() {
            return 'conversion' === this.phase
                && ('conv_errored' === this.pageStatus.status || this.post.errored);
        },
        showWhenRunning() {
            return 'conversion' === this.phase
                && 'conv_running' === this.pageStatus.status;
        },
        showWhenDone() {
            return 'conversion' === this.phase
                && 'conv_done' === this.pageStatus.status
                && 0 !== this.transactionCount;
        },
        showWhenDoneEmpty() {
            return 'conversion' === this.phase
                && 'conv_done' === this.pageStatus.status
                && 0 === this.transactionCount;
        },
        showIfError() {
            return 'conversion' === this.phase
                && 'conv_errored' === this.pageStatus.status
                && !this.post.errored;
        },
        hasAuthError() {
            const errors = Object.values(this.messages.errors || {});
            const authKeywords = ['re-authentication', 'otp', 'session', 'expired', 'login.aspx'];
            return errors.some((msg) => {
                const text = String(msg).toLowerCase();
                return authKeywords.some((kw) => text.includes(kw));
            });
        },

        // ─────────────────────────────────────────────────────────────
        // Visibility predicates — submission phase
        // ─────────────────────────────────────────────────────────────
        showSubmissionPhase() {
            return 'submission' === this.phase;
        },
        showSubmissionWaiting() {
            return 'submission' === this.phase
                && 'waiting_to_start' === this.pageStatus.status
                && true === this.pageStatus.triedToStart
                && false === this.post.errored;
        },
        showSubmissionTooManyChecks() {
            return 'submission' === this.phase
                && this.longRunningNotice
                && 'submission_running' === this.pageStatus.status;
        },
        showSubmissionPostError() {
            return 'submission' === this.phase
                && ('submission_errored' === this.pageStatus.status || this.post.errored);
        },
        showSubmissionRunning() {
            return 'submission' === this.phase
                && 'submission_running' === this.pageStatus.status;
        },
        showSubmissionDone() {
            return 'submission' === this.phase
                && 'submission_done' === this.pageStatus.status;
        },
        showSubmissionError() {
            return 'submission' === this.phase
                && 'submission_errored' === this.pageStatus.status
                && !this.post.errored;
        },

        // ─────────────────────────────────────────────────────────────
        // Actions — conversion
        // ─────────────────────────────────────────────────────────────
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
            this.phase = 'conversion';
            this.startJobButton();
        },

        // ─────────────────────────────────────────────────────────────
        // Actions — submission
        // ─────────────────────────────────────────────────────────────
        retrySubmission() {
            this.post.errored = false;
            this.post.done = false;
            this.post.result = '';
            this.post.running = false;
            this.pageStatus.status = 'init';
            this.pageStatus.triedToStart = false;
            this.submissionMessages.errors = [];
            this.submissionMessages.warnings = [];
            this.submissionMessages.messages = [];
            this.longRunningNotice = false;
            this.checkCount = 0;
            this.manualRefreshAvailable = false;
            this.phase = 'submission';
            this.startSubmission();
        },
        refreshSubmissionStatus() {
            this.checkCount = 0;
            this.manualRefreshAvailable = false;
            this.pageStatus.status = 'submission_running';
            this.getSubmissionJobStatus();
        },

        // ─────────────────────────────────────────────────────────────
        // Initialization
        // ─────────────────────────────────────────────────────────────
        init() {
            this.flow       = document.querySelector('#data-helper').dataset.flow;
            this.identifier = document.querySelector('#data-helper').dataset.identifier;
            this.nextUrl    = document.querySelector('#data-helper').dataset.url;
            // Detect whether the nextUrl points to the submit page
            this.nextUrlIsSubmit = this.nextUrl.includes('/submit-data/');
            this.phase = 'conversion';
            console.log('Flow is ' + this.flow);
            console.log('Identifier is ' + this.identifier);
            console.log('Next URL is submit: ' + this.nextUrlIsSubmit);
            this.getConversionJobStatus();
        },

        // ─────────────────────────────────────────────────────────────
        // Conversion: start + poll
        // ─────────────────────────────────────────────────────────────
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
            this.postConversionJobStart();
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
        postConversionJobStart() {
            this.post.running = true;
            const jobStartUrl = './data-conversion/' + this.identifier + '/start';

            // Collect new account data for SimpleFIN + Nordigen
            const newAccountData = this.collectNewAccountData();
            const postData       = {
                identifier: this.identifier,
                new_account_data: newAccountData
            };

            window.axios.post(jobStartUrl, postData).then((response) => {
                console.log('Conversion POST was OK');
                this.post.result        = '';
                this.post.errored       = false;
                this.post.startTimedOut = false;
                this.getConversionJobStatus(true);
            }).catch((error) => {
                if (this.isLikelyGatewayTimeout(error)) {
                    console.warn('Start request timed out at gateway; continue with status polling.');
                    this.post.result        = this.errorToText(error);
                    this.post.errored       = false;
                    this.post.startTimedOut = true;
                    this.longRunningNotice  = true;

                    return;
                }
                console.error('CONVERSION JOB HAS FAILED :(');
                this.post.result        = this.errorToText(error);
                this.post.errored       = true;
                this.post.startTimedOut = false;
            }).finally(() => {
                           this.post.running = false;
                           this.getConversionJobStatus(true);
                       }
            );
            this.getConversionJobStatus(true);
        },

        // ─────────────────────────────────────────────────────────────
        // Polling control
        // ─────────────────────────────────────────────────────────────
        stopPolling() {
            if (null !== this.polling.timerHandle) {
                window.clearTimeout(this.polling.timerHandle);
                this.polling.timerHandle = null;
            }
        },
        queueNextConversionPoll() {
            this.stopPolling();
            if (this.post.done || 'conv_done' === this.pageStatus.status || 'conv_errored' === this.pageStatus.status) {
                return;
            }
            const delay = this.longRunningNotice ? 2500 : 1000;
            this.polling.timerHandle = window.setTimeout(function () {
                this.getConversionJobStatus();
            }.bind(this), delay);
        },
        queueNextSubmissionPoll() {
            this.stopPolling();
            if (this.post.errored || this.post.done || 'submission_done' === this.pageStatus.status || 'submission_errored' === this.pageStatus.status) {
                return;
            }
            const delay = 1000;
            this.polling.timerHandle = window.setTimeout(function () {
                this.getSubmissionJobStatus();
            }.bind(this), delay);
        },

        // ─────────────────────────────────────────────────────────────
        // Redirect helpers (for non-submit nextUrl, e.g. mapping)
        // ─────────────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────────────
        // Conversion status polling
        // ─────────────────────────────────────────────────────────────
        getConversionJobStatus(force = false) {
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
                console.log('[conv] Status is now ' + response.data.status + ' (' + this.checkCount + ')');

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

                if (true === this.pageStatus.triedToStart && 'conv_errored' === this.pageStatus.status) {
                    console.error('Conversion job status noticed job failed.');
                    this.status = response.data.status;
                    return;
                }

                if ('conv_running' === this.pageStatus.status) {
                    console.log('Conversion is running...')
                }
                if ('conv_done' === this.pageStatus.status) {
                    console.log('Conversion is done!');
                    this.post.done = true;
                    this.post.startTimedOut = false;
                    this.longRunningNotice = false;
                    if ('number' === typeof response.data.transaction_count) {
                        this.transactionCount = response.data.transaction_count;
                    }
                    // Zero transactions: stay on conversion page so user sees the message
                    if (0 === this.transactionCount) {
                        console.log('Zero transactions found; staying on conversion page.');
                        return;
                    }
                    // If nextUrl points to submit, auto-start submission inline
                    if (this.nextUrlIsSubmit) {
                        console.log('Conversion done with transactions; auto-starting submission...');
                        this.transitionToSubmission();
                        return;
                    }
                    // Otherwise redirect (e.g. to mapping)
                    this.startRedirectCountdown();
                    return;
                }
                if ('conv_errored' === this.pageStatus.status) {
                    console.error('Conversion job is kill.');
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
                console.error('CONVERSION JOB HAS FAILED :(');
                this.post.result        = this.errorToText(error);
                this.post.errored       = true;
                this.post.startTimedOut = false;
            }).finally(() => {
                this.polling.inFlight = false;
                this.checkCount++;
                if (this.checkCount >= this.maxCheckCount && 'conv_running' === this.pageStatus.status) {
                    this.longRunningNotice = true;
                }
                this.queueNextConversionPoll();
            });
        },

        // ─────────────────────────────────────────────────────────────
        // Transition: conversion done -> submission
        // ─────────────────────────────────────────────────────────────
        transitionToSubmission() {
            this.phase = 'submission';
            this.submissionStatus = 'running';

            // Reset post state for the submission phase
            this.post.errored       = false;
            this.post.done          = false;
            this.post.result        = '';
            this.post.running       = false;
            this.post.startTimedOut = false;

            // Reset polling counters
            this.stopPolling();
            this.checkCount        = 0;
            this.longRunningNotice = false;
            this.manualRefreshAvailable = false;

            // Reset submission-specific state
            this.submissionProgress = {
                currentTransaction: 0,
                totalTransactions: 0,
                progressPercentage: 0,
                uniqueTransactions: 0,
                duplicateTransactions: 0,
            };
            this.submissionPerformance = {};
            this.submissionMessages = {
                messages: [],
                warnings: [],
                errors: [],
            };

            // Reset page status for the new phase
            this.pageStatus.triedToStart = false;
            this.pageStatus.status       = 'init';

            this.startSubmission();
        },

        // ─────────────────────────────────────────────────────────────
        // Submission: start + poll
        // ─────────────────────────────────────────────────────────────
        startSubmission() {
            if (this.post.running || this.pageStatus.triedToStart) {
                return;
            }
            this.pageStatus.triedToStart = true;
            this.pageStatus.status       = 'waiting_to_start';
            this.postSubmissionJobStart();
        },
        postSubmissionJobStart() {
            console.log('postSubmissionJobStart');
            this.post.running = true;
            const jobStartUrl = './submit-data/' + this.identifier + '/start';
            window.axios.post(jobStartUrl, null).then((response) => {
                console.log('Submission POST was OK');
            }).catch((error) => {
                if (error.response) {
                    console.error('Submission error response:', error.response.data);
                    console.error('Status code:', error.response.status);

                    if (524 === error.response.status) {
                        console.error('Error 524: A timeout occurred. The job is probably still running.');
                        this.pageStatus.triedToStart = true;
                        this.getSubmissionJobStatus();
                    }
                } else {
                    console.warn('Submission start request failed (network error). The job may still be running. Will keep checking status.');
                    this.pageStatus.triedToStart = true;
                }
            }).finally(() => {
                    this.post.running = false;
                    this.getSubmissionJobStatus();
                    this.pageStatus.triedToStart = true;
                }
            );
        },
        getSubmissionJobStatus() {
            const submitUrl = './submit-data/' + this.identifier + '/status';
            window.axios.get(submitUrl, {
                params: {'_': Date.now()},
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache',
                },
            }).then((response) => {
                this.pageStatus.status = response.data.status;
                this.submissionStatus = response.data.status;
                console.log('[submit] Status is now ' + response.data.status + ' (' + this.checkCount + ') (' + this.pageStatus.triedToStart + ')');

                // process messages, warnings and errors (normalized for submission):
                this.submissionMessages.errors = this.normalizeMessageBuckets(response.data.errors);
                this.submissionMessages.warnings = this.normalizeMessageBuckets(response.data.warnings);
                this.submissionMessages.messages = this.normalizeMessageBuckets(response.data.messages);

                // process progress data:
                this.submissionProgress.currentTransaction = response.data.currentTransaction || 0;
                this.submissionProgress.totalTransactions = response.data.totalTransactions || 0;
                this.submissionProgress.progressPercentage = response.data.progressPercentage || 0;
                this.submissionProgress.uniqueTransactions = response.data.uniqueTransactions || 0;
                this.submissionProgress.duplicateTransactions = response.data.duplicateTransactions || 0;
                this.submissionPerformance = response.data.performance || {};
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

                // job has not started yet. Let's wait.
                if (false === this.pageStatus.triedToStart && 'waiting_to_start' === this.pageStatus.status) {
                    this.pageStatus.status = response.data.status;
                    return;
                }

                if (true === this.pageStatus.triedToStart && 'submission_errored' === this.pageStatus.status) {
                    console.error('Submission job status noticed job failed.');
                    this.submissionStatus = 'errored';
                    return;
                }

                if ('submission_running' === this.pageStatus.status) {
                    console.log('Submission is running...')
                }
                if ('submission_done' === this.pageStatus.status) {
                    console.log('Submission is done!');
                    this.post.done = true;
                    this.submissionStatus = 'done';
                    return;
                }
                if ('submission_errored' === this.pageStatus.status) {
                    console.error('Submission job is kill.');
                    this.submissionStatus = 'errored';
                    this.pageStatus.triedToStart = true;
                    this.post.errored = true;
                }
            }).catch((error) => {
                if (!error.response) {
                    console.warn('Submission status poll network error, retrying...');
                } else if ([502, 503, 504].includes(error.response.status)) {
                    console.warn('Gateway timeout during submission status poll, retrying...');
                } else {
                    console.error('Submission status poll error:', error.response.status);
                    this.post.result = error;
                    this.post.errored = true;
                }
            });
            this.checkCount++;
            if (this.checkCount >= 1200 && !this.longRunningNotice) {
                this.longRunningNotice = true;
                this.manualRefreshAvailable = true;
            }
            if (!this.post.errored && !this.post.done) {
                this.queueNextSubmissionPoll();
            }
        },
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
