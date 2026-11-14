<?php
declare(strict_types=1);

namespace Elabftw\Services;

/** Presentation of the same experiment, with references kept apart from actual values. */
final class SmartlabAssayWorkspace
{
    public static function stages(string $type): array
    {
        return $type === 'qpcr'
            ? array('prepare' => '样本与配板', 'reaction' => '反应体系', 'acquire' => '上机与 Ct', 'analysis' => '后续分析')
            : array('prepare' => '样本与载体', 'staining' => '染色与孵育', 'acquire' => '成像', 'analysis' => '结果整理');
    }

    public static function definitions(string $type): array
    {
        $base = array(
            'prepare.sample_id' => array('label' => '沿用已有样本', 'kind' => 'sample', 'path' => ''),
            'prepare.sample_label' => array('label' => '本次样本 / 批次', 'kind' => 'text', 'path' => ''),
            'prepare.groups' => array('label' => '样本与分组 / 已有表格', 'kind' => 'textarea', 'path' => 'sample.treatment_group'),
        );
        return $base + ($type === 'qpcr' ? array(
            'reaction.target' => array('label' => '检测基因', 'kind' => 'text', 'path' => 'primer.target_gene'),
            'reaction.reference' => array('label' => '内参基因', 'kind' => 'text', 'path' => 'primer.reference_gene'),
            'reaction.primer' => array('label' => '引物编号', 'kind' => 'text', 'path' => 'primer.primer_code'),
            'reaction.kit' => array('label' => '试剂盒', 'kind' => 'text', 'path' => 'setup.kit_name'),
            'reaction.volume' => array('label' => '反应体积（带单位）', 'kind' => 'text', 'path' => 'setup.reaction_volume'),
            'acquire.instrument' => array('label' => '实际使用仪器', 'kind' => 'text', 'path' => 'setup.instrument_model'),
            'acquire.settings' => array('label' => '本次程序 / 条件', 'kind' => 'textarea', 'path' => 'setup.cycler_program'),
        ) : array(
            'prepare.substrate' => array('label' => '载体 / 切片编号', 'kind' => 'text', 'path' => ''),
            'staining.antibody' => array('label' => '本次一抗 / 靶标', 'kind' => 'text', 'path' => 'staining.primary_antibody'),
            'staining.dilution' => array('label' => 'IF 稀释比例', 'kind' => 'text', 'path' => 'staining.if_dilution'),
            'staining.incubation' => array('label' => 'IF 孵育条件', 'kind' => 'text', 'path' => 'staining.if_incubation'),
            'staining.secondary' => array('label' => '二抗 / 通道', 'kind' => 'text', 'path' => 'staining.secondary_antibody'),
            'staining.nuclear' => array('label' => '核染', 'kind' => 'text', 'path' => 'staining.dapi'),
            'acquire.instrument' => array('label' => '显微镜', 'kind' => 'text', 'path' => 'imaging.microscope'),
            'acquire.objective' => array('label' => '物镜', 'kind' => 'text', 'path' => 'imaging.objective'),
            'acquire.exposure' => array('label' => '曝光条件', 'kind' => 'text', 'path' => 'imaging.exposure'),
        ));
    }

