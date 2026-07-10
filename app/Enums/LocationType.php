<?php

namespace App\Enums;

enum LocationType: string
{
    case HEAD_OFFICE = 'head_office';
    case BRANCH = 'branch';
    case WAREHOUSE = 'warehouse';
    case KITCHEN = 'kitchen';

    public function title(): string
    {
        return match($this) {
            self::HEAD_OFFICE => 'Head Office',
            self::BRANCH => 'Branch',
            self::WAREHOUSE => 'Warehouse',
            self::KITCHEN => 'Kitchen',
        };
    }
    
    public static function options(): array
    {
        return array_map(fn($case) => [
            'slug' => $case->value,
            'title' => $case->title(),
        ], self::cases());
    }
}
