{{--
    Submission messages partial for the unified conversion page.
    Uses submissionMessages (normalized format) instead of messages,
    because the conversion page keeps conversion messages in messages
    and submission messages in submissionMessages.
--}}
<div x-show="showJobMessages()">
    <div class="submit-log-grid">
        <div class="submit-log-pane">
            <div class="submit-log-pane-header text-danger">Errors</div>
            <div class="submit-log-pane-body">
                <template x-if="0 === submissionMessages.errors.length">
                    <div class="text-muted small">No errors reported.</div>
                </template>
                <template x-for="entry in submissionMessages.errors" :key="'errors-' + entry.key">
                    <div class="submit-log-entry">
                        <div class="submit-log-line">
                            Line #<span x-text="entry.line"></span>
                        </div>
                        <ul>
                            <template x-for="(message, messageIndex) in entry.messages" :key="entry.key + '-e-' + messageIndex">
                                <li x-html="message"></li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>

        <div class="submit-log-pane">
            <div class="submit-log-pane-header text-warning">Warnings</div>
            <div class="submit-log-pane-body">
                <template x-if="0 === submissionMessages.warnings.length">
                    <div class="text-muted small">No warnings reported.</div>
                </template>
                <template x-for="entry in submissionMessages.warnings" :key="'warnings-' + entry.key">
                    <div class="submit-log-entry">
                        <div class="submit-log-line">
                            Line #<span x-text="entry.line"></span>
                        </div>
                        <ul>
                            <template x-for="(message, messageIndex) in entry.messages" :key="entry.key + '-w-' + messageIndex">
                                <li x-html="message"></li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>

        <div class="submit-log-pane">
            <div class="submit-log-pane-header text-info">Success</div>
            <div class="submit-log-pane-body">
                <template x-if="0 === submissionMessages.messages.length">
                    <div class="text-muted small">No success messages reported.</div>
                </template>
                <template x-for="entry in submissionMessages.messages" :key="'messages-' + entry.key">
                    <div class="submit-log-entry">
                        <div class="submit-log-line">
                            Line #<span x-text="entry.line"></span>
                        </div>
                        <ul>
                            <template x-for="(message, messageIndex) in entry.messages" :key="entry.key + '-m-' + messageIndex">
                                <li x-html="message"></li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
