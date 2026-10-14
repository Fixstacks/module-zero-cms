<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Ai;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ConfigInterface as LocaleConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Cms\Model\Component\Registry;
use Zero\Cms\Model\Content\Validator;

/**
 * What the editor asks the model for: a whole page from a description,
 * help with one text field, a page translated into a store view's language.
 * Answers are always cleaned (Content\Validator) and never saved here: the
 * editor loads them as unsaved changes, for a person to check and save.
 */
class Assistant
{
    public const XML_PATH_ENABLED = 'zero_cms/ai/enabled';
    public const XML_PATH_PROVIDER = 'zero_cms/ai/provider';
    public const ASSIST_ACTIONS = ['write', 'improve', 'shorten'];
    private const MAX_BRIEF = 4000;
    private const MAX_CATEGORIES = 80;

    /**
     * @param array<string, ClientInterface> $clients Providers by code (di.xml)
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly Registry $registry,
        private readonly Validator $validator,
        private readonly Schema $schema,
        private readonly LocaleConfig $localeConfig,
        private readonly array $clients = []
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED) && $this->clients !== [];
    }

    /**
     * A page from a description, in the store view's language.
     *
     * @return array{components: array}
     * @throws LocalizedException
     */
    public function generate(string $brief, int $storeId, string $pageTitle = ''): array
    {
        $brief = trim(mb_substr($brief, 0, self::MAX_BRIEF));
        if ($brief === '') {
            throw new LocalizedException(__('Describe the page first.'));
        }
        $language = $this->language($storeId);
        $system = implode("\n", [
            'You build pages for an online store out of ready-made components, by calling the page tool.',
            'Store: ' . $this->storeName($storeId) . '. Write all visible text in ' . $language . '.',
            'Rules:',
            '- Usually 3 to 8 components; start with a hero unless the description says otherwise.',
            '- Plain text only, no HTML or Markdown. In long text fields a blank line separates paragraphs.',
            '- Links point to store paths from the category list (e.g. /women.html) or to /contact, /customer/account/create/.',
            '- Product sliders use a category id from the list. Never invent ids or SKUs.',
            '- Leave image paths empty (a person adds images); fill alt with a short description of the image to use.',
            '- Write like a good copywriter: specific, brief, no filler, no exclamation marks.',
            '- Dates and times are store time. Now: ' . $this->now($storeId) . '.',
            '',
            'Categories (id: name, path):',
            $this->categories($storeId),
        ]);
        $prompt = ($pageTitle !== '' ? 'Page title: ' . $pageTitle . "\n\n" : '') . "Description:\n" . $brief;
        $answer = $this->client()->structured(
            $system,
            $prompt,
            'page',
            'The page content: a list of components, top to bottom.',
            $this->schema->page(),
            16000
        );
        $content = $this->validator->clean($answer);
        if ($content['components'] === []) {
            throw new LocalizedException(__('The model gave no usable components. Try a more concrete description.'));
        }

        return $content;
    }

