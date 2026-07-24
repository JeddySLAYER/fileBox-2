<?php

namespace App\Enums;

enum ValidationStatus: string
{
    case Pending = 'en_attente';
    case Approved = 'approuve';
    case Rejected = 'rejete';
    case CorrectionRequested = 'correction_demandee';
}
