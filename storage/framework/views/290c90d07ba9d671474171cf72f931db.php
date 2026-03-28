
<?php $__env->startSection('content'); ?>
    <div class="container">
        <div class="row mt-4">
            <div class="col-lg-10 offset-lg-1">
                <h1>Firefly III Data Import Tool,
                    <?php if(str_starts_with($version, 'develop')): ?>
                        <?php echo e($version); ?>

                    <?php else: ?>
                        v<?php echo e($version); ?>

                    <?php endif; ?>

                </h1>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Authenticate with Firefly III
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            Welcome! This tool will help you import data into Firefly III.
                        </p>
                        <p>
                            This tool is sparsely documented, you can find all the details you need
                            in the <a href="https://docs.firefly-iii.org/" target="_blank">
                                documentation</a>. Any links you see to the docs will open in a new window or tab.
                        </p>
                        <p class="card-text">
                            <?php if('' !== (string)$baseUrl): ?>
                                In order to get access to your Firefly III installation at <a
                                href="<?php echo e($baseUrl); ?>"><?php echo e($baseUrl); ?></a>
                                <?php if('' !== (string)$vanityUrl): ?>
                                    (<a href="<?php echo e($vanityUrl); ?>"><?php echo e($vanityUrl); ?></a>)
                                <?php endif; ?>
                                , you need to create an OAuth client and submit its Client ID.
                            <?php else: ?>
                                In order to get access to your Firefly III installation, you need to create an OAuth client and submit its Client ID.
                            <?php endif; ?>
                        </p>
                        <div class="alert alert-info" role="alert">
                            <strong>Required OAuth settings</strong><br>
                            1. Open Firefly III <code>Profile -> OAuth -> Create New Client</code>.<br>
                            2. Set <strong>Name</strong> to any label (for example: <code>Data Importer</code>).<br>
                            3. Set <strong>Redirect URL</strong> to:<br>
                            <code><?php echo e(route('token.callback')); ?></code><br>
                            4. Leave <strong>Confidential</strong> unchecked.<br>
                            5. Click <strong>Create</strong> and enter the generated <strong>Client ID</strong> below.
                        </div>
                        <div class="alert alert-warning" role="alert">
                            If <strong>Confidential</strong> is enabled, token exchange will fail with
                            <code>invalid_client</code> / <code>Client authentication failed</code>.
                        </div>
                        <div class="mb-3">
                            <p class="mb-2"><strong>Example of correct client dialog settings:</strong></p>
                            <img
                                src="<?php echo e(asset('images/oauth-client-setup-example.svg')); ?>"
                                alt="OAuth create client example: Name any value, Redirect URL set to callback URL, Confidential checkbox unchecked."
                                class="img-fluid border rounded"
                            >
                        </div>
                        <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <p class="text-danger"><?php echo e($error); ?></p>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                        <form action="<?php echo e(route('token.submitClientId')); ?>" method="POST">
                            <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>"/>
                            <?php if('' === (string)$baseUrl): ?>
                                <div class="form-group mb-3">
                                    <label for="input_base_url">Firefly III URL</label>
                                    <input type="url" placeholder="https://" value="<?php echo e($baseUrl); ?>" class="form-control" id="input_base_url" autocomplete="off" name="base_url">
                                    <?php if($errors->has('base_url')): ?>
                                        <span class="text-danger"><?php echo e($errors->first('base_url')); ?></span>
                                    <?php endif; ?>
                                    <?php if(session()->has('secure_url')): ?>
                                        <span class="text-danger"><?php echo e(session()->get('secure_url')); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="form-group mb-3">
                                <label for="input_client_id">Client ID</label>
                                <input type="number" step="1" min="1" class="form-control" id="input_client_id" autocomplete="off" name="client_id" value="<?php echo e($clientId); ?>">
                                <?php if($errors->has('client_id')): ?>
                                    <span class="text-danger"><?php echo e($errors->first('client_id')); ?></span>
                                <?php endif; ?>
                            </div>
                            <input type="submit" name="submit" value="Submit" class="float-end text-white btn btn-success"/>
                        </form>

                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-body">
                        <p>
                            <a class="btn btn-danger text-white btn-sm" href="<?php echo e(route('flush')); ?>" data-bs-toggle="tooltip"
                               data-bs-placement="top" title="This button resets your progress">Start over</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.v2', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/v2/token/client_id.blade.php ENDPATH**/ ?>