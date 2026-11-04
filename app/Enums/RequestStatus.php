<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Pending = 'Pending';
    case Ongoing = 'Ongoing';
    case Completed = 'Completed';
    case Disapproved = 'Disapproved';
    case Canceled = 'Canceled';
}
