<?php

namespace SchulzeFelix\AdWords\Responses;

use SchulzeFelix\DataTransferObject\DataTransferObject;

class ServiceCategory extends DataTransferObject
{
    protected $casts = [
        'google_id'  => 'integer',
    ];
}
