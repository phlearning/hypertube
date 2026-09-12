<?php

namespace App\Enums;

enum TorrentJobClearScope: string
{
    case All = 'all';
    case Stuck = 'stuck';
}
