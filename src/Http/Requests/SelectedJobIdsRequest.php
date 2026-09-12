<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SelectedJobIdsRequest extends FormRequest
{
    private const int MAX_SELECTED_IDS = 500;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTED_IDS],
            'ids.*' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = $this->input('ids');

        return array_values(array_filter(is_array($ids) ? $ids : [], is_string(...)));
    }
}
