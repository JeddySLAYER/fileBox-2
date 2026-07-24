<?php

namespace App\Enums;

enum AccessAbility: string
{
    case View = 'view';
    case Download = 'download';
    case Edit = 'edit';
    case Delete = 'delete';
    case Share = 'share';
    case Manage = 'manage';
}
