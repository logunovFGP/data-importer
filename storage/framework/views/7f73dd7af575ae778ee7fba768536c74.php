<?php if(count($list) > 0): ?>
    <div class="form-group row mb-3">
        <label for="config_file" class="col-sm-4 col-form-label">Pre-made configuration
            file</label>
        <div class="col-sm-8">
            <select class="form-control" name="existing_config">
                <option value="" label="Upload or manual config">Upload or manual config
                </option>
                <?php $__currentLoopData = $list; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $file): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($file); ?>" label="<?php echo e($file); ?>"><?php echo e($file); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
        </div>
    </div>
<?php endif; ?>
<?php /**PATH /var/www/html/resources/views/v2/import/003-upload/partials/premade-config.blade.php ENDPATH**/ ?>