    /**
     * New text for one field.
     *
     * @param string $action write|improve|shorten
     * @param array $context component (type), field (label), max, page (title), siblings (other texts)
     * @throws LocalizedException
     */
    public function assist(string $action, string $text, array $context, int $storeId): string
    {
        if (!in_array($action, self::ASSIST_ACTIONS, true)) {
            throw new LocalizedException(__('Unknown action.'));
        }
        $text = trim(mb_substr($text, 0, 10000));
        if ($action !== 'write' && $text === '') {
            throw new LocalizedException(__('The field is empty: write something first, or use Write.'));
        }
        $definition = $this->registry->get((string) ($context['component'] ?? ''));
        $max = max(20, min(10000, (int) ($context['max'] ?? 255)));
        $multiline = !empty($context['multiline']);
        $task = match ($action) {
            'write' => 'Write the text for this field.',
            'improve' => 'Improve this text: clearer, more concrete, better rhythm. Keep its meaning, facts and language.',
            'shorten' => 'Make this text noticeably shorter (about half), keeping what matters. Keep its language.',
        };
        $system = implode("\n", [
            'You write copy for an online store (' . $this->storeName($storeId) . ').',
            'Answer with the text only: no quotes, no comments, no HTML or Markdown.',
            'At most ' . $max . ' characters.' . ($multiline ? ' A blank line separates paragraphs.' : ' One line.'),
            $action === 'write' ? 'Write in ' . $this->language($storeId) . '.' : '',
        ]);
        $siblings = array_filter(array_map(
            static fn ($value) => is_scalar($value) ? mb_substr(trim((string) $value), 0, 500) : '',
            (array) ($context['siblings'] ?? [])
        ));
        $prompt = implode("\n", array_filter([
            'Page: ' . (string) ($context['page'] ?? ''),
            'Component: ' . ($definition ? $definition['label'] . ' (' . $definition['description'] . ')' : 'unknown'),
            'Field: ' . (string) ($context['field'] ?? ''),
            $siblings ? "Other fields of this component:\n" . implode("\n", array_map(
                static fn ($name, $value) => '- ' . $name . ': ' . $value,
                array_keys($siblings),
                $siblings
            )) : '',
            $text !== '' ? "Current text:\n" . $text : '',
            '',
            $task,
        ]));
        $answer = $this->client()->text($system, $prompt, min(4000, (int) ceil($max / 2) + 200));
        $answer = trim(strip_tags($answer), " \t\n\r\0\x0B\"“”«»");
        if (!$multiline) {
            $answer = (string) preg_replace('/\s+/u', ' ', $answer);
        }

        return mb_substr($answer, 0, $max);
    }

    /**
     * The page with its texts in another language: `$locale` (one of
     * Magento's locales, e.g. de_DE), or the store view's language. Everything
     * that isn't text (links, images, products, options) is kept from the original.
     *
     * @return array{components: array}
     * @throws LocalizedException
     */
    public function translate(array $content, int $storeId, string $locale = ''): array
    {
        $content = $this->validator->clean($content);
        if ($content['components'] === []) {
            throw new LocalizedException(__('Nothing to translate.'));
        }
        if ($locale !== '' && !isset($this->locales()[$locale])) {
            throw new LocalizedException(__('Unknown language.'));
        }
        $language = $locale !== '' ? $this->localeName($locale) : $this->language($storeId);
        $system = implode("\n", [
            'You translate the content of a store page (' . $this->storeName($storeId) . ') into ' . $language . ', by calling the page tool.',
            'Translate every human-readable text: headings, paragraphs, link labels, image alt texts, questions and answers.',
            'Keep everything else exactly as it is: the components and their order, URLs, image paths, ids, SKUs, options, numbers.',
            'Natural, idiomatic ' . $language . ' for shoppers, not word-for-word. Keep brand and product names. Plain text only.',
        ]);
        $answer = $this->client()->structured(
            $system,
            (string) json_encode(['components' => $content['components']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'page',
            'The same page, translated.',
            $this->schema->page(),
            32000
        );
        $translated = $this->validator->clean($answer)['components'];
        $components = [];
        foreach ($content['components'] as $index => $original) {
            $copy = $translated[$index] ?? null;
            $definition = $this->registry->get($original['type']);
            $components[] = $copy !== null && $copy['type'] === $original['type'] && $definition !== null
                ? ['uid' => $original['uid'], 'type' => $original['type'], 'data' => $this->keepStructure((array) $definition['fields'], $original['data'], $copy['data'])]
                : $original;
        }

        return ['components' => $components];
    }

    /**
     * The language of a store view, as an English name ("Arabic (Saudi Arabia)").
     */
    public function language(int $storeId): string
    {
        return $this->localeName($this->locale($storeId));
    }

    /**
     * The locale of a store view (All Store Views: the default store view's).
     */
    public function locale(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeId ?: $this->storeManager->getDefaultStoreView()?->getId()
        ) ?: 'en_US';
    }

    /**
     * Languages to translate into: Magento's locales, by English name
     * (with the native name), sorted.
     *
     * @return array<string, string> locale code => label
     */
    public function locales(): array
    {
        $locales = [];
        foreach ($this->localeConfig->getAllowedLocales() as $code) {
            $english = $this->localeName($code);
            $native = class_exists(\Locale::class) ? \Locale::getDisplayName($code, $code) : '';
            $locales[$code] = $native !== '' && $native !== $english ? $english . ' · ' . $native : $english;
        }
        asort($locales, SORT_NATURAL | SORT_FLAG_CASE);

        return $locales;
    }

    private function localeName(string $locale): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayName($locale, 'en') : '';

        return $name !== '' ? $name : $locale;
    }

