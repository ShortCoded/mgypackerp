<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Services\ItemLookupDefinition;
use Modules\Core\Services\ItemLookupRegistry;

class BulkDeleteItemLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->definition()->permission('delete'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string', 'distinct'],
        ];
    }

    private function definition(): ItemLookupDefinition
    {
        return app(ItemLookupRegistry::class)->fromRouteName($this->route()?->getName());
    }
}
