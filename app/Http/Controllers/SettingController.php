<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LibrarySetting;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    /**
     * Get all library settings
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $settings = LibrarySetting::getSimpleSettings();

            return response()->json([
                'success' => true,
                'settings' => $settings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get settings for circulation (public endpoint)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function circulation()
    {
        try {
            return response()->json([
                'success' => true,
                'default_loan_days' => LibrarySetting::getDefaultLoanDays(),
                'max_loans_per_student' => LibrarySetting::getMaxLoansPerStudent(),
                'fine_per_day' => LibrarySetting::getFinePerDay(),
                'library_name' => LibrarySetting::getLibraryName()
            ]);
        } catch (\Exception $e) {
            // Return defaults if settings table doesn't exist yet
            return response()->json([
                'success' => true,
                'default_loan_days' => 7,
                'max_loans_per_student' => 3,
                'fine_per_day' => 5,
                'library_name' => 'PCLU Library System'
            ]);
        }
    }

    /**
     * Update a single setting
     * 
     * @param Request $request
     * @param string $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, string $key)
    {
        try {
            $setting = LibrarySetting::where('key', $key)->first();

            if (!$setting) {
                return response()->json(['message' => 'Setting not found'], 404);
            }

            $value = $request->input('value');

            // Validate based on type
            if ($setting->type === 'integer') {
                if (!is_numeric($value)) {
                    return response()->json(['message' => 'Value must be a number'], 422);
                }
                $value = (int) $value;
            }

            LibrarySetting::setValue($key, $value);

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully',
                'key' => $key,
                'value' => LibrarySetting::getValue($key)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update setting',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update multiple settings
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkUpdate(Request $request)
    {
        try {
            $settings = $request->all();

            // Validate required settings
            $validator = Validator::make($settings, [
                'default_loan_days' => 'sometimes|integer|min:1|max:365',
                'max_loans_per_student' => 'sometimes|integer|min:1|max:20',
                'fine_per_day' => 'sometimes|numeric|min:0|max:1000',
                'library_name' => 'sometimes|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Clear cache before updating
            LibrarySetting::clearCache();

            $updated = LibrarySetting::bulkUpdate($settings);

            return response()->json([
                'success' => true,
                'message' => "Successfully updated {$updated} setting(s)",
                'updated_count' => $updated,
                'settings' => LibrarySetting::getSimpleSettings()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset all settings to defaults
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset()
    {
        try {
            $defaults = [
                'library_name' => 'PCLU Library System',
                'default_loan_days' => 7,
                'max_loans_per_student' => 3,
                'fine_per_day' => 5
            ];

            LibrarySetting::clearCache();
            LibrarySetting::bulkUpdate($defaults);

            return response()->json([
                'success' => true,
                'message' => 'Settings reset to defaults',
                'settings' => LibrarySetting::getSimpleSettings()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
