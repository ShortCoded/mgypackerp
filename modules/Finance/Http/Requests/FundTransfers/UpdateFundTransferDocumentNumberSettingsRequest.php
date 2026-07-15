<?php

namespace Modules\Finance\Http\Requests\FundTransfers;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFundTransferDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fund_transfers.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    public function attributes(): array
    {
        return [
            'prefix' => __('common.document_number_settings.prefix'),
            'padding' => __('common.document_number_settings.padding'),
        ];
    }
}
