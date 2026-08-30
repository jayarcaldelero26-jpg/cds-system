<?php

namespace App\Domain\Modules;

enum ProgramArea: string
{
    case PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT = 'protected_area_management_and_development';
    case WILDLIFE_CONSERVATION_AND_PROTECTION = 'wildlife_conservation_and_protection';
    case COMMUNITY_BASED_FOREST_MANAGEMENT = 'community_based_forest_management';
    case INTEGRATED_WATERSHED_MANAGEMENT = 'integrated_watershed_management';
    case ENGP = 'engp';
    case CONSERVATION = 'conservation';
    case DEVELOPMENT = 'development';

    public function label(): string
    {
        return match ($this) {
            self::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT => 'Protected Area Management and Development',
            self::WILDLIFE_CONSERVATION_AND_PROTECTION => 'Wildlife Conservation and Protection',
            self::COMMUNITY_BASED_FOREST_MANAGEMENT => 'Community-Based Forest Management',
            self::INTEGRATED_WATERSHED_MANAGEMENT => 'Integrated Watershed Management',
            self::ENGP => 'ENGP',
            self::CONSERVATION => 'Conservation',
            self::DEVELOPMENT => 'Development',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $area): array => ['value' => $area->value, 'label' => $area->label()], self::cases());
    }
}
