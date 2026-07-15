<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('chat.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_doc_num' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_doc_num' => __('chat.fields.user'),
        ];
    }
}
