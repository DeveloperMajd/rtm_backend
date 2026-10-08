<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PresenceHeartbeatRequest;
use App\Services\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PresenceController extends Controller
{
    /**
     * Keeps the person online for the next 30 seconds: using the app, or
     * away (`state: away`) when they've left it idle.
     */
    public function heartbeat(PresenceHeartbeatRequest $request, PresenceService $service): Response
    {
        $service->heartbeat($request->user(), $request->isAway());

        return response()->noContent();
    }

    public function leave(Request $request, PresenceService $service): Response
    {
        $service->leave($request->user());

        return response()->noContent();
    }
}
