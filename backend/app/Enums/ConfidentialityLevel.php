<?php

namespace App\Enums;

enum ConfidentialityLevel: string
{
    case PublicInternal = 'public_interne';
    case Restricted = 'restreint';
    case Confidential = 'confidentiel';
    case HighlyConfidential = 'tres_confidentiel';
}
