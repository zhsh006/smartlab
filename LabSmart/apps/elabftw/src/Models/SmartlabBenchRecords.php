<?php
declare(strict_types=1);

namespace Elabftw\Models;

use DateTimeImmutable;
use DateTimeZone;
use Elabftw\Enums\AccessType;
use Elabftw\Enums\Storage;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use Elabftw\Services\SmartlabAssayWorkspace;
use Elabftw\Services\SmartlabQpcrImport;
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
        $wbPrepare = $prepare;
        $wbPrepare['fields'][] = $f('exception', '异常情况', 'textarea', false);
        $acquire = array('key' => 'acquire', 'label' => '采集原始结果', 'fields' => array($f('instrument', '实际使用仪器'), $f('settings', '采集条件或程序', 'textarea'), $f('exception', '异常情况', 'textarea', false)));
        return array(
            'western_blot' => array('label' => 'Western Blot', 'steps' => array($wbPrepare,
                array('key' => 'loading', 'label' => '上样与电泳', 'fields' => array($f('loading', '实际上样量', 'text', true, 'sample.loading_amount'), $f('gel', '实际胶浓度', 'text', true, 'run.gel_percentage'), $f('exception', '异常情况', 'textarea', false))),
                array('key' => 'transfer', 'label' => '转膜', 'fields' => array($f('transfer', '实际转膜条件', 'text', true, 'run.transfer_condition'), $f('exception', '异常情况', 'textarea', false))),
                array('key' => 'antibody', 'label' => '抗体孵育', 'fields' => array($f('primary_lot_id', '关联一抗批次', 'lot', false), $f('primary', '实际使用一抗', 'text', true, 'reagent.primary_antibody'), $f('dilution', '实际稀释比例', 'text', true, 'reagent.primary_dilution'), $f('incubation', '实际孵育条件'), $f('exception', '异常情况', 'textarea', false))),
                array('key' => 'imaging', 'label' => '显影曝光', 'fields' => array($f('instrument', '成像设备'), $f('exposure', '曝光时间'), $f('settings', '采集条件或程序', 'textarea', false), $f('exception', '异常情况', 'textarea', false))),
                array('key' => 'finish', 'label' => '完成记录', 'fields' => array($f('note', '完成说明或补充', 'textarea', false))))),
            'qpcr' => array('label' => 'qPCR', 'steps' => array($prepare,
                array('key' => 'reaction', 'label' => '反应体系', 'fields' => array($f('target', '检测基因'), $f('reference', '内参基因'), $f('reagent_lot_id', '关联试剂批次', 'lot', false), $f('volume', '实际反应体积'))), $acquire,
                array('key' => 'finish', 'label' => '结果整理', 'fields' => array($f('note', '补充说明', 'textarea', false))))),
            'if' => array('label' => '免疫荧光', 'steps' => array($prepare,
                array('key' => 'staining', 'label' => '染色与孵育', 'fields' => array($f('antibody_lot_id', '关联抗体批次', 'lot', false), $f('antibody', '实际使用抗体'), $f('dilution', '实际稀释比例'), $f('incubation', '实际孵育条件'))), $acquire,
                array('key' => 'finish', 'label' => '结果整理', 'fields' => array($f('note', '补充说明', 'textarea', false))))),
            'ihc' => array('label' => '免疫组化', 'steps' => array($prepare,
                array('key' => 'staining', 'label' => '染色与显色', 'fields' => array($f('antibody_lot_id', '关联抗体批次', 'lot', false), $f('antibody', '实际使用抗体'), $f('conditions', '实际染色及显色条件', 'textarea'))), $acquire)),
            'elisa' => array('label' => 'ELISA', 'steps' => array($prepare,
                array('key' => 'reaction', 'label' => '试剂盒与反应', 'fields' => array($f('kit_lot_id', '关联试剂盒批次', 'lot', false), $f('kit', '实际使用试剂盒'), $f('dilution', '实际样本稀释比例'), $f('conditions', '实际孵育及洗板条件', 'textarea'))), $acquire)),
        );
    }

    /** Human label for a bench record key; step keys are mapped to their stage label. */
    public static function describeRecordKey(string $key, array $template = array()): string
    {
        if ($key === 'plan') { return '实验目的与计划'; }
        if ($key === 'result') { return '结果与结论'; }
        if ($key === 'parameters') { return '完整参数表'; }
        if ($key === 'wb.setup') { return '选择抗体与 WB 方案'; }
        if ($key === 'wb.actual') { return '本次 WB 条件'; }
        if ($key === 'wb.progress') { return 'WB 进展'; }
        if (str_starts_with($key, 'submission.')) { return '提交导师版本'; }
        if (str_starts_with($key, 'feedback.')) { return '导师反馈'; }
        if (str_starts_with($key, 'step.')) {
            $stepKey = substr($key, 5);
            foreach (($template['steps'] ?? array()) as $step) {
                if (($step['key'] ?? '') === $stepKey) { return (string) $step['label']; }
            }
            return '操作记录';
        }
        return '记录';
    }

    /** Latest recorded stage and execution confirmation are separate facts. */
    public static function progress(array $template, array $records): array
    {
        $steps = $template['steps'] ?? array();
        $index = 0;
        $latest = null;
        foreach ($steps as $i => $step) {
            $record = $records['step.' . $step['key']] ?? null;
            if ($record && (!$latest || (int) $record['id'] > (int) $latest['id'])) {
                $latest = $record;
                $index = $i;
            }
        }
        $state = (string) ($latest['data']['state'] ?? '');
        $finish = $records['step.finish'] ?? null;
        $finished = ($finish['data']['state'] ?? '') === 'confirmed';
        if ($finished) {
            foreach ($steps as $step) {
                $record = $records['step.' . $step['key']] ?? null;
                // Later photos/annotations keep the operation finished. New draft
                // conditions need confirmation, without demanding another report.
                if (($record['data']['state'] ?? '') === 'draft' && (int) $record['id'] > (int) $finish['id']) { $finished = false; break; }
            }
        }
        $resume = $state === 'confirmed' && !$finished && isset($steps[$index + 1]) ? $index + 1 : $index;
        $batch = $records['wb.actual'] ?? null;
        if ($batch && (int) $batch['id'] > (int) ($finish['id'] ?? 0) && ($batch['data']['state'] ?? '') === 'draft') { $finished = false; }
        $setup = $records['wb.setup'] ?? null;
        if ($setup && $finish && (int) $setup['id'] > (int) $finish['id']) { $finished = false; }
        if (isset($records['wb.progress']) && !$finished) {
            $key = $records['wb.progress']['data']['fields']['stage'] ?? '';
            foreach ($steps as $i => $step) { if ($step['key'] === $key) { $index = $i; $resume = $i; break; } }
            $latest = $records['wb.progress']; $state = 'draft';
        }
        if (in_array($template['label'] ?? '', array('qPCR', '免疫荧光'), true) && isset($records['assay.workspace'])) {
            $workspace = $records['assay.workspace']; $finished = ($workspace['data']['state'] ?? '') === 'confirmed';
            $stage = $workspace['data']['stage'] ?? 'prepare'; $stage = $stage === 'analysis' ? 'finish' : $stage;
            foreach ($steps as $i => $definition) { if ($definition['key'] === $stage) { $index = $i; $resume = $i; break; } }
            $latest = $workspace; $state = $workspace['data']['state'] ?? 'draft';
        }
        return array('index' => $index, 'resume_index' => $resume,
            'resume_key' => $steps[$resume]['key'] ?? '', 'label' => $steps[$index]['label'] ?? '尚未选择模板',
            'state' => $state, 'has_stage' => $latest !== null, 'finished' => (bool) $finished,
            'next_label' => $finished ? '补充原始资料或整理分析' : ($steps[$resume]['label'] ?? '选择实验模板'));
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
        // A submitted view must use its frozen configuration rather than today's pack.
        if ($submissionFlow && isset($display['parameters']['data']['fields']['payload'])) {
            $legacy = $display['parameters']['data']['fields']['payload'];
        }
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
        // The WB quick flow confirms one batch (wb.actual) instead of per-step records;
        // treat that batch confirmation as satisfying the step requirements.
        $wbBatchConfirmed = $templateKey === 'western_blot' && ($display['wb.actual']['data']['state'] ?? '') === 'confirmed';
        if (!$templateKey) { $missing[] = '实验模板'; }
        if (empty($display['plan']['data']['fields']['objective']) || ($display['plan']['data']['state'] ?? '') !== 'confirmed') { $missing[] = '实验目的与计划尚未确认'; }
        foreach ($template['steps'] as $definition) {
            $record = $display['step.' . $definition['key']] ?? null;
            if (!$wbBatchConfirmed && ($record['data']['state'] ?? '') !== 'confirmed') { $missing[] = $definition['label'] . '尚未确认'; }
            $reference = array(); $unknown = array();
            foreach ($definition['fields'] as &$field) {
                $hint = $legacy;
                foreach (explode('.', $field['hint']) as $part) { $hint = is_array($hint) ? ($hint[$part] ?? '') : ''; }
                $field['planned'] = is_scalar($hint) ? (string) $hint : '';
                $reference[$field['key']] = $field['planned'];
                $field['candidate'] = $record['data']['fields'][$field['key']] ?? $field['planned'];
                $field['candidate_label'] = $record['data']['fields'][$field['key'] . '_label'] ?? $field['candidate'];
                $field['candidate_source'] = $field['candidate'] === '' ? 'unknown' : (isset($record['data']['fields'][$field['key']]) ? (($record['data']['state'] ?? '') === 'confirmed' ? 'actual' : 'draft') : 'reference');
                if ($field['required'] && $field['candidate'] === '') { $unknown[] = $field['key']; }
            }
            unset($field);
            $steps[] = array('definition' => $definition, 'record' => $record, 'unknown' => $unknown,
                'reference_token' => hash('sha256', json_encode(array(self::VERSION, $templateKey, $definition['key'], $reference), JSON_THROW_ON_ERROR)));
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
        $light = ($input['wb_light'] ?? '') === '1' && $context['template_key'] === 'western_blot' && str_starts_with($key, 'step.');
        $previous = $context['records'][$key] ?? null;
        $reference = array();
        $preserveConfirmed = false;
        $fields = array();
        if ($key === 'plan') {
            foreach (array('objective', 'notes') as $field) { $fields[$field] = $this->text($input[$field] ?? ''); }
            if ($state === 'confirmed' && $fields['objective'] === '') { throw new AppException('请填写本次实验目的。'); }
        } elseif ($key === 'result') {
            foreach (array('summary', 'conclusion') as $field) { $fields[$field] = $this->text($input[$field] ?? ''); }
        } else {
            $stepView = null;
            foreach ($context['steps'] as $candidate) { if ($candidate['definition']['key'] === substr($key, 5)) { $stepView = $candidate; break; } }
            $step = $stepView['definition'] ?? null;
            if (!str_starts_with($key, 'step.') || !$step) { throw new AppException('请先选择有效的实验模板。'); }
            if ($light) {
                if (!hash_equals($stepView['reference_token'], $this->text($input['expected_reference'] ?? '', 64))) {
                    throw new AppException('方案条件已在其他页面更新，请刷新后核对再确认。当前输入仍保留在页面。');
                }
                // Unopened editors are omitted by the browser. Merge them on the server,
                // preserving explicit blanks, draft values and optional associations.
                foreach ($step['fields'] as $field) {
                    $reference[$field['key']] = $field['planned'];
                    if (!array_key_exists($field['key'], $input)) { $input[$field['key']] = $previous['data']['fields'][$field['key']] ?? $field['planned']; }
                }
                foreach (array('entry_mode' => 'live', 'operator' => $context['operator'], 'entry_reason' => '', 'occurred_at' => '') as $name => $fallback) {
                    if (!array_key_exists($name, $input)) {
                        $input[$name] = $name === 'entry_mode' ? ($previous['entry_mode'] ?? $fallback) : ($previous['data'][$name === 'occurred_at' ? 'declared_time' : $name] ?? $fallback);
                    }
                }
                if (!array_key_exists('evidence_ids', $input) && !isset($input['evidence_present'])) { $input['evidence_ids'] = $previous['data']['evidence_ids'] ?? array(); }
            }
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
            // Adding a photo or annotation must not undo an already confirmed operation.
            if ($light && $state === 'draft' && ($previous['data']['state'] ?? '') === 'confirmed') {
                $conditionsChanged = false;
                foreach ($step['fields'] as $field) {
                    if (!in_array($field['key'], array('exception', 'note'), true) && $fields[$field['key']] !== ($previous['data']['fields'][$field['key']] ?? '')) { $conditionsChanged = true; }
                }
                if (!$conditionsChanged && ($input['entry_mode'] ?? 'live') === $previous['entry_mode'] && ($input['operator'] ?? '') === ($previous['data']['operator'] ?? '')
                    && ($input['occurred_at'] ?? '') === ($previous['data']['declared_time'] ?? '') && ($input['entry_reason'] ?? '') === ($previous['data']['entry_reason'] ?? '')) { $state = 'confirmed'; $preserveConfirmed = true; }
            }
        }
        $mode = $this->text($input['entry_mode'] ?? 'live', 24);
        if (!in_array($mode, array('live', 'repeat', 'retrospective', 'paper'), true)) { throw new AppException('记录方式不正确。'); }
        $ids = array_values(array_unique(array_map('intval', is_array($input['evidence_ids'] ?? null) ? $input['evidence_ids'] : array())));
        if (array_diff($ids, array_keys($context['uploads']))) { throw new AppException('附件必须来自当前实验。'); }
        $occurred = null; $reason = $this->text($input['entry_reason'] ?? '');
        if (str_starts_with($key, 'step.') && $state === 'confirmed') {
            if ($preserveConfirmed) { $occurred = $previous['occurred_at']; }
            elseif ($mode === 'live' || $mode === 'repeat') {
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
        if ($light) {
            // Keep the exact reference alongside each revision. Configuration is never
            // execution: only an explicit confirmation promotes new actual values.
            if ($preserveConfirmed && isset($previous['data']['reference_values'])) { $reference = $previous['data']['reference_values']; }
            $data['reference_values'] = $reference;
            $data['reference_token'] = $preserveConfirmed ? ($previous['data']['reference_token'] ?? $stepView['reference_token']) : $stepView['reference_token'];
            $data['parameter_states'] = array();
            foreach ($step['fields'] as $field) {
                $value = $fields[$field['key']]; $planned = $reference[$field['key']];
                $data['parameter_states'][$field['key']] = $value === '' ? 'unknown' : ($state === 'draft' ? ($planned !== '' && $value === $planned ? 'default_unconfirmed' : 'draft') : ($planned === '' ? 'confirmed_actual' : ($value === $planned ? 'confirmed_default' : 'overridden')));
            }
        }
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

    private function assayContext(int $id, bool $write = false): array
    {
        $this->entity($id, $write);
        $context = $this->read($id);
        if (!in_array($context['template_key'], array('qpcr', 'if'), true)) { throw new AppException('该记录入口只适用于 qPCR 和免疫荧光。'); }
        return $context;
    }

    public function saveAssayWorkspace(int $id, array $input): array
    {
        return $this->Db->transaction(function () use ($id, $input): array {
            $this->entity($id, true); $this->query('SELECT id FROM experiments WHERE id=:id FOR UPDATE', array('id' => $id));
            $context = $this->assayContext($id); $view = SmartlabAssayWorkspace::view($context);
            if (!hash_equals($view['source_token'], $this->text($input['source_token'] ?? '', 64))) { throw new AppException('原有条件已在其他页面更新，请刷新后核对；当前输入仍保留。'); }
            if ((int) ($input['expected_revision'] ?? 0) !== $view['revision']) { throw new AppException('本次记录已更新，请刷新后查看最新内容。'); }
            $stage = $this->text($input['stage'] ?? $view['stage'], 30);
            if (!isset($view['stages'][$stage])) { throw new AppException('实验阶段不正确。'); }
            if (!is_array($input['fields'] ?? array())) { throw new AppException('条件格式不正确。'); }
            $fields = array(); $changed = false; $states = array();
            foreach ($view['rows'] as $key => $row) {
                $value = array_key_exists($key, $input['fields'] ?? array()) ? $this->text($input['fields'][$key]) : $row['candidate'];
                if ($key === 'prepare.sample_id' && $value !== '') {
                    if (!ctype_digit($value)) { throw new AppException('请选择有效的样本。'); }
                    $sample = $this->query('SELECT code,name FROM smartlab_samples WHERE id=:id AND team_id=:team', array('id' => (int) $value, 'team' => (int) $this->Users->team))->fetch();
                    if (!$sample) { throw new AppException('该样本不属于当前团队。'); }
                }
                if ($value !== $row['candidate']) { $changed = true; }
                // A manufacturer's range is a reference, never an exact execution value.
                if (in_array($key, array('reaction.volume', 'staining.dilution'), true) && preg_match('/\d\s*(?:-|–|—|~|至)\s*(?:1\s*:\s*)?\d/u', $value)) { $value = ''; }
                $fields[$key] = $value;
            }
            $state = ($input['record_state'] ?? '') === 'confirmed' || ($view['finished'] && !$changed) ? 'confirmed' : 'draft';
            foreach ($fields as $key => $value) { $states[$key] = $value === '' ? 'unknown' : ($state === 'draft' ? 'draft' : (($view['reference_values'][$key] ?? '') === '' ? 'confirmed_actual' : (($view['reference_values'][$key] ?? '') === $value ? 'confirmed_default' : 'overridden'))); }
            $data = array('template_key' => $context['template_key'], 'state' => $state, 'stage' => $stage, 'fields' => $fields,
                'reference_values' => $view['reference_values'], 'parameter_states' => $states, 'source_token' => $view['record']['data']['source_token'] ?? $view['source_token']);
            if ($view['record'] && SmartlabAssayWorkspace::canonical($view['record']['data']) === SmartlabAssayWorkspace::canonical($data)) { return $view['record']; }
            $record = $this->append($id, 'assay.workspace', ($input['record_state'] ?? '') === 'confirmed' ? 'confirmed' : ($changed ? 'conditions_changed' : 'progress_noted'), $data, 'live', null, $view['revision']);
            return $record;
        });
    }

    public function saveAssayNote(int $id, array $input): array
    {
        return $this->Db->transaction(function () use ($id, $input): array {
            $this->entity($id, true); $this->query('SELECT id FROM experiments WHERE id=:id FOR UPDATE', array('id' => $id));
            $context = $this->assayContext($id); $stage = $this->text($input['stage'] ?? 'prepare', 30);
            if (!isset(SmartlabAssayWorkspace::stages($context['template_key'])[$stage])) { throw new AppException('记录阶段不正确。'); }
            $noteId = $this->text($input['note_id'] ?? '', 40);
            if (!preg_match('/^[a-f0-9-]{36}$/D', $noteId)) { throw new AppException('记录编号不正确，请重新打开记录。'); }
            $note = $this->text($input['note'] ?? ''); if ($note === '') { throw new AppException('请写一句变化或异常；没有变化时无需新增记录。'); }
            return $this->append($id, 'assay.note.' . $noteId, 'note_added', array('state' => 'noted', 'stage' => $stage, 'fields' => array('note' => $note)), 'live', null, (int) ($input['expected_revision'] ?? 0));
        });
    }

    public function saveAssayFile(int $id, array $input): array
    {
        return $this->Db->transaction(function () use ($id, $input): array {
            $this->entity($id, true); $this->query('SELECT id FROM experiments WHERE id=:id FOR UPDATE', array('id' => $id));
            $context = $this->assayContext($id); $fileId = (int) ($input['file_id'] ?? 0);
            if (!isset($context['uploads'][$fileId])) { throw new AppException('文件必须来自当前实验。'); }
            $fields = array();
            foreach ($context['template_key'] === 'if' ? array('sample', 'substrate', 'field', 'channel', 'pixel_size') : array('sample', 'plate', 'run') as $key) { $fields[$key] = $this->text($input[$key] ?? '', 200); }
            return $this->append($id, 'assay.file.' . $fileId, 'source_noted', array('state' => 'noted', 'file_id' => $fileId, 'fields' => $fields), 'live', null, (int) ($input['expected_revision'] ?? 0));
        });
    }

    private function assaySource(int $id, int $fileId): array
    {
        $context = $this->assayContext($id);
        if ($context['template_key'] !== 'qpcr' || !isset($context['uploads'][$fileId])) { throw new AppException('请选择当前 qPCR 实验的原始结果文件。'); }
        $file = $context['uploads'][$fileId];
        if ((int) ($file['filesize'] ?? 0) > 10000000) { throw new AppException('此文件较大，原件已保留；请导出本次结果页。'); }
        $bytes = Storage::from((int) ($file['storage'] ?? 1))->getStorage()->getFs()->read((string) $file['long_name']);
        return array($context, $file, $bytes);
    }

    public function previewAssayCsv(int $id, array $input): array
    {
        [, $file, $bytes] = $this->assaySource($id, (int) ($input['file_id'] ?? 0));
        $preview = SmartlabQpcrImport::preview($bytes, (string) $file['real_name'], $this->text($input['sheet'] ?? '', 200));
        $preview['row_count'] = count($preview['rows']); $preview['rows'] = array_slice($preview['rows'], 0, 8);
        return $preview;
    }

    public function saveAssayCsv(int $id, array $input): array
    {
        return $this->Db->transaction(function () use ($id, $input): array {
            $this->entity($id, true); $this->query('SELECT id FROM experiments WHERE id=:id FOR UPDATE', array('id' => $id));
            $fileId = (int) ($input['file_id'] ?? 0); [$context, $file, $bytes] = $this->assaySource($id, $fileId);
            $preview = SmartlabQpcrImport::preview($bytes, (string) $file['real_name'], $this->text($input['sheet'] ?? '', 200));
            if (!hash_equals($preview['hash'], $this->text($input['expected_hash'] ?? '', 64))) { throw new AppException('原始文件已变化，请重新预览后核对。'); }
            if (!is_array($input['mapping'] ?? null)) { throw new AppException('请核对结果列对应。'); }
            $tags = $context['records']['assay.file.' . $fileId]['data']['fields'] ?? array();
            $projection = SmartlabQpcrImport::project($preview, $input['mapping'], $tags['plate'] ?? '', $tags['run'] ?? '');
            $key = 'assay.qpcr.' . $fileId; $previous = $context['records'][$key] ?? null;
            if ((int) ($input['expected_revision'] ?? 0) !== (int) ($previous['revision'] ?? 0)) { throw new AppException('此文件的导入版本已更新，请刷新后查看。'); }
            $data = array('state' => 'confirmed', 'source_id' => $fileId, 'source_name' => $file['real_name'], 'source_hash' => $preview['hash'], 'sheet' => $preview['sheet'], 'mapping' => $projection['mapping'], 'rows' => $projection['rows']);
            if ($previous && SmartlabAssayWorkspace::canonical($data) === SmartlabAssayWorkspace::canonical($previous['data'])) { return $previous; }
            return $this->append($id, $key, 'data_imported', $data, 'configuration', null, (int) ($input['expected_revision'] ?? 0));
        });
    }

    /** A selection is configuration only; it never confirms execution. */
    public function saveWbSetup(int $id, array $input): array
    {
        return $this->Db->transaction(function () use ($id, $input): array {
            $this->entity($id, true);
            $this->query('SELECT id FROM experiments WHERE id=:id FOR UPDATE', array('id' => $id));
            $context = $this->read($id);
            if ($context['template_key'] !== 'western_blot') { throw new AppException('抗体带入只适用于 WB 实验。'); }
            $catalog = new SmartlabWbConditions($this->Users);
            if (!hash_equals($catalog->catalogToken(), $this->text($input['catalog_token'] ?? '', 64))) { throw new AppException('抗体说明书或常用方案已更新，请刷新后选择。'); }
            $ids = $input['antibody_ids'] ?? array();
            if (!is_array($ids)) { throw new AppException('抗体选择格式不正确。'); }
            $snapshot = $catalog->resolve($ids, $this->text($input['method_code'] ?? '', 100), (int) ($input['secondary_id'] ?? 0));
            return $this->append($id, 'wb.setup', 'conditions_selected', array('state' => 'default_unconfirmed', 'method_selection' => $this->text($input['method_code'] ?? '', 100), 'snapshot' => $snapshot), 'configuration', null, (int) ($input['expected_revision'] ?? 0));
        });
    }

    public function wbView(array $context): array
    {
        $setup = $context['records']['wb.setup'] ?? null;
        $snapshot = $setup['data']['snapshot'] ?? array('antibodies' => array(), 'secondary' => null, 'method' => null, 'rows' => array());
        $rows = $snapshot['rows'];
        if (!$setup) {
            // Read old references/actuals without rewriting any legacy record.
            foreach ($context['steps'] as $step) {
                foreach ($step['definition']['fields'] as $field) {
                    if (!in_array($field['key'], array('loading', 'gel', 'transfer', 'primary', 'dilution', 'incubation', 'instrument', 'exposure'), true)) { continue; }
                    $rows[$field['key']] = array('label' => str_replace('实际', '', $field['label']), 'value' => $field['planned'], 'source' => '原有实验方案', 'legacy_candidate' => $field['candidate'],
                        'legacy_state' => $field['candidate_source'] === 'actual' ? 'confirmed_actual' : ($field['candidate_source'] === 'unknown' ? 'unknown' : 'default_unconfirmed'));
                }
            }
        }
        $rows['instrument'] ??= array('label' => '成像设备', 'value' => '', 'source' => '本次记录');
        $rows['exposure'] ??= array('label' => '曝光时间', 'value' => '', 'source' => '本次记录');
        // Applicability describes the method; it is not an executed parameter.
        unset($rows['applicability']);
        // MySQL JSON objects reorder keys; keep conditions in laboratory workflow order.
        $order = array('quantification', 'loading', 'gel', 'electrophoresis', 'transfer', 'membrane', 'applicability');
        foreach (array_merge($snapshot['antibodies'], $snapshot['secondary'] ? array($snapshot['secondary']) : array()) as $antibody) {
            foreach (array('wb_dilution', 'wb_diluent', 'wb_incubation') as $field) { $order[] = 'antibody.' . $antibody['material_id'] . '.' . $field; }
        }
        $ordered = array();
        foreach (array_unique(array_merge($order, array_keys($rows))) as $key) { if (isset($rows[$key])) { $ordered[$key] = $rows[$key]; } }
        $rows = $ordered;
        $actual = $context['records']['wb.actual'] ?? null;
        $same = $actual && (int) ($actual['data']['setup_revision'] ?? 0) === (int) ($setup['revision'] ?? 0);
        foreach ($rows as $key => &$row) {
            $row['candidate'] = $same ? ($actual['data']['fields'][$key] ?? $row['value']) : ($row['legacy_candidate'] ?? $row['value']);
            $row['state'] = $same ? ($actual['data']['parameter_states'][$key] ?? 'unknown') : ($row['legacy_state'] ?? ($row['value'] === '' ? 'unknown' : 'default_unconfirmed'));
        }
        unset($row);
        return array('setup' => $setup, 'snapshot' => $snapshot, 'rows' => $rows, 'actual' => $actual, 'current_actual' => $same,
            'method_selection' => $setup['data']['method_selection'] ?? '',
            'protein_table' => $actual['data']['fields']['protein_table'] ?? '', 'loading_calculation' => $actual['data']['loading_calculation'] ?? array(),
            'note' => $actual['data']['fields']['note'] ?? '', 'stage' => $context['records']['wb.progress']['data']['fields']['stage'] ?? '',
            'progress_revision' => $context['records']['wb.progress']['revision'] ?? 0);
    }

    /** One batch confirmation. Unknown values stay unknown and never block starting. */
    public function saveWbActual(int $id, array $input): array
    {
        return $this->Db->transaction(function () use ($id, $input): array {
            $this->entity($id, true);
            $this->query('SELECT id FROM experiments WHERE id=:id FOR UPDATE', array('id' => $id));
            $context = $this->read($id);
            if ($context['template_key'] !== 'western_blot') { throw new AppException('该操作只适用于 WB。'); }
            $view = $this->wbView($context);
            $setupRevision = (int) ($view['setup']['revision'] ?? 0);
            if ($setupRevision !== (int) ($input['setup_revision'] ?? 0)) { throw new AppException('本次方案已更新，请刷新后核对。'); }
            $edits = $input['conditions'] ?? array();
            if (!is_array($edits) || array_diff(array_keys($edits), array_keys($view['rows']))) { throw new AppException('本次条件字段不正确。'); }
            $fields = array(); $states = array(); $changed = false;
            foreach ($view['rows'] as $key => $row) {
                $fields[$key] = $this->text(array_key_exists($key, $edits) ? $edits[$key] : $row['candidate'], 1000);
                if ($fields[$key] !== $row['candidate']) { $changed = true; }
            }
            $fields['note'] = $this->text($input['note'] ?? $view['note']);
            $fields['protein_table'] = $this->text($input['protein_table'] ?? $view['protein_table'], 16000);
            if ($fields['protein_table'] !== $view['protein_table']) { $changed = true; }
            $calculation = \Elabftw\Services\SmartlabWbLoadingCalculator::calculate($fields['loading'] ?? '', $fields['protein_table']);
            $state = ($input['record_state'] ?? '') === 'confirmed' ? 'confirmed' : 'draft';
            $previous = $view['actual'];
            if ($state === 'draft' && !$changed && $view['current_actual'] && ($previous['data']['state'] ?? '') === 'confirmed') { $state = 'confirmed'; }
            foreach ($view['rows'] as $key => $row) {
                if ($state === 'confirmed' && str_ends_with($key, '.wb_dilution') && preg_match('/^1\s*[:：]\s*\d[\d,]*\s*[-–—~至]\s*(?:1\s*[:：]\s*)?\d[\d,]*$/u', $fields[$key])) { $fields[$key] = ''; }
                $states[$key] = $fields[$key] === '' ? 'unknown' : ($state === 'draft' ? 'default_unconfirmed' : ($fields[$key] === $row['value'] ? 'confirmed_default' : 'overridden'));
            }
            $time = $state === 'confirmed' ? ($view['current_actual'] && ($previous['data']['state'] ?? '') === 'confirmed' ? $previous['occurred_at'] : gmdate('Y-m-d H:i:s')) : null;
            $referenceRows = array_map(static fn($row) => array_intersect_key($row, array_flip(array('label', 'value', 'source'))), $view['rows']);
            $record = $this->append($id, 'wb.actual', $state === 'confirmed' ? 'confirmed' : 'draft_saved', array('state' => $state, 'setup_revision' => $setupRevision,
                'fields' => $fields, 'loading_calculation' => $calculation, 'parameter_states' => $states, 'reference_rows' => $referenceRows, 'reference_order' => array_keys($referenceRows), 'snapshot' => $view['snapshot'], 'operator' => $context['operator']), 'live', $time, (int) ($input['expected_revision'] ?? 0));
            if (($input['record_state'] ?? '') === 'confirmed') {
                $this->append($id, 'step.finish', 'confirmed', array('template_key' => 'western_blot', 'template_version' => self::VERSION, 'state' => 'confirmed', 'fields' => array('note' => $fields['note']),
                    'evidence_ids' => array(), 'operator' => $context['operator'], 'batch_record_id' => (int) $record['id']), 'live', (int) ($context['records']['step.finish']['data']['batch_record_id'] ?? 0) === (int) $record['id'] ? $context['records']['step.finish']['occurred_at'] : gmdate('Y-m-d H:i:s'));
            }
            return $record;
        });
    }

    public function saveWbProgress(int $id, array $input): array
    {
        $this->entity($id, true); $context = $this->read($id);
        if ($context['template_key'] !== 'western_blot') { throw new AppException('该操作只适用于 WB。'); }
        $stage = $this->text($input['stage'] ?? '', 50);
        if (!in_array($stage, array_column($context['template']['steps'], 'key'), true)) { throw new AppException('请选择有效的进展。'); }
        return $this->append($id, 'wb.progress', 'progress_saved', array('state' => 'draft', 'fields' => array('stage' => $stage)), 'progress', null, (int) ($input['expected_revision'] ?? 0));
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
