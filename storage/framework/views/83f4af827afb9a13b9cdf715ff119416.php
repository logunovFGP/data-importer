<?php if($errors->has('connection')): ?>
    <div class="alert alert-danger" role="alert">
        <strong>Connection Error:</strong> <?php echo e($errors->first('connection')); ?>

    </div>
<?php endif; ?>

<div class="form-group row mb-3">
    <label for="trc20_api_key" class="col-sm-4 col-form-label"><?php echo e(__('import.label_trc20_api_key')); ?></label>
    <div class="col-sm-8">
        <?php $hasExistingKey = !empty($settings['trc20']['api_key']); ?>
        <input type="password"
               class="form-control <?php if($errors->has('trc20_api_key')): ?> is-invalid <?php endif; ?>"
               id="trc20_api_key"
               name="trc20_api_key"
               autocomplete="off"
               placeholder="<?php echo e($hasExistingKey ? __('import.placeholder_trc20_api_key_keep') : __('import.help_trc20_api_key')); ?>"/>
        <?php if($hasExistingKey): ?>
            <small class="form-text text-success">API key is stored. Leave empty to keep it.</small>
        <?php endif; ?>
        <?php if($errors->has('trc20_api_key')): ?>
            <div class="invalid-feedback">
                <?php echo e($errors->first('trc20_api_key')); ?>

            </div>
        <?php endif; ?>
        <small class="form-text text-muted">
            <?php echo e(__('import.help_trc20_api_key')); ?>

        </small>
    </div>
</div>

<div class="form-group row mb-3">
    <label for="trc20_wallets" class="col-sm-4 col-form-label"><?php echo e(__('import.label_trc20_wallets')); ?></label>
    <div class="col-sm-8">
        <textarea
            rows="4"
            class="form-control <?php if($errors->has('trc20_wallets')): ?> is-invalid <?php endif; ?>"
            id="trc20_wallets"
            name="trc20_wallets"
            autocomplete="off"
            placeholder="<?php echo e(__('import.placeholder_trc20_wallets')); ?>"
        ><?php echo e($settings['trc20']['wallets']); ?></textarea>
        <?php if($errors->has('trc20_wallets')): ?>
            <div class="invalid-feedback">
                <?php echo e($errors->first('trc20_wallets')); ?>

            </div>
        <?php endif; ?>
        <small class="form-text text-muted">
            <?php echo e(__('import.help_trc20_wallets')); ?>

        </small>
    </div>
</div>
<?php /**PATH /var/www/html/resources/views/v2/import/003-upload/partials/trc20.blade.php ENDPATH**/ ?>