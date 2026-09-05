<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PresenceController extends Controller
{
    public function heartbeat(Request $request, PresenceService $service): Response
    {
        $service->heartbeat($request->user());

        return response()->noContent();
    }

    public function leave(Request $request, PresenceService $service): Response
    {
        $service->leave($request->user());

        return response()->noContent();
    }
}
