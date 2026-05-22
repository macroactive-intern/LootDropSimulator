<?php

namespace App\Http\Requests;

use App\Models\Trade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AcceptTradeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $trade = $this->routeTrade();

        return $this->user() !== null
            && $trade !== null
            && (int) $trade->recipient_id === (int) $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Get the after validation callbacks for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $trade = $this->routeTrade();

                if ($trade !== null && ! $trade->isPending()) {
                    $validator->errors()->add('trade', 'Trade is no longer pending.');
                }
            },
        ];
    }

    private function routeTrade(): ?Trade
    {
        $trade = $this->route('trade');

        return $trade instanceof Trade ? $trade : null;
    }
}