    /**
     * Texts from the translation, everything else from the original.
     */
    private function keepStructure(array $fields, array $original, array $translated): array
    {
        $result = $original;
        foreach ($fields as $name => $field) {
            $from = $translated[$name] ?? null;
            switch ($field['type'] ?? 'text') {
                case 'text':
                case 'textarea':
                case 'markdown':
                case 'html':
                    $result[$name] = is_string($from) && $from !== '' ? $from : ($original[$name] ?? '');
                    break;
                case 'link':
                    if (is_array($from) && ($from['label'] ?? '') !== '') {
                        $result[$name]['label'] = $from['label'];
                    }
                    break;
                case 'image':
                    if (is_array($from) && ($from['alt'] ?? '') !== '') {
                        $result[$name]['alt'] = $from['alt'];
                    }
                    break;
                case 'repeater':
                    foreach ((array) ($original[$name] ?? []) as $i => $item) {
                        $result[$name][$i] = $this->keepStructure(
                            (array) ($field['fields'] ?? []),
                            (array) $item,
                            is_array($from[$i] ?? null) ? $from[$i] : []
                        );
                    }
                    break;
            }
        }

        return $result;
    }

    private function client(): ClientInterface
    {
        if (!$this->isEnabled()) {
            throw new LocalizedException(__('AI is turned off: Stores > Configuration > Zero > CMS > AI.'));
        }
        $provider = (string) $this->scopeConfig->getValue(self::XML_PATH_PROVIDER);

        return $this->clients[$provider] ?? $this->clients[array_key_first($this->clients)];
    }

    private function now(int $storeId): string
    {
        $store = $storeId ? $this->storeManager->getStore($storeId) : $this->storeManager->getDefaultStoreView();
        $zone = (string) $this->scopeConfig->getValue('general/locale/timezone', ScopeInterface::SCOPE_STORE, $store->getId()) ?: 'UTC';

        return (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format('l, Y-m-d\TH:i');
    }

    private function storeName(int $storeId): string
    {
        $store = $storeId ? $this->storeManager->getStore($storeId) : $this->storeManager->getDefaultStoreView();
        $name = (string) $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $store->getId());

        return $name !== '' ? $name : $store->getWebsite()->getName();
    }

    private function categories(int $storeId): string
    {
        $store = $storeId ? $this->storeManager->getStore($storeId) : $this->storeManager->getDefaultStoreView();
        $suffix = (string) $this->scopeConfig->getValue('catalog/seo/category_url_suffix', ScopeInterface::SCOPE_STORE, $store->getId());
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId((int) $store->getId())
            ->addAttributeToSelect(['name', 'url_path'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['in' => [2, 3]])
            ->addAttributeToFilter('path', ['like' => '1/' . $store->getRootCategoryId() . '/%'])
            ->setOrder('path', 'ASC')
            ->setPageSize(self::MAX_CATEGORIES);
        $lines = [];
        foreach ($collection as $category) {
            $lines[] = $category->getId() . ': ' . $category->getName() . ', /' . $category->getUrlPath() . $suffix;
        }

        return $lines ? implode("\n", $lines) : '(none)';
    }
}
