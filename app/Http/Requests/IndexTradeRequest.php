<?php

namespace App\Http\Requests;

use App\Models\Trade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTradeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([
                Trade::STATUS_PENDING,
                Trade::STATUS_REJECTED,
                Trade::STATUS_COMPLETED,
                Trade::STATUS_EXPIRED,
                Trade::STATUS_CANCELLED,
            ])],
        ];
    }

    public function statusFilter(): ?string
    {
        return $this->validated('status');
    }
}
