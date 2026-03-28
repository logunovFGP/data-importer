@extends('layout.v2')
@section('content')
    @php
        $message       = (string)($message ?? '');
        $errorBodyRaw  = (string)($body ?? '');
        $combinedLower = strtolower($message . ' ' . $errorBodyRaw);
        $isBasisBank    = str_contains($combinedLower, 'basisbank');
    @endphp

    <div class="container">
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1>Whoops!</h1>
                <p>Sorry, the Firefly III Data Importer could not continue.</p>

                @if('' !== $message)
                    <h2>Error message</h2>
                    <p class="text-danger">{{ $message }}</p>
                @endif

                @if($isBasisBank)
                    <h2>BasisBank auth steps</h2>
                    <p>Please check each item before retrying:</p>
                    <ul>
                        <li>Confirm login and password are correct.</li>
                        <li>If prompted for OTP, submit the latest SMS/app code immediately.</li>
                        <li>If your device trust flow is requested, enter the trusted-device confirmation code.</li>
                        <li>If the session appears to be expired, clear the data importer session and re-authenticate from the start.</li>
                    </ul>
                @endif

                @if('' !== $errorBodyRaw)
                    <h2>More details</h2>
                    <pre class="text-break">{{ $errorBodyRaw }}</pre>
                @endif

                <h2>Get help</h2>
                <p>
                    Open the application logs in <code>storage/logs</code> and check entries around this failure.
                    If running Docker, use <code>docker logs -f [container]</code>.
                </p>
                <p>
                    You're welcome to open an issue on <a href="https://github.com/firefly-iii/firefly-iii/issues">GitHub</a>.
                    Include the message and logs shown here, plus exactly what action was performed.
                </p>

                <a href="{{ route('index') }}" class="btn btn-secondary"><span class="fas fa-arrow-left"></span> Back to importer</a>
            </div>
        </div>
    </div>
@endsection
