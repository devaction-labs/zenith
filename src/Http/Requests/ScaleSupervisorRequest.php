<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ScaleSupervisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'processes' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function processes(): int
    {
        return $this->safe()->integer('processes');
    }
}
