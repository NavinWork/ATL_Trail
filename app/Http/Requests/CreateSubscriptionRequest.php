<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'start_date' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->customer_id && $this->plan_id) {
                $customer = Customer::find($this->customer_id);
                $plan = Plan::find($this->plan_id);

                if ($customer && $plan && $customer->merchant_id !== $plan->merchant_id) {
                    $validator->errors()->add('plan_id', 'The selected plan does not belong to the customer\'s merchant (multi-tenant boundary violation).');
                }
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
