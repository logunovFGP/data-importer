@php
    $flow           = $flow ?? 'file';
    $currentStepNum = $currentStepNum ?? 1;
    $steps          = match ($flow) {
        'file'  => ['Auth', 'Configure', 'Roles', 'Map', 'Import'],
        default => ['Auth', 'Configure', 'Import'],
    };
@endphp
<nav aria-label="Import steps" class="mb-3">
    <div class="d-flex justify-content-between align-items-center">
        @foreach($steps as $i => $label)
            @php $num = $i + 1; @endphp
            <div class="text-center flex-fill">
                <div class="d-inline-block">
                    @if($num < $currentStepNum)
                        <span class="badge rounded-pill bg-success" style="width: 26px; height: 26px; line-height: 18px;">
                            <span class="fas fa-check" aria-hidden="true" style="font-size: 0.65rem;"></span>
                        </span>
                    @elseif($num === $currentStepNum)
                        <span class="badge rounded-pill bg-primary" style="width: 26px; height: 26px; line-height: 18px;">
                            {{ $num }}
                        </span>
                    @else
                        <span class="badge rounded-pill bg-secondary bg-opacity-25 text-muted" style="width: 26px; height: 26px; line-height: 18px;">
                            {{ $num }}
                        </span>
                    @endif
                </div>
                <div>
                    <small class="{{ $num === $currentStepNum ? 'fw-bold text-primary' : ($num < $currentStepNum ? 'text-success' : 'text-muted') }}">
                        {{ $label }}
                    </small>
                </div>
            </div>
        @endforeach
    </div>
</nav>
