<div class="form-group row mb-3">
    <label for="config_file" class="col-sm-4 col-form-label">Optional configuration
        file</label>
    <div class="col-sm-8">
        <input type="file" class="form-control <?php $__errorArgs = ['config_file'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" id="config_file" name="config_file"
               placeholder="Configuration file"
               accept=".json"/>
        <?php $__errorArgs = ['config_file'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <div class="invalid-feedback">
                <?php echo e($message); ?>

            </div>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>

    </div>
</div>
<?php /**PATH /var/www/html/resources/views/v2/import/003-upload/partials/config.blade.php ENDPATH**/ ?>