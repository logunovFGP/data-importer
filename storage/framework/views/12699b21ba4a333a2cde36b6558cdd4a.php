<?php $__env->startSection('content'); ?>

    <!-- another tiny hack to get data from a to b -->
    <span id="data-helper" data-flow="<?php echo e($flow); ?>" data-identifier="<?php echo e($identifier); ?>" data-url="<?php echo e($nextUrl); ?>"></span>

    <div class="container" x-data="index">
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1><?php echo e($mainTitle); ?></h1>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <?php echo $__env->make('components.step-navigation', [
                    'backUrl' => $jobBackUrl,
                    'backLabel' => 'Go back to previous step',
                    'identifier' => $identifier,
                    'flow' => $flow,
                    'showDownloadConfig' => true,
                    'currentStep' => 'Convert',
                ], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </div>
        <div id="app">
            <div class="row mt-3">
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
                                    <a href="<?php echo e(route('authenticate-flow.index', [$flow])); ?>" class="btn btn-primary btn-sm ms-2">Re-authenticate</a>
                                </div>
                            </template>
                            <?php if (isset($component)) { $__componentOriginalb775ecbeac984fa2fddae202aedadca2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb775ecbeac984fa2fddae202aedadca2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.conversion-messages','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('conversion-messages'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $attributes = $__attributesOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $component = $__componentOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__componentOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
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
                                                <div class="progress" style="height: 8px;">
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
                            <?php if (isset($component)) { $__componentOriginalb775ecbeac984fa2fddae202aedadca2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb775ecbeac984fa2fddae202aedadca2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.conversion-messages','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('conversion-messages'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $attributes = $__attributesOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $component = $__componentOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__componentOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
                        </div>
                        <div x-show="showWhenDone()" class="card-body">
                            <p>
                                <span class="fas fa-sync fa-spin"></span> The conversion routine has finished 🎉. Please
                                wait to be redirected!
                            </p>
                            <?php if (isset($component)) { $__componentOriginalb775ecbeac984fa2fddae202aedadca2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb775ecbeac984fa2fddae202aedadca2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.conversion-messages','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('conversion-messages'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $attributes = $__attributesOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $component = $__componentOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__componentOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
                        </div>
                        <div x-show="showWhenDoneEmpty()" class="card-body">
                            <div class="alert alert-warning mb-3">
                                <span class="fas fa-exclamation-triangle"></span>
                                <strong>No transactions found.</strong>
                                The conversion completed successfully, but no transactions were returned for the selected date range and wallets.
                            </div>
                            <p>You can go back to adjust your settings (date range, wallets, accounts) and try again, or proceed to the submit step with zero transactions.</p>
                            <?php if (isset($component)) { $__componentOriginalb775ecbeac984fa2fddae202aedadca2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb775ecbeac984fa2fddae202aedadca2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.conversion-messages','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('conversion-messages'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $attributes = $__attributesOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $component = $__componentOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__componentOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
                            <div class="mt-3">
                                <a href="<?php echo e($jobBackUrl); ?>" class="btn btn-secondary me-2">
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
                                    <a href="<?php echo e(route('authenticate-flow.index', [$flow])); ?>" class="btn btn-primary btn-sm ms-2">Re-authenticate</a>
                                </div>
                            </template>
                            <?php if (isset($component)) { $__componentOriginalb775ecbeac984fa2fddae202aedadca2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb775ecbeac984fa2fddae202aedadca2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.conversion-messages','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('conversion-messages'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $attributes = $__attributesOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__attributesOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb775ecbeac984fa2fddae202aedadca2)): ?>
<?php $component = $__componentOriginalb775ecbeac984fa2fddae202aedadca2; ?>
<?php unset($__componentOriginalb775ecbeac984fa2fddae202aedadca2); ?>
<?php endif; ?>
                            <button class="btn btn-warning mt-2" @click="retryConversion">Retry conversion</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New Account Creation Section (SimpleFIN + Nordigen + Lunch Flow) -->
        <?php if(config('importer.providers.'.$flow.'.supports_new_accounts') && count($newAccountsToCreate) > 0): ?>
            <div class="row mt-3">
                <div class="col-lg-10 offset-lg-1">
                    <div class="card border-warning">
                        <div class="card-header">
                            <h5 class="mb-0"><span class="fas fa-plus-circle"></span> New accounts to create</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">
                                The following accounts will be created in Firefly III before importing transactions. You
                                can customize their settings below or proceed with the default values.
                            </p>

                            <?php $__currentLoopData = $newAccountsToCreate; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $accountId => $accountData): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h6 class="card-title"><?php echo e($accountData['name'] ?? 'New account'); ?></h6>
                                                <small class="text-muted">Account: <?php echo e($accountId); ?></small>
                                            </div>
                                            <div class="col-md-6">
                                                <form class="new-account-form" data-account-id="<?php echo e($accountId); ?>">
                                                    <input type="hidden" name="liability_type" value="<?php echo e($accountData['liability_type'] ?? ''); ?>">
                                                    <input type="hidden" name="liability_direction" value="<?php echo e($accountData['liability_direction'] ?? ''); ?>">
                                                    <div class="form-group mb-2">
                                                        <label class="form-label">Account name:</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                               name="account_name"
                                                               value="<?php echo e($accountData['name'] ?? ''); ?>" required>
                                                    </div>

                                                    <div class="form-group mb-2">
                                                        <label class="form-label">Account type:</label>
                                                        <select class="form-control form-control-sm" name="account_type"
                                                                required>
                                                            <option value="asset" <?php if($accountData['type'] === 'asset'): ?> selected <?php endif; ?>>Asset account</option>
                                                            <option value="liabilities" <?php if($accountData['type'] === 'liabilities'): ?> selected <?php endif; ?>>Liability account</option>
                                                        </select>
                                                        <small class="form-text text-muted">Smart default: asset account
                                                            (recommended for most accounts)</small>
                                                    </div>

                                                    <div class="form-group mb-2">
                                                        <label class="form-label">Currency:</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                               name="account_currency"
                                                               value="<?php echo e($accountData['currency'] ?? 'EUR'); ?>"
                                                               maxlength="12" required>
                                                        <small class="form-text text-muted">3-letter currency
                                                            code</small>
                                                    </div>

                                                    <div class="form-group mb-2">
                                                        <label class="form-label">Opening balance (optional):</label>
                                                        <input type="number" step="0.01"
                                                               class="form-control form-control-sm"
                                                               name="opening_balance" placeholder="0.00">
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                            <div class="alert alert-info">
                                <small>
                                    <span class="fas fa-info-circle"></span>
                                    These accounts will be created automatically when you start the conversion process.
                                    You can modify the details above or proceed with the defaults.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <!-- end of simplefin code -->

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
                <?php echo $__env->make('components.step-navigation', [
                    'backUrl' => $jobBackUrl,
                    'backLabel' => 'Go back to previous step',
                    'identifier' => $identifier,
                    'flow' => $flow,
                    'showDownloadConfig' => true,
                    'currentStep' => 'Convert',
                ], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </div>


    </div>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('scripts'); ?>
    <?php echo app('Illuminate\Foundation\Vite')(['src/pages/conversion/index.js']); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.v2', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/v2/import/007-convert/index.blade.php ENDPATH**/ ?>