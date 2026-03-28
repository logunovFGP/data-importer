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
                        <p>Hi there!</p>
                        <p>
                            Your configuration file needs parsing. This page now shows live status while the parser is running.
                        </p>
                        <div class="border rounded p-3 bg-light" role="status" aria-live="polite" aria-atomic="true">
                            <div class="d-flex align-items-center mb-2">
                                <div class="spinner-border spinner-border-sm text-primary me-2" aria-hidden="true"></div>
                                <strong id="parse-status-line">Preparing parser...</strong>
                            </div>
                            <div class="small text-muted mb-2" id="parse-phase-line">Phase: initializing</div>
                            <div class="small text-muted mb-2" id="parse-elapsed-line">Elapsed: 0s</div>
                            <div class="progress" style="height: 8px;">
                                <div id="parse-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                                     style="width: 5%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="5"></div>
                            </div>
                        </div>
                        <noscript>
                            <p>
                                <a href="<?php echo e(route('configure-import.index', [$identifier])); ?>?parse=true">Please follow
                                    this link to continue</a>.
                            </p>
                        </noscript>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('scripts'); ?>
    <script type="text/javascript">
        window.onload = function () {
            const parseUrl = '<?php echo e(route('configure-import.index', [$identifier])); ?>?parse=true';
            const statusLine = document.getElementById('parse-status-line');
            const phaseLine = document.getElementById('parse-phase-line');
            const elapsedLine = document.getElementById('parse-elapsed-line');
            const progressBar = document.getElementById('parse-progress-bar');

            let elapsedSeconds = 0;
            let progress = 5;

            const setProgress = function (value) {
                const bounded = Math.max(0, Math.min(100, value));
                progress = bounded;
                progressBar.style.width = bounded + '%';
                progressBar.setAttribute('aria-valuenow', String(bounded));
            };

            const updatePhase = function (elapsed) {
                if (elapsed < 3) {
                    phaseLine.textContent = 'Phase: initializing';
                    return;
                }
                if (elapsed < 10) {
                    phaseLine.textContent = 'Phase: parsing configuration';
                    return;
                }
                if (elapsed < 30) {
                    phaseLine.textContent = 'Phase: collecting accounts and currencies';
                    return;
                }
                phaseLine.textContent = 'Phase: finalizing import setup';
            };

            const timer = window.setInterval(function () {
                elapsedSeconds += 1;
                elapsedLine.textContent = 'Elapsed: ' + elapsedSeconds + 's';
                updatePhase(elapsedSeconds);

                if (elapsedSeconds < 10) {
                    setProgress(progress + 7);
                    return;
                }
                if (elapsedSeconds < 30) {
                    setProgress(progress + 2);
                    return;
                }
                setProgress(progress + 1);
            }, 1000);

            const startParsing = async function () {
                statusLine.textContent = 'Parser is running...';
                try {
                    const response = await fetch(parseUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const payload = await response.json();
                    if (!response.ok || payload.success !== true) {
                        // Errors are flashed to session by the server; redirect to the
                        // URL it provided so the user sees them on the destination page.
                        setProgress(100);
                        progressBar.classList.remove('progress-bar-animated');
                        progressBar.classList.replace('bg-primary', 'bg-danger');
                        statusLine.textContent = payload.message || 'Parsing failed. Redirecting...';
                        window.location.href = payload.redirect || '<?php echo e(route('new-import.index', ['file'])); ?>';
                        return;
                    }
                    setProgress(100);
                    statusLine.textContent = payload.message || 'Parsing finished. Redirecting...';
                    window.location.href = payload.redirect || '<?php echo e(route('configure-import.index', [$identifier])); ?>';
                } catch (error) {
                    statusLine.textContent = 'Parser is still running. Opening parser endpoint...';
                    window.location.href = parseUrl;
                } finally {
                    window.clearInterval(timer);
                }
            };

            window.setTimeout(startParsing, 250);
        };
    </script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.v2', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/v2/import/004-configure/parsing.blade.php ENDPATH**/ ?>