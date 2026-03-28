@extends('layout.v2')
@section('content')
    <div class="container">
        <div class="row mt-4">
            <div class="col-lg-10 offset-lg-1">
                <h1>Firefly III Data Import Tool,
                    @if(str_starts_with($version, 'develop'))
                        {{ $version }}
                    @else
                        v{{ $version }}
                    @endif

                </h1>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Authenticate with Firefly III
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            Welcome! This tool will help you import data into Firefly III.
                        </p>
                        <p>
                            This tool is sparsely documented, you can find all the details you need
                            in the <a href="https://docs.firefly-iii.org/" target="_blank">
                                documentation</a>. Any links you see to the docs will open in a new window or tab.
                        </p>
                        <p class="card-text">
                            @if('' !== (string)$baseUrl)
                                In order to get access to your Firefly III installation at <a
                                href="{{ $baseUrl }}">{{ $baseUrl }}</a>
                                @if('' !== (string)$vanityUrl)
                                    (<a href="{{ $vanityUrl }}">{{ $vanityUrl }}</a>)
                                @endif
                                , you need to create an OAuth client and submit its Client ID.
                            @else
                                In order to get access to your Firefly III installation, you need to create an OAuth client and submit its Client ID.
                            @endif
                        </p>
                        <div class="alert alert-info" role="alert">
                            <strong>Required OAuth settings</strong><br>
                            1. Open Firefly III <code>Profile -> OAuth -> Create New Client</code>.<br>
                            2. Set <strong>Name</strong> to any label (for example: <code>Data Importer</code>).<br>
                            3. Set <strong>Redirect URL</strong> to:<br>
                            <code>{{ route('token.callback') }}</code><br>
                            4. Leave <strong>Confidential</strong> unchecked.<br>
                            5. Click <strong>Create</strong> and enter the generated <strong>Client ID</strong> below.
                        </div>
                        <div class="alert alert-warning" role="alert">
                            If <strong>Confidential</strong> is enabled, token exchange will fail with
                            <code>invalid_client</code> / <code>Client authentication failed</code>.
                        </div>
                        <div class="mb-3">
                            <p class="mb-2"><strong>Example of correct client dialog settings:</strong></p>
                            <img
                                src="{{ asset('images/oauth-client-setup-example.svg') }}"
                                alt="OAuth create client example: Name any value, Redirect URL set to callback URL, Confidential checkbox unchecked."
                                class="img-fluid border rounded"
                            >
                        </div>
                        @foreach($errors->all() as $error)
                            <p class="text-danger">{{ $error }}</p>
                        @endforeach

                        <form action="{{ route('token.submitClientId') }}" method="POST">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                            @if('' === (string)$baseUrl)
                                <div class="form-group mb-3">
                                    <label for="input_base_url">Firefly III URL</label>
                                    <input type="url" placeholder="https://" value="{{ $baseUrl }}" class="form-control" id="input_base_url" autocomplete="off" name="base_url">
                                    @if($errors->has('base_url'))
                                        <span class="text-danger">{{ $errors->first('base_url') }}</span>
                                    @endif
                                    @if(session()->has('secure_url'))
                                        <span class="text-danger">{{ session()->get('secure_url') }}</span>
                                    @endif
                                </div>
                            @endif
                            <div class="form-group mb-3">
                                <label for="input_client_id">Client ID</label>
                                <input type="number" step="1" min="1" class="form-control" id="input_client_id" autocomplete="off" name="client_id" value="{{ $clientId }}">
                                @if($errors->has('client_id'))
                                    <span class="text-danger">{{ $errors->first('client_id') }}</span>
                                @endif
                            </div>
                            <input type="submit" name="submit" value="Submit" class="float-end text-white btn btn-success"/>
                        </form>

                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-body">
                        <p>
                            <a class="btn btn-danger text-white btn-sm" href="{{ route('flush') }}" data-bs-toggle="tooltip"
                               data-bs-placement="top" title="This button resets your progress">Start over</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection
