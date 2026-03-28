@if($errors->has('connection'))
    <div class="alert alert-danger" role="alert">
        <strong>Connection Error:</strong> {{ $errors->first('connection') }}
    </div>
@endif

<div class="form-group row mb-3">
    <label for="trc20_api_key" class="col-sm-4 col-form-label">{{ __('import.label_trc20_api_key') }}</label>
    <div class="col-sm-8">
        @php
            $hasExistingKey = !empty($settings['trc20']['api_key']);
            $maskedKey      = $hasExistingKey ? str_repeat('*', max(0, strlen($settings['trc20']['api_key']) - 4)) . substr($settings['trc20']['api_key'], -4) : '';
            $rawKey         = $hasExistingKey ? $settings['trc20']['api_key'] : '';
        @endphp
        <div class="input-group">
            <input type="password"
                   class="form-control @if($errors->has('trc20_api_key')) is-invalid @endif"
                   id="trc20_api_key"
                   name="trc20_api_key"
                   autocomplete="off"
                   placeholder="{{ $hasExistingKey ? __('import.placeholder_trc20_api_key_keep') : __('import.help_trc20_api_key') }}"/>
            <button class="btn btn-outline-secondary" type="button" id="trc20_api_key_toggle"
                    onclick="var i=document.getElementById('trc20_api_key');var t=this.querySelector('i');if(i.type==='password'){i.type='text';t.classList.remove('fa-eye');t.classList.add('fa-eye-slash');}else{i.type='password';t.classList.remove('fa-eye-slash');t.classList.add('fa-eye');}"
                    aria-label="Toggle API key visibility">
                <i class="fas fa-eye"></i>
            </button>
        </div>
        @if($hasExistingKey)
            <small class="form-text text-success">
                Stored key: <code>{{ $maskedKey }}</code> &mdash; leave empty to keep it, or enter a new key to replace.
            </small>
        @endif
        @if($errors->has('trc20_api_key'))
            <div class="invalid-feedback d-block">
                {{ $errors->first('trc20_api_key') }}
            </div>
        @endif
        <small class="form-text text-muted">
            {{ __('import.help_trc20_api_key') }}
        </small>
    </div>
</div>

<div class="form-group row mb-3">
    <label for="trc20_wallets" class="col-sm-4 col-form-label">{{ __('import.label_trc20_wallets') }}</label>
    <div class="col-sm-8">
        <textarea
            rows="4"
            class="form-control @if($errors->has('trc20_wallets')) is-invalid @endif"
            id="trc20_wallets"
            name="trc20_wallets"
            autocomplete="off"
            placeholder="{{ __('import.placeholder_trc20_wallets') }}"
        >{{ $settings['trc20']['wallets'] }}</textarea>
        @if($errors->has('trc20_wallets'))
            <div class="invalid-feedback">
                {{ $errors->first('trc20_wallets') }}
            </div>
        @endif
        <small class="form-text text-muted">
            {{ __('import.help_trc20_wallets') }}
        </small>
    </div>
</div>
