@if(count($fileImportTypes ?? []) > 0)
<div class="form-group row mb-3">
    <label for="file_import_type" class="col-sm-4 col-form-label">Import type</label>
    <div class="col-sm-8">
        <select class="form-control" id="file_import_type" name="file_import_type" aria-describedby="fileImportTypeHelp">
            @foreach($fileImportTypes as $typeKey => $typeInfo)
                <option value="{{ $typeKey }}"
                        @if(($selectedFileImportType ?? 'manual') === $typeKey) selected @endif
                        label="{{ $typeInfo['label'] }}">
                    {{ $typeInfo['label'] }}
                </option>
            @endforeach
        </select>
        <small id="fileImportTypeHelp" class="form-text text-muted">
            Select a preset for known export formats or keep manual setup.
        </small>
    </div>
</div>
@endif

<div class="form-group row mb-3">
    <label for="importable_file" class="col-sm-4 col-form-label">Importable file</label>
    <div class="col-sm-8">
        <input type="file"
               class="form-control
                                           @if($errors->has('importable_file')) is-invalid @endif"
               id="importable_file" name="importable_file"
               placeholder="Importable file"
               accept=".xml,.csv"/>
        @if($errors->has('importable_file'))
            <div class="invalid-feedback">
                {{ $errors->first('importable_file') }}
            </div>
        @endif
    </div>
</div>
