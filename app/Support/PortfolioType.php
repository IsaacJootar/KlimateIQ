<?php

namespace App\Support;

/**
 * What kind of organisation an agency is, for the enterprise pivot (docs/ENTERPRISE_PIVOT_PLAN.md
 * §6b, docs/ENTERPRISE_BUILD_PLAN.md Module 1) — drives which indices an agency sees by default,
 * the same way a Sector drives which indices a user sees today (App\Support\IndexCoverage), but
 * at the organisation level rather than the individual one.
 *
 * Nine cases: the seven enterprise sectors from the pivot plan, plus the two legacy categories
 * (Public Health, Agriculture) so nothing already built loses its home. An agency with no
 * portfolio type set (every agency seeded before this migration) falls back to seeing every
 * index — see App\Support\AgencyIndexCoverage::resolve().
 */
enum PortfolioType: string
{
    case InsuranceReinsurance = 'insurance_reinsurance';
    case BankingLending = 'banking_lending';
    case ClimateDisclosureEsg = 'climate_disclosure_esg';
    case TelecomInfrastructure = 'telecom_infrastructure';
    case LogisticsSupplyChain = 'logistics_supply_chain';
    case PublicFinance = 'public_finance';
    case PharmaHealthDistribution = 'pharma_health_distribution';
    case PublicHealth = 'public_health';
    case Agriculture = 'agriculture';

    public function label(): string
    {
        return match ($this) {
            self::InsuranceReinsurance => 'Insurance & Reinsurance',
            self::BankingLending => 'Banking & Lending',
            self::ClimateDisclosureEsg => 'Climate-Risk Disclosure / ESG',
            self::TelecomInfrastructure => 'Telecom & Infrastructure',
            self::LogisticsSupplyChain => 'Logistics & Supply Chain',
            self::PublicFinance => 'Public Finance',
            self::PharmaHealthDistribution => 'Pharma & Health Supply Distribution',
            self::PublicHealth => 'Public Health',
            self::Agriculture => 'Agriculture',
        };
    }

    /**
     * The index codes this portfolio type sees by default (App\Support\AgencyIndexCoverage
     * applies per-agency overrides on top of this). Public Health and Agriculture deliberately
     * mirror the existing PUBLIC_HEALTH / AGRICULTURE sector bundles in SectorSeeder exactly —
     * same audience, same indices, just resolved per-agency now instead of per-user-subscription.
     *
     * @return array<int, string>
     */
    public function defaultIndexCodes(): array
    {
        return match ($this) {
            self::InsuranceReinsurance => ['FLOOD_RISK', 'RIVERINE_FLOOD_FORECAST', 'DROUGHT_RISK'],
            self::BankingLending => ['AGRICULTURE_STRESS', 'DROUGHT_RISK', 'FLOOD_RISK'],
            self::ClimateDisclosureEsg => ['COMPOSITE_PRESSURE', 'FLOOD_RISK', 'DROUGHT_RISK', 'HEAT_STRESS_RISK'],
            self::TelecomInfrastructure => ['FLOOD_RISK', 'HEAT_STRESS_RISK', 'DUST_STORM_RISK', 'WILDFIRE_RISK'],
            self::LogisticsSupplyChain => ['RIVERINE_FLOOD_FORECAST', 'FLOOD_RISK'],
            self::PublicFinance => ['COMPOSITE_PRESSURE', 'DROUGHT_RISK', 'FLOOD_RISK'],
            self::PharmaHealthDistribution => ['MALARIA_RISK', 'RESPIRATORY_RISK', 'WATERBORNE_DISEASE_RISK', 'MENINGITIS_RISK'],
            self::PublicHealth => ['MALARIA_RISK', 'WATERBORNE_DISEASE_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK', 'MENINGITIS_RISK'],
            self::Agriculture => ['DROUGHT_RISK', 'AGRICULTURE_STRESS', 'IRRIGATION_NEED', 'RANGELAND_STRESS', 'PLANTING_WINDOW_RISK'],
        };
    }

    /**
     * The seven enterprise-pivot types only, in the order they're listed in
     * docs/ENTERPRISE_PIVOT_PLAN.md §5 — for a signup dropdown that leads with the new
     * direction rather than burying it after the two legacy options.
     *
     * @return array<int, self>
     */
    public static function enterpriseCases(): array
    {
        return [
            self::InsuranceReinsurance,
            self::BankingLending,
            self::ClimateDisclosureEsg,
            self::TelecomInfrastructure,
            self::LogisticsSupplyChain,
            self::PublicFinance,
            self::PharmaHealthDistribution,
        ];
    }
}
