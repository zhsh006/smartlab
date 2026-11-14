<?php
declare(strict_types=1);
namespace Elabftw\Models;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Exceptions\AppException;
use PDO;

/** Shared consumable information; experiment selections retain their own version snapshots. */
final class SmartlabWbConditions extends AbstractSmartlabModel
{
    public const array METHOD_FIELDS = array('quantification' => '定量方法', 'loading' => '每泳道总蛋白量', 'gel' => '胶类型 / 浓度', 'electrophoresis' => '电泳条件', 'transfer' => '转膜条件', 'membrane' => '膜类型 / 孔径', 'applicability' => '适用范围');
    public const array PROFILE_FIELDS = array('role', 'host_species', 'reactivity', 'target', 'molecular_weight', 'clone', 'storage', 'wb_dilution', 'wb_diluent', 'wb_incubation', 'lab_dilution', 'lab_diluent', 'lab_incubation', 'method_code', 'source_url', 'source_note');

    private function query(string $sql, array $params = array()): \PDOStatement
    {
        $q = $this->Db->prepare($sql);
        foreach ($params as $key => $value) { $q->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)); }
        $this->Db->execute($q); return $q;
    }

    public function material(int $id): array
    {
        return $this->query('SELECT id,code,name,brand,catalog_no FROM smartlab_materials WHERE id=:id AND team_id=:team AND category="antibody"', array('id' => $id, 'team' => (int) $this->Users->team))->fetch() ?: throw new AppException('请选择当前实验室的抗体耗材。');
    }

    public function profiles(): array
    {
        $rows = $this->query('SELECT m.id AS material_id,m.code,m.name,m.brand,m.catalog_no,p.id AS profile_id,p.revision,p.fields,p.source_name,p.source_hash
            FROM smartlab_materials m LEFT JOIN smartlab_antibody_wb_profiles p ON p.material_id=m.id AND p.team_id=m.team_id
                AND p.revision=(SELECT MAX(p2.revision) FROM smartlab_antibody_wb_profiles p2 WHERE p2.material_id=m.id AND p2.team_id=m.team_id)
            WHERE m.team_id=:team AND m.category="antibody" ORDER BY m.name,m.id LIMIT 500', array('team' => (int) $this->Users->team))->fetchAll();
        foreach ($rows as &$row) { $row['fields'] = array_replace(array_fill_keys(self::PROFILE_FIELDS, ''), json_decode($row['fields'] ?? '{}', true, flags: JSON_THROW_ON_ERROR)); }
        return $rows;
    }

    public function latestProfile(int $materialId, bool $withSource = false): ?array
    {
        $this->material($materialId);
        $row = $this->query('SELECT id,revision,fields,source_name,source_mime,source_hash' . ($withSource ? ',source_bytes' : '') . ' FROM smartlab_antibody_wb_profiles WHERE material_id=:id AND team_id=:team ORDER BY revision DESC LIMIT 1', array('id' => $materialId, 'team' => (int) $this->Users->team))->fetch();
        if (!$row) { return null; } $row['fields'] = json_decode($row['fields'], true, flags: JSON_THROW_ON_ERROR); return $row;
    }

    public function source(int $id): array
    {
        $row = $this->query('SELECT source_name,source_mime,source_hash,source_bytes FROM smartlab_antibody_wb_profiles WHERE id=:id AND team_id=:team', array('id' => $id, 'team' => (int) $this->Users->team))->fetch();
        if (!$row || $row['source_bytes'] === null) { throw new AppException('该版本未保存说明书原件。'); }
        if (!hash_equals($row['source_hash'], hash('sha256', $row['source_bytes']))) { throw new AppException('说明书原件校验失败。'); }
        return $row;
    }

    private function assertManager(): void
    {
        if (!SmartlabPortalView::canAccessAssistantView($this->Users)) { throw new AppException('说明书和常用方案由入库人员维护。'); }
    }

    public function cleanProfile(array $input): array
    {
        $fields = array();
        foreach (self::PROFILE_FIELDS as $key) {
            if (!is_scalar($input[$key] ?? '') || mb_strlen((string) ($input[$key] ?? '')) > 1000) { throw new AppException('说明书字段格式不正确或过长。'); }
            $fields[$key] = trim((string) ($input[$key] ?? ''));
        }
        if (!in_array($fields['role'], array('primary', 'secondary'), true)) { $fields['role'] = 'primary'; }
        if ($fields['source_url'] !== '' && !preg_match('#^https?://#i', $fields['source_url'])) { throw new AppException('说明书来源链接需为 http 或 https 地址。'); }
        if ($fields['method_code'] !== '' && !array_filter($this->methods(), static fn($m) => $m['code'] === $fields['method_code'])) { throw new AppException('所选常用方案已不可用，请重新选择。'); }
        return $fields;
    }

    public function saveProfile(int $materialId, array $input, ?array $source = null): int
    {
        return $this->Db->transaction(function () use ($materialId, $input, $source): int {
        $this->query('SELECT id FROM teams WHERE id=:team FOR UPDATE', array('team' => (int) $this->Users->team));
        $this->assertManager(); $this->material($materialId);
        if (($input['reviewed'] ?? '') !== '1') { throw new AppException('请核对提取结果后保存；未写明的条件可留空。'); }
        $fields = $this->cleanProfile($input);
        $before = $this->latestProfile($materialId, true);
        $revision = (int) ($before['revision'] ?? 0);
        if ($revision !== (int) ($input['expected_revision'] ?? 0)) { throw new AppException('说明书已被其他人更新，请刷新后核对。'); }
        $source ??= array('name' => $before['source_name'] ?? '', 'mime' => $before['source_mime'] ?? '', 'hash' => $before['source_hash'] ?? '', 'bytes' => $before['source_bytes'] ?? null);
        if ($before && $before['fields'] === $fields && $before['source_hash'] === $source['hash']) { return (int) $before['id']; }
        $this->query('INSERT INTO smartlab_antibody_wb_profiles (team_id,material_id,revision,fields,source_name,source_mime,source_hash,source_bytes,created_by)
            VALUES (:team,:material,:revision,:fields,:name,:mime,:hash,:bytes,:actor)', array('team' => (int) $this->Users->team, 'material' => $materialId, 'revision' => $revision + 1,
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'name' => $source['name'], 'mime' => $source['mime'], 'hash' => $source['hash'], 'bytes' => $source['bytes'], 'actor' => (int) $this->Users->userid));
        return $this->Db->lastInsertId();
        });
    }

    public function methods(): array
    {
        $rows = $this->query('SELECT m.* FROM smartlab_wb_methods m WHERE m.team_id=:team AND m.is_active=1
            AND m.revision=(SELECT MAX(m2.revision) FROM smartlab_wb_methods m2 WHERE m2.team_id=m.team_id AND m2.code=m.code) ORDER BY m.id DESC', array('team' => (int) $this->Users->team))->fetchAll();
        $hasDefault = false;
        foreach ($rows as &$row) {
            $row['fields'] = json_decode($row['fields'], true, flags: JSON_THROW_ON_ERROR);
            $row['is_default'] = (bool) $row['is_default'] && !$hasDefault;
            $hasDefault = $hasDefault || $row['is_default'];
        }
        return $rows;
    }

    public function saveMethod(array $input): int
    {
        return $this->Db->transaction(function () use ($input): int {
        $this->query('SELECT id FROM teams WHERE id=:team FOR UPDATE', array('team' => (int) $this->Users->team));
        $this->assertManager();
        $code = trim((string) ($input['code'] ?? '')); $name = trim((string) ($input['name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $code) || $name === '' || mb_strlen($name) > 255) { throw new AppException('请填写方案编号（字母、数字、横线）和名称。'); }
        $before = $this->query('SELECT revision FROM smartlab_wb_methods WHERE team_id=:team AND code=:code ORDER BY revision DESC LIMIT 1', array('team' => (int) $this->Users->team, 'code' => $code))->fetch();
        $revision = (int) ($before['revision'] ?? 0);
        if ($revision !== (int) ($input['expected_revision'] ?? 0)) { throw new AppException('方案已被其他人更新，请刷新后核对。'); }
        $fields = array();
        foreach (self::METHOD_FIELDS as $key => $label) {
            if (!is_scalar($input[$key] ?? '') || mb_strlen((string) ($input[$key] ?? '')) > 1000) { throw new AppException('方案条件格式不正确或过长。'); }
            $fields[$key] = trim((string) ($input[$key] ?? ''));
        }
        $this->query('INSERT INTO smartlab_wb_methods (team_id,code,revision,name,fields,is_default,created_by) VALUES (:team,:code,:revision,:name,:fields,:is_default,:actor)', array('team' => (int) $this->Users->team,
            'code' => $code, 'revision' => $revision + 1, 'name' => $name, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'is_default' => ($input['is_default'] ?? '') === '1' ? 1 : 0, 'actor' => (int) $this->Users->userid));
        return $this->Db->lastInsertId();
        });
    }

    public function catalogToken(): string
    {
        return hash('sha256', json_encode(array(array_map(static fn($p) => array($p['material_id'], $p['profile_id']), $this->profiles()), array_column($this->methods(), 'id')), JSON_THROW_ON_ERROR));
    }

    public function resolve(array $ids, string $methodCode, int $secondaryId = 0): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (count($ids) > 12) { throw new AppException('一次最多选择 12 个一抗。'); }
        $profiles = array_column($this->profiles(), null, 'material_id'); $antibodies = array();
        foreach ($ids as $id) {
            if (!isset($profiles[$id])) { throw new AppException('所选抗体不属于当前实验室。'); }
            if (($profiles[$id]['fields']['role'] ?? '') === 'secondary') { throw new AppException('请把二抗放在二抗选择栏。'); }
            $antibodies[] = $profiles[$id];
        }
        $secondary = null;
        if ($secondaryId) {
            $secondary = $profiles[$secondaryId] ?? throw new AppException('二抗不属于当前实验室。');
            if (($secondary['fields']['role'] ?? '') === 'primary') { throw new AppException('所选对象已登记为一抗，请选择二抗。'); }
            if (in_array($secondaryId, $ids, true)) { throw new AppException('一抗和二抗不能选择同一耗材。'); }
        }
        $methods = $this->methods(); $method = null; $methodConflict = false;
        if ($methodCode === '') {
            $mapped = array_values(array_unique(array_filter(array_map(static fn($a) => $a['fields']['method_code'] ?? '', $antibodies))));
            if (count($mapped) === 1) { $methodCode = $mapped[0]; }
            elseif (count($mapped) > 1) { $methodConflict = true; }
            else { foreach ($methods as $candidate) { if ($candidate['is_default']) { $methodCode = $candidate['code']; break; } } }
        }
        foreach ($methods as $candidate) { if ($candidate['code'] === $methodCode) { $method = $candidate; break; } }
        if ($methodCode !== '' && !$method) { throw new AppException('常用方案已不可用，请重新选择。'); }
        $rows = array();
        foreach (self::METHOD_FIELDS as $key => $label) {
            $rows[$key] = array('label' => $label, 'value' => $method['fields'][$key] ?? '', 'source' => $method ? $method['name'] . ' · V' . $method['revision'] : ($methodConflict ? '所选抗体的配套方案不同' : '未设置常用方案'));
        }
        foreach (array_merge($antibodies, $secondary ? array($secondary) : array()) as $antibody) {
            $prefix = 'antibody.' . $antibody['material_id'] . '.';
            foreach (array('wb_dilution' => '稀释比例', 'wb_diluent' => '稀释液', 'wb_incubation' => '孵育条件') as $field => $label) {
                $labField = str_replace('wb_', 'lab_', $field); $lab = $antibody['fields'][$labField] ?? '';
                $rows[$prefix . $field] = array('label' => $antibody['name'] . ' · ' . $label, 'value' => $lab ?: ($antibody['fields'][$field] ?? ''), 'source' => ($lab !== '' ? '实验室常用' : '说明书推荐') . ' · V' . ($antibody['revision'] ?: '未登记'));
            }
        }
        return array('antibodies' => $antibodies, 'secondary' => $secondary, 'method' => $method, 'method_conflict' => $methodConflict, 'rows' => $rows);
    }
}
