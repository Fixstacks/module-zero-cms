<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Ai;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\Cms\Controller\Adminhtml\Editor\AbstractEditor;
use Zero\Cms\Model\Ai\Assistant;
use Zero\Cms\Model\Content\Target\Pool;

/**
 * The editor's AI actions: own permission (each request is paid for), the
 * session released before the model is called (an answer can take a while;
 * the editor's preview requests shouldn't wait for it), errors as JSON.
 */
abstract class AbstractAi extends AbstractEditor implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_Cms::ai';

    public function __construct(
        Context $context,
        Pool $targets,
        protected readonly Assistant $assistant
    ) {
        parent::__construct($context, $targets);
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $target = $this->target();
            $this->_session->writeClose();

            return $this->json($this->answer($target));
        } catch (LocalizedException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @param array{type: string, id: int, store: int, entity: object} $target
     * @throws LocalizedException
     */
    abstract protected function answer(array $target): array;
}
