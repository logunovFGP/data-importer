@php
    $boardDataVar = $boardDataVar ?? 'transactionBoard';
    $totalVar     = $totalVar ?? 'transactionBoardTotal';
    $hiddenVar    = $hiddenVar ?? 'transactionBoardHidden';
@endphp

<div class="card mt-3" x-show="{{ $boardDataVar }}.length > 0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            Transaction board
            <span class="badge bg-secondary ms-1" x-text="{{ $totalVar }} + ' total'"></span>
        </span>
        <button class="btn btn-sm btn-outline-secondary" type="button"
                @click="boardExpanded = !boardExpanded"
                x-text="boardExpanded ? 'Collapse' : 'Expand'">
        </button>
    </div>
    <div x-show="boardExpanded" x-transition>
        <div x-show="{{ $hiddenVar }} > 0"
             class="text-center py-2 bg-body-secondary border-bottom">
            <small class="text-muted">
                <span class="fas fa-ellipsis-h me-1" aria-hidden="true"></span>
                and <strong x-text="{{ $hiddenVar }}"></strong> more transactions processed
            </small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 importer-data-table">
                <thead>
                    <tr class="text-muted">
                        <th style="width: 2rem;"></th>
                        <th>TX ID</th>
                        <th class="text-end">Amount</th>
                        <th style="width: 2rem;">Dir</th>
                        <th>Counterparty</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(tx, txIndex) in {{ $boardDataVar }}" :key="txIndex">
                        <tr>
                            <td>
                                <span x-show="tx.status === 'fetched'" class="fas fa-download text-primary" title="Fetched" aria-hidden="true"></span>
                                <span x-show="tx.status === 'converting'" class="fas fa-cog fa-spin text-primary" title="Converting" aria-hidden="true"></span>
                                <span x-show="tx.status === 'submitted'" class="fas fa-check-circle text-success" title="Submitted" aria-hidden="true"></span>
                                <span x-show="tx.status === 'duplicate'" class="fas fa-forward text-warning" title="Duplicate" aria-hidden="true"></span>
                                <span x-show="tx.status === 'error'" class="fas fa-times-circle text-danger" :title="tx.message || 'Error'" aria-hidden="true"></span>
                                <span x-show="tx.status === 'pending'" class="fas fa-clock text-muted" title="Pending" aria-hidden="true"></span>
                            </td>
                            <td><code x-text="tx.tx_id" ></code></td>
                            <td class="text-end font-monospace" x-text="tx.amount + ' ' + tx.currency"></td>
                            <td class="text-center">
                                <span x-show="tx.direction === 'incoming'" class="text-success">&larr;</span>
                                <span x-show="tx.direction === 'outgoing'" class="text-danger">&rarr;</span>
                            </td>
                            <td><code x-text="tx.counterparty" ></code></td>
                            <td class="text-muted" x-text="tx.date"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
