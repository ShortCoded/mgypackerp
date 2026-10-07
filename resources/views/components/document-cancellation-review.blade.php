@props(['record'])
@php($review = app(\App\Services\DocumentCancellationReviewService::class)->review($record, request()))
@if($review)
    <details class="card mb-3" id="document-cancellation-review" data-cancellation-review @if(request()->boolean('review_cancellation')) open @endif>
        <summary class="card-header py-2">{{ __('cancellation_review.title') }} — {{ $review['document'] }}</summary>
        <div class="card-body">
            <p>{{ __('cancellation_review.help') }}</p>
            <p class="small text-600">{{ __('cancellation_review.source_period') }}: {{ $review['period'] }}
                @unless($review['period_unbound']) · {{ $review['period_open'] ? __('cancellation_review.period_open') : __('cancellation_review.period_closed') }} @endunless</p>
            @if($review['blockers'])
                <div class="alert alert-warning"><ul class="mb-0">@foreach($review['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
            @endif
            @if($review['links'])
                <h6>{{ __('cancellation_review.dependencies') }}</h6>
                <ul>@foreach($review['links'] as $link)<li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>@endforeach</ul>
                <p class="small text-600">{{ __('cancellation_review.bounded_links') }}</p>
            @endif
            @if($review['steps'])
                <h6>{{ __('cancellation_review.manual_steps') }}</h6>
                <ol>@foreach($review['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
            @endif
            @if($review['cancellation_audit'])
                @php($cancellationProperties = json_decode($review['cancellation_audit']->properties, true, flags: JSON_THROW_ON_ERROR))
                <div class="alert alert-info">
                    {{ __('cancellation_review.reason') }}: {{ $cancellationProperties['reason'] ?? '' }}
                    · {{ app(\Modules\Core\Services\DateFormatService::class)->formatDateTime($review['cancellation_audit']->created_at) }}
                </div>
            @endif
            @if($review['action'])
                <form method="POST" action="{{ $review['action']['url'] }}" data-document-cancellation-form
                    data-error-message="{{ __('cancellation_review.operation_failed') }}">
                    @csrf
                    <input type="hidden" name="_submission_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    @foreach($review['action']['fields'] as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                    <label class="form-label w-100">{{ __('cancellation_review.reason') }}
                        <textarea class="form-control mt-1" name="{{ $review['action']['reason_field'] }}" required maxlength="1000" rows="3"></textarea>
                    </label>
                    <div class="alert alert-danger d-none" role="alert" data-cancellation-error></div>
                    <div><button class="btn btn-danger btn-sm" type="submit">{{ $review['action']['label'] ?? ($review['action']['reversal'] ? __('cancellation_review.reverse') : __('cancellation_review.cancel')) }}</button></div>
                </form>
            @endif
            @if($review['delete_action'])
                <form class="mt-3" method="POST" action="{{ $review['delete_action']['url'] }}" data-document-cancellation-form
                    data-success-url="{{ $review['delete_action']['success_url'] }}" data-error-message="{{ __('cancellation_review.operation_failed') }}">
                    @csrf
                    @method('DELETE')
                    <p class="small text-600">{{ __('cancellation_review.delete_help') }}</p>
                    <div class="alert alert-danger d-none" role="alert" data-cancellation-error></div>
                    <button class="btn btn-outline-danger btn-sm" type="submit">{{ __('cancellation_review.archive') }}</button>
                </form>
            @endif
        </div>
    </details>
    @once
        @push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/document-cancellation-review.js') }}"></script>@endpush
    @endonce
@endif
