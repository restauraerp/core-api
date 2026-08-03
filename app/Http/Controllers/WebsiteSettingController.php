<?php

namespace App\Http\Controllers;

use App\Models\WebsiteSetting;
use Illuminate\Http\Request;

class WebsiteSettingController extends Controller
{
    public function index()
    {
        return response()->json(WebsiteSetting::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255', $this->tenantUnique('website_settings')],
            'value' => 'nullable|string',
            'type' => 'nullable|string|max:255',
        ]);

        $setting = WebsiteSetting::create($validated);
        return response()->json($setting, 201);
    }

    public function show(WebsiteSetting $websiteSetting)
    {
        return response()->json($websiteSetting);
    }

    public function update(Request $request, WebsiteSetting $websiteSetting)
    {
        $validated = $request->validate([
            'key' => ['sometimes', 'string', 'max:255', $this->tenantUnique('website_settings')->ignore($websiteSetting->id)],
            'value' => 'nullable|string',
            'type' => 'nullable|string|max:255',
        ]);

        $websiteSetting->update($validated);
        return response()->json($websiteSetting);
    }

    public function destroy(WebsiteSetting $websiteSetting)
    {
        $websiteSetting->delete();
        return response()->json(null, 204);
    }
}
