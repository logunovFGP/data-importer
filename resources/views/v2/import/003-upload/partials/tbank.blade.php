@if($errors->has('connection'))
    <div class="alert alert-danger" role="alert">
        <strong>Connection Error:</strong> {{ $errors->first('connection') }}
    </div>
@endif

@if($settings['tbank']['session_ready'])
    <div class="alert alert-success" role="alert">
        {{ trans('import.tbank_upload_state_ready') }}
    </div>
@else
    <div class="alert alert-warning" role="alert">
        {{ trans('import.tbank_upload_state_missing') }}
    </div>
@endif

<div class="form-group row mb-3">
    <label for="tbank_auth_state" class="col-sm-4 col-form-label">TBank auth state</label>
    <div class="col-sm-8">
        <input type="text"
               class="form-control @if($errors->has('tbank_auth')) is-invalid @endif"
               id="tbank_auth_state"
               name="tbank_auth_state"
               autocomplete="off"
               value="{{ '' !== trim((string)$settings['tbank']['auth_state']) ? $settings['tbank']['auth_state'] : 'UNKNOWN' }}"
               placeholder="Authentication state"
               readonly />
        @if($errors->has('tbank_auth'))
            <div class="invalid-feedback">
                {{ $errors->first('tbank_auth') }}
            </div>
        @endif
    </div>
</div>

<div class="form-group row mb-3">
    <label for="tbank_session_id_preview" class="col-sm-4 col-form-label">TBank session ID</label>
    <div class="col-sm-8">
        <input type="text"
               class="form-control"
               id="tbank_session_id_preview"
               name="tbank_session_id_preview"
               autocomplete="off"
               value="{{ $settings['tbank']['session_id_preview'] }}"
               placeholder="No active session"
               readonly />
    </div>
</div>

<div class="form-group row mb-3">
    <label for="tbank_device_pin_state" class="col-sm-4 col-form-label">Device PIN</label>
    <div class="col-sm-8">
        <input type="text"
               class="form-control"
               id="tbank_device_pin_state"
               name="tbank_device_pin_state"
               autocomplete="off"
               value="{{ '' !== trim((string)$settings['tbank']['device_pin']) ? 'saved' : 'not saved' }}"
               placeholder="not saved"
               readonly />
        <small class="form-text text-muted">
            {{ trans('import.tbank_upload_hint') }}
        </small>
    </div>
</div>
