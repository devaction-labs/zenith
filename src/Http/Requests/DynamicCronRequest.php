<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Requests;

use Cron\CronExpression;
use DateTimeZone;
use DevactionLabs\Zenith\Schedule\Data\DynamicCronFormData;
use DevactionLabs\Zenith\Schedule\DynamicCronAllowlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class DynamicCronRequest extends FormRequest
{
    public function __construct(
        private readonly DynamicCronAllowlist $allowlist,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('zenith_dynamic_crons', 'name')->ignore($this->route('cron')),
            ],
            'expression' => ['required', 'string', 'max:255'],
            'job_class' => ['required', 'string', 'max:255', Rule::in($this->allowlist->allowed())],
            'payload' => ['nullable', 'string', 'json'],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $expression = $this->input('expression');

            if (is_string($expression) && $expression !== '' && ! CronExpression::isValidExpression($expression)) {
                $validator->errors()->add('expression', 'The expression must be a valid cron expression.');
            }
        });
    }

    public function getData(): DynamicCronFormData
    {
        $validated = $this->safe();
        $timezone = $validated->input('timezone');

        return new DynamicCronFormData(
            name: $validated->string('name')->value(),
            expression: $validated->string('expression')->value(),
            jobClass: $validated->string('job_class')->value(),
            payload: $this->decodedPayload($validated->input('payload')),
            timezone: is_string($timezone) && $timezone !== '' ? $timezone : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedPayload(mixed $payload): array
    {
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
