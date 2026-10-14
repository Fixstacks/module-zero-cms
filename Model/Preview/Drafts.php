<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Preview;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Zero\Cms\Model\Content\Validator;

/**
 * Unsaved editor content for the live preview: kept in the cache for an
 * hour under a random id, opened on the storefront through a link signed
 * with the installation's crypt key (draft id, store view, expiry). Only
 * someone with the editor could have made the link.
 */
class Drafts
{
    public const LIFETIME = 3600;
    private const CACHE_PREFIX = 'zero_cms_draft_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Validator $validator
    ) {
    }

    /**
     * Stores a draft; returns the query for the storefront preview link.
     *
     * @param mixed $content
     * @return array{draft: string, expires: int, token: string}
     */
    public function save($content, int $storeId, ?int $now = null): array
    {
        $draft = bin2hex(random_bytes(16));
        $this->cache->save(
            (string) json_encode($this->validator->clean($content)),
            self::CACHE_PREFIX . $draft,
            [],
            self::LIFETIME
        );
        $expires = ($now ?? time()) + self::LIFETIME;

        return ['draft' => $draft, 'expires' => $expires, 'token' => $this->sign($draft, $storeId, $expires)];
    }

    /**
     * The draft behind a valid link, or null.
     *
     * @return array{components: array}|null
     */
    public function load(string $draft, int $storeId, int $expires, string $token, ?int $now = null): ?array
    {
        $now ??= time();
        if (!preg_match('/^[a-f0-9]{32}$/', $draft) || $expires < $now || $expires > $now + self::LIFETIME
            || !hash_equals($this->sign($draft, $storeId, $expires), $token)
        ) {
            return null;
        }
        $json = $this->cache->load(self::CACHE_PREFIX . $draft);

        return $json !== false ? $this->validator->clean(json_decode((string) $json, true)) : null;
    }

    public function sign(string $draft, int $storeId, int $expires): string
    {
        $key = (string) $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);

        return hash_hmac('sha256', 'zero-cms|' . $draft . '|' . $storeId . '|' . $expires, 'zero-cms-preview|' . $key);
    }
}