    public static function canonical(mixed $value): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (is_array($value)) { if (!array_is_list($value)) { ksort($value); } foreach ($value as &$entry) { $entry = $sort($entry); } unset($entry); }
            return $value;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    public static function view(array $context, int $selectedId = 0): array
    {
        $type = $context['template_key']; $definitions = self::definitions($type);
        $workspace = $context['records']['assay.workspace'] ?? null;
        $legacy = $context['records']['parameters']['data']['fields']['payload'] ?? array(); $references = array(); $rows = array(); $legacyIds = array();
        foreach ($definitions as $key => $definition) {
            $value = $legacy;
            foreach (explode('.', $definition['path']) as $part) { $value = is_array($value) ? ($value[$part] ?? '') : ''; }
            $references[$key] = is_scalar($value) ? trim((string) $value) : '';
            [$stage, $field] = explode('.', $key, 2);
            $old = $context['records']['step.' . $stage] ?? null;
            if ($old) { $legacyIds[$stage] = (int) $old['id']; }
            $frozen = $workspace['data']['reference_values'][$key] ?? $references[$key];
            $candidate = $workspace['data']['fields'][$key] ?? ($old['data']['fields'][$field] ?? $frozen);
            $state = $workspace['data']['parameter_states'][$key] ?? ($candidate === '' ? 'unknown' : ($old ? (($old['data']['state'] ?? '') === 'confirmed' ? 'confirmed_actual' : 'draft') : 'reference'));
            $rows[$key] = $definition + array('key' => $key, 'stage' => $stage, 'reference' => $frozen, 'candidate' => (string) $candidate, 'state' => $state);
        }
        $sourceToken = hash('sha256', self::canonical(array($references, $legacyIds, (int) ($context['records']['parameters']['revision'] ?? 0))));
        $files = array();
        foreach ($context['uploads'] as $id => $file) {
            $record = $context['records']['assay.file.' . $id] ?? null;
            $ext = strtolower(pathinfo((string) $file['real_name'], PATHINFO_EXTENSION));
            $files[$id] = $file + array('tags' => $record['data']['fields'] ?? array(), 'tag_revision' => (int) ($record['revision'] ?? 0),
                'previewable' => in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'webp'), true), 'importable' => $type === 'qpcr' && in_array($ext, array('csv', 'tsv', 'txt', 'xlsx'), true),
                'synthetic' => str_starts_with(strtoupper((string) $file['real_name']), 'SYNTHETIC_'));
        }
        if (!isset($files[$selectedId])) {
            $matching = array_filter($files, static fn($file): bool => $type === 'if' ? $file['previewable'] : $file['importable']);
            $selectedId = (int) (array_key_last($matching) ?? array_key_last($files) ?? 0);
        }
        $selected = $files[$selectedId] ?? null;
        $dataset = $context['records']['assay.qpcr.' . $selectedId] ?? null;
        $events = array();
        foreach ($context['history'] as $event) {
            $key = (string) $event['record_key']; $data = $event['data']; $title = ''; $text = ''; $fileId = 0;
            if (str_starts_with($key, 'assay.note.')) { if ((int) $event['id'] !== (int) ($context['records'][$key]['id'] ?? 0)) { continue; } $title = self::stages($type)[$data['stage'] ?? 'prepare'] ?? '现场记录'; $text = (string) ($data['fields']['note'] ?? ''); }
            elseif (str_starts_with($key, 'assay.qpcr.')) { if ((int) $event['id'] !== (int) ($context['records'][$key]['id'] ?? 0)) { continue; } $title = '已导入 Ct 数据'; $text = (string) ($data['source_name'] ?? '') . ' · ' . count($data['rows'] ?? array()) . ' 行；原始值保留'; $fileId = (int) ($data['source_id'] ?? 0); }
            elseif (str_starts_with($key, 'assay.file.')) { if ((int) $event['id'] !== (int) ($context['records'][$key]['id'] ?? 0)) { continue; } $title = '补充文件来源'; $fileId = (int) ($data['file_id'] ?? 0); $text = implode(' · ', array_filter($data['fields'] ?? array(), static fn($value): bool => $value !== '')); }
            elseif ($key === 'assay.workspace') {
                $parts = array(); foreach ($event['changes'] ?? array() as $change) { if (isset($definitions[$change['field']]) && is_scalar($change['after']) && $change['after'] !== '') { $parts[] = $definitions[$change['field']]['label'] . '：' . $change['after']; } }
                if ($parts) { $title = '本次条件变化'; $text = implode('；', array_slice($parts, 0, 3)); }
                elseif (($data['state'] ?? '') === 'confirmed' && $event['event_type'] === 'confirmed') { $title = '已结束本次实验'; $text = '已记录条件已确认；资料可以继续补充。'; }
            }
            elseif (str_starts_with($key, 'step.') && (int) $event['id'] === (int) ($context['records'][$key]['id'] ?? 0)) {
                $stage = substr($key, 5); $parts = array();
                foreach ($data['fields'] ?? array() as $field => $value) { if (isset($definitions[$stage . '.' . $field]) && is_scalar($value) && $value !== '') { $parts[] = $definitions[$stage . '.' . $field]['label'] . '：' . $value; } }
                if ($parts) { $title = self::stages($type)[$stage] ?? '既有记录'; $text = implode('；', array_slice($parts, 0, 3)); }
            }
            elseif ($key === 'result' && (int) $event['id'] === (int) ($context['records'][$key]['id'] ?? 0)) { $title = '已有结果记录'; $text = (string) ($data['fields']['summary'] ?? $data['fields']['conclusion'] ?? ''); }
            if ($title === '' || ($text === '' && $key !== 'assay.workspace')) { continue; }
            $utc = new \DateTimeImmutable((string) $event['recorded_at'], new \DateTimeZone('UTC')); $time = $utc->setTimezone(new \DateTimeZone('Asia/Shanghai'));
            $events[] = array('id' => (int) $event['id'], 'title' => $title, 'text' => $text, 'day' => $time->format('m月d日'), 'time' => $time->format('H:i'), 'file' => $files[$fileId] ?? null, 'actor' => (string) ($event['actor_name'] ?? ''));
        }
        $stages = self::stages($type); $stage = $workspace['data']['stage'] ?? 'prepare'; if (!isset($stages[$stage])) { $stage = 'prepare'; }
        $recent = $workspace ? (new \DateTimeImmutable($workspace['recorded_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('m-d H:i') : '';
        return array('type' => $type, 'stages' => $stages, 'stage' => $stage, 'record' => $workspace, 'revision' => (int) ($workspace['revision'] ?? 0), 'source_token' => $sourceToken,
            'reference_values' => $workspace['data']['reference_values'] ?? $references, 'rows' => $rows, 'files' => $files, 'selected' => $selected, 'dataset' => $dataset,
            'events' => $events, 'finished' => ($workspace['data']['state'] ?? '') === 'confirmed', 'recent' => $recent);
    }
}
