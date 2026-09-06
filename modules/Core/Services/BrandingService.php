<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Company;
use Throwable;

class BrandingService
{
    public const MainCompanyCacheKey = 'erp.branding.main_company.v1';

    public function __construct(
        private readonly RequestMemo $memo,
    ) {}

    /**
     * @return array{name: string, logo_url: string, logo_path: string|null, favicon_url: string, favicon_path: string|null, has_company: bool}
     */
    public function current(): array
    {
        return $this->memo->remember('branding.current', fn (): array => $this->resolveCurrent());
    }

    /**
     * @return array{name: string, logo_url: string, logo_path: string|null, favicon_url: string, favicon_path: string|null, has_company: bool}
     */
    private function resolveCurrent(): array
    {
        $company = $this->mainCompanyData();
        $fallbackLogo = public_path('assets/img/logos/Logo.svg');
        $fallbackFavicon = public_path('assets/img/favicon/favicon.ico');
        $companyLogoPath = $this->assetPath($company['logo'] ?? null);
        $companyFaviconPath = $this->assetPath($company['favicon'] ?? null);

        return [
            'name' => (string) ($company['name'] ?? config('app.name', 'ERP')),
            'logo_url' => $companyLogoPath ? $this->assetUrl($company['logo'] ?? null) : (is_file($fallbackLogo) ? asset('assets/img/logos/Logo.svg') : ''),
            'logo_path' => $companyLogoPath ?? (is_file($fallbackLogo) ? $fallbackLogo : null),
            'favicon_url' => $companyFaviconPath ? $this->assetUrl($company['favicon'] ?? null) : (is_file($fallbackFavicon) ? asset('assets/img/favicon/favicon.ico') : ''),
            'favicon_path' => $companyFaviconPath ?? (is_file($fallbackFavicon) ? $fallbackFavicon : null),
            'has_company' => $company !== null,
        ];
    }

    /**
     * @return array{name: string, logo: string|null, favicon: string|null}|null
     */
    private function mainCompanyData(): ?array
    {
        return $this->memo->remember('branding.main_company', function (): ?array {
            try {
                return Cache::rememberForever(self::MainCompanyCacheKey, function (): ?array {
                    $company = Company::query()
                        ->select(['name', 'logo', 'favicon'])
                        ->where('status', 'active')
                        ->where('is_main', true)
                        ->whereNull('deleted_at')
                        ->first();

                    if (! $company) {
                        return null;
                    }

                    return [
                        'name' => (string) $company->name,
                        'logo' => is_string($company->logo) && $company->logo !== '' ? $company->logo : null,
                        'favicon' => is_string($company->favicon) && $company->favicon !== '' ? $company->favicon : null,
                    ];
                });
            } catch (Throwable) {
                return null;
            }
        });
    }

    public static function forgetPersistentCache(): void
    {
        Cache::forget(self::MainCompanyCacheKey);

        try {
            app(RequestMemo::class)->forget('branding.current');
            app(RequestMemo::class)->forget('branding.main_company');
        } catch (Throwable) {
        }
    }

    private function assetUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    private function assetPath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($path);

        return is_file($absolutePath) ? $absolutePath : null;
    }
}
