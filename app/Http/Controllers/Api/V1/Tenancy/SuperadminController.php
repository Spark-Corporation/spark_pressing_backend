<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Support\Superadmin\SuperadminHub;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group('Superadmin')]
class SuperadminController extends Controller
{
    /** Hub superadmin (Figma 13) : KPIs réseau, alertes, raccourcis. */
    public function overview(SuperadminHub $hub): JsonResponse
    {
        return $this->ok($hub->overview());
    }
}
