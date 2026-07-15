<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sales\Models\ProjectStructure;

class UpdateProjectStructureRequest extends StoreProjectStructureRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('project_structures.edit')
            && $this->currentProjectStructure() instanceof ProjectStructure;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->companyId();
        $record = $this->currentProjectStructure();
        $rules = parent::rules();

        $rules['code'] = [
            'required',
            'string',
            'max:50',
            Rule::unique('project_structures', 'code')
                ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))
                ->ignore($record?->getKey()),
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('project_structures', 'doc_number')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))
                    ->ignore($record?->getKey()),
            ];
        }

        unset($rules['clone_source_token']);

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $record = $this->currentProjectStructure();

            if (! $record instanceof ProjectStructure) {
                return;
            }

            if ($this->input('parent_doc_num') === $record->doc_num) {
                $validator->errors()->add('parent_doc_num', __('project_structures.messages.self_parent'));

                return;
            }

            $parent = $this->filled('parent_doc_num')
                ? ProjectStructure::query()->forCompany($this->companyId())->where('doc_num', $this->input('parent_doc_num'))->first()
                : null;

            if ($parent instanceof ProjectStructure && $this->wouldCreateCycle($record, $parent)) {
                $validator->errors()->add('parent_doc_num', __('project_structures.messages.parent_cycle'));
            }
        });
    }

    protected function currentProjectStructure(): ?ProjectStructure
    {
        $docNum = $this->route('projectStructure');

        if (! is_string($docNum) || trim($docNum) === '') {
            return null;
        }

        return ProjectStructure::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->first();
    }

    private function wouldCreateCycle(ProjectStructure $record, ProjectStructure $parent): bool
    {
        $parentId = $parent->getKey();

        while ($parentId !== null) {
            if ((int) $parentId === (int) $record->getKey()) {
                return true;
            }

            $parentId = ProjectStructure::query()
                ->forCompany((int) $record->company_id)
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return false;
    }
}
