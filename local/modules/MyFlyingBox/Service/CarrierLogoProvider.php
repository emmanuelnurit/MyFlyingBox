<?php

namespace MyFlyingBox\Service;

use Thelia\Model\ConfigQuery;

/**
 * Service to provide carrier logos based on carrier_code
 */
class CarrierLogoProvider
{
    /**
     * Mapping of carrier codes to local logo filenames
     */
    private const CARRIER_LOCAL_LOGOS = [
        'dhl' => 'dhl.svg',
        'dhl_express' => 'dhl.svg',
        'ups' => 'ups.svg',
        'fedex' => 'fedex.svg',
        'tnt' => 'tnt.svg',
        'dpd' => 'dpd.svg',
        'gls' => 'gls.svg',
        'chronopost' => 'chronopost.svg',
        'colissimo' => 'colissimo.svg',
        'laposte' => 'laposte.svg',
        'la_poste' => 'laposte.svg',
        'mondialrelay' => 'mondialrelay.svg',
        'mondial_relay' => 'mondialrelay.svg',
        'relais_colis' => 'relaiscolis.svg',
        'colis_prive' => 'colisprive.svg',
        'db_schenker' => 'dbschenker.svg',
        'hermes' => 'hermes.svg',
        'postnl' => 'postnl.svg',
        'bpost' => 'bpost.svg',
        'correos' => 'correos.svg',
        'poste_italiane' => 'posteitaliane.svg',
        'royal_mail' => 'royalmail.svg',
        'spring' => 'spring.svg',
        'geodis' => 'geodis.svg',
        'seur' => 'seur.svg',
        'mrw' => 'mrw.svg',
        'nacex' => 'nacex.svg',
    ];

    /**
     * Mapping of carrier codes to external logo URLs (fallback when local not available)
     * Using official domains for Clearbit Logo API
     */
    private const CARRIER_DOMAINS = [
        // From MyFlyingBox API
        'bpost' => 'bpost.be',
        'canada_post' => 'canadapost-postescanada.ca',
        'chronopost' => 'chronopost.fr',
        'colis_prive' => 'colisprive.com',
        'colissimo' => 'colissimo.fr',
        'correos_express' => 'correosexpress.com',
        'dhl' => 'dhl.com',
        'dpd' => 'dpd.com',
        'fedex' => 'fedex.com',
        'itella' => 'itella.com',
        'itella_gls' => 'gls-group.eu',
        'mondial_relay' => 'mondialrelay.fr',
        'omniva' => 'omniva.ee',
        'parcelforce' => 'parcelforce.com',
        'post_nl' => 'postnl.nl',
        'purolator' => 'purolator.com',
        'tnt' => 'tnt.com',
        'ups' => 'ups.com',
        'usps' => 'usps.com',
        'venipak' => 'venipak.com',
        'we_post' => 'wepost.be',
        'zeleris' => 'zeleris.com',
        // Additional common carriers
        'gls' => 'gls-group.eu',
        'laposte' => 'laposte.fr',
        'la_poste' => 'laposte.fr',
        'mondialrelay' => 'mondialrelay.fr',
        'postnl' => 'postnl.nl',
        'correos' => 'correos.es',
        'royal_mail' => 'royalmail.com',
        'hermes' => 'hermesworld.com',
        'geodis' => 'geodis.com',
        'seur' => 'seur.com',
    ];

    /**
     * Brand colors for carriers
     */
    private const CARRIER_COLORS = [
        'dhl' => '#FFCC00',
        'ups' => '#351C15',
        'fedex' => '#4D148C',
        'tnt' => '#FF6600',
        'dpd' => '#DC0032',
        'gls' => '#003DA5',
        'chronopost' => '#FFD200',
        'colissimo' => '#FFD200',
        'laposte' => '#FFD200',
        'la_poste' => '#FFD200',
        'mondial_relay' => '#B41F23',
        'mondialrelay' => '#B41F23',
        'bpost' => '#E4002B',
        'post_nl' => '#FF6600',
        'postnl' => '#FF6600',
        'usps' => '#004B87',
        'canada_post' => '#E31837',
        'correos_express' => '#FFCC00',
        'colis_prive' => '#1E3A8A',
        'parcelforce' => '#E4002B',
        'hermes' => '#00A0DF',
        'geodis' => '#003DA5',
        'seur' => '#E4002B',
        'itella' => '#FF6600',
        'itella_gls' => '#003DA5',
        'omniva' => '#FF6600',
        'purolator' => '#E4002B',
        'venipak' => '#00A0DF',
        'we_post' => '#003DA5',
        'zeleris' => '#003DA5',
        'royal_mail' => '#E4002B',
    ];

