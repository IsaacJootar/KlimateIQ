<?php

namespace App\Support;

/**
 * How trustworthy a single scoring weight or calibration bound is. Structured, queryable, and
 * shown in the admin UI and the product — so "is this validated?" is a database question, not a
 * "did someone read docs/MODEL.md" gamble that silently drifts.
 *
 * Every weight and bound carries one. New indices default to `placeholder`, which forces a
 * deliberate choice (and a failing test) rather than an accidental claim of rigour.
 */
enum CalibrationStatus: string
{
    /** A plausible engineering guess — a sensible range or weight chosen by judgement, tied to nothing measured. */
    case Placeholder = 'placeholder';

    /** Set deliberately by a platform admin through the UI — a human decision, not a formal validation. */
    case AdminTuned = 'admin_tuned';

    /** Set from a cited external public reference (e.g. US EPA AQI "Hazardous", WHO air-quality guideline). */
    case Reference = 'reference';

    /** Computed from a real dataset — e.g. GloFAS reanalysis return periods, a population census. */
    case ReferenceDerived = 'reference_derived';

    /** Fitted / validated against health or damage outcome data (Malaria Atlas, DHS-MIS, flood records). None yet. */
    case OutcomeValidated = 'outcome_validated';

    public function label(): string
    {
        return match ($this) {
            self::Placeholder => 'Uncalibrated placeholder',
            self::AdminTuned => 'Set by an admin',
            self::Reference => 'From a cited reference',
            self::ReferenceDerived => 'Derived from real data',
            self::OutcomeValidated => 'Validated against outcomes',
        };
    }

    /** 0 (a raw guess) … 4 (validated). Used to reduce a whole index to one headline. */
    public function rank(): int
    {
        return match ($this) {
            self::Placeholder => 0,
            self::AdminTuned => 1,
            self::Reference => 2,
            self::ReferenceDerived => 3,
            self::OutcomeValidated => 4,
        };
    }

    /** True while the value is still nothing more than an engineering guess. */
    public function isPlaceholder(): bool
    {
        return $this === self::Placeholder;
    }

    /** Tailwind classes for the admin-UI chip. */
    public function chipClasses(): string
    {
        return match ($this) {
            self::Placeholder => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            self::AdminTuned => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
            self::Reference => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
            self::ReferenceDerived => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
            self::OutcomeValidated => 'bg-emerald-200 text-emerald-900 dark:bg-emerald-800/50 dark:text-emerald-200',
        };
    }
}
