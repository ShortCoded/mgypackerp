<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.document_number_settings.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    private function permissionPrefix(): string
    {
        $routeName = (string) ($this->route()?->getName() ?? '');

        return match (true) {
            str_starts_with($routeName, 'admin.raw-materials.') => 'raw_materials',
            str_starts_with($routeName, 'admin.packaging-materials.') => 'packaging_materials',
            default => 'products',
        };
    }
}
