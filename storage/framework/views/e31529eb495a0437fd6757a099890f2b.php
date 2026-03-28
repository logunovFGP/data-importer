<div class="row mt-3">
    <div class="col-lg-10 offset-lg-1">
        <div class="card">
            <div class="card-header">
                <?php echo e($subTitle); ?>

            </div>
            <div class="card-body">
                <?php if('file' === $flow): ?>
                    <p>
                        <?php if('camt' === $configuration->getContentType()): ?>
                            Even though camt.<?php echo e($camtType); ?> is a defined standard, you might want to customize. Some of the most important settings are below.
                            They apply to all records in the uploaded files. If you would like some support,
                            you won't find anything at <a
                                href="https://docs.firefly-iii.org/how-to/data-importer/import/csv/"
                                target="_blank">
                                this page.</a> right now.
                        <?php endif; ?>
                        <?php if('csv' === $configuration->getContentType()): ?>
                            Importable files come in many shapes and forms. Some of the most important
                            settings are below.
                            They apply to all lines in the file. If you would like some support, <a
                                href="https://docs.firefly-iii.org/how-to/data-importer/import/csv/"
                                target="_blank">
                                check out the documentation for this page.</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if('nordigen' === $flow || 'spectre' === $flow || 'lunchflow' === $flow || 'basisbank' === $flow || 'tbank' === $flow): ?>
                    <p>
                        Your
                        <?php if('nordigen' === $flow): ?>
                            GoCardless
                        <?php endif; ?>
                        <?php if('lunchflow' === $flow): ?>
                            Lunch Flow
                        <?php endif; ?>
                        <?php if('spectre' === $flow): ?>
                            Spectre
                        <?php endif; ?>
                        <?php if('basisbank' === $flow): ?>
                            BasisBank
                        <?php endif; ?>
                        <?php if('tbank' === $flow): ?>
                            TBank
                        <?php endif; ?>
                        import can be configured and fine-tuned.
                        <a href="https://docs.firefly-iii.org/how-to/data-importer/import/gocardless/"
                           target="_blank">Check
                            out the documentation for this page.</a>
                    </p>
                <?php endif; ?>
                <?php if('simplefin' === $flow): ?>
                    <p>
                        Configure how your SimpleFIN accounts will be mapped to Firefly III accounts.
                        You can map existing accounts or create new ones during import.
                        Accounts marked for import will have their transactions synchronized based on your date range settings.
                    </p>
                <?php endif; ?>
                <?php if('trc20' === $flow): ?>
                    <p>
                        Configure which TRC-20 wallets to include and how import dates/cursors are managed for USDT transactions.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php /**PATH /var/www/html/resources/views/v2/import/004-configure/partials/opening-box.blade.php ENDPATH**/ ?>