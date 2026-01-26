<?php

namespace MDDev\DynamicDashboard\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

/**
 * Eloquent cast that JSON-decodes widget settings and applies per-key casts
 * defined by each DynamicWidget (primitives, BackedEnums, arrays of enums).
 */
class AsWidgetSettings implements CastsAttributes
{
    /**
     * Cast the given value (from DB to PHP).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $settings = json_decode($value, true) ?? [];
        if (! (class_exists($attributes['type']) && is_subclass_of($attributes['type'], DynamicWidget::class))) {
            return $settings;
        }

        $widgetClass = new $attributes['type'];

        $casts = $widgetClass::getSettingsCasts();

        foreach ($casts as $settingKey => $cast) {
            if (! array_key_exists($settingKey, $settings) || ! isset($settings[$settingKey])) {
                continue;
            }

            $settings[$settingKey] = $this->castValue($settings[$settingKey], $cast);
        }

        return $settings;
    }

    /**
     * Cast a single value according to cast definition.
     *
     * @param  string|array{0: string, 1: class-string}  $cast
     */
    protected function castValue(mixed $value, string|array $cast): mixed
    {
        if ($value === null) {
            return null;
        }

        // Handle array cast: ['array', EnumClass::class]
        if (is_array($cast)) {
            if ($cast[0] === 'array' && isset($cast[1]) && is_array($value)) {
                return array_map(fn ($v) => $this->castValue($v, $cast[1]), $value);
            }

            return $value;
        }

        // Handle primitive types (same as Laravel's castAttribute)
        return match ($cast) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            default => $this->castToEnum($value, $cast),
        };
    }

    /**
     * Cast value to BackedEnum using Laravel's pattern.
     *
     * @param  class-string  $enumClass
     */
    protected function castToEnum(mixed $value, string $enumClass): mixed
    {
        if (! is_subclass_of($enumClass, BackedEnum::class)) {
            return $value;
        }

        // Already the correct enum instance
        if ($value instanceof $enumClass) {
            return $value;
        }

        return $enumClass::tryFrom($value);
    }

    /**
     * Prepare the given value for storage (from PHP to DB).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (is_string($value)) {
            return $value;
        }

        // Convert enums back to their values for storage
        $storable = array_map(function ($v) {
            return $v instanceof BackedEnum ? $v->value : $v;
        }, $value ?? []);

        return json_encode($storable);
    }
}
