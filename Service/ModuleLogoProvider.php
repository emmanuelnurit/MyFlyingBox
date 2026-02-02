<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

/**
 * Service to provide the module logo as a data URI
 *
 * Uses base64 encoding for reliable display across different server configurations,
 * following the same pattern as CarrierLogoProvider.
 */
final class ModuleLogoProvider
{
    private const LOGO_PATH = __DIR__ . '/../images/mfb-icon.png';

    private ?string $cachedLogoUri = null;

    /**
     * Get the module logo as a data URI
     */
    public function getLogoDataUri(): string
    {
        if ($this->cachedLogoUri !== null) {
            return $this->cachedLogoUri;
        }

        if (file_exists(self::LOGO_PATH)) {
            $content = file_get_contents(self::LOGO_PATH);
            if ($content !== false) {
                $this->cachedLogoUri = 'data:image/png;base64,' . base64_encode($content);

                return $this->cachedLogoUri;
            }
        }

        // Fallback to file path if reading fails
        return '/modules/MyFlyingBox/images/mfb-icon.png';
    }
}
