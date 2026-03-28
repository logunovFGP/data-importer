<div x-show="showJobMessages()">
    <div class="submit-log-grid">
        <div class="submit-log-pane">
            <div class="submit-log-pane-header text-danger">Errors</div>
            <div class="submit-log-pane-body">
                <template x-if="0 === messages.errors.length">
                    <div class="text-muted small">No errors reported.</div>
                </template>
                <template x-for="entry in messages.errors" :key="'errors-' + entry.key">
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
                <template x-if="0 === messages.warnings.length">
                    <div class="text-muted small">No warnings reported.</div>
                </template>
                <template x-for="entry in messages.warnings" :key="'warnings-' + entry.key">
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
                <template x-if="0 === messages.messages.length">
                    <div class="text-muted small">No success messages reported.</div>
                </template>
                <template x-for="entry in messages.messages" :key="'messages-' + entry.key">
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
