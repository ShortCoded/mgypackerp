@extends('layouts.app')
@section('title', $title)
@section('content')
<div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0">{{ $title }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.'.$destination.'.index') }}">{{ __('common.actions.back') }}</a></div><div class="card-body">
<form data-procurement-source-picker data-destination="{{ route('admin.purchases.'.$destination.'.create', '__DOCUMENT__') }}" class="row g-3 align-items-end"><div class="col-md-8"><label class="form-label" for="source_document">{{ __('Source document') }}</label><select id="source_document" class="form-select js-select2-ajax" data-url="{{ route('admin.purchases.select2.'.$lookup, ['purpose' => $destination === 'goods-receipt-notes' ? 'receipt' : null]) }}" data-placeholder="{{ __('Select') }}" required></select></div><div class="col-md-4"><button class="btn btn-primary">{{ __('procurement.ui.load_lines') }}</button></div></form>
</div></div>
@endsection
@push('scripts')<script src="{{ asset('assets/js/modules/Purchases/procurement-cycle.js').'?v='.filemtime(public_path('assets/js/modules/Purchases/procurement-cycle.js')) }}"></script>@endpush
