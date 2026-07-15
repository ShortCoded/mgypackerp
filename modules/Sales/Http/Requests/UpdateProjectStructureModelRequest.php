<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Sales\Models\ProjectStructureModel;

class UpdateProjectStructureModelRequest extends StoreProjectStructureModelRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('project_structure_models.edit')
            && $this->currentProjectStructureModel() instanceof ProjectStructureModel;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->companyId();
        $record = $this->currentProjectStructureModel();
        $rules = parent::rules();

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('project_structure_models', 'doc_number')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))
                    ->ignore($record?->getKey()),
            ];
        }

        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentProjectStructureModel(): ?ProjectStructureModel
    {
        $docNum = $this->route('projectStructureModel');

        if (! is_string($docNum) || trim($docNum) === '') {
            return null;
        }

        return ProjectStructureModel::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->first();
    }
}
