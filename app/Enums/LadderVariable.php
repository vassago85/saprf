<?php

namespace App\Enums;

/**
 * The variable held on the x-axis of a ladder session.
 *
 * The whole analysis service, DTO and UI read the axis label, unit and short
 * name from this enum — nothing hardcodes "charge" or "grains". A seating-depth
 * ladder is the same analysis with a different unit, and adding a further
 * variable (case volume, neck tension, ...) later is a one-line change here
 * plus a UI-only follow-up.
 */
enum LadderVariable: string
{
    case ChargeWeight = 'charge_weight';
    case SeatingDepth = 'seating_depth';

    /**
     * Short unit symbol stored on the ladder_sessions.unit column.
     */
    public function unit(): string
    {
        return match ($this) {
            self::ChargeWeight => 'gr',
            self::SeatingDepth => 'mm',
        };
    }

    /**
     * Axis label rendered on the analysis chart and per-step table header.
     */
    public function axisLabel(): string
    {
        return match ($this) {
            self::ChargeWeight => 'Charge (gr)',
            self::SeatingDepth => 'Seating (mm)',
        };
    }

    /**
     * Human-friendly name used in verdict text and export headings.
     */
    public function label(): string
    {
        return match ($this) {
            self::ChargeWeight => 'Charge weight',
            self::SeatingDepth => 'Seating depth',
        };
    }

    /**
     * Slope unit used in fitted-slope readouts and verdict copy.
     */
    public function slopeUnit(): string
    {
        return 'fps/'.$this->unit();
    }
}
