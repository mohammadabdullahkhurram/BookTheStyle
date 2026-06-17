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
 */
class ServiceStylist extends Pivot
{
    use BelongsToSalon;

    protected $table = 'service_stylist';

    public $incrementing = true;

    public $timestamps = true;
}
