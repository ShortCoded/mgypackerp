<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Throwable;

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
     *     logo_source: string|null,
     *     commercial_register_number: string|null,
     *     tax_card_number: string|null,
     *     vat_registration_number: string|null,
     *     address: string|null,
     *     phone: string|null,
     *     email: string|null,
     *     company_stamp_url: string|null,
     *     company_stamp_source: string|null,
     *     authorized_signatory_name: string|null,
     *     authorized_signatory_title: string|null,
     *     authorized_signatory_signature_url: string|null
     *     authorized_signatory_signature_source: string|null
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
            'company_id' => $company->getKey(),
            'name' => (string) $company->name,
            'legal_name' => $this->nullableString($company->legal_name),
            'logo_url' => $this->logoUrl($company->logo),
            'logo_source' => $this->publicImageSource($company->logo),
            'commercial_register_number' => $this->nullableString($company->commercial_register_number),
            'tax_card_number' => $this->nullableString($company->tax_card_number),
            'vat_registration_number' => $this->nullableString($company->vat_registration_number),
            'address' => $this->address($company),
            'phone' => $this->nullableString($company->phone ?: $company->mobile),
            'email' => $this->nullableString($company->email),
            'company_stamp_url' => $this->archiveImageUrl($company->companyStampArchiveFile),
            'company_stamp_source' => $this->archiveImageSource($company->companyStampArchiveFile),
            'authorized_signatory_name' => $this->nullableString($company->authorized_signatory_name),
            'authorized_signatory_title' => $this->nullableString($company->authorized_signatory_title),
            'authorized_signatory_signature_url' => $this->archiveImageUrl($company->authorizedSignatorySignatureArchiveFile),
            'authorized_signatory_signature_source' => $this->archiveImageSource($company->authorizedSignatorySignatureArchiveFile),
        ];
    }

    public function policyForView(string $view): string
    {
        return match ($view) {
            'reports.sales.quotation' => 'quotation',
            'modules.purchases.procurement.print', 'modules.purchases.purchase-orders.print',
            'modules.purchases.purchase-invoices.print', 'reports.inventory.document',
            'reports.inventory.opening-stock', 'reports.inventory.stock-count', 'reports.sales.document',
            'reports.sales.customer-credit-refund', 'modules.finance.cash-vouchers.print',
            'modules.finance.cheques.print', 'reports.production.run-sheet' => 'operational',
            default => 'report',
        };
    }

    public function shouldShow(string $policy, ?Company $company = null): bool
    {
        return in_array($policy, ['quotation', 'legal', 'report'], true)
            || (bool) $company?->show_company_identity_on_prints;
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

    private function publicImageSource(mixed $path): ?string
    {
        $path = $this->nullableString($path);

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return $this->filesystemImageSource('public', $path, Storage::disk('public')->mimeType($path));
    }

    private function archiveImageSource(?ArchiveFile $file): ?string
    {
        if (! $file instanceof ArchiveFile
            || ! $this->filePicker->isImageFile($file)
            || ! $this->filePicker->isAvailableFile($file)
        ) {
            return null;
        }

        return $this->filesystemImageSource($file->disk, $file->path, $file->mime_type);
    }

    private function filesystemImageSource(string $disk, string $path, ?string $mimeType): ?string
    {
        try {
            $absolutePath = Storage::disk($disk)->path($path);

            if (is_file($absolutePath)) {
                return $absolutePath;
            }
        } catch (Throwable) {
        }

        $size = Storage::disk($disk)->size($path);

        if ($size > 5 * 1024 * 1024) {
            return null;
        }

        $contents = Storage::disk($disk)->get($path);

        return 'data:'.($mimeType ?: 'image/png').';base64,'.base64_encode($contents);
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
