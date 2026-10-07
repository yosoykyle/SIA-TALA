<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestApplicationStatusLink extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference' => is_string($this->input('reference')) ? strtoupper(trim($this->input('reference'))) : $this->input('reference'),
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return route('home').'#application-status';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
