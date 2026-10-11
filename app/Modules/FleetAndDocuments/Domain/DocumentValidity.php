<?php

namespace App\Modules\FleetAndDocuments\Domain;

enum DocumentValidity: string
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case NoExpiration = 'no_expiration';
}
