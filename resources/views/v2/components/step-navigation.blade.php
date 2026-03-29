@php
    $backUrl          = $backUrl ?? null;
    $backLabel        = $backLabel ?? 'Go back';
    $identifier       = $identifier ?? null;
    $flow             = $flow ?? null;
    $showDownloadConfig = $showDownloadConfig ?? false;
    $currentStep      = $currentStep ?? null;
@endphp

@if($currentStep)
<nav aria-label="Import progress" class="mb-1">
    <small class="text-muted">
        @if($flow)<strong>{{ ucfirst($flow) }}</strong> &middot; @endif
        {{ $currentStep }}
    </small>
</nav>
@endif

<div class="d-flex flex-wrap gap-1 align-items-center" role="group" aria-label="Navigation">
    @if($backUrl)
    <a href="{{ $backUrl }}" class="btn btn-secondary btn-sm">
        <span class="fas fa-arrow-left" aria-hidden="true"></span> {{ $backLabel }}
    </a>
    @endif

    <a href="{{ route('index') }}" class="btn btn-outline-primary btn-sm"
       title="Go to main page without losing your session">
        <span class="fas fa-home" aria-hidden="true"></span> Main page
    </a>

    @if($identifier)
    <form method="POST" action="{{ route('abort-import', ['identifier' => $identifier]) }}"
          class="d-inline"
          onsubmit="return confirm('This will delete the current import job. Continue?')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-outline-warning btn-sm"
                title="Delete this import job and go to main page">
            <span class="fas fa-times-circle" aria-hidden="true"></span> Abort import
        </button>
    </form>
    @endif

    <form method="POST" action="{{ route('flush') }}"
          class="d-inline"
          onsubmit="return confirm('This will clear ALL session data including your Firefly III authentication. Continue?')">
        @csrf
        <button type="submit" class="btn btn-danger btn-sm"
                title="Clear all session data including authentication and start fresh">
            <span class="fas fa-redo-alt" aria-hidden="true"></span> Full reset session
        </button>
    </form>

    @if($showDownloadConfig && $identifier)
    <a href="{{ route('configure-import.download', [$identifier]) }}" class="btn btn-info text-white btn-sm"
       title="Download configuration file for quick re-import next time">
        <span class="fas fa-download" aria-hidden="true"></span> Download config
    </a>
    @endif
</div>
