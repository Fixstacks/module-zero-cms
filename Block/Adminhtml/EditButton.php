<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Block\Adminhtml;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Zero\Cms\Model\Content\Repository;

/**
 * "Edit with Zero CMS" on the CMS page and block edit forms (once saved,
 * and for admins allowed to use the editor).
 */
class EditButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly UrlInterface $url,
        private readonly AuthorizationInterface $authorization,
        private readonly string $type = Repository::TYPE_PAGE
    ) {
    }

    /**
     * @return array
     */
    public function getButtonData()
    {
        $id = (int) $this->request->getParam($this->type === Repository::TYPE_PAGE ? 'page_id' : 'block_id');
        if (!$id || !$this->authorization->isAllowed('Zero_Cms::editor')) {
            return [];
        }

        return [
            'label' => __('Edit with Zero CMS'),
            'class' => 'zero-cms-edit',
            'on_click' => sprintf("location.href = '%s';", $this->url->getUrl('zero_cms/editor/index', ['type' => $this->type, 'id' => $id])),
            'sort_order' => 25,
        ];
    }
}
