<div class="row mt-3">
    <div class="col-lg-10 offset-lg-1">
        <div class="card">
            <div class="card-header">
                <?php if('trc20' === $flow): ?>
                    TRC-20 Wallet configuration
                <?php else: ?>
                    <?php echo e(config('importer.providers.' . $flow . '.title')); ?> account configuration
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if('trc20' === $flow): ?>
                    <p>Map your TRC-20 wallets to Firefly III accounts. You can link to existing accounts or create new ones during import.</p>
                <?php else: ?>
                    <p>Map your <?php echo e(config('importer.providers.' . $flow . '.title')); ?> accounts to Firefly III accounts. You can link to existing accounts or create new ones during import.</p>
                <?php endif; ?>

                <?php if(count($accounts) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless">
                            <thead>
                            <tr>
                                <?php if('trc20' === $flow): ?>
                                    <th style="width:45%">TRC-20 Wallet</th>
                                <?php else: ?>
                                    <th style="width:45%"><?php echo e(config('importer.providers.' . $flow . '.title')); ?> Account</th>
                                <?php endif; ?>
                                <th style="width:10%"></th>
                                <th style="width:45%">Firefly III Account</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $__currentLoopData = $accounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $information): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if (isset($component)) { $__componentOriginala3cb5910f37fc739c7c7ce4e155dde0e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3cb5910f37fc739c7c7ce4e155dde0e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.importer-account','data' => ['account' => $information,'configuration' => $configuration,'currencies' => $currencies,'flow' => $flow,'currencyPreflight' => $currencyPreflight ?? [],'currencyPreflightCodes' => $currencyPreflightCodes ?? []]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('importer-account'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['account' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($information),'configuration' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($configuration),'currencies' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($currencies),'flow' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($flow),'currencyPreflight' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($currencyPreflight ?? []),'currencyPreflightCodes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($currencyPreflightCodes ?? [])]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3cb5910f37fc739c7c7ce4e155dde0e)): ?>
<?php $attributes = $__attributesOriginala3cb5910f37fc739c7c7ce4e155dde0e; ?>
<?php unset($__attributesOriginala3cb5910f37fc739c7c7ce4e155dde0e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3cb5910f37fc739c7c7ce4e155dde0e)): ?>
<?php $component = $__componentOriginala3cb5910f37fc739c7c7ce4e155dde0e; ?>
<?php unset($__componentOriginala3cb5910f37fc739c7c7ce4e155dde0e); ?>
<?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <?php if('trc20' === $flow): ?>
                            <strong>No TRC-20 wallets found.</strong> Please ensure your settings are valid and try again.
                        <?php else: ?>
                            <strong>No <?php echo e(config('importer.providers.' . $flow . '.title')); ?> accounts found.</strong> Please ensure your settings are valid and try again.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php /**PATH /var/www/html/resources/views/v2/import/004-configure/partials/data-importer-accounts.blade.php ENDPATH**/ ?>