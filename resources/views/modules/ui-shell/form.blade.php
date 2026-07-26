@extends('layouts.app')

@php
    $isReadonly = $mode === 'view';
    $title = __('erp_ui_shell.titles.'.$mode, ['screen' => $definition->title()]);
    $erpUiShellConfig = [
        'mode' => $mode,
        'messages' => __('erp_ui_shell.messages'),
        'shortcuts' => __('erp_ui_shell.shortcuts'),
    ];
@endphp

@section('title', $title)

@push('styles')
    <link href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('vendors/summernote/summernote-bs5.min.css') }}" rel="stylesheet">
    <link href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Core/erp-ui-shell.css') }}" rel="stylesheet">
@endpush

@section('content')
    <form class="js-erp-ui-shell-form" action="#" method="post" data-mode="{{ $mode }}" novalidate>
        <div class="card erp-ui-shell-form-card">
            <div class="card-header border-bottom border-200">
                <div class="row flex-between-center g-2">
                    <div class="col min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <h5 class="mb-0 text-truncate">{{ $title }}</h5>
                            {{-- <span class="badge badge-subtle-secondary">{{ __('erp_ui_shell.ui_only') }}</span> --}}
                        </div>
                        <div class="text-600 fs-11 mt-1">{{ $definition->localized($screen['module_title']) }}</div>
                    </div>
                    <div class="col-auto">
                        @include('modules.ui-shell.partials.form-actions', ['position' => 'top'])
                    </div>
                </div>
            </div>

            <div class="card-body">
                @if ($docNum)
                    <div class="alert alert-subtle-info py-2 mb-3 d-flex flex-wrap align-items-center gap-2" role="status">
                        <span class="fas fa-link"></span>
                        <span>{{ $mode === 'clone' ? __('erp_ui_shell.source_reference') : __('erp_ui_shell.document_reference') }}:</span>
                        <code dir="ltr">{{ $docNum }}</code>
                    </div>
                @endif

                @include('modules.ui-shell.partials.tabs')
            </div>

            <div class="card-footer border-top border-200">
                @include('modules.ui-shell.partials.form-actions', ['position' => 'bottom'])
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        window.ErpUiShellConfig = @json($erpUiShellConfig);
    </script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('vendors/summernote/summernote-bs5.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Core/erp-ui-shell.js') }}"></script>
@endpush
