<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Setup\Patch\Data;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Turns Page Builder's editor off once, with an admin notification: its
 * widgets need RequireJS, which Zero doesn't load. Pages are built with Zero
 * CMS; Page Builder can be turned back on in Content Management settings.
 */
class TurnOffPageBuilder implements DataPatchInterface
{
    private const PATH = 'cms/pagebuilder/enabled';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly NotifierInterface $notifier
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->insertOnDuplicate(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['scope' => 'default', 'scope_id' => 0, 'path' => self::PATH, 'value' => '0'],
            ['value']
        );
        $this->notifier->addNotice(
            (string) __('Page Builder is off: pages are built with Zero CMS'),
            (string) __(
                'Page Builder\'s sliders, tabs, carousels and other interactive content don\'t work on the Zero '
                . 'storefront. Build pages with Content > Pages > Edit with Zero CMS. Page Builder can be turned '
                . 'back on in Stores > Configuration > General > Content Management > Advanced Content Tools.'
            )
        );
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
