@props([
    'lineLabel' => null,
    'layout' => 'cards',
])

<span hidden data-line-card-editor data-line-card-layout="{{ $layout }}" data-line-label="{{ $lineLabel ?? __('Line') }}" data-invalid-label="{{ __('Enter a valid value.') }}" data-remove-label="{{ __('Remove line') }}"></span>
<input type="hidden" name="_submission_token" value="{{ old('_submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
@pushOnce('styles', 'line-item-cards')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/line-item-cards.css') }}">
@endPushOnce
@pushOnce('scripts', 'line-item-cards-script')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Core/line-item-cards.js') }}"></script>
@endPushOnce
