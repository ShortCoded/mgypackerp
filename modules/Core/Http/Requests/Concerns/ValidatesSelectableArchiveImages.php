<?php

namespace Modules\Core\Http\Requests\Concerns;

use Illuminate\Validation\Validator;
use Modules\Core\Services\FilePickerService;

trait ValidatesSelectableArchiveImages
{
    protected function validateSelectableArchiveImage(
        Validator $validator,
        string $field,
        ?int $companyId,
        string $message,
    ): void {
        if (! $this->filled($field)) {
            return;
        }

        $file = $companyId
            ? app(FilePickerService::class)->selectableFileByPublicId(
                (string) $this->input($field),
                $companyId,
                FilePickerService::AcceptImage,
            )
            : null;

        if (! $file) {
            $validator->errors()->add($field, $message);
        }
    }
}
