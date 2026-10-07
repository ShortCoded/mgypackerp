@props(['record', 'menu' => true])
@php($ownerAction = app(\App\Services\DocumentCancellationReviewService::class)->navigation($record, request()))
@if($ownerAction)
    <a class="{{ $menu ? 'dropdown-item' : 'btn btn-falcon-warning btn-sm' }}" href="{{ $ownerAction['url'] }}">{{ $ownerAction['label'] }}</a>
@endif
