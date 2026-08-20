<?php
namespace App\Traits;

use Ramsey\Uuid\Nonstandard\Uuid;

trait HasUuid
{
    public function initializeHasUuid()
    {
        $this->setKeyType('string');
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            $model->id = Uuid::uuid4();
            if (!isset($model->attributes[$model->getKeyName()])) {
                $model->incrementing = false;
                $uuid = Uuid::uuid4();
                $model->attributes[$model->getKeyName()] = $uuid->toString();
            }
        }, 0);
    }
}
// Sync marker: 2026-08-20 17:39:39
