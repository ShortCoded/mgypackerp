<?php

namespace Modules\Core\Services\Reports;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Modules\Core\Models\Company;
use Modules\Core\Services\BrandingService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class ReportPdfService
{
    /**
     * @var list<string>
     */
    private const SupportedLogoExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    public function __construct(
        private readonly BrandingService $branding,
        private readonly DateFormatService $dates,
        private readonly ViewFactory $views,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function stream(string $view, array $data, string $filename, ?string $orientation = null): Response
    {
        $branding = $this->branding->current();
        $identity = is_array($data['companyPrintIdentity'] ?? null) ? $data['companyPrintIdentity'] : [];
        $identities = app(CompanyPrintIdentityService::class);
        $policy = $data['printIdentityPolicy'] ?? $identities->policyForView($view);
        $orientation ??= $policy === 'report' ? 'L' : 'P';
        $companyId = $identity['company_id'] ?? app(OperatingCompanyContextService::class)->currentCompanyId();
        $showIdentity = $identities->shouldShow($policy, $companyId ? Company::query()->find($companyId) : null);
        $generatedAt = now();
        $generatedBy = auth()->user()?->name ?: '';
        $direction = config('languages.available.'.app()->getLocale().'.dir', 'ltr');
        $shared = [
            'branding' => $branding,
            'companyName' => (string) ($identity['legal_name'] ?? $identity['name'] ?? $branding['name']),
            'companyLogoPath' => $this->pdfImageSource($identity['logo_source'] ?? $branding['logo_path']),
            'direction' => $direction,
            'pdfFontFamily' => 'dejavusans',
            'generatedAt' => $generatedAt,
            'generatedAtLabel' => $this->dates->formatDateTime($generatedAt),
            'generatedByName' => $generatedBy,
            'printDate' => $this->dates->formatDateTime($generatedAt),
            'reportTitle' => $data['title'] ?? __('reports.report_title'),
        ];
        $payload = $data + $shared;
        $payload['showCompanyIdentity'] = $showIdentity;
        $payload['printIdentityPolicy'] = $policy;
        if (! $showIdentity) {
            $payload['companyName'] = '';
            $payload['companyLogoPath'] = null;
        }
        $html = $this->views->make($view, $payload)->render();
        $header = $this->views->make('reports.partials.header', $payload)->render();
        $footer = $this->views->make('reports.partials.footer', $payload)->render();
        $styles = $this->views->make('reports.partials.styles', $payload)->render();
        $extraStylesView = $data['pdfStylesView'] ?? null;

        if (is_string($extraStylesView) && $this->views->exists($extraStylesView)) {
            $styles .= $this->views->make($extraStylesView, $payload)->render();
        }

        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => $orientation,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
            'margin_top' => 28,
            'margin_bottom' => 18,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_header' => 6,
            'margin_footer' => 6,
        ]);

        $mpdf->SetTitle((string) $payload['reportTitle']);
        $mpdf->WriteHTML($styles, HTMLParserMode::HEADER_CSS);
        $mpdf->SetHTMLHeader($header);
        $mpdf->SetHTMLFooter($footer);
        $mpdf->WriteHTML($html);

        $contents = $mpdf->Output($filename, Destination::STRING_RETURN);
        unset($mpdf);
        gc_collect_cycles();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function download(string $view, array $data, string $filename, ?string $orientation = null): Response
    {
        return $this->stream($view, $data, $filename, $orientation);
    }

    public function stockDocumentTitle(Model $record): string
    {
        $record->loadMissing('lines.product');
        $type = $record->document_type;
        if ($type === InventoryDocument::TypeMaterialReturn) {
            return __('Material Return Note');
        }
        $inbound = $record instanceof UnpricedInventoryReceipt
            || in_array($type, ['production_receipt', 'inventory_adjustment_in', 'sales_return_receipt'], true);
        $classifications = $record->lines->pluck('product.item_classification')->filter()->unique();
        $classification = $classifications->count() === 1 ? $classifications->first() : null;

        return match ($classification) {
            'raw_material' => $inbound ? __('Raw Material Receipt') : __('Raw Material Issue'),
            'finished_product' => $inbound ? __('Finished Goods Receipt') : __('Finished Goods Issue'),
            'packaging', 'other' => $inbound ? __('Production Supplies Receipt') : __('Production Supplies Issue'),
            default => $record instanceof UnpricedInventoryReceipt ? __('Goods Receipt') : __(str($type)->replace('_', ' ')->title()->toString()),
        };
    }

    private function pdfImageSource(mixed $source): ?string
    {
        if (! is_string($source) || trim($source) === '') {
            return null;
        }

        if (str_starts_with($source, 'data:image/')) {
            return $source;
        }

        if (! is_file($source)) {
            return null;
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SupportedLogoExtensions, true)) {
            return null;
        }

        return $source;
    }
}
