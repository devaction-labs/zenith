<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Requests;

use DevactionLabs\HorizonNewDawn\Queues\Data\QueueTargetData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ClearQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'connection' => $this->route('connection'),
            'queue' => $this->route('queue'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'connection' => [
                'required',
                'string',
                'max:255',
                Rule::in(array_keys(config('queue.connections', []))),
            ],
            'queue' => ['required', 'string', 'max:255'],
        ];
    }

    public function getData(): QueueTargetData
    {
        $validated = $this->validated();

        return new QueueTargetData(
            connection: $validated['connection'],
            queue: $validated['queue'],
        );
    }
}
