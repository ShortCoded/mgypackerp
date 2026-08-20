<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmExcelImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('excelImportModule') === 'fixed_assets' ? 'fixed_assets' : 'products';

        return (bool) $this->user()?->can("{$permission}.import")
            && (bool) $this->user()?->can("{$permission}.create");
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['confirmed' => ['required', 'accepted']];
    }
}
