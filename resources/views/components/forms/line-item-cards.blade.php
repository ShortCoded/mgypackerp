<span hidden data-line-card-editor data-line-label="{{ $lineLabel ?? __('Line') }}" data-invalid-label="{{ __('Enter a valid value.') }}" data-remove-label="{{ __('Remove line') }}"></span>
<input type="hidden" name="_submission_token" value="{{ old('_submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
@pushOnce('styles', 'line-item-cards')
    <link rel="stylesheet" href="{{ asset('assets/css/line-item-cards.css').'?v='.filemtime(public_path('assets/css/line-item-cards.css')) }}">
@endPushOnce
@pushOnce('scripts', 'line-item-cards-script')
    <script src="{{ asset('assets/js/modules/Core/line-item-cards.js').'?v='.filemtime(public_path('assets/js/modules/Core/line-item-cards.js')) }}"></script>
@endPushOnce
