<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ChatMessage extends Model
{
    use BelongsToTenant;

    //
}
