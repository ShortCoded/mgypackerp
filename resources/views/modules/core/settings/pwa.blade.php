@extends('layouts.app')

@section('title', __('pwa.title'))

@php
    use Modules\Core\Models\ArchiveFile;
    use Modules\Core\Services\FilePickerService;
    use Modules\Core\Services\OperatingCompanyContextService;

    $value = fn (string $key, mixed $default = '') => old($key, $settings[$key] ?? $default);
    $checked = fn (string $key): bool => (bool) old($key, $settings[$key] ?? false);
    $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
    $filePicker = app(FilePickerService::class);
    $iconCards = [
        'icon_192' => [
            'field' => 'icon_192_archive_file_doc_num',
            'label' => __('pwa.fields.icon_192'),
            'url' => $settings['icon_192_url'] ?? null,
            'help' => __('pwa.help.icon_192'),
        ],
        'icon_512' => [
            'field' => 'icon_512_archive_file_doc_num',
            'label' => __('pwa.fields.icon_512'),
            'url' => $settings['icon_512_url'] ?? null,
            'help' => __('pwa.help.icon_512'),
        ],
        'icon_maskable' => [
            'field' => 'icon_maskable_archive_file_doc_num',
            'label' => __('pwa.fields.icon_maskable'),
            'url' => $settings['icon_maskable_url'] ?? null,
            'help' => __('pwa.help.icon_maskable'),
        ],
        'apple_touch_icon' => [
            'field' => 'apple_touch_icon_archive_file_doc_num',
            'label' => __('pwa.fields.apple_touch_icon'),
            'url' => $settings['apple_touch_icon_url'] ?? null,
            'help' => __('pwa.help.apple_touch_icon'),
        ],
    ];

    foreach ($iconCards as $key => $icon) {
        $selectedPublicId = trim((string) old($icon['field'], ''));
        $selectedFile = $selectedPublicId !== '' && $companyId
            ? $filePicker->selectableFileByPublicId($selectedPublicId, $companyId, FilePickerService::AcceptImage)
            : null;
        $selectedUrl = $selectedFile instanceof ArchiveFile
            ? route('admin.file-manager.files.preview', $selectedFile->doc_num)
            : null;
        $currentUrl = $selectedUrl ?: $icon['url'];

        $iconCards[$key]['selected_public_id'] = $selectedPublicId;
        $iconCards[$key]['current_url'] = $currentUrl;
        $iconCards[$key]['file_label'] = $selectedFile instanceof ArchiveFile
            ? $selectedFile->original_name
            : ($currentUrl ? __('pwa.icons.current_icon') : __('pwa.icons.default_icon'));
        $iconCards[$key]['button_label'] = $currentUrl ? __('pwa.actions.replace_icon') : __('pwa.actions.choose_icon');
    }
@endphp

@push('styles')
    <style>
        .pwa-settings-page .pwa-icon-preview-frame {
            width: 7.5rem;
            height: 7.5rem;
            min-width: 7.5rem;
            aspect-ratio: 1 / 1;
        }

        .pwa-settings-page .pwa-icon-preview-frame img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .pwa-settings-page .pwa-icon-card {
            min-height: 100%;
        }
    </style>
@endpush

