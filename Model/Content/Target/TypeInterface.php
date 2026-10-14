<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content\Target;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Something the Zero CMS editor can edit: CMS pages and blocks here, other
 * modules add theirs (a blog post) to Pool in di.xml. The content itself is
 * stored by Model\Content\Repository under the type's code.
 */
interface TypeInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function load(int $id): object;

    public function title(object $entity): string;

    /** Shown under the title in the editor (URL key, identifier). */
    public function identifier(object $entity): string;

    /** The page on the storefront, or '' when it has none of its own (a block). */
    public function storefrontUrl(object $entity, StoreInterface $store): string;

    /** The entity's own admin form, for the editor's Back link. */
    public function backUrl(int $id): string;

    /** The admin menu item highlighted while editing. */
    public function menu(): string;
}
