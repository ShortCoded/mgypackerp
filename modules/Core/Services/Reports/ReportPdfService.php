<?php

namespace Modules\Core\Services\Reports;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Modules\Core\Services\BrandingService;
use Modules\Core\Services\DateFormatService;
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
    public function stream(string $view, array $data, string $filename, string $orientation = 'L'): Response
    {
        $branding = $this->branding->current();
        $generatedAt = now();
        $generatedBy = auth()->user()?->name ?: '';
        $direction = config('languages.available.'.app()->getLocale().'.dir', 'ltr');
        $shared = [
            'branding' => $branding,
            'companyName' => $branding['name'],
            'companyLogoPath' => $this->pdfLogoPath($branding['logo_path']),
            'direction' => $direction,
            'pdfFontFamily' => 'dejavusans',
            'generatedAt' => $generatedAt,
            'generatedAtLabel' => $this->dates->formatDateTime($generatedAt),
            'generatedByName' => $generatedBy,
            'printDate' => $this->dates->formatDateTime($generatedAt),
            'reportTitle' => $data['title'] ?? __('reports.report_title'),
        ];
        $payload = $data + $shared;
        $html = $this->views->make($view, $payload)->render();
        $header = $this->views->make('reports.partials.header', $payload)->render();
        $footer = $this->views->make('reports.partials.footer', $payload)->render();
        $styles = $this->views->make('reports.partials.styles', $payload)->render();

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

        return response($mpdf->Output($filename, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function download(string $view, array $data, string $filename, string $orientation = 'L'): Response
    {
        return $this->stream($view, $data, $filename, $orientation);
    }

    private function pdfLogoPath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || ! is_file($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SupportedLogoExtensions, true)) {
            return null;
        }

        return $path;
    }
}
