<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Requests;

use DevactionLabs\Zenith\Jobs\Data\JobIndexFiltersData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class JobIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'query',
            'filter_job',
            'filter_queue',
            'filter_connection',
            'filter_state',
            'tag',
        ] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([$key => trim($value)]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'starting_at' => ['nullable', 'string', 'max:2048'],
            'query' => ['nullable', 'string', 'max:512'],
            'filter_job' => ['nullable', 'string', 'max:512'],
            'filter_queue' => ['nullable', 'string', 'max:255'],
            'filter_connection' => ['nullable', 'string', 'max:255'],
            'filter_state' => [
                'nullable',
                Rule::in(['ready', 'reserved', 'delayed', 'released']),
            ],
            'tag' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function startingAt(): ?string
    {
        $value = $this->validated('starting_at');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getData(): JobIndexFiltersData
    {
        return new JobIndexFiltersData(
            job: $this->nullableString('filter_job'),
            queue: $this->nullableString('filter_queue'),
            connection: $this->nullableString('filter_connection'),
            state: $this->nullableString('filter_state'),
        );
    }

    public function search(): ?string
    {
        return $this->nullableString('query');
    }

    public function tag(): ?string
    {
        return $this->nullableString('tag');
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
