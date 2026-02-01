<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class LibrarySetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'type', 'group', 'description'];

    /**
     * Get a setting value by key with type casting
     * Uses caching for performance
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getValue(string $key, $default = null)
    {
        $cacheKey = "library_setting_{$key}";

        return Cache::remember($cacheKey, 3600, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();

            if (!$setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting value
     * 
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public static function setValue(string $key, $value): bool
    {
        $setting = self::where('key', $key)->first();

        if (!$setting) {
            return false;
        }

        // Convert value to string for storage
        if (is_array($value)) {
            $value = json_encode($value);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } else {
            $value = (string) $value;
        }

        $setting->update(['value' => $value]);

        // Clear cache
        Cache::forget("library_setting_{$key}");
        Cache::forget('library_settings_all');

        return true;
    }

    /**
     * Get all settings as associative array
     * 
     * @return array
     */
    public static function getAllSettings(): array
    {
        return Cache::remember('library_settings_all', 3600, function () {
            $settings = [];

            foreach (self::all() as $setting) {
                $settings[$setting->key] = [
                    'value' => self::castValue($setting->value, $setting->type),
                    'type' => $setting->type,
                    'group' => $setting->group,
                    'description' => $setting->description
                ];
            }

            return $settings;
        });
    }

    /**
     * Get simple key-value settings
     * 
     * @return array
     */
    public static function getSimpleSettings(): array
    {
        $all = self::getAllSettings();
        $simple = [];

        foreach ($all as $key => $data) {
            $simple[$key] = $data['value'];
        }

        return $simple;
    }

    /**
     * Cast value based on type
     * 
     * @param string $value
     * @param string $type
     * @return mixed
     */
    private static function castValue(string $value, string $type)
    {
        switch ($type) {
            case 'integer':
                return (int) $value;
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($value, true);
            case 'float':
                return (float) $value;
            default:
                return $value;
        }
    }

    /**
     * Bulk update settings
     * 
     * @param array $settings
     * @return int Number of settings updated
     */
    public static function bulkUpdate(array $settings): int
    {
        $updated = 0;

        foreach ($settings as $key => $value) {
            if (self::setValue($key, $value)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get the loan days setting (simplified - no course-specific)
     * 
     * @return int
     */
    public static function getDefaultLoanDays(): int
    {
        return (int) self::getValue('default_loan_days', 7);
    }

    /**
     * Get max loans per student
     * 
     * @return int
     */
    public static function getMaxLoansPerStudent(): int
    {
        return (int) self::getValue('max_loans_per_student', 3);
    }

    /**
     * Get fine per day rate
     * 
     * @return float
     */
    public static function getFinePerDay(): float
    {
        return (float) self::getValue('fine_per_day', 5);
    }

    /**
     * Get library name
     * 
     * @return string
     */
    public static function getLibraryName(): string
    {
        return (string) self::getValue('library_name', 'Library');
    }

    /**
     * Clear all settings cache
     */
    public static function clearCache(): void
    {
        Cache::forget('library_settings_all');

        $settings = self::all();
        foreach ($settings as $setting) {
            Cache::forget("library_setting_{$setting->key}");
        }
    }
}
