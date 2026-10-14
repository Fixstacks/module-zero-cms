<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Cms\Model\Ai\Assistant;
use Zero\Cms\Model\Component\Registry;
use Zero\Cms\Model\Content\Repository;

/**
 * Everything the editor script needs, as one JSON object (editor.phtml):
 * the page or block, its content for the store view (own, or inherited
 * from All Store Views), versions, components and their fields, store
 * views, the editor's admin URLs, and AI (when on and allowed).
 */
class Editor implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Repository $repository,
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl,
        private readonly FormKey $formKey,
        private readonly Assistant $assistant,
        private readonly AuthorizationInterface $authorization,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @param array{type: string, id: int, store: int, entity: object, title: string, handler: \Zero\Cms\Model\Content\Target\TypeInterface} $target
     */
    public function getConfig(array $target): array
    {
        $own = $this->repository->getOwn($target['type'], $target['id'], $target['store']);
        $inherited = $own === null && $target['store'] !== 0
            ? $this->repository->getOwn($target['type'], $target['id'], 0)
            : null;
        $params = ['type' => $target['type'], 'id' => $target['id'], 'store' => $target['store']];
        $url = fn (string $route) => $this->backendUrl->getUrl($route, $params);

        return [
            'target' => $params + [
                'title' => $target['title'],
                'identifier' => $target['handler']->identifier($target['entity']),
                'storefrontUrl' => $this->storefrontUrl($target),
            ],
            'content' => $own ?? $inherited ?? ['components' => []],
            'state' => $own !== null ? 'own' : ($inherited !== null ? 'inherited' : 'new'),
            'revisions' => $this->repository->getRevisions($target['type'], $target['id'], $target['store']),
            'components' => $this->components(),
            'stores' => $this->stores(),
            // Datetime fields are in store time: this store view's (All Store Views: the default one's).
            'timezone' => $this->timezone->getConfigTimezone(
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $target['store'] ?: $this->storeManager->getDefaultStoreView()?->getId()
            ),
            'mediaUrl' => rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/',
            'formKey' => $this->formKey->getFormKey(),
            'ai' => $this->assistant->isEnabled() && $this->authorization->isAllowed('Zero_Cms::ai') ? [
                'language' => $this->assistant->language($target['store']),
                'locale' => $this->assistant->locale($target['store']),
                'locales' => $this->assistant->locales(),
                'generate' => $url('zero_cms/ai/generate'),
                'assist' => $url('zero_cms/ai/assist'),
                'translate' => $url('zero_cms/ai/translate'),
            ] : null,
            'urls' => [
                'editor' => $this->backendUrl->getUrl('zero_cms/editor/index', ['type' => $target['type'], 'id' => $target['id']]),
                'save' => $url('zero_cms/editor/save'),
                'preview' => $url('zero_cms/editor/preview'),
                'revision' => $url('zero_cms/editor/revision'),
                'reset' => $url('zero_cms/editor/reset'),
                'upload' => $url('zero_cms/editor/upload'),
                'back' => $target['handler']->backUrl($target['id']),
            ],
        ];
    }

    /**
     * Components with their labels translated, for the "Add" list and forms.
     */
    private function components(): array
    {
        $components = [];
        foreach ($this->registry->getAll() as $code => $definition) {
            $components[$code] = [
                'label' => (string) __($definition['label']),
                'description' => (string) __($definition['description']),
                'fields' => $this->translateFields((array) $definition['fields']),
            ];
        }

        return $components;
    }

    private function translateFields(array $fields): array
    {
        foreach ($fields as $name => $field) {
            $fields[$name]['label'] = (string) __($field['label'] ?? $name);
            if (isset($field['options'])) {
                $fields[$name]['options'] = array_map(static fn ($label) => (string) __($label), (array) $field['options']);
            }
            if (isset($field['fields'])) {
                $fields[$name]['fields'] = $this->translateFields((array) $field['fields']);
            }
        }

        return $fields;
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function stores(): array
    {
        $stores = [['id' => 0, 'label' => (string) __('All Store Views')]];
        foreach ($this->storeManager->getStores() as $store) {
            $stores[] = ['id' => (int) $store->getId(), 'label' => $store->getWebsite()->getName() . ' / ' . $store->getName()];
        }

        return $stores;
    }

    private function storefrontUrl(array $target): string
    {
        $store = $target['store']
            ? $this->storeManager->getStore($target['store'])
            : $this->storeManager->getDefaultStoreView();

        return $target['handler']->storefrontUrl($target['entity'], $store);
    }
}
