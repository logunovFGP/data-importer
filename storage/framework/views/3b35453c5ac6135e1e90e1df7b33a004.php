<?php if(count($fileImportTypes ?? []) > 0): ?>
<div class="form-group row mb-3">
    <label for="file_import_type" class="col-sm-4 col-form-label">Import type</label>
    <div class="col-sm-8">
        <select class="form-control" id="file_import_type" name="file_import_type" aria-describedby="fileImportTypeHelp">
            <?php $__currentLoopData = $fileImportTypes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $typeKey => $typeInfo): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($typeKey); ?>"
                        <?php if(($selectedFileImportType ?? 'manual') === $typeKey): ?> selected <?php endif; ?>
                        label="<?php echo e($typeInfo['label']); ?>">
                    <?php echo e($typeInfo['label']); ?>

                </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select>
        <small id="fileImportTypeHelp" class="form-text text-muted">
            Select a preset for known export formats or keep manual setup.
        </small>
    </div>
</div>
<?php endif; ?>

<div class="form-group row mb-3">
    <label for="importable_file" class="col-sm-4 col-form-label">Importable file</label>
    <div class="col-sm-8">
        <input type="file"
               class="form-control
                                           <?php if($errors->has('importable_file')): ?> is-invalid <?php endif; ?>"
               id="importable_file" name="importable_file"
               placeholder="Importable file"
               accept=".xml,.csv"/>
        <?php if($errors->has('importable_file')): ?>
            <div class="invalid-feedback">
                <?php echo e($errors->first('importable_file')); ?>

            </div>
        <?php endif; ?>
    </div>
</div>
<?php /**PATH /var/www/html/resources/views/v2/import/003-upload/partials/file.blade.php ENDPATH**/ ?>