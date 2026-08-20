<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExcelImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->permissionPrefix();

        return (bool) $this->user()?->can("{$permission}.import")
            && (bool) $this->user()?->can("{$permission}.create");
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workbook' => [
                'required',
                'file',
                'extensions:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
                'max:'.(int) config('excel_imports.max_file_size_kb'),
            ],
        ];
    }

    private function permissionPrefix(): string
    {
        return $this->route('excelImportModule') === 'fixed_assets' ? 'fixed_assets' : 'products';
    }
}
