<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

enum WorkplaceType: string
{
    case HOSPITAL = 'hospital';
    case HEALTH_CENTER = 'health_center';
    case LOCAL_CLINIC = 'local_clinic';
    case OUT_OF_HOSPITAL_URGENT_CARE = 'out_of_hospital_urgent_care';
}
