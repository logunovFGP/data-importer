<?php $__env->startSection('content'); ?>
    <div class="container" x-data="index">
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1>Firefly III Data Importer,
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
                        Firefly III Data Importer,
                        <?php if(str_starts_with($version, 'develop')): ?>
                            <?php echo e($version); ?>

                        <?php else: ?>
                            v<?php echo e($version); ?>

                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            Welcome! This tool will help you import data into Firefly III. You can find instructions in
                            the <a href="https://docs.firefly-iii.org/" target="_blank">documentation</a>. Any links you
                            see to the documentation will open in a <em>new</em> window or tab.
                            To import data, you need to authenticate with Firefly III, and optionally with one of the
                            data sources this importer supports.
                        </p>
                        <?php if($pat): ?>
                            <p id="firefly_expl">
                                You're using a Personal Access Token to <span class="text-info">authenticate</span> to
                                Firefly III.
                            </p>
                        <?php endif; ?>
                        <?php if($clientIdWithURL): ?>
                            <p id="firefly_expl">
                                You're using a fixed Client ID and a fixed Firefly III URL to <span class="text-info">authenticate</span>
                                to Firefly III.
                            </p>
                        <?php endif; ?>
                        <?php if($URLonly): ?>
                            <p id="firefly_expl">
                                You're using a Client ID and a fixed Firefly III URL to <span
                                    class="text-info">authenticate</span> to Firefly III.
                            </p>
                        <?php endif; ?>
                        <?php if($flexible): ?>
                            <p id="firefly_expl">
                                You're using a self-submitted Client ID and Firefly III URL to <span class="text-info">authenticate</span>
                                to Firefly III.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="row" style="margin-top:1em;" x-show="pageProperties.connectionError">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Configuration or connection error :(
                    </div>
                    <div class="card-body">
                        <p>The importer could not connect to Firefly III. Please remedy the error below first, and check
                            out the <a href="https://docs.firefly-iii.org/references/faq/data-importer/general/"
                                       target="_blank">documentation</a> if necessary.</p>
                        <p class="text-danger" x-text="pageProperties.connectionErrorMessage"></p>
                    </div>
                </div>
            </div>
        </div>
        <?php if('' !== $warning): ?>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="alert alert-warning" role="alert">
                    <?php echo $warning; ?>

                </div>
            </div>
        </div>
        <?php endif; ?>
            
        <?php if(!empty($recentJobs)): ?>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Recent imports
                    </div>
                    <div class="card-body">
                        <p>
                            These are your recent import jobs from the last 24 hours. You can resume any of them
                            by clicking the <strong>Resume</strong> button.
                        </p>
                        <table class="table table-sm table-striped">
                            <thead>
                            <tr>
                                <th>Data source</th>
                                <th>Current step</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $__currentLoopData = $recentJobs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $job): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <tr>
                                    <td><?php echo e(ucfirst($job['flow'])); ?></td>
                                    <td><?php echo e(ucfirst($job['step'])); ?></td>
                                    <td><?php echo e($job['created']); ?></td>
                                    <td><a href="<?php echo e($job['url']); ?>" class="btn btn-sm btn-outline-primary">Resume</a></td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Create a new import job
                    </div>
                    <div class="card-body">
                        <p>
                            To start importing data into Firefly III, select your data source below and press the [Start] button.
                        </p>
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th style="width:33%;">Import data souce</th>
                                <th style="width:33%;">Availability</th>
                                <th>Button!</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-for="flow in importFlows">
                                <tr>
                                    <td><span x-text="flow.title"></span>
                                        <span x-show="'' !== flow.explanation">
                                            <span>
                                        <br>
                                        <small class="text-muted" x-text="flow.explanation"></small>
                                                </span>
                                        </span>
                                    </td>
                                    <td>
                                        <span x-show="flow.loading" class="fas fa-cog fa-spin"></span>
                                        <small x-show="!flow.error && !flow.loading && !flow.enabled" class="text-danger">Not available yet.</small>
                                        <span  x-show="!flow.error && !flow.loading &&  flow.enabled && !flow.authenticated" class="text-primary">Needs authentication details</span>
                                        <span  x-show="!flow.error && !flow.loading &&  flow.enabled &&  flow.authenticated" class="text-success">Available</span>
                                        <span  x-show="flow.error" class="text-danger" x-text="flow.errorMessage"></span>
                                    </td>
                                    <td>
                                        <span x-show="flow.loading" class="fas fa-cog fa-spin"></span>
                                        <a x-show="!flow.error && !flow.loading && true === flow.enabled && false === flow.authenticated" :href="'<?php echo e(route('authenticate-flow.index', [''])); ?>/' + flow.key" class="btn btn-sm btn-primary">Authenticate</a>
                                        <a x-show="!flow.error && !flow.loading && true === flow.enabled && flow.authenticated" :href="'<?php echo e(route('new-import.index', [''])); ?>/' + flow.key" class="btn btn-sm btn-success">Start</a>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="row" style="margin-top:1em;" id="importers">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Extra information
                    </div>
                    <div class="card-body">
                        <p>If you change your settings, you may need to press <strong>Full reset session</strong> for the
                            settings to be recognized. If you are in doubt if the button works: your session identifier
                            is "<?php echo e($identifier); ?>" and should change every time you
                            press the <?php if(!$isDocker): ?>
                                button,
                            <?php else: ?>
                                button or restart the container,
                            <?php endif; ?> but it has to stay the same when you simply refresh the page.
                        </p>
                        <p>
                            <a class="btn btn-danger text-white btn-sm" href="<?php echo e(route('flush')); ?>"
                               data-bs-toggle="tooltip" data-bs-placement="top"
                               title="Clear all session data including authentication and start fresh"
                               onclick="return confirm('This will clear ALL session data including your Firefly III authentication. Continue?')">
                                <span class="fas fa-redo-alt" aria-hidden="true"></span> Full reset session</a>
                            <a class="btn btn-secondary btn-sm" onclick="window.location.reload(true)">Only refresh the
                                page</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>
<?php $__env->startSection('scripts'); ?>
    <?php echo app('Illuminate\Foundation\Vite')(['src/pages/index/index.js']); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.v2', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/v2/index.blade.php ENDPATH**/ ?>