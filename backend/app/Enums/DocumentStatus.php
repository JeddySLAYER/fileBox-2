<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'brouillon';
    case InValidation = 'en_validation';
    case Validated = 'valide';
    case Published = 'publie';
    case Rejected = 'rejete';
    case Archived = 'archive';
    case Deleted = 'supprime';
}
