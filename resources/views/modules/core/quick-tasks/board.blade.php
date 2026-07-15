@extends('layouts.app')

@php
    use Modules\Core\Models\QuickTask;
    use Modules\Core\Services\AssetVersionService;

    $erpAsset = app(AssetVersionService::class);
    $groups = collect(QuickTask::ActiveStatuses)
        ->mapWithKeys(fn (string $status): array => [$status => collect()])
        ->all();
@endphp

@section('title', __('quick_tasks.board_title'))

@push('styles')
    <link href="{{ $erpAsset->url('assets/css/modules/Core/quick-tasks.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="quick-task-board-page">
        <div class="quick-task-board-toolbar mb-3">
            <div class="min-w-0">
                <h4 class="mb-1">{{ __('quick_tasks.board_title') }}</h4>
                <div class="text-600 fs-10 d-flex flex-wrap align-items-center gap-2">
                    <span>{{ __('quick_tasks.board.auto_refresh') }}</span>
                    <span class="vr d-none d-sm-inline-block"></span>
                    <span>{{ __('quick_tasks.board.last_updated_at') }}: <span id="quick-task-board-last-updated">-</span></span>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                @can('quick_tasks.view')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.quick-tasks.index') }}">
                        <span class="fas fa-list me-1"></span>{{ __('quick_tasks.management_title') }}
                    </a>
                @endcan
                <button class="btn btn-falcon-primary btn-sm js-quick-task-board-refresh" type="button">
                    <span class="fas fa-sync-alt me-1"></span>{{ __('quick_tasks.actions.refresh') }}
                </button>
                <button class="btn btn-falcon-info btn-sm js-quick-task-display-mode" type="button">
                    <span class="fas fa-tv me-1"></span><span data-display-mode-label>{{ __('quick_tasks.actions.display_mode') }}</span>
                </button>
            </div>
        </div>

        <div id="quick-task-board" class="quick-task-board" data-url="{{ route('admin.quick-tasks.board.data') }}" data-interval="10000">
            @include('modules.core.quick-tasks.partials.board-columns', ['groups' => $groups, 'lastUpdatedAt' => null])
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $quickTaskMessages = [
            'statusDoneConfirmTitle' => __('quick_tasks.messages.status_done_confirm_title'),
            'statusDoneConfirmText' => __('quick_tasks.messages.status_done_confirm_text'),
            'statusDoneConfirmYes' => __('quick_tasks.messages.status_done_confirm_yes'),
            'boardRefreshFailed' => __('quick_tasks.messages.board_refresh_failed'),
            'saved' => __('common.messages.saved_successfully'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'cancel' => __('common.actions.cancel'),
            'no' => __('common.actions.no'),
            'confirm' => __('common.actions.confirm'),
            'displayMode' => __('quick_tasks.actions.display_mode'),
            'exitDisplayMode' => __('quick_tasks.actions.exit_display_mode'),
        ];
    @endphp
    <script>
        window.coreQuickTasksMessages = @json($quickTaskMessages);
    </script>
    <script src="{{ $erpAsset->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/quick-tasks.js') }}"></script>
@endpush