@section('content')
    <form class="js-pwa-settings-form pwa-settings-page" action="{{ route('admin.settings.pwa.update') }}" method="POST" novalidate>
        @csrf

        <div class="mb-3 card">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ __('pwa.title') }}</h5>
                        <p class="mb-0 text-600 fs-10">{{ __('pwa.subtitle') }}</p>
                    </div>
                    @can('settings.pwa.update')
                        <div class="col-auto">
                            <button class="btn btn-primary" type="submit">
                                <span class="fas fa-save me-1"></span>{{ __('pwa.actions.save') }}
                            </button>
                        </div>
                    @endcan
                </div>
            </div>
        </div>

        <div class="mb-3 card">
            <div class="card-body">
                <div class="row align-items-center g-3">
                    <div class="col-md">
                        <h6 class="mb-1 text-800">{{ __('pwa.sections.availability') }}</h6>
                        <div class="text-600 fs-10">{{ __('pwa.help.availability') }}</div>
                    </div>
                    <div class="col-md-auto">
                        <x-forms.input type="hidden" name="enabled" value="0" />
                        <div class="mb-0 form-check form-switch">
                            <x-forms.input class="form-check-input" id="pwa-enabled" name="enabled" type="checkbox" value="1" :checked="$checked('enabled')" />
                            <label class="form-check-label fw-semibold" for="pwa-enabled">{{ __('pwa.fields.enabled') }}</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-3 card">
            <div class="card-header bg-body-tertiary">
                <h6 class="mb-0">{{ __('pwa.sections.identity') }}</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <x-forms.label for="pwa-app-name" :label="__('pwa.fields.app_name')" required />
                        <x-forms.input id="pwa-app-name" name="app_name" class="form-control @error('app_name') is-invalid @enderror" type="text" value="{{ $value('app_name') }}" required />
                        <div class="invalid-feedback" data-error-for="app_name">@error('app_name'){{ $message }}@enderror</div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="pwa-short-name" :label="__('pwa.fields.short_name')" required />
                        <x-forms.input id="pwa-short-name" name="short_name" class="form-control @error('short_name') is-invalid @enderror" type="text" value="{{ $value('short_name') }}" required />
                        <div class="invalid-feedback" data-error-for="short_name">@error('short_name'){{ $message }}@enderror</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="pwa-description">{{ __('pwa.fields.description') }}</label>
                        <x-forms.textarea id="pwa-description" name="description" class="form-control @error('description') is-invalid @enderror" rows="3">{{ $value('description') }}</x-forms.textarea>
                        <div class="invalid-feedback" data-error-for="description">@error('description'){{ $message }}@enderror</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-3 card">
            <div class="card-header bg-body-tertiary">
                <h6 class="mb-0">{{ __('pwa.sections.behavior') }} </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-4">
                        <x-forms.label for="pwa-display" :label="__('pwa.fields.display')" required />
                        <x-forms.select id="pwa-display" name="display" class="form-select @error('display') is-invalid @enderror" required>
                            @foreach ($displayModes as $mode)
                                <option value="{{ $mode }}" @selected($value('display') === $mode)>{{ __("pwa.display_modes.{$mode}") }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback" data-error-for="display">@error('display'){{ $message }}@enderror</div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <x-forms.label for="pwa-orientation" :label="__('pwa.fields.orientation')" required />
                        <x-forms.select id="pwa-orientation" name="orientation" class="form-select @error('orientation') is-invalid @enderror">
                            @foreach ($orientations as $orientation)
                                <option value="{{ $orientation }}" @selected($value('orientation') === $orientation)>{{ __("pwa.orientations.{$orientation}") }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback" data-error-for="orientation">@error('orientation'){{ $message }}@enderror</div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <x-forms.label for="pwa-direction" :label="__('pwa.fields.direction')" required />
                        <x-forms.select id="pwa-direction" name="direction" class="form-select @error('direction') is-invalid @enderror">
                            @foreach ($directions as $direction)
                                <option value="{{ $direction }}" @selected($value('direction') === $direction)>{{ __("pwa.directions.{$direction}") }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback" data-error-for="direction">@error('direction'){{ $message }}@enderror</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-3 card">
            <div class="card-header bg-body-tertiary">
                <h6 class="mb-0">{{ __('pwa.sections.offline') }}</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <x-forms.label for="pwa-offline-title" :label="__('pwa.fields.offline_title')" />
                        <x-forms.input id="pwa-offline-title" name="offline_title" class="form-control @error('offline_title') is-invalid @enderror" type="text" value="{{ $value('offline_title') }}" />
                        <div class="invalid-feedback" data-error-for="offline_title">@error('offline_title'){{ $message }}@enderror</div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="pwa-offline-message" :label="__('pwa.fields.offline_message')" />
                        <x-forms.textarea id="pwa-offline-message" name="offline_message" class="form-control @error('offline_message') is-invalid @enderror" rows="3">{{ $value('offline_message') }}</x-forms.textarea>
                        <div class="invalid-feedback" data-error-for="offline_message">@error('offline_message'){{ $message }}@enderror</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-3 card">
            <div class="card-header bg-body-tertiary">
                <h6 class="mb-0">{{ __('pwa.sections.icons') }}</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($iconCards as $input => $icon)
                        <div class="col-md-6 col-xl-3">
                            <div id="pwa-{{ str_replace('_', '-', $input) }}-field"
                                class="p-3 border rounded-2 bg-body-tertiary pwa-icon-card js-pwa-icon-card"
                                data-current-url="{{ $icon['url'] ?? '' }}"
                                data-current-label="{{ $icon['url'] ? __('pwa.icons.current_icon') : __('pwa.icons.default_icon') }}"
                                data-no-image-label="{{ __('pwa.icons.default_icon') }}"
                                data-selected-label="{{ __('pwa.icons.selected_icon') }}"
                                data-replace-label="{{ __('pwa.actions.replace_icon') }}">
                                <label class="form-label fw-semibold" for="pwa-{{ str_replace('_', '-', $input) }}-picker-button">{{ $icon['label'] }}</label>
                                <x-forms.input type="hidden" id="pwa-{{ str_replace('_', '-', $input) }}-archive-file" name="{{ $icon['field'] }}" value="{{ $icon['selected_public_id'] }}" />
                                <div class="gap-3 d-flex flex-column align-items-start">
                                    <div class="overflow-hidden bg-white border d-flex align-items-center justify-content-center rounded-2 pwa-icon-preview-frame">
                                        <img class="js-pwa-icon-preview @if (! $icon['current_url']) d-none @endif"
                                            src="{{ $icon['current_url'] ?? '' }}"
                                            alt="{{ $icon['label'] }}">
                                        <span class="fas fa-image text-400 fs-5 js-pwa-icon-placeholder @if ($icon['current_url']) d-none @endif"></span>
                                    </div>
                                    <div class="w-100">
                                        <div class="fw-semibold text-truncate js-pwa-icon-file-name" title="{{ $icon['file_label'] }}">{{ $icon['file_label'] }}</div>
                                        <div class="mt-1 small text-600">{{ $icon['help'] }}</div>
                                        @can('file_manager.view')
                                            <button type="button"
                                                id="pwa-{{ str_replace('_', '-', $input) }}-picker-button"
                                                class="mt-2 btn btn-falcon-primary btn-sm js-pwa-icon-picker-trigger"
                                                data-file-picker
                                                data-picker-accept="image"
                                                data-picker-max="1"
                                                data-picker-title="{{ __('pwa.actions.choose_icon') }}"
                                                data-picker-target-input="#pwa-{{ str_replace('_', '-', $input) }}-archive-file"
                                                data-picker-uploader="#pwa-{{ str_replace('_', '-', $input) }}-field"
                                                data-picker-collection="pwa_icon"
                                                data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                <span class="fas fa-images me-1"></span><span class="js-pwa-icon-button-label">{{ $icon['button_label'] }}</span>
                                            </button>
                                        @endcan
                                    </div>
                                </div>
                                <div class="invalid-feedback d-block" data-error-for="{{ $icon['field'] }}">@error($icon['field']){{ $message }}@enderror</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @can('settings.pwa.update')
            <div class="mb-3 card">
                <div class="card-footer bg-body-tertiary text-end">
                    <button class="btn btn-primary" type="submit">
                        <span class="fas fa-save me-1"></span>{{ __('pwa.actions.save') }}
                    </button>
                </div>
            </div>
        @endcan
    </form>

    @can('file_manager.view')
        <x-file-picker-modal />
    @endcan
@endsection

@push('scripts')
    @can('file_manager.view')
        <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
        <script src="{{ asset('assets/js/modules/Core/file-picker.js') }}"></script>
    @endcan
    <script src="{{ asset('assets/js/modules/Core/pwa-settings.js') }}"></script>
@endpush
