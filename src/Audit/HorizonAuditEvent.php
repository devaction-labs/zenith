<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $occurred_at
 * @property string $action
 * @property string $route
 * @property string|null $user_id
 * @property string|null $ip
 * @property array<string, scalar|null>|null $context
 */
final class HorizonAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = HorizonAuditRecorder::TABLE;

    protected $fillable = [
        'occurred_at',
        'action',
        'route',
        'user_id',
        'ip',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'context' => 'array',
        ];
    }
}
