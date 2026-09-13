<tr class="js-journal-entry-line" data-index="{{ $index }}">
    @foreach([
        ['account', 'account_doc_num', 'account_label'],
        ['cost_center', 'cost_center_doc_num', 'cost_center_label'],
        ['customer', 'customer_doc_num', 'customer_label'],
        ['supplier', 'supplier_doc_num', 'supplier_label'],
        ['employee', 'employee_doc_num', 'employee_label'],
    ] as $field)
        @php([$kind, $name, $labelKey] = $field)
        @if($kind === 'account')
            <td data-line-field="account">
                @if($readonly)
                    <span>{{ $line[$labelKey] ?? $line[$name] ?? '—' }}</span>
                @else
                    <x-forms.select class="form-select form-select-sm js-journal-entry-select" name="lines[{{ $index }}][{{ $name }}]" data-kind="{{ $kind }}" required>
                        @if(! empty($line[$name]))<option value="{{ $line[$name] }}" selected>{{ $line[$labelKey] ?? $line[$name] }}</option>@endif
                    </x-forms.select>
                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.{{ $name }}"></div>
                @endif
            </td>
            <td>
                @if($readonly)<span class="d-block text-end" dir="ltr">{{ $numbers->format($line['debit_amount'] ?? 0) }}</span>@else<x-forms.input class="form-control form-control-sm text-end js-journal-entry-amount" name="lines[{{ $index }}][debit_amount]" value="{{ $line['debit_amount'] ?? 0 }}" inputmode="decimal" dir="ltr" /><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.debit_amount"></div>@endif
            </td>
            <td>
                @if($readonly)<span class="d-block text-end" dir="ltr">{{ $numbers->format($line['credit_amount'] ?? 0) }}</span>@else<x-forms.input class="form-control form-control-sm text-end js-journal-entry-amount" name="lines[{{ $index }}][credit_amount]" value="{{ $line['credit_amount'] ?? 0 }}" inputmode="decimal" dir="ltr" /><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.credit_amount"></div>@endif
            </td>
            <td>
                @if($readonly)<span>{{ $line['description'] ?? '—' }}</span>@else<x-forms.input class="form-control form-control-sm" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" /><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.description"></div>@endif
            </td>
        @else
            <td data-line-field="{{ $kind }}">
                @if($readonly)
                    <span>{{ $line[$labelKey] ?? $line[$name] ?? '—' }}</span>
                @else
                    <x-forms.select class="form-select form-select-sm js-journal-entry-select" name="lines[{{ $index }}][{{ $name }}]" data-kind="{{ $kind }}" data-allow-clear="true">
                        @if(! empty($line[$name]))<option value="{{ $line[$name] }}" selected>{{ $line[$labelKey] ?? $line[$name] }}</option>@endif
                    </x-forms.select>
                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.{{ $name }}"></div>
                @endif
            </td>
        @endif
    @endforeach
    @unless($readonly)
        <td class="text-center"><button class="btn btn-link text-danger p-0 js-journal-entry-remove-line" type="button" aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-trash-alt"></span></button></td>
    @endunless
</tr>
