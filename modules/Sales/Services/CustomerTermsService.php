<?php

namespace Modules\Sales\Services;

use App\Services\RichTextSanitizer;
use Modules\Core\Services\CrudAuditService;
use Modules\Sales\Models\Customer;

class CustomerTermsService
{
    public const Fields = [
        'quotation_terms',
        'quotation_payment_terms',
        'quotation_execution_terms',
        'quotation_warranty_terms',
        'quotation_delivery_terms',
        'quotation_technical_notes',
    ];

    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly RichTextSanitizer $richText,
    ) {}

    /** @param array<string, mixed> $data */
    public function update(Customer $customer, array $data): Customer
    {
        $values = [];

        foreach (self::Fields as $field) {
            $values[$field] = $this->richText->sanitize($data[$field] ?? null);
        }

        $this->audit->saveUpdate($customer, $values);

        return $customer->refresh();
    }

    /** @return array<string, string|null> */
    public function quotationDefaults(Customer $customer): array
    {
        return [
            'terms' => $customer->quotation_terms,
            'payment_terms' => $customer->quotation_payment_terms,
            'execution_terms' => $customer->quotation_execution_terms,
            'warranty_terms' => $customer->quotation_warranty_terms,
            'delivery_terms' => $customer->quotation_delivery_terms,
            'technical_notes' => $customer->quotation_technical_notes,
        ];
    }
}
