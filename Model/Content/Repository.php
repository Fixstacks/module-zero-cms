<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content;

use Magento\Framework\App\ResourceConnection;

/**
 * Zero CMS content per CMS page or block and store view (0 = all store
 * views; a store view without its own falls back to it), with revisions:
 * every save keeps the previous version, the last 30 per row.
 */
class Repository
{
    public const TYPE_PAGE = 'cms_page';
    public const TYPE_BLOCK = 'cms_block';
    private const KEEP_REVISIONS = 30;

    /** @var array<string, array|null> */
    private array $memo = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Validator $validator
    ) {
    }

    /**
     * Content shown in a store view: its own, else the all-store-views one, else null.
     *
     * @return array{components: array}|null
     */
    public function getForStore(string $type, int $entityId, int $storeId): ?array
    {
        $key = $type . '|' . $entityId . '|' . $storeId;
        if (!array_key_exists($key, $this->memo)) {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from($this->resource->getTableName('zero_cms_content'), ['store_id', 'data'])
                    ->where('entity_type = ?', $type)
                    ->where('entity_id = ?', $entityId)
                    ->where('store_id IN (?)', array_unique([0, $storeId]))
            );
            $json = $rows[$storeId] ?? $rows[0] ?? null;
            $this->memo[$key] = $json !== null ? $this->decode($json) : null;
        }

        return $this->memo[$key];
    }

    /**
     * Exactly the given store view's own row (no fallback), or null.
     *
     * @return array{components: array}|null
     */
    public function getOwn(string $type, int $entityId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $json = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('zero_cms_content'), ['data'])
                ->where('entity_type = ?', $type)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
        );

        return $json !== false ? $this->decode((string) $json) : null;
    }

    /**
     * Saves cleaned content; the version it replaces becomes a revision.
     *
     * @param mixed $content
     * @return array{components: array} What was saved
     */
    public function save(string $type, int $entityId, int $storeId, $content, string $user = '', string $note = ''): array
    {
        $clean = $this->validator->clean($content);
        $json = (string) json_encode($clean);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('zero_cms_content');

        $previous = $this->getOwn($type, $entityId, $storeId);
        if ($previous !== null) {
            $this->addRevision($type, $entityId, $storeId, $previous, $user, $note);
        }
        $connection->insertOnDuplicate($table, [
            'entity_type' => $type,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'data' => $json,
            'updated_by' => $user,
        ], ['data', 'updated_by']);
        $this->memo = [];

        return $clean;
    }

    /** Removes a store view's own content (it then falls back to all store views). */
    public function delete(string $type, int $entityId, int $storeId, string $user = ''): void
    {
        $previous = $this->getOwn($type, $entityId, $storeId);
        if ($previous === null) {
            return;
        }
        $this->addRevision($type, $entityId, $storeId, $previous, $user, 'deleted');
        $this->resource->getConnection()->delete($this->resource->getTableName('zero_cms_content'), [
            'entity_type = ?' => $type,
            'entity_id = ?' => $entityId,
            'store_id = ?' => $storeId,
        ]);
        $this->memo = [];
    }

    /**
     * @return array<int, array{revision_id: int, created_by: string, note: string, created_at: string}>
     */
    public function getRevisions(string $type, int $entityId, int $storeId): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('zero_cms_revision'), ['revision_id', 'created_by', 'note', 'created_at'])
                ->where('entity_type = ?', $type)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
                ->order('revision_id DESC')
        );
    }

    /**
     * @return array{components: array}|null
     */
    public function getRevision(int $revisionId, string $type, int $entityId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $json = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('zero_cms_revision'), ['data'])
                ->where('revision_id = ?', $revisionId)
                ->where('entity_type = ?', $type)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
        );

        return $json !== false ? $this->decode((string) $json) : null;
    }

    private function addRevision(string $type, int $entityId, int $storeId, array $content, string $user, string $note): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('zero_cms_revision');
        $connection->insert($table, [
            'entity_type' => $type,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'data' => (string) json_encode($content),
            'created_by' => $user,
            'note' => mb_substr($note, 0, 255),
        ]);
        $keep = $connection->fetchCol(
            $connection->select()
                ->from($table, ['revision_id'])
                ->where('entity_type = ?', $type)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
                ->order('revision_id DESC')
                ->limit(self::KEEP_REVISIONS)
        );
        $connection->delete($table, [
            'entity_type = ?' => $type,
            'entity_id = ?' => $entityId,
            'store_id = ?' => $storeId,
            'revision_id NOT IN (?)' => $keep ?: [0],
        ]);
    }

    /**
     * @return array{components: array}
     */
    private function decode(string $json): array
    {
        return $this->validator->clean(json_decode($json, true));
    }
}
