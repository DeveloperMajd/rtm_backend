<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The viewer's notification and privacy settings (Settings-1440).
 *
 * The privacy ones are enforced here on the server, wherever what they
 * hide would otherwise leave it: read state in the read pointers and their
 * broadcasts, last seen in every resource that carries it (see
 * LastSeenVisibility), typing in the typing endpoint. The notification ones
 * are kept here so they follow the person between devices; the client acts
 * on them.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => UserSettings::for($request->user())->toPreferences()]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $settings = UserSettings::for($request->user());
        $settings->fill($request->validated())->save();

        return response()->json(['data' => $settings->toPreferences()]);
    }
}
