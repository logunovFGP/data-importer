
<?php $__env->startSection('content'); ?>
    <div class="container">
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1><?php echo e($mainTitle); ?></h1>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        <?php echo e($subTitle); ?>

                    </div>
                    <div class="card-body">
                        <?php if('file' === $flow): ?>
                            <p>
                                The first step of your data import is that you upload your data file.
                            </p>
                        <?php endif; ?>
                        <?php if('file' !== $flow): ?>
                            <p>
                            The first (optional) step of your data import is that you upload a configuration file
                            from a previous run. If this is the first time ever you import data, this is obviously not possible and
                            you can skip this step. In a next step, you will be offered a configuration file that you can use here to make it easier for yourself.
                            </p>
                        <?php endif; ?>
                        <p>
                            Use the form elements below to upload your data.
                            If you need support, <a target="_blank"
                                                    href="https://docs.firefly-iii.org/how-to/">check
                                out the documentation</a>.
                        </p>
                        <p>
                            A configuration file is entirely <strong>optional</strong>. You can use it to pre-configure
                            the import options. In a later stage you may even use it for automation.
                            It will be generated for you by the data importer so you can download it.
                        </p>
                        <?php if('simplefin' === $flow): ?>
                        <p>
                            If your configuration already contains an encrypted SimpleFIN access URL, you do not need to fill in the "SimpleFIN token" field. If you are unsure,
                            using the SimpleFIN token field will overrule whatever (if any) access URL is in your configuration file.
                        </p>
                        <p>
                            <strong>Demo Mode:</strong> You can use demo mode to test the import process with sample data before connecting your real financial accounts.
                            Simply check the "Use demo mode" option below.
                        </p>
                        <?php endif; ?>
                        <?php if('basisbank' === $flow): ?>
                        <p>
                            Use this form to override your BasisBank API token for this import run. If your token requires a Consent-ID, add it here as well.
                        </p>
                        <?php endif; ?>
                        <?php if('tbank' === $flow): ?>
                        <p>
                            Use this form to override your TBank API token for this import run.
                        </p>
                        <?php endif; ?>
                        <?php if('trc20' === $flow): ?>
                        <p>
                            Use this form to override your TRC-20 API key and wallet list for this USDT import run.
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Form
                    </div>
                    <div class="card-body">
                        <form method="post" action="<?php echo e(route('new-import.post', [$flow])); ?>" accept-charset="UTF-8" id="store"
                              enctype="multipart/form-data">
                            <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>"/>

                            <!-- SimpleFIN options -->
                            <?php if('simplefin' === $flow): ?>
                                <?php echo $__env->make('import.003-upload.partials.simplefin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <?php endif; ?>
                            <?php if('basisbank' === $flow): ?>
                                <?php echo $__env->make('import.003-upload.partials.basisbank', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <?php endif; ?>
                            <?php if('tbank' === $flow): ?>
                                <?php echo $__env->make('import.003-upload.partials.tbank', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <?php endif; ?>
                            <?php if('trc20' === $flow): ?>
                                <?php echo $__env->make('import.003-upload.partials.trc20', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <?php endif; ?>

                            <!-- Importable FILE -->
                            <?php if('file' === $flow): ?>
                                <?php echo $__env->make('import.003-upload.partials.file', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <?php endif; ?>

                            <!-- Configuration file (for all flows)  -->
                            <?php echo $__env->make('import.003-upload.partials.config', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

                            <!-- Pre-made configuration file(s) -->
                            <?php echo $__env->make('import.003-upload.partials.premade-config', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

                            <div class="row">
                                <div class="col-lg-12">
                                    <button type="submit" class="float-end btn btn-primary">Next &rarr;</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-body">
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo e(route('index')); ?>" class="btn btn-secondary"><span class="fas fa-arrow-left"></span> Go back to the index</a>
                            <a href="<?php echo e(route('flush')); ?>" class="btn btn-danger text-white"><span class="fas fa-redo-alt"></span>
                                Start over entirely</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.v2', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/v2/import/003-upload/index.blade.php ENDPATH**/ ?>