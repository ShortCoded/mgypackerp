<?php

namespace Modules\Accounting\Http\Requests;

class UpdateJournalEntryRequest extends JournalEntryRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('journal_entries.edit');
    }
}
