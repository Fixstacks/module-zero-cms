<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Component;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Zero CMS components: every enabled module's etc/zero_cms/components.json,
 * merged in module order (a later module can extend or override one).
 *
 *   {
 *     "faq": {
 *       "label": "FAQ",
 *       "description": "Questions and answers",
 *       "template": "Zero_Cms::components/faq.phtml",
 *       "fields": {
 *         "heading": {"type": "text", "label": "Heading"},
 *         "items": {"type": "repeater", "label": "Questions", "max": 20, "fields": {
 *           "question": {"type": "text", "label": "Question", "required": true},
 *           "answer": {"type": "textarea", "label": "Answer", "required": true}
 *         }}
 *       }
 *     }
 *   }
 *
 * Field types: see Model\Content\Validator. Cached with the config cache.
 */
class Registry
{
    private const FILE = 'zero_cms/components.json';
    private const CACHE_ID = 'zero_cms_components';

    /** @var array<string, array>|null */
    private ?array $components = null;

    public function __construct(
        private readonly Reader $moduleReader,
        private readonly ConfigCache $cache,
        private readonly Json $json
    ) {
    }

    /**
     * @return array<string, array{label: string, description: string, template: string, fields: array}>
     */
    public function getAll(): array
    {
        if ($this->components === null) {
            $cached = $this->cache->load(self::CACHE_ID);
            if ($cached !== false) {
                $this->components = (array) $this->json->unserialize($cached);
            } else {
                $this->components = $this->read();
                $this->cache->save($this->json->serialize($this->components), self::CACHE_ID);
            }
        }

        return $this->components;
    }

    /**
     * Forgets the cached list. For data patches: during setup:upgrade the
     * config cache can still hold the list from before the deploy, without
     * the components the new code adds.
     */
    public function reload(): void
    {
        $this->components = null;
        $this->cache->remove(self::CACHE_ID);
    }

    public function get(string $code): ?array
    {
        return $this->getAll()[$code] ?? null;
    }

    /**
     * @return array<string, array>
     */
    private function read(): array
    {
        $components = [];
        foreach ($this->moduleReader->getConfigurationFiles(self::FILE) as $content) {
            $definitions = json_decode((string) $content, true);
            if (is_array($definitions)) {
                $components = array_replace_recursive($components, $definitions);
            }
        }
        foreach ($components as $code => $definition) {
            if (!is_array($definition) || empty($definition['template']) || !preg_match('/^[a-z0-9_]+$/', (string) $code)) {
                unset($components[$code]);
                continue;
            }
            $components[$code] += ['label' => $code, 'description' => '', 'fields' => []];
        }

        return $components;
    }
}