    /**
     * Get the URL of a carrier logo
     * First checks for local file, then generates an inline SVG data URI
     *
     * @param string $carrierCode The carrier code from API
     * @return string|null The logo URL or null if no logo available
     */
    public function getLogoUrl(string $carrierCode): ?string
    {
        $normalizedCode = $this->normalizeCarrierCode($carrierCode);

        // First, check if local logo file exists
        if (isset(self::CARRIER_LOCAL_LOGOS[$normalizedCode])) {
            $filename = self::CARRIER_LOCAL_LOGOS[$normalizedCode];
            $localPath = __DIR__ . '/../images/carriers/' . $filename;

            if (file_exists($localPath)) {
                $moduleUrl = $this->getModuleAssetsUrl();
                return $moduleUrl . '/images/carriers/' . $filename;
            }
        }

        // Fallback to inline SVG with carrier initials
        return $this->generateSvgDataUri($normalizedCode);
    }

    /**
     * Generate fallback SVG for carrier (public wrapper)
     */
    public function generateFallbackSvg(string $carrierCode): string
    {
        return $this->generateSvgDataUri($this->normalizeCarrierCode($carrierCode));
    }

    /**
     * Generate an inline SVG data URI with carrier initials
     */
    private function generateSvgDataUri(string $carrierCode): string
    {
        $color = self::CARRIER_COLORS[$carrierCode] ?? '#6B7280';
        $initials = $this->getCarrierInitials($carrierCode);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="40" viewBox="0 0 80 40">';
        $svg .= '<rect width="80" height="40" rx="4" fill="' . $color . '"/>';
        $svg .= '<text x="40" y="26" font-family="Arial,sans-serif" font-size="14" font-weight="bold" fill="white" text-anchor="middle">' . htmlspecialchars($initials) . '</text>';
        $svg .= '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Get carrier initials for SVG display
     */
    private function getCarrierInitials(string $carrierCode): string
    {
        $initials = [
            'dhl' => 'DHL',
            'ups' => 'UPS',
            'fedex' => 'FedEx',
            'tnt' => 'TNT',
            'dpd' => 'DPD',
            'gls' => 'GLS',
            'chronopost' => 'CHRONO',
            'colissimo' => 'COLI',
            'laposte' => 'LP',
            'la_poste' => 'LP',
            'mondial_relay' => 'MR',
            'mondialrelay' => 'MR',
            'bpost' => 'BPOST',
            'post_nl' => 'PostNL',
            'postnl' => 'PostNL',
            'usps' => 'USPS',
            'canada_post' => 'CP',
            'correos_express' => 'CORREOS',
            'colis_prive' => 'CP',
            'parcelforce' => 'PF',
            'hermes' => 'HERMES',
            'geodis' => 'GEODIS',
            'seur' => 'SEUR',
            'itella' => 'ITELLA',
            'itella_gls' => 'GLS',
            'omniva' => 'OMNIVA',
            'purolator' => 'PURO',
            'venipak' => 'VENI',
            'we_post' => 'WEPOST',
            'zeleris' => 'ZELERIS',
            'royal_mail' => 'RM',
        ];

        return $initials[$carrierCode] ?? strtoupper(substr($carrierCode, 0, 4));
    }

    /**
     * Get the file path of a carrier logo
     *
     * @param string $carrierCode The carrier code from API
     * @return string|null The logo file path or null if not found
     */
    public function getLogoPath(string $carrierCode): ?string
    {
        $normalizedCode = $this->normalizeCarrierCode($carrierCode);

        if (!isset(self::CARRIER_LOCAL_LOGOS[$normalizedCode])) {
            return null;
        }

        $filename = self::CARRIER_LOCAL_LOGOS[$normalizedCode];
        $logoPath = __DIR__ . '/../images/carriers/' . $filename;

        if (file_exists($logoPath)) {
            return $logoPath;
        }

        return null;
    }

    /**
     * Check if a logo exists for the given carrier
     *
     * @param string $carrierCode The carrier code
     * @return bool
     */
    public function hasLogo(string $carrierCode): bool
    {
        $normalizedCode = $this->normalizeCarrierCode($carrierCode);
        return isset(self::CARRIER_DOMAINS[$normalizedCode]);
    }

    /**
     * Get all supported carrier codes
     *
     * @return array<string>
     */
    public function getSupportedCarriers(): array
    {
        return array_keys(self::CARRIER_DOMAINS);
    }

    /**
     * Normalize carrier code for lookup
     *
     * @param string $carrierCode
     * @return string
     */
    private function normalizeCarrierCode(string $carrierCode): string
    {
        return strtolower(trim($carrierCode));
    }

    /**
     * Get the base URL for module assets
     *
     * @return string
     */
    private function getModuleAssetsUrl(): string
    {
        $baseUrl = ConfigQuery::read('url_site', '');

        // Remove trailing slash if present
        $baseUrl = rtrim($baseUrl, '/');

        return $baseUrl . '/modules/MyFlyingBox';
    }
}
