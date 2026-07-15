@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class(['admin-report-page']) }}>
    <div class="gap-2 mb-2 d-flex flex-column flex-lg-row align-items-lg-end justify-content-between admin-report-header">
        <div class="min-w-0">
            <h5 class="mb-1">{{ $title }}</h5>
            @if ($description)
                <p class="mb-0 text-600 fs-10">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            {{ $actions }}
        @endisset
    </div>

    {{ $slot }}
</div>
