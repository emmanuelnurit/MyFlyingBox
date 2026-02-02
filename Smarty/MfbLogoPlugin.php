<?php

declare(strict_types=1);

namespace MyFlyingBox\Smarty;

use MyFlyingBox\Service\ModuleLogoProvider;
use TheliaSmarty\Template\AbstractSmartyPlugin;
use TheliaSmarty\Template\SmartyPluginDescriptor;

/**
 * Smarty plugin to expose the module logo in templates
 *
 * Usage in templates: {mfb_logo}
 */
class MfbLogoPlugin extends AbstractSmartyPlugin
{
    private ModuleLogoProvider $logoProvider;

    public function __construct(ModuleLogoProvider $logoProvider)
    {
        $this->logoProvider = $logoProvider;
    }

    public function getPluginDescriptors(): array
    {
        return [
            new SmartyPluginDescriptor('function', 'mfb_logo', $this, 'getLogo'),
        ];
    }

    /**
     * Smarty function to get the module logo as a data URI
     *
     * @param array<string, mixed> $params Smarty parameters (unused)
     * @param \Smarty_Internal_Template $smarty Smarty template instance
     */
    public function getLogo(array $params, \Smarty_Internal_Template $smarty): string
    {
        return $this->logoProvider->getLogoDataUri();
    }
}
