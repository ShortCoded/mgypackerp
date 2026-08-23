@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('title', __($translationKey.'.print_title', ['doc' => $record->doc_num]))

@section('content')
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
        <a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix.'.show', $record->doc_num) }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
        <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">
            <span class="fas fa-print me-1"></span>{{ __($translationKey.'.actions.print') }}
        </button>
    </div>

    <div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
        <div class="card-body">
            <x-company-print-header :identity="$companyPrintIdentity" />

            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                <div>
                    <h3 class="mb-1">{{ __($translationKey.'.singular') }}</h3>
                    <div class="text-700" dir="ltr">{{ $record->doc_num }}</div>
                </div>
                <div class="text-end">
                    <div class="fw-semibold">{{ __($translationKey.'.statuses.'.$record->status) }}</div>
                    <div dir="ltr">{{ $dates->formatDate($record->voucher_date, '') }}</div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="text-700">{{ __($translationKey.'.attributes.person_name') }}</div>
                    <div class="fw-semibold">{{ $record->person_name }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __($translationKey.'.attributes.cashbox') }}</div>
                    <div class="fw-semibold">{{ trim(implode(' / ', array_filter([$record->cashbox?->doc_num, $record->cashbox?->name]))) ?: __('common.empty_value') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __($translationKey.'.attributes.currency') }}</div>
                    <div class="fw-semibold">{{ trim(implode(' / ', array_filter([$record->currency?->code, $record->currency?->name]))) ?: __('common.empty_value') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __($translationKey.'.attributes.amount') }}</div>
                    <div class="fw-semibold" dir="ltr">{{ $numbers->format($record->amount) }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __($translationKey.'.attributes.exchange_rate') }}</div>
                    <div class="fw-semibold" dir="ltr">{{ $numbers->format($record->exchange_rate) }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __($translationKey.'.attributes.person_phone') }}</div>
                    <div class="fw-semibold" dir="ltr">{{ $record->person_phone ?: __('common.empty_value') }}</div>
                </div>
                <div class="col-12">
                    <div class="text-700">{{ __($translationKey.'.attributes.reason') }}</div>
                    <div class="fw-semibold">{{ $record->reason }}</div>
                </div>
                @if ($record->description)
                    <div class="col-12">
                        <div class="text-700">{{ __($translationKey.'.attributes.description') }}</div>
                        <div>{{ $record->description }}</div>
                    </div>
                @endif
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="bg-100">
                        <tr>
                            <th>#</th>
                            <th>{{ __($translationKey.'.attributes.account') }}</th>
                            <th>{{ __($translationKey.'.attributes.line_description') }}</th>
                            <th class="text-end">{{ __($translationKey.'.attributes.line_amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($record->lines as $line)
                            <tr>
                                <td>{{ $line->line_number }}</td>
                                <td>{{ $line->account?->codeNameLabel() ?: __('common.empty_value') }}</td>
                                <td>{{ $line->description ?: __('common.empty_value') }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($line->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <th colspan="3">{{ __($translationKey.'.attributes.total_distributed') }}</th>
                            <th class="text-end" dir="ltr">{{ $numbers->format($record->lines->sum('amount')) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <x-company-print-authorization :identity="$companyPrintIdentity" />
        </div>
    </div>
@endsection
