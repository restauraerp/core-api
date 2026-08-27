<?php

namespace App\Http\Controllers;

use App\Models\WebsiteSetting;
use App\Support\Assets\ManagedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    public function uploadLogo(Request $request, ManagedAssets $assets)
    {
        $request->validate(['logo' => 'required|image|max:2048']);

        $path = $request->file('logo')->store('logos', 'public');

        $setting = WebsiteSetting::firstOrNew(['key' => 'logo_url']);
        $previous = $setting->value;
        $setting->value = $path;
        $setting->type = 'string';
        $setting->save();

        $assets->release($previous);

        return response()->json($setting);
    }
}
