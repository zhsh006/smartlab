<?php
declare(strict_types=1);

namespace Elabftw\Models;

use DateTimeImmutable;
use DateTimeZone;
use Elabftw\Enums\AccessType;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use PDO;
use PDOStatement;

/** Append-only field revisions; physical operation times are declared by the operator. */
final class SmartlabBenchRecords extends AbstractSmartlabModel
{
    public const string VERSION = 'bench-v1';

    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        (new SmartlabSchemaMigrator())->ensureTable('smartlab_bench_records', 'CREATE TABLE IF NOT EXISTS smartlab_bench_records (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            experiment_id INT UNSIGNED NOT NULL,
            record_key VARCHAR(100) NOT NULL,
            revision INT UNSIGNED NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            payload JSON NOT NULL,
            actor_userid INT UNSIGNED NOT NULL,
            recorded_at DATETIME(6) NOT NULL,
            occurred_at DATETIME NULL,
            entry_mode VARCHAR(24) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY bench_revision (experiment_id, record_key, revision),
            KEY bench_history (experiment_id, id),
            CONSTRAINT fk_bench_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE,
            CONSTRAINT fk_bench_actor FOREIGN KEY (actor_userid) REFERENCES users(userid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    public static function templates(): array
    {
        $f = static fn(string $key, string $label, string $kind = 'text', bool $required = true, string $hint = ''): array => compact('key', 'label', 'kind', 'required', 'hint');
        $prepare = array('key' => 'prepare', 'label' => '样本与分组', 'fields' => array($f('sample_id', '选择已有样本', 'sample', false), $f('sample_label', '本次样本或批次'), $f('groups', '分组与对应样本', 'textarea'), $f('material_lot_id', '关联主要试剂批次', 'lot', false)));
        $acquire = array('key' => 'acquire', 'label' => '采集原始结果', 'fields' => array($f('instrument', '实际使用仪器'), $f('settings', '采集条件或程序', 'textarea'), $f('exception', '异常情况', 'textarea', false)));
        return array(
            'western_blot' => array('label' => 'Western Blot', 'steps' => array($prepare,
                array('key' => 'separation', 'label' => '上样、跑胶与转膜', 'fields' => array($f('loading', '实际上样量'), $f('gel', '实际胶浓度', 'text', true, 'run.gel_percentage'), $f('transfer', '实际转膜条件', 'text', true, 'run.transfer_condition'))),
                array('key' => 'antibody', 'label' => '抗体孵育', 'fields' => array($f('primary_lot_id', '关联一抗批次', 'lot', false), $f('primary', '实际使用一抗', 'text', true, 'reagent.primary_antibody'), $f('dilution', '实际稀释比例'), $f('incubation', '实际孵育条件'))), $acquire)),
            'qpcr' => array('label' => 'qPCR', 'steps' => array($prepare,
                array('key' => 'reaction', 'label' => '反应体系', 'fields' => array($f('target', '检测基因'), $f('reference', '内参基因'), $f('reagent_lot_id', '关联试剂批次', 'lot', false), $f('volume', '实际反应体积'))), $acquire)),
            'if' => array('label' => '免疫荧光', 'steps' => array($prepare,
                array('key' => 'staining', 'label' => '染色与孵育', 'fields' => array($f('antibody_lot_id', '关联抗体批次', 'lot', false), $f('antibody', '实际使用抗体'), $f('dilution', '实际稀释比例'), $f('incubation', '实际孵育条件'))), $acquire)),
            'ihc' => array('label' => '免疫组化', 'steps' => array($prepare,
                array('key' => 'staining', 'label' => '染色与显色', 'fields' => array($f('antibody_lot_id', '关联抗体批次', 'lot', false), $f('antibody', '实际使用抗体'), $f('conditions', '实际染色及显色条件', 'textarea'))), $acquire)),
            'elisa' => array('label' => 'ELISA', 'steps' => array($prepare,
                array('key' => 'reaction', 'label' => '试剂盒与反应', 'fields' => array($f('kit_lot_id', '关联试剂盒批次', 'lot', false), $f('kit', '实际使用试剂盒'), $f('dilution', '实际样本稀释比例'), $f('conditions', '实际孵育及洗板条件', 'textarea'))), $acquire)),
        );
    }

    private function query(string $sql, array $params = array()): PDOStatement
    {
        $req = $this->Db->prepare($sql);
        foreach ($params as $key => $value) { $req->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)); }
        $this->Db->execute($req);
        return $req;
    }

