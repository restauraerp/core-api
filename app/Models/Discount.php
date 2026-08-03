<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Discount extends Model
{
    use BelongsToTenant;

    //
}
