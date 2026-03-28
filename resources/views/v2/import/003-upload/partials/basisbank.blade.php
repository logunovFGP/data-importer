@if($errors->has('connection'))
    <div class="alert alert-danger" role="alert">
        <strong>Connection Error:</strong> {{ $errors->first('connection') }}
    </div>
@endif

<div class="form-group row mb-3">
    <label for="basisbank_api_token" class="col-sm-4 col-form-label">BasisBank API token</label>
    <div class="col-sm-8">
        <input type="text"
               class="form-control @if($errors->has('basisbank_api_token')) is-invalid @endif"
               id="basisbank_api_token"
               name="basisbank_api_token"
               autocomplete="off"
               value="{{ $settings['basisbank']['api_token'] }}"
               placeholder="BasisBank API token"/>
        @if($errors->has('basisbank_api_token'))
            <div class="invalid-feedback">
                {{ $errors->first('basisbank_api_token') }}
            </div>
        @endif
    </div>
</div>

<div class="form-group row mb-3">
    <label for="basisbank_consent_id" class="col-sm-4 col-form-label">BasisBank Consent-ID</label>
    <div class="col-sm-8">
        <input type="text"
               class="form-control @if($errors->has('basisbank_consent_id')) is-invalid @endif"
               id="basisbank_consent_id"
               name="basisbank_consent_id"
               autocomplete="off"
               value="{{ $settings['basisbank']['consent_id'] }}"
               placeholder="BasisBank Consent-ID (optional)"/>
        @if($errors->has('basisbank_consent_id'))
            <div class="invalid-feedback">
                {{ $errors->first('basisbank_consent_id') }}
            </div>
        @endif
    </div>
</div>
