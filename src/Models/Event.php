<?php

namespace CorporateIp\Insights\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'insights_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'properties' => 'array',
        ];
    }
}
