<?php $__env->startSection('content'); ?>
    <div class="container" x-data="index">
        <!-- this is a bit of a hack, but it works well enough to sync AlpineJS and the configuration object -->
        <span id="date-range-helper" data-date-range="<?php echo e($configuration->getDateRange()); ?>"></span>
        <span id="date-format-helper" data-date-format="<?php echo e($configuration->getDate()); ?>"></span>
        <span id="detection-method-helper" data-method="<?php echo e($configuration->getDuplicateDetectionMethod()); ?>"></span>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1><?php echo e($mainTitle); ?></h1>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <?php echo $__env->make('components.step-navigation', [
                    'backUrl' => route('new-import.index', [$flow]),
                    'backLabel' => 'Go back to upload',
                    'identifier' => $identifier,
                    'flow' => $flow,
                    'showDownloadConfig' => true,
                    'currentStep' => 'Configure',
                ], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </div>

        <!-- error -->
        <?php if(!$errors->isEmpty()): ?>
            <div class="row mt-3">
                <div class="col-lg-10 offset-lg-1">
                    <div class="card">
                        <div class="card-header">
                            Errors :(
                        </div>
                        <div class="card-body">
                            <p class="text-danger">Some error(s) occurred:</p>
                            <ul>
                                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <li class="text-danger"><?php echo e($error); ?></li>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <!-- end of error -->


        <!-- user has no accounts -->
        <?php echo $__env->make('import.004-configure.partials.no-account-warning', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

        <!-- user has accounts! -->
        <?php if(count($applicationAccounts['assets']) > 0 || count($applicationAccounts['liabilities']) > 0 || $flow !== 'file'): ?>
            <!-- opening box with instructions -->
            <?php echo $__env->make('import.004-configure.partials.opening-box', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

            <!-- start of form -->
            <form method="post" action="<?php echo e(route('configure-import.post', [$identifier])); ?>" accept-charset="UTF-8" id="store">
                <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>"/>

                <!-- overrule settings when the flow is not "file" -->
                <?php if('file' !== $flow): ?>
                    <input type="hidden" name="ignore_duplicate_transactions" value="1"/>
                <?php endif; ?>

                

                <!-- Account selection and date range settings for all third party data providers -->
                <?php if('file' !== $flow): ?>
                    <?php echo $__env->make('import.004-configure.partials.data-importer-accounts', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    <?php echo $__env->make('import.004-configure.partials.data-importer-dates', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <?php endif; ?>


                <!-- spectre specific options -->
                <?php if('spectre' === $flow): ?>
                    <?php echo $__env->make('import.004-configure.partials.spectre-options', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <?php endif; ?>
                <!-- end of spectre options -->

                <!-- Nordigen / GoCardless specific options -->
                <?php if('nordigen' === $flow): ?>
                    <?php echo $__env->make('import.004-configure.partials.gocardless-options', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <?php endif; ?>
                <!-- end of Nordigen / GoCardless options -->

                <!-- camt.053 options -->
                <?php if('file' === $flow && 'camt'  === $configuration->getContentType()): ?>
                    <?php echo $__env->make('import.004-configure.partials.camt-053-options', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <?php endif; ?>
                <!-- end of camt.053 options -->
                <!-- start of CSV options -->
                <?php if('file' === $flow && 'csv'  === $configuration->getContentType()): ?>
                    <?php echo $__env->make('import.004-configure.partials.csv-options', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <?php endif; ?>
                <!-- end of CSV options -->

                <!-- generic import options -->
                <?php echo $__env->make('import.004-configure.partials.generic-options', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <!-- end of generic import options -->

                <!-- duplicate detection options -->
                <?php echo $__env->make('import.004-configure.partials.duplicate-detection-options', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <!-- end of duplicate detection options -->

                <!-- other options -->
                <?php echo $__env->make('import.004-configure.partials.import-options', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

                <!-- end of other options -->

                <!-- start of submit button -->
                <div class="row mt-3">
                    <div class="col-lg-10 offset-lg-1">
                        <div class="card">
                            <div class="card-header">
                                Submit!
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-lg-12">
                                        <button type="submit" class="float-end btn btn-primary">Submit &rarr;</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <?php echo $__env->make('components.step-navigation', [
                    'backUrl' => route('new-import.index', [$flow]),
                    'backLabel' => 'Go back to upload',
                    'identifier' => $identifier,
                    'flow' => $flow,
                    'showDownloadConfig' => true,
                    'currentStep' => 'Configure',
                ], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </div>
    </div>


<?php $__env->stopSection(); ?>
<?php $__env->startSection('scripts'); ?>
    <?php echo app('Illuminate\Foundation\Vite')(['src/pages/configuration/index.js']); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.v2', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/v2/import/004-configure/index.blade.php ENDPATH**/ ?>