    private function entity(int $id, bool $write = false): Experiments
    {
        $entity = new Experiments($this->Users, $id);
        $entity->readOne();
        $entity->canOrExplode($write ? AccessType::Write : AccessType::Read);
        if ($write && $entity->isReadOnly) { throw new AppException('这条记录已锁定或只读，请使用可编辑的记录。'); }
        return $entity;
    }

    private function latest(int $id, string $key): ?array
    {
        $row = $this->query('SELECT * FROM smartlab_bench_records WHERE experiment_id=:id AND record_key=:record_key ORDER BY revision DESC LIMIT 1', array('id' => $id, 'record_key' => $key))->fetch();
        return $row === false ? null : $this->decode($row);
    }

    private function decode(array $row): array
    {
        $row['data'] = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    private function append(int $id, string $key, string $type, array $data, string $mode = 'live', ?string $occurred = null, ?int $expected = null): array
    {
        $previous = $this->latest($id, $key);
        $revision = (int) ($previous['revision'] ?? 0);
        if ($expected !== null && $revision !== $expected) { throw new AppException('记录已在其他页面更新，请刷新后查看最新内容再修改。'); }
        // Repeated unchanged autosaves do not create empty revisions or change operation times.
        if ($previous && $previous['data'] === $data && $previous['entry_mode'] === $mode && $previous['occurred_at'] === $occurred) { return $previous; }
        $this->query('INSERT INTO smartlab_bench_records (experiment_id,record_key,revision,event_type,payload,actor_userid,recorded_at,occurred_at,entry_mode) VALUES (:id,:record_key,:revision,:event_type,:payload,:actor,UTC_TIMESTAMP(6),:occurred,:entry_mode)', array('id' => $id, 'record_key' => $key, 'revision' => $revision + 1, 'event_type' => $type, 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'actor' => (int) $this->Users->userid, 'occurred' => $occurred, 'entry_mode' => $mode));
        return $this->latest($id, $key) ?? throw new AppException('记录保存失败。');
    }

    private function text(mixed $value, int $limit = 4000): string
    {
        if (!is_scalar($value) && $value !== null) { throw new AppException('字段内容格式不正确。'); }
        $value = trim((string) $value);
        if (mb_strlen($value) > $limit) { throw new AppException('字段内容过长，请拆分记录或上传原始文件。'); }
        return $value;
    }

    public function read(int $id, ?int $submissionFlow = null): array
    {
        $entity = $this->entity($id);
        $rows = $this->query('SELECT b.*, CONCAT(u.firstname," ",u.lastname) AS actor_name FROM smartlab_bench_records b JOIN users u ON u.userid=b.actor_userid WHERE b.experiment_id=:id ORDER BY b.id', array('id' => $id))->fetchAll();
        $current = array(); $history = array(); $submissions = array();
        foreach ($rows as $row) {
            $row = $this->decode($row); $key = $row['record_key'];
            $row['changes'] = array();
            $tracked = static fn(?array $record): array => array_merge($record['data']['fields'] ?? array(), array('结果表' => $record['data']['rows'] ?? array(), '关联证据' => $record['data']['evidence_ids'] ?? array(), '实际操作者' => $record['data']['operator'] ?? '', '补录说明' => $record['data']['entry_reason'] ?? '', '声明操作时间（UTC）' => $record['occurred_at'] ?? '', '记录方式' => $record['entry_mode'] ?? '', '确认状态' => $record['data']['state'] ?? ''));
            $before = $tracked($current[$key] ?? null);
            foreach ($tracked($row) as $field => $value) {
                if (($before[$field] ?? '') !== $value) { $row['changes'][] = array('field' => $field, 'before' => $before[$field] ?? '', 'after' => $value); }
            }
            $current[$key] = $row; $history[] = $row;
            if (str_starts_with($key, 'submission.')) { $submissions[] = $row; }
        }
        $submission = $submissionFlow ? ($current['submission.' . $submissionFlow] ?? null) : (empty($submissions) ? null : end($submissions));
        if ($submissionFlow && !$submission) { throw new AppException('该提交没有现场记录快照，请查看原有实验记录。'); }
        $display = $current;
        if ($submissionFlow) {
            $selected = array_map('intval', $submission['data']['record_ids']); $display = array();
            foreach ($history as $row) { if (in_array((int) $row['id'], $selected, true)) { $display[$row['record_key']] = $row; } }
        }
        $pack = $this->query('SELECT pack_key,payload FROM smartlab_assay_packs WHERE experiment_id=:id AND team_id=:team', array('id' => $id, 'team' => (int) $this->Users->team))->fetch();
        $legacy = $pack ? json_decode((string) $pack['payload'], true) : array();
        $templateKey = $display['plan']['data']['template_key'] ?? ($pack['pack_key'] ?? '');
        $template = self::templates()[$templateKey] ?? array('label' => '请选择实验模板', 'steps' => array());
        $uploads = $entity->entityData['uploads'] ?? array();
        if ($submissionFlow) { $uploads = $submission['data']['uploads'] ?? $uploads; }
        $uploadMap = array();
        foreach ($uploads as $upload) {
            $upload['url'] = 'app/download.php?' . http_build_query(array('f' => $upload['long_name'], 'name' => $upload['real_name'], 'storage' => $upload['storage'] ?? 1));
            $uploadMap[(int) $upload['id']] = $upload;
        }
        $steps = array(); $missing = array();
        if (!$templateKey) { $missing[] = '实验模板'; }
        if (empty($display['plan']['data']['fields']['objective']) || ($display['plan']['data']['state'] ?? '') !== 'confirmed') { $missing[] = '实验目的与计划尚未确认'; }
        foreach ($template['steps'] as $definition) {
            $record = $display['step.' . $definition['key']] ?? null;
            if (($record['data']['state'] ?? '') !== 'confirmed') { $missing[] = $definition['label'] . '尚未确认'; }
            foreach ($definition['fields'] as &$field) {
                $hint = $legacy;
                foreach (explode('.', $field['hint']) as $part) { $hint = is_array($hint) ? ($hint[$part] ?? '') : ''; }
                $field['planned'] = is_scalar($hint) ? (string) $hint : '';
            }
            unset($field);
            $steps[] = array('definition' => $definition, 'record' => $record);
        }
        $result = $display['result'] ?? null;
        if (empty($result['data']['fields']['conclusion']) || ($result['data']['state'] ?? '') !== 'confirmed') { $missing[] = '结果与结论尚未确认'; }
        $resultEvidence = array_values(array_intersect(array_map('intval', $result['data']['evidence_ids'] ?? array()), array_keys($uploadMap)));
        if (!$resultEvidence) { $missing[] = '关联结果原始文件'; }
        $changed = array();
        if ($submission) {
            $submittedIds = array_map('intval', $submission['data']['record_ids']);
            foreach ($current as $key => $row) { if (!str_starts_with($key, 'submission.') && !str_starts_with($key, 'feedback.') && !in_array((int) $row['id'], $submittedIds, true)) { $changed[] = $key; } }
            foreach ($submission['data']['entity'] ?? array() as $key => $value) { if ((string) ($entity->entityData[$key] ?? '') !== (string) ($value ?? '')) $changed[] = 'entity.' . $key; }
            // JSON and database rows can use different key order and scalar types.
            $signature = static fn(array $file): array => array('id' => (int) $file['id'], 'long_name' => (string) ($file['long_name'] ?? ''), 'real_name' => (string) ($file['real_name'] ?? ''), 'hash' => (string) ($file['hash'] ?? ''), 'filesize' => (int) ($file['filesize'] ?? 0));
            $oldFiles = array_map($signature, $submission['data']['uploads'] ?? array());
            $newFiles = array_map($signature, $entity->entityData['uploads'] ?? array());
            usort($oldFiles, static fn(array $a,array $b):int => (int)$a['id'] <=> (int)$b['id']);
            usort($newFiles, static fn(array $a,array $b):int => (int)$a['id'] <=> (int)$b['id']);
            if ($oldFiles !== $newFiles) $changed[] = 'uploads';
        }
        $printBenchOnly = isset($display['plan']) || isset($display['result']);
        foreach ($history as $event) { if (($event['data']['source'] ?? '') === 'template_created' || str_starts_with($event['record_key'], 'step.')) $printBenchOnly = true; }
        return array('experiment_id' => $id, 'print_bench_only' => $printBenchOnly, 'template_key' => $templateKey, 'template' => $template, 'version' => self::VERSION, 'plan' => $display['plan'] ?? null, 'steps' => $steps, 'result' => $result, 'records' => $display, 'history' => array_reverse($history), 'uploads' => $uploadMap, 'missing' => $missing, 'submission' => $submission, 'snapshot' => $submissionFlow !== null, 'snapshot_entity' => $submission['data']['entity'] ?? array(), 'changed_keys' => $changed, 'has_records' => count($rows) > 0, 'legacy_objective' => $legacy['cover']['objective'] ?? $legacy['plan']['objective'] ?? '', 'operator' => trim(($this->Users->userData['firstname'] ?? '') . ' ' . ($this->Users->userData['lastname'] ?? '')));
    }

    public function save(int $id, array $input): array
    {
        $this->entity($id, true);
        $context = $this->read($id);
        $key = $this->text($input['record_key'] ?? '', 100);
        $state = ($input['record_state'] ?? '') === 'confirmed' ? 'confirmed' : 'draft';
        $fields = array();
        if ($key === 'plan') {
            foreach (array('objective', 'notes') as $field) { $fields[$field] = $this->text($input[$field] ?? ''); }
            if ($state === 'confirmed' && $fields['objective'] === '') { throw new AppException('请填写本次实验目的。'); }
        } elseif ($key === 'result') {
            foreach (array('summary', 'conclusion') as $field) { $fields[$field] = $this->text($input[$field] ?? ''); }
        } else {
            $definitions = array_column($context['template']['steps'], null, 'key');
            $step = $definitions[substr($key, 5)] ?? null;
            if (!str_starts_with($key, 'step.') || !$step) { throw new AppException('请先选择有效的实验模板。'); }
            foreach ($step['fields'] as $field) {
                $value = $this->text($input[$field['key']] ?? '');
                if ($field['kind'] === 'sample' || $field['kind'] === 'lot') {
                    if ($value !== '') {
                        if (!ctype_digit($value)) { throw new AppException('请选择有效的样本或批次。'); }
                        $sql = $field['kind'] === 'sample' ? 'SELECT CONCAT(code," · ",name) AS label FROM smartlab_samples WHERE id=:id AND team_id=:team' : 'SELECT CONCAT(m.name," · ",l.lot_no) AS label FROM smartlab_material_lots l JOIN smartlab_materials m ON m.id=l.material_id WHERE l.id=:id AND m.team_id=:team';
                        $source = $this->query($sql, array('id' => (int) $value, 'team' => (int) $this->Users->team))->fetch();
                        if (!$source) { throw new AppException('该样本或批次不属于当前团队。'); }
                        $fields[$field['key'] . '_label'] = $source['label'];
                        if ($field['kind'] === 'sample' && empty($input['sample_label'])) { $input['sample_label'] = $source['label']; }
                    }
                }
                if ($state === 'confirmed' && $field['required'] && $value === '') { throw new AppException('请确认“' . $field['label'] . '”。'); }
                $fields[$field['key']] = $value;
            }
        }
        $mode = $this->text($input['entry_mode'] ?? 'live', 24);
        if (!in_array($mode, array('live', 'repeat', 'retrospective', 'paper'), true)) { throw new AppException('记录方式不正确。'); }
        $ids = array_values(array_unique(array_map('intval', is_array($input['evidence_ids'] ?? null) ? $input['evidence_ids'] : array())));
        if (array_diff($ids, array_keys($context['uploads']))) { throw new AppException('附件必须来自当前实验。'); }
        $occurred = null; $reason = $this->text($input['entry_reason'] ?? '');
        if (str_starts_with($key, 'step.') && $state === 'confirmed') {
            if ($mode === 'live' || $mode === 'repeat') {
                $prior = $this->query('SELECT occurred_at FROM smartlab_bench_records WHERE experiment_id=:id AND record_key=:record_key AND event_type="confirmed" ORDER BY id DESC LIMIT 1', array('id' => $id, 'record_key' => $key))->fetch();
                $occurred = $mode === 'live' && $prior ? $prior['occurred_at'] : gmdate('Y-m-d H:i:s');
            }
            else {
                $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $this->text($input['occurred_at'] ?? '', 20), new DateTimeZone('Asia/Shanghai'));
                if (!$time || $time->format('Y-m-d\TH:i') !== ($input['occurred_at'] ?? '') || $time->getTimestamp() > time() + 60 || $reason === '') { throw new AppException('补录请填写有效的实际操作时间和补录说明，不能填写未来时间。'); }
                if ($mode === 'paper' && !$ids) { throw new AppException('纸质转录请关联操作单照片或扫描件。'); }
                $occurred = $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        $data = array('template_key' => $context['template_key'], 'template_version' => self::VERSION, 'state' => $state, 'fields' => $fields, 'evidence_ids' => $ids, 'entry_reason' => $reason, 'declared_time' => $this->text($input['occurred_at'] ?? '', 20), 'operator' => $this->text($input['operator'] ?? $context['operator'], 120));
        if ($state === 'confirmed' && str_starts_with($key, 'step.') && $data['operator'] === '') { throw new AppException('请确认实际操作者。'); }
        if ($key === 'result') {
            $data['rows'] = array();
            $rows = $input['result_rows'] ?? array();
            if (!is_array($rows) || count($rows) > 200) { throw new AppException('结果表最多保存 200 行，请将更大的表作为原始文件上传。'); }
            foreach ($rows as $row) {
                if (!is_array($row)) { throw new AppException('结果表行格式不正确。'); }
                $clean = array();
                foreach (array('sample', 'group', 'indicator', 'value', 'unit') as $column) { $clean[$column] = $this->text($row[$column] ?? '', 200); }
                if (implode('', $clean) !== '') { $data['rows'][] = $clean; }
            }
            if ($state === 'confirmed' && ($fields['conclusion'] === '' || ($fields['summary'] === '' && !$data['rows']))) { throw new AppException('请填写结果摘要或结果表，并填写结论。'); }
        }
        return $this->append($id, $key, $state === 'confirmed' ? 'confirmed' : 'draft_saved', $data, $mode, $occurred, (int) ($input['expected_revision'] ?? 0));
    }

    public function recordParameters(int $id, string $packKey, array $payload, array $results, bool $initializing = false): void
    {
        $this->entity($id, true);
        $this->append($id, 'parameters', 'parameters_saved', array('template_key' => $packKey, 'template_version' => self::VERSION, 'source' => $initializing ? 'template_created' : 'parameters_edited', 'fields' => array('payload' => $payload, 'results' => $results)), 'configuration');
    }

    public function recordFeedback(int $id, int $flowId, string $decision, string $comment): void
    {
        $this->entity($id);
        $allowed = $this->query('SELECT rt.id FROM smartlab_review_tasks rt JOIN smartlab_review_flows rf ON rf.id=rt.flow_id WHERE rf.id=:flow AND rf.entity_type="experiment" AND rf.entity_id=:id AND (rt.assignee_userid=:actor OR :admin=1) LIMIT 1', array('flow' => $flowId, 'id' => $id, 'actor' => (int) $this->Users->userid, 'admin' => (int) $this->Users->isAdmin))->fetch();
        if (!$allowed) { throw new \Elabftw\Exceptions\UnauthorizedException(); }
        $labels = array('approve' => '通过或完成指导', 'request_changes' => '退回补充', 'reject' => '不通过');
        $this->append($id, 'feedback.' . $flowId, 'feedback', array('fields' => array('审核决定' => $labels[$decision] ?? $decision, '导师意见' => $this->text($comment)), 'flow_id' => $flowId), 'review');
    }

    public function recordSubmission(int $id, int $flowId, string $mode, string $comment): void
    {
        $this->entity($id, true);
        $flow = $this->query('SELECT id FROM smartlab_review_flows WHERE id=:flow AND status="submitted" AND entity_type="experiment" AND entity_id=:id AND requester_userid=:actor', array('flow' => $flowId, 'id' => $id, 'actor' => (int) $this->Users->userid))->fetch();
        if (!$flow) { throw new AppException('提交版本必须关联当前操作者的审核流。'); }
        $context = $this->read($id);
        if ($mode === 'formal' && $context['missing']) { throw new AppException('正式审核前还有未确认的关键记录。'); }
        $ids = array();
        foreach ($context['records'] as $key => $record) { if (!str_starts_with($key, 'submission.')) { $ids[] = (int) $record['id']; } }
        $uploads = array();
        foreach ($context['uploads'] as $file) { $uploads[] = array_intersect_key($file, array_flip(array('id', 'real_name', 'long_name', 'storage', 'hash', 'hash_algorithm', 'filesize'))); }
        $entity = $this->entity($id);
        $this->append($id, 'submission.' . $flowId, 'submitted', array('mode' => $mode, 'comment' => $comment, 'record_ids' => $ids, 'uploads' => $uploads, 'entity' => array_intersect_key($entity->entityData, array_flip(array('title', 'date', 'body_html'))), 'template_version' => self::VERSION));
    }
}
