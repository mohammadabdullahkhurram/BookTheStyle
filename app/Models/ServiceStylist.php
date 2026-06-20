<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The service↔stylist pivot, modelled so it carries a redundant salon_id and
 * the SalonScope global scope — direct pivot queries are tenant-scoped, which
 * matters now that the booking engine reads qualifications from here.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $service_id
 * @property int $user_id
 * @property int|null $duration_override
 * @property int|null $buffer_override
 */
class ServiceStylist extends Pivot
{
    use BelongsToSalon;

    protected $table = 'service_stylist';

    public $incrementing = true;

    public $timestamps = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_override' => 'integer',
            'buffer_override' => 'integer',
        ];
    }
}
