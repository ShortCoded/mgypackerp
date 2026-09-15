@props([
    'title',
    'emptyMessage',
    'hasRows' => false,
    'columns' => 1,
])

<div class="card">
    <div class="card-header py-2"><h6 class="mb-0">{{ $title }}</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr>{{ $head }}</tr></thead>
            <tbody>
                @if($hasRows)
                    {{ $slot }}
                @else
                    <tr><td colspan="{{ $columns }}" class="text-center text-600 py-4">{{ $emptyMessage }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
