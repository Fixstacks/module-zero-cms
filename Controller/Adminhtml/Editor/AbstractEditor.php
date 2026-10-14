<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Editor;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Zero\Cms\Model\Content\Repository;
use Zero\Cms\Model\Content\Target\Pool;
use Zero\Cms\Model\Content\Target\TypeInterface;

/**
 * Shared by the Zero CMS editor's actions: permission, what is being edited
 * (type, id, store: a CMS page or block, or a type another module adds to
 * Model\Content\Target\Pool), JSON answers.
 */
abstract class AbstractEditor extends Action
{
    public const ADMIN_RESOURCE = 'Zero_Cms::editor';

    public function __construct(
        Context $context,
        protected readonly Pool $targets
    ) {
        parent::__construct($context);
    }

    /**
     * What the request asks to edit.
     *
     * @return array{type: string, id: int, store: int, entity: object, title: string, handler: TypeInterface}
     * @throws LocalizedException
     */
    protected function target(): array
    {
        $type = (string) $this->getRequest()->getParam('type', Repository::TYPE_PAGE);
        $id = (int) $this->getRequest()->getParam('id', 0);
        $handler = $this->targets->get($type);
        try {
            $entity = $handler->load($id);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('This page or block no longer exists.'));
        }

        return [
            'type' => $type,
            'id' => $id,
            'store' => max(0, (int) $this->getRequest()->getParam('store', 0)),
            'entity' => $entity,
            'title' => $handler->title($entity),
            'handler' => $handler,
        ];
    }

    protected function json(array $data, int $status = 200): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode($status)->setData($data);

        return $result;
    }

    /**
     * @return mixed The posted `content` (JSON string), decoded
     */
    protected function postedContent()
    {
        return json_decode((string) $this->getRequest()->getParam('content', ''), true);
    }

    protected function adminName(): string
    {
        return (string) ($this->_auth->getUser()?->getUserName() ?? '');
    }
}
