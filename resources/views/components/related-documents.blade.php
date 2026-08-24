@props(['documents' => collect(), 'title' => __('Related documents and source lineage')])

@php($items = collect($documents)->filter(fn (array $document) => filled($document['number'] ?? null)))
@if($items->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ $title }}</h6></div>
        <div class="card-body"><div class="row g-3">
            @foreach($items as $document)
                @php($canOpen = filled($document['url'] ?? null) && (! filled($document['permission'] ?? null) || auth()->user()?->can($document['permission'])))
                <div class="col-md-4">
                    <strong>{{ $document['label'] }}</strong>
                    <div>@if($canOpen)<a href="{{ $document['url'] }}">{{ $document['number'] }}</a>@else<span>{{ $document['number'] }}</span>@endif @if(filled($document['meta'] ?? null))<small class="text-muted">· {{ $document['meta'] }}</small>@endif</div>
                </div>
            @endforeach
        </div></div>
    </div>
@endif
