<?php 

/**
 * Debug Preset Helper Class
 */
class DebugPresets
{
    public static function apply(Model $model, string $preset): void
    {
        match ($preset) {
            'off' => $model->setDebug(false),
            'basic' => $model->setDebug(DebugLevel::BASIC, DebugCategory::SQL),
            'developer' => $model->setDebug(DebugLevel::DETAILED, DebugCategory::DEVELOPER, 'html'),
            'performance' => $model->setDebug(DebugLevel::DETAILED, DebugCategory::PERFORMANCE | DebugCategory::BULK),
            'cli' => $model->setDebug(DebugLevel::DETAILED, DebugCategory::DEVELOPER, 'ansi'),
            'production' => $model->setDebug(DebugLevel::BASIC, DebugCategory::PERFORMANCE, 'json'),
            'verbose' => $model->setDebug(DebugLevel::VERBOSE, DebugCategory::ALL, 'html'),
            default => throw new InvalidArgumentException("Unknown debug preset: $preset")
        };
    }
}