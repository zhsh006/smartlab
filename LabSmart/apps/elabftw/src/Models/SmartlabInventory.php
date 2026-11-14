<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2026 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use PDO;

final class SmartlabInventory extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        (new SmartlabSchemaMigrator())->ensureMaterialColumns();
    }

    public function readSummary(): array
    {
        $sql = 'SELECT
                COUNT(DISTINCT c2i.item_id) AS stored_resource_count,
                COUNT(DISTINCT c2i.storage_id) AS used_location_count,
                COUNT(DISTINCT CASE WHEN c2i.qty_stored <= 2 THEN c2i.id END) AS low_stock_count,
                COUNT(DISTINCT i2e.link_id) AS linked_experiment_count
            FROM containers2items AS c2i
            INNER JOIN items AS i ON i.id = c2i.item_id
            LEFT JOIN items2experiments AS i2e ON i2e.item_id = i.id
            LEFT JOIN experiments AS e ON e.id = i2e.link_id AND e.team = :team
            WHERE i.team = :team
              AND i.state IN (1, 2)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetch();
    }

    public function readLotSummary(): array
    {
        $sql = 'SELECT
                COUNT(DISTINCT m.id) AS material_count,
                COUNT(DISTINCT l.id) AS lot_count,
                COUNT(DISTINCT CASE WHEN COALESCE(l.quantity, 0) <= 2 THEN l.id END) AS low_stock_lot_count,
                COUNT(DISTINCT CASE WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN l.id END) AS expiring_lot_count,
                COUNT(DISTINCT t.id) AS transaction_count
            FROM smartlab_materials AS m
            LEFT JOIN smartlab_material_lots AS l ON l.material_id = m.id
            LEFT JOIN smartlab_inventory_transactions AS t ON t.lot_id = l.id
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("instrument", "instruments", "仪器"))';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetch();
    }

    public function readInventoryRows(string $search = ''): array
    {
        $searchSql = '';
        if ($search !== '') {
            $searchSql = ' AND (
                i.title LIKE :query
                OR i.custom_id LIKE :query
                OR sh.full_path LIKE :query
                OR EXISTS (
                    SELECT 1
                    FROM items2experiments AS i2e_search
                    INNER JOIN experiments AS e_search ON e_search.id = i2e_search.link_id
                    WHERE i2e_search.item_id = i.id
                      AND e_search.team = :team
                      AND e_search.title LIKE :query
                )
            )';
        }

        $sql = 'WITH RECURSIVE storage_hierarchy AS (
                SELECT
                    su.id AS storage_id,
                    su.name AS storage_name,
                    su.parent_id,
                    su.name AS full_path
                FROM storage_units AS su
                WHERE su.parent_id IS NULL

                UNION

                SELECT
                    su.id AS storage_id,
                    su.name AS storage_name,
                    su.parent_id,
                    CONCAT(parent.full_path, " > ", su.name) AS full_path
                FROM storage_units AS su
                INNER JOIN storage_hierarchy AS parent ON su.parent_id = parent.storage_id
            )
            SELECT
                c2i.id,
                i.id AS item_id,
                i.title AS item_title,
                i.custom_id,
                c2i.qty_stored,
                c2i.qty_unit,
                c2i.created_at,
                c2i.modified_at,
                sh.storage_id,
                sh.full_path,
                (
                    SELECT COUNT(*)
                    FROM items2experiments AS i2e
                    INNER JOIN experiments AS e2 ON e2.id = i2e.link_id
                    WHERE i2e.item_id = i.id
                      AND e2.team = :team
                ) AS experiment_count,
                (
                    SELECT e3.id
                    FROM items2experiments AS i2e
                    INNER JOIN experiments AS e3 ON e3.id = i2e.link_id
                    WHERE i2e.item_id = i.id
                      AND e3.team = :team
                    ORDER BY e3.modified_at DESC, e3.id DESC
                    LIMIT 1
                ) AS latest_experiment_id,
                (
                    SELECT e4.title
                    FROM items2experiments AS i2e
                    INNER JOIN experiments AS e4 ON e4.id = i2e.link_id
                    WHERE i2e.item_id = i.id
                      AND e4.team = :team
                    ORDER BY e4.modified_at DESC, e4.id DESC
                    LIMIT 1
                ) AS latest_experiment_title
            FROM containers2items AS c2i
            INNER JOIN items AS i ON i.id = c2i.item_id
            LEFT JOIN storage_hierarchy AS sh ON sh.storage_id = c2i.storage_id
            WHERE i.team = :team
              AND i.state IN (1, 2)' . $searchSql . '
            ORDER BY
                CASE WHEN c2i.qty_stored <= 2 THEN 0 ELSE 1 END ASC,
                c2i.modified_at DESC,
                c2i.id DESC
            LIMIT 100';

        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($search !== '') {
            $req->bindValue(':query', '%' . $search . '%');
        }
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    private function buildMaterialSearchSql(string $search, array &$bindings): string
    {
        $tokens = preg_split('/\s+/u', trim($search)) ?: array();
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn(string $token): bool => $token !== ''));
        if ($tokens === array()) {
            return '';
        }

        $clauses = array();
        foreach ($tokens as $tokenIndex => $token) {
            $tokenClauses = array();
            foreach ($this->expandMaterialSearchTerm($token) as $variantIndex => $variant) {
                $param = sprintf(':material_query_%d_%d', $tokenIndex, $variantIndex);
                $bindings[$param] = '%' . $variant . '%';
                $tokenClauses[] = sprintf(
                    '(m.name LIKE %1$s
                        OR m.code LIKE %1$s
                        OR m.category LIKE %1$s
                        OR m.brand LIKE %1$s
                        OR m.manufacturer LIKE %1$s
                        OR m.catalog_no LIKE %1$s
                        OR m.specification LIKE %1$s
                        OR m.package_size LIKE %1$s
                        OR l.lot_no LIKE %1$s
                        OR l.location_label LIKE %1$s
                        OR l.note LIKE %1$s
                        OR i.title LIKE %1$s
                        OR i.custom_id LIKE %1$s)',
                    $param,
                );
            }
            $clauses[] = '(' . implode(' OR ', $tokenClauses) . ')';
        }

        return ' AND ' . implode(' AND ', $clauses);
    }

    private function expandMaterialSearchTerm(string $term): array
    {
        $normalized = strtolower(trim($term));
        $categoryAliases = array(
            '试剂盒' => array('kit'),
            'kit' => array('kit'),
            '试剂' => array('reagent', 'kit'),
            'reagent' => array('reagent'),
            '抗体' => array('antibody'),
            'antibody' => array('antibody'),
            '引物' => array('primer'),
            'primer' => array('primer'),
            '耗材' => array('consumable'),
            'consumable' => array('consumable'),
            '样本' => array('sample'),
            '标本' => array('sample'),
            'sample' => array('sample'),
            'samples' => array('sample'),
            '关键材料' => array('key_material', 'other'),
            'key_material' => array('key_material'),
            'other' => array('other'),
            '其他' => array('other', 'key_material'),
        );

        return array_values(array_unique(array_merge(array($term), $categoryAliases[$normalized] ?? array())));
    }

    public function countLots(string $search = ''): int
    {
        $searchBindings = array();
        $searchSql = $this->buildMaterialSearchSql($search, $searchBindings);

        $sql = 'SELECT COUNT(DISTINCT l.id) AS lot_count
            FROM smartlab_material_lots AS l
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN items AS i ON i.id = m.source_item_id
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("instrument", "instruments", "仪器"))' . $searchSql;
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        foreach ($searchBindings as $param => $value) {
            $req->bindValue($param, $value);
        }
        $this->Db->execute($req);
        $row = $req->fetch();

        return (int) ($row['lot_count'] ?? 0);
    }

    public function readLots(string $search = '', int $limit = 100, int $offset = 0): array
    {
        $searchBindings = array();
        $searchSql = $this->buildMaterialSearchSql($search, $searchBindings);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT
                l.id,
                l.lot_no,
                l.expires_at,
                l.quantity,
                l.location_label,
                l.note,
                l.updated_at,
                m.id AS material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.category,
                COALESCE(NULLIF(m.brand, ""), NULLIF(m.manufacturer, "")) AS brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size,
                m.unit,
                i.id AS item_id,
                i.title AS item_title,
                (
                    SELECT t1.txn_type
                    FROM smartlab_inventory_transactions AS t1
                    WHERE t1.lot_id = l.id
                    ORDER BY t1.occurred_at DESC, t1.id DESC
                    LIMIT 1
                ) AS latest_txn_type,
                (
                    SELECT t2.occurred_at
                    FROM smartlab_inventory_transactions AS t2
                    WHERE t2.lot_id = l.id
                    ORDER BY t2.occurred_at DESC, t2.id DESC
                    LIMIT 1
                ) AS latest_txn_at
            FROM smartlab_material_lots AS l
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN items AS i ON i.id = m.source_item_id
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("instrument", "instruments", "仪器"))' . $searchSql . '
            ORDER BY
                CASE WHEN COALESCE(l.quantity, 0) <= 2 THEN 0 ELSE 1 END ASC,
                CASE WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 0 ELSE 1 END ASC,
                l.updated_at DESC,
                l.id DESC
            LIMIT :limit OFFSET :offset';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        foreach ($searchBindings as $param => $value) {
            $req->bindValue($param, $value);
        }
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $req->bindValue(':offset', $offset, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readRecentTransactions(int $limit = 8): array
    {
        $sql = 'SELECT
                t.id,
                t.txn_type,
                t.quantity,
                t.note,
                t.occurred_at,
                l.id AS lot_id,
                l.lot_no,
                m.code AS material_code,
                m.name AS material_name,
                COALESCE(NULLIF(m.brand, ""), NULLIF(m.manufacturer, "")) AS material_brand,
                m.catalog_no,
                m.specification,
                m.package_size,
                m.unit,
                e.id AS experiment_id,
                e.title AS experiment_title,
                CONCAT(u.firstname, " ", u.lastname) AS actor_name
            FROM smartlab_inventory_transactions AS t
            INNER JOIN smartlab_material_lots AS l ON l.id = t.lot_id
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN experiments AS e ON e.id = t.experiment_id
            LEFT JOIN users AS u ON u.userid = t.actor_userid
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("instrument", "instruments", "仪器"))
            ORDER BY t.occurred_at DESC, t.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readMyConsumables(int $limit = 100, string $search = ''): array
    {
        $this->ensureMyConsumableHiddenTable();
        $searchBindings = array();
        $searchSql = $this->buildMaterialSearchSql($search, $searchBindings);

        $sql = 'SELECT
                t.id AS transaction_id,
                t.txn_type,
                t.quantity,
                t.note,
                t.occurred_at,
                l.id AS lot_id,
                l.lot_no,
                l.expires_at,
                l.location_label,
                m.code AS material_code,
                m.name AS material_name,
                COALESCE(NULLIF(m.brand, ""), NULLIF(m.manufacturer, "")) AS material_brand,
                m.catalog_no,
                m.specification,
                m.package_size,
                m.unit,
                i.id AS item_id,
                i.title AS item_title,
                e.id AS experiment_id,
                e.title AS experiment_title
            FROM smartlab_inventory_transactions AS t
            INNER JOIN smartlab_material_lots AS l ON l.id = t.lot_id
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN items AS i ON i.id = m.source_item_id
            LEFT JOIN experiments AS e ON e.id = t.experiment_id AND e.team = :team
            LEFT JOIN smartlab_my_consumable_hidden AS h
                ON h.transaction_id = t.id
                AND h.user_id = :user
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("instrument", "instruments", "仪器"))
              AND t.actor_userid = :user
              AND t.txn_type IN ("REQUESTED", "OUTBOUND")
              AND h.transaction_id IS NULL' . $searchSql . '
            ORDER BY t.occurred_at DESC, t.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':user', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach ($searchBindings as $param => $value) {
            $req->bindValue($param, $value);
        }
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function hideMyConsumable(int $transactionId): void
    {
        $this->ensureMyConsumableHiddenTable();

        $sql = 'INSERT IGNORE INTO smartlab_my_consumable_hidden
            (transaction_id, user_id, hidden_at)
            SELECT t.id, :user, NOW()
            FROM smartlab_inventory_transactions AS t
            INNER JOIN smartlab_material_lots AS l ON l.id = t.lot_id
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            WHERE t.id = :transaction_id
              AND t.actor_userid = :user
              AND m.team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':transaction_id', $transactionId, PDO::PARAM_INT);
        $req->bindValue(':user', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function requestUse(int $lotId, float $quantity, string $note, ?int $experimentId = null): void
    {
        $this->readLot($lotId);

        $sql = 'INSERT INTO smartlab_inventory_transactions
            (lot_id, experiment_id, actor_userid, txn_type, quantity, note, occurred_at)
            VALUES (:lot_id, :experiment_id, :actor_userid, "REQUESTED", :quantity, :note, NOW())';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':lot_id', $lotId, PDO::PARAM_INT);
        $this->Db->bindNullableInt($req, ':experiment_id', $experimentId);
        $req->bindValue(':actor_userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':quantity', $quantity);
        $req->bindValue(':note', $note !== '' ? $note : null);
        $this->Db->execute($req);
    }

    private function ensureMyConsumableHiddenTable(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS smartlab_my_consumable_hidden (
            transaction_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (transaction_id, user_id),
            INDEX smartlab_my_consumable_hidden_user_idx (user_id)
        )';
        $this->Db->execute($this->Db->prepare($sql));
    }

    public function readAvailableItems(): array
    {
        $sql = 'SELECT id, title, custom_id
            FROM items
            WHERE team = :team
              AND state IN (1, 2)
            ORDER BY modified_at DESC, id DESC
            LIMIT 200';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAvailableExperiments(): array
    {
        $sql = 'SELECT id, title
            FROM experiments
            WHERE team = :team
              AND state = 1
            ORDER BY modified_at DESC, id DESC
            LIMIT 200';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function createLot(
        ?int $itemId,
        string $lotNo,
        ?string $expiresAt,
        ?float $quantity,
        ?string $locationLabel,
        string $note,
        ?string $materialCode = null,
        ?string $materialName = null,
        ?string $materialCategory = null,
        ?string $materialUnit = null,
        ?string $materialBrand = null,
        ?string $materialCatalogNo = null,
        ?string $materialSpecification = null,
        ?string $materialPackageSize = null,
    ): int
    {
        $materialId = $itemId !== null && $itemId > 0
            ? $this->ensureMaterialForItem($itemId, $materialCategory, $materialUnit, $materialBrand, $materialCatalogNo, $materialSpecification, $materialPackageSize)
            : $this->ensureManualMaterial($materialCode, $materialName, $materialCategory, $materialUnit, $materialBrand, $materialCatalogNo, $materialSpecification, $materialPackageSize);
        if ($materialId <= 0) {
            return 0;
        }
        $sql = 'INSERT INTO smartlab_material_lots
            (material_id, lot_no, expires_at, quantity, location_label, note)
            VALUES (:material_id, :lot_no, :expires_at, :quantity, :location_label, :note)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':material_id', $materialId, PDO::PARAM_INT);
        $req->bindValue(':lot_no', $lotNo);
        $req->bindValue(':expires_at', $expiresAt ?: null);
        $req->bindValue(':quantity', $quantity);
        $req->bindValue(':location_label', $locationLabel ?: null);
        $req->bindValue(':note', $note !== '' ? $note : null);
        $this->Db->execute($req);
        return $materialId;
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function addTransaction(int $lotId, string $txnType, float $quantity, string $note, ?int $experimentId = null): void
    {
        $lot = $this->readLot($lotId);
        $normalizedType = strtoupper($txnType);
        $delta = match ($normalizedType) {
            'INBOUND' => $quantity,
            'OUTBOUND', 'SCRAP' => -1 * $quantity,
            'ADJUSTMENT' => $quantity,
            default => 0.0,
        };

        if ($delta === 0.0) {
            return;
        }

        $sql = 'INSERT INTO smartlab_inventory_transactions
            (lot_id, experiment_id, actor_userid, txn_type, quantity, note, occurred_at)
            VALUES (:lot_id, :experiment_id, :actor_userid, :txn_type, :quantity, :note, NOW())';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':lot_id', $lotId, PDO::PARAM_INT);
        $this->Db->bindNullableInt($req, ':experiment_id', $experimentId);
        $req->bindValue(':actor_userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':txn_type', $normalizedType);
        $req->bindValue(':quantity', $quantity);
        $req->bindValue(':note', $note !== '' ? $note : null);
        $this->Db->execute($req);

        $sql = 'UPDATE smartlab_material_lots
            SET quantity = COALESCE(quantity, 0) + :delta
            WHERE id = :lot_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':delta', $delta);
        $req->bindValue(':lot_id', $lotId, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function ensureMaterialForItem(
        int $itemId,
        ?string $materialCategory = null,
        ?string $materialUnit = null,
        ?string $materialBrand = null,
        ?string $materialCatalogNo = null,
        ?string $materialSpecification = null,
        ?string $materialPackageSize = null,
    ): int
    {
        $sql = 'SELECT id
            FROM smartlab_materials
            WHERE team_id = :team
              AND source_item_id = :item_id
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $material = $req->fetch();
        $existingMaterialId = !empty($material) ? (int) $material['id'] : 0;

        $sql = 'SELECT i.id, i.title, i.custom_id, ic.title AS category_title
            FROM items AS i
            LEFT JOIN items_categories AS ic ON ic.id = i.category
            WHERE i.team = :team
              AND i.id = :item_id
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $item = $this->Db->fetch($req);

        $code = !empty($item['custom_id']) ? ('MAT-' . $item['custom_id']) : ('MAT-' . $itemId);
        $category = $this->normalizeMaterialCategory($materialCategory ?: (string) ($item['category_title'] ?? ''));
        $brand = $this->normalizeMaterialText($materialBrand);
        $catalogNo = $this->normalizeMaterialText($materialCatalogNo);
        $specification = $this->normalizeMaterialText($materialSpecification);
        $packageSize = $this->normalizeMaterialText($materialPackageSize);
        $unit = $this->normalizeMaterialText($materialUnit);

        $sql = 'INSERT INTO smartlab_materials
            (team_id, source_item_id, code, name, category, brand, manufacturer, catalog_no, specification, package_size, unit, created_by)
            VALUES (:team_id, :source_item_id, :code, :name, :category, :brand, :manufacturer, :catalog_no, :specification, :package_size, :unit, :created_by)
            ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            category = COALESCE(VALUES(category), category),
            brand = COALESCE(VALUES(brand), brand),
            manufacturer = COALESCE(VALUES(manufacturer), manufacturer),
            catalog_no = COALESCE(VALUES(catalog_no), catalog_no),
            specification = COALESCE(VALUES(specification), specification),
            package_size = COALESCE(VALUES(package_size), package_size),
            unit = COALESCE(VALUES(unit), unit)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':source_item_id', $itemId, PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $item['title']);
        $req->bindValue(':category', $category);
        $req->bindValue(':brand', $brand);
        $req->bindValue(':manufacturer', $brand);
        $req->bindValue(':catalog_no', $catalogNo);
        $req->bindValue(':specification', $specification);
        $req->bindValue(':package_size', $packageSize);
        $req->bindValue(':unit', $unit);
        $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);

        $insertId = $this->Db->lastInsertId();
        if ($insertId > 0) {
            return $insertId;
        }
        return $existingMaterialId > 0 ? $existingMaterialId : $this->readMaterialIdByCode($code);
    }

    private function ensureManualMaterial(
        ?string $materialCode,
        ?string $materialName,
        ?string $materialCategory = null,
        ?string $materialUnit = null,
        ?string $materialBrand = null,
        ?string $materialCatalogNo = null,
        ?string $materialSpecification = null,
        ?string $materialPackageSize = null,
    ): int
    {
        $code = trim((string) $materialCode);
        $name = trim((string) $materialName);
        if ($code === '' || $name === '') {
            return 0;
        }

        $category = $this->normalizeMaterialCategory($materialCategory);
        $brand = $this->normalizeMaterialText($materialBrand);
        $catalogNo = $this->normalizeMaterialText($materialCatalogNo);
        $specification = $this->normalizeMaterialText($materialSpecification);
        $packageSize = $this->normalizeMaterialText($materialPackageSize);
        $unit = $this->normalizeMaterialText($materialUnit);

        $sql = 'SELECT id
            FROM smartlab_materials
            WHERE team_id = :team
              AND code = :code
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $this->Db->execute($req);
        $material = $req->fetch();
        $existingMaterialId = !empty($material) ? (int) $material['id'] : 0;

        $sql = 'INSERT INTO smartlab_materials
            (team_id, code, name, category, brand, manufacturer, catalog_no, specification, package_size, unit, created_by)
            VALUES (:team_id, :code, :name, :category, :brand, :manufacturer, :catalog_no, :specification, :package_size, :unit, :created_by)
            ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            category = COALESCE(VALUES(category), category),
            brand = COALESCE(VALUES(brand), brand),
            manufacturer = COALESCE(VALUES(manufacturer), manufacturer),
            catalog_no = COALESCE(VALUES(catalog_no), catalog_no),
            specification = COALESCE(VALUES(specification), specification),
            package_size = COALESCE(VALUES(package_size), package_size),
            unit = COALESCE(VALUES(unit), unit)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $name);
        $req->bindValue(':category', $category);
        $req->bindValue(':brand', $brand);
        $req->bindValue(':manufacturer', $brand);
        $req->bindValue(':catalog_no', $catalogNo);
        $req->bindValue(':specification', $specification);
        $req->bindValue(':package_size', $packageSize);
        $req->bindValue(':unit', $unit);
        $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);

        $insertId = $this->Db->lastInsertId();
        if ($insertId > 0) {
            return $insertId;
        }
        return $existingMaterialId > 0 ? $existingMaterialId : $this->readMaterialIdByCode($code);
    }

    private function readMaterialIdByCode(string $code): int
    {
        $sql = 'SELECT id
            FROM smartlab_materials
            WHERE team_id = :team
              AND code = :code
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $this->Db->execute($req);
        $material = $req->fetch();
        return !empty($material) ? (int) $material['id'] : 0;
    }

    private function normalizeMaterialText(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function normalizeMaterialCategory(?string $category): ?string
    {
        $category = trim((string) $category);
        if ($category === '') {
            return null;
        }
        if (preg_match('/instrument/i', $category) === 1 || in_array($category, array('仪器'), true)) {
            return 'other';
        }
        if (preg_match('/samples?/i', $category) === 1 || in_array($category, array('样本', '标本'), true)) {
            return 'sample';
        }
        return $category;
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function readLot(int $lotId): array
    {
        $sql = 'SELECT l.id
            FROM smartlab_material_lots AS l
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            WHERE l.id = :lot_id
              AND m.team_id = :team
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':lot_id', $lotId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $this->Db->fetch($req);
    }
}
