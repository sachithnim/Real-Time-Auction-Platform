<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuctionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) and full update (PUT) of an auction by an admin.
 * Money must be JSON integers in whole rupees; times are ISO-8601 and are
 * converted to UTC before they reach the service.
 */
class SaveAuctionRequest extends FormRequest
{
    private const MAX_MONEY = 1_000_000_000_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'starting_price' => ['required', 'integer:strict', 'min:1', 'max:'.self::MAX_MONEY],
            'reserve_price' => ['nullable', 'integer:strict', 'gte:starting_price', 'max:'.self::MAX_MONEY],
            'min_increment' => ['required', 'integer:strict', 'min:1', 'max:'.self::MAX_MONEY],
            'start_at' => array_filter([
                'required', 'date',
                $this->wantsSchedule() ? 'after:now' : null,
            ]),
            'end_at' => ['required', 'date', 'after:start_at'],
            'anti_snipe_seconds' => ['required', 'integer:strict', 'min:0', 'max:3600'],
            'extension_seconds' => ['required', 'integer:strict', 'min:0', 'max:3600'],
            'max_extensions' => ['required', 'integer:strict', 'min:0', 'max:50'],
            // Only on create: start as a draft (default) or schedule immediately.
            'status' => [
                Rule::prohibitedIf($this->isMethod('PUT')),
                Rule::in([AuctionStatus::Draft->value, AuctionStatus::Scheduled->value]),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $duration = $this->startAt()->diffInSeconds($this->endAt());
            $antiSnipe = $this->integer('anti_snipe_seconds');

            if ($duration < 60) {
                $validator->errors()->add('end_at', 'An auction must run for at least one minute.');
            }

            if ($antiSnipe > 0) {
                if ($this->integer('extension_seconds') < 1) {
                    $validator->errors()->add('extension_seconds', 'The extension must be at least one second when anti-sniping is enabled.');
                }
                if ($this->integer('max_extensions') < 1) {
                    $validator->errors()->add('max_extensions', 'Allow at least one extension when anti-sniping is enabled.');
                }
                if ($antiSnipe >= $duration) {
                    $validator->errors()->add('anti_snipe_seconds', 'The anti-sniping window must be shorter than the auction.');
                }
            }
        }];
    }

    public function wantsSchedule(): bool
    {
        return $this->input('status') === AuctionStatus::Scheduled->value;
    }

    /**
     * Validated, admin-editable fields with times normalised to UTC.
     *
     * @return array<string, mixed>
     */
    public function auctionData(): array
    {
        $data = $this->safe()->except('status');
        $data['start_at'] = $this->startAt();
        $data['end_at'] = $this->endAt();

        return $data;
    }

    private function startAt(): Carbon
    {
        return Carbon::parse($this->input('start_at'))->utc();
    }

    private function endAt(): Carbon
    {
        return Carbon::parse($this->input('end_at'))->utc();
    }
}
