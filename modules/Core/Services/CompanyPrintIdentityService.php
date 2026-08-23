<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;

class CompanyPrintIdentityService
{
    public function __construct(
        private readonly FilePickerService $filePicker,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     legal_name: string|null,
     *     logo_url: string|null,
     *     commercial_register_number: string|null,
     *     tax_card_number: string|null,
     *     vat_registration_number: string|null,
     *     address: string|null,
     *     phone: string|null,
     *     email: string|null,
     *     company_stamp_url: string|null,
     *     authorized_signatory_name: string|null,
     *     authorized_signatory_title: string|null,
     *     authorized_signatory_signature_url: string|null
     * }
     */
    public function forCompany(Company $company): array
    {
        $company->loadMissing([
            'companyStampArchiveFile',
            'authorizedSignatorySignatureArchiveFile',
            'area',
            'city',
            'governorate',
            'country',
        ]);

        return [
            'name' => (string) $company->name,
            'legal_name' => $this->nullableString($company->legal_name),
            'logo_url' => $this->logoUrl($company->logo),
            'commercial_register_number' => $this->nullableString($company->commercial_register_number),
            'tax_card_number' => $this->nullableString($company->tax_card_number),
            'vat_registration_number' => $this->nullableString($company->vat_registration_number),
            'address' => $this->address($company),
            'phone' => $this->nullableString($company->phone ?: $company->mobile),
            'email' => $this->nullableString($company->email),
            'company_stamp_url' => $this->archiveImageUrl($company->companyStampArchiveFile),
            'authorized_signatory_name' => $this->nullableString($company->authorized_signatory_name),
            'authorized_signatory_title' => $this->nullableString($company->authorized_signatory_title),
            'authorized_signatory_signature_url' => $this->archiveImageUrl($company->authorizedSignatorySignatureArchiveFile),
        ];
    }

    private function logoUrl(mixed $path): ?string
    {
        $path = $this->nullableString($path);

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    private function archiveImageUrl(?ArchiveFile $file): ?string
    {
        if (! $file instanceof ArchiveFile
            || ! $this->filePicker->isImageFile($file)
            || ! $this->filePicker->isAvailableFile($file)
        ) {
            return null;
        }

        return route('admin.file-manager.files.preview', $file->doc_num);
    }

    private function address(Company $company): ?string
    {
        $parts = array_values(array_unique(array_filter([
            $this->nullableString($company->address),
            $this->relationName($company, 'area') ?? $this->nullableString($company->getRawOriginal('area')),
            $this->relationName($company, 'city') ?? $this->nullableString($company->getRawOriginal('city')),
            $this->relationName($company, 'governorate') ?? $this->nullableString($company->getRawOriginal('governorate')),
            $this->relationName($company, 'country') ?? $this->nullableString($company->getRawOriginal('country')),
            $this->nullableString($company->postal_code),
        ])));

        $separator = app()->isLocale('ar') ? '، ' : ', ';

        return $parts === [] ? null : implode($separator, $parts);
    }

    private function relationName(Company $company, string $relation): ?string
    {
        return $this->nullableString(data_get($company->getRelation($relation), 'name'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
