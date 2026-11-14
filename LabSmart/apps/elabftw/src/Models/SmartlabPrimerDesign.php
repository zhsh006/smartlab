<?php

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use JsonException;
use PDO;
use RuntimeException;

use function array_key_exists;
use function abs;
use function count;
use function escapeshellcmd;
use function escapeshellarg;
use function fclose;
use function fgets;
use function fwrite;
use function getenv;
use function implode;
use function ini_get;
use function is_array;
use function is_dir;
use function is_executable;
use function is_resource;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function preg_split;
use function proc_close;
use function proc_open;
use function round;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function str_replace;
use function stream_get_contents;
use function strlen;
use function strtoupper;
use function trim;

use const JSON_THROW_ON_ERROR;

final class SmartlabPrimerDesign extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        (new SmartlabSchemaMigrator())->ensureTable('smartlab_primer_design_records',
            'CREATE TABLE IF NOT EXISTS smartlab_primer_design_records (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id INT UNSIGNED NOT NULL,
            experiment_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            experiment_type VARCHAR(32) NOT NULL DEFAULT "qpcr",
            sequence_length INT UNSIGNED NOT NULL,
            input_sequence MEDIUMTEXT NOT NULL,
            parameters JSON NOT NULL,
            results JSON NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_smartlab_primer_design_team_time (team_id, created_at),
            KEY idx_smartlab_primer_design_experiment (experiment_id),
            CONSTRAINT fk_smartlab_primer_design_team FOREIGN KEY (team_id) REFERENCES teams(id),
            CONSTRAINT fk_smartlab_primer_design_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE SET NULL,
            CONSTRAINT fk_smartlab_primer_design_created_by FOREIGN KEY (created_by) REFERENCES users(userid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    /**
     * @throws JsonException
     */
    public function design(array $input): array
    {
        $sequence = $this->normalizeSequence((string) ($input['sequence'] ?? ''));
        if ($sequence === '') {
            throw new RuntimeException('请输入目标 DNA 序列。');
        }
        if (preg_match('/[^ACGTURYKMSWBDHVN]/', $sequence) === 1) {
            throw new RuntimeException('序列包含不支持的字符，请仅输入 DNA/IUPAC 碱基字符。');
        }
        if (strlen($sequence) < 60) {
            throw new RuntimeException('目标序列过短，建议至少输入 60 bp。');
        }

        $parameters = $this->normalizeParameters($input);
        $primer3Input = $this->buildPrimer3Input($sequence, $parameters);
        $rawOutput = $this->runPrimer3($primer3Input);
        $pairs = $this->parsePrimer3Output($rawOutput, $parameters);

        return array(
            'sequence' => $sequence,
            'sequence_length' => strlen($sequence),
            'parameters' => $parameters,
            'pairs' => $pairs,
            'raw_output' => $rawOutput,
        );
    }

    /**
     * @throws JsonException
     */
    public function saveRecord(string $title, string $sequence, array $parameters, array $pairs, ?int $experimentId = null): int
    {
        $sequence = $this->normalizeSequence($sequence);
        if ($title === '') {
            $title = sprintf('Primer design %s bp', strlen($sequence));
        }
        if ($experimentId !== null) {
            $this->assertExperimentInTeam($experimentId);
        }

        $sql = 'INSERT INTO smartlab_primer_design_records
            (team_id, experiment_id, title, experiment_type, sequence_length, input_sequence, parameters, results, created_by)
            VALUES (:team_id, :experiment_id, :title, :experiment_type, :sequence_length, :input_sequence, :parameters, :results, :created_by)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, $experimentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $req->bindValue(':title', $title);
        $req->bindValue(':experiment_type', (string) ($parameters['experiment_type'] ?? 'qpcr'));
        $req->bindValue(':sequence_length', strlen($sequence), PDO::PARAM_INT);
        $req->bindValue(':input_sequence', $sequence);
        $req->bindValue(':parameters', json_encode($parameters, JSON_THROW_ON_ERROR));
        $req->bindValue(':results', json_encode($pairs, JSON_THROW_ON_ERROR));
        $req->bindValue(':created_by', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);

        return (int) $this->Db->lastInsertId();
    }

    public function readRecords(int $limit = 20): array
    {
        $sql = 'SELECT
                r.id,
                r.title,
                r.experiment_type,
                r.sequence_length,
                r.created_at,
                e.id AS experiment_id,
                e.title AS experiment_title,
                CONCAT(u.firstname, " ", u.lastname) AS creator_name
            FROM smartlab_primer_design_records AS r
            LEFT JOIN experiments AS e ON e.id = r.experiment_id
            LEFT JOIN users AS u ON u.userid = r.created_by
            WHERE r.team_id = :team
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    /**
     * @throws JsonException
     * @throws ResourceNotFoundException
     */
    public function readRecord(int $recordId): array
    {
        $sql = 'SELECT
                r.*,
                e.title AS experiment_title,
                CONCAT(u.firstname, " ", u.lastname) AS creator_name
            FROM smartlab_primer_design_records AS r
            LEFT JOIN experiments AS e ON e.id = r.experiment_id
            LEFT JOIN users AS u ON u.userid = r.created_by
            WHERE r.team_id = :team
              AND r.id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $recordId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $record = $this->Db->fetch($req);
        $record['parameters_arr'] = json_decode((string) $record['parameters'], true, 512, JSON_THROW_ON_ERROR);
        $record['results_arr'] = json_decode((string) $record['results'], true, 512, JSON_THROW_ON_ERROR);

        return $record;
    }

    public function readQpcrExperiments(): array
    {
        $sql = 'SELECT
                e.id,
                e.title,
                e.modified_at,
                ap.pack_key
            FROM experiments AS e
            LEFT JOIN smartlab_assay_packs AS ap ON ap.experiment_id = e.id
            WHERE e.team = :team
              AND e.state = 1
              AND (ap.pack_key = "qpcr" OR e.title LIKE "%qPCR%" OR e.title LIKE "%PCR%")
            ORDER BY e.modified_at DESC, e.id DESC
            LIMIT 100';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function exportRecordAsExcelTsv(array $record): string
    {
        $lines = array(
            "Rank\tForward primer\tReverse primer\tProduct length\tForward Tm\tReverse Tm\tForward GC%\tReverse GC%\tSelf complement risk\t3' complement risk\tRecommendation",
        );
        foreach ($record['results_arr'] as $pair) {
            $lines[] = sprintf(
                "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s",
                $pair['rank'] ?? '',
                $pair['forward_sequence'] ?? '',
                $pair['reverse_sequence'] ?? '',
                $pair['product_size'] ?? '',
                $pair['forward_tm'] ?? '',
                $pair['reverse_tm'] ?? '',
                $pair['forward_gc'] ?? '',
                $pair['reverse_gc'] ?? '',
                $pair['self_complementarity'] ?? '',
                $pair['end_complementarity'] ?? '',
                $pair['recommendation'] ?? '',
            );
        }
        return implode("\n", $lines) . "\n";
    }

    private function normalizeSequence(string $sequence): string
    {
        $sequenceLines = preg_split('/\R/', $sequence) ?: array($sequence);
        $sequenceBody = '';
        foreach ($sequenceLines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '>')) {
                continue;
            }
            $sequenceBody .= $line;
        }

        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $sequenceBody));
    }

    private function normalizeParameters(array $input): array
    {
        $experimentType = (string) ($input['experiment_type'] ?? 'qpcr');
        $experimentType = $experimentType === 'pcr' ? 'pcr' : 'qpcr';
        $defaultProductRange = $experimentType === 'qpcr' ? '80-200' : '200-800';
        [$primerSizeMin, $primerSizeOpt, $primerSizeMax] = $this->normalizeMinOptMaxInt(
            (int) ($input['primer_size_min'] ?? 18),
            (int) ($input['primer_size_opt'] ?? 20),
            (int) ($input['primer_size_max'] ?? 24),
            12,
            35,
        );
        [$tmMin, $tmOpt, $tmMax] = $this->normalizeMinOptMaxFloat(
            (float) ($input['tm_min'] ?? 57),
            (float) ($input['tm_opt'] ?? 60),
            (float) ($input['tm_max'] ?? 63),
            40,
            80,
        );
        [$gcMin, $gcMax] = $this->normalizeMinMaxFloat(
            (float) ($input['gc_min'] ?? 40),
            (float) ($input['gc_max'] ?? 60),
            0,
            100,
        );

        return array(
            'experiment_type' => $experimentType,
            'product_size_range' => $this->normalizeRange((string) ($input['product_size_range'] ?? $defaultProductRange), $defaultProductRange, 50, 2000),
            'primer_size_min' => $primerSizeMin,
            'primer_size_opt' => $primerSizeOpt,
            'primer_size_max' => $primerSizeMax,
            'tm_min' => $tmMin,
            'tm_opt' => $tmOpt,
            'tm_max' => $tmMax,
            'gc_min' => $gcMin,
            'gc_max' => $gcMax,
            'num_return' => $this->boundInt((int) ($input['num_return'] ?? 5), 1, 20),
        );
    }

    private function buildPrimer3Input(string $sequence, array $parameters): string
    {
        $primer3Input = array(
            'SEQUENCE_ID=smartlab_primer_design',
            'SEQUENCE_TEMPLATE=' . $sequence,
            'PRIMER_TASK=generic',
            'PRIMER_PICK_LEFT_PRIMER=1',
            'PRIMER_PICK_INTERNAL_OLIGO=0',
            'PRIMER_PICK_RIGHT_PRIMER=1',
            'PRIMER_PRODUCT_SIZE_RANGE=' . $parameters['product_size_range'],
            'PRIMER_NUM_RETURN=' . $parameters['num_return'],
            'PRIMER_MIN_SIZE=' . $parameters['primer_size_min'],
            'PRIMER_OPT_SIZE=' . $parameters['primer_size_opt'],
            'PRIMER_MAX_SIZE=' . $parameters['primer_size_max'],
            'PRIMER_MIN_TM=' . $parameters['tm_min'],
            'PRIMER_OPT_TM=' . $parameters['tm_opt'],
            'PRIMER_MAX_TM=' . $parameters['tm_max'],
            'PRIMER_MIN_GC=' . $parameters['gc_min'],
            'PRIMER_MAX_GC=' . $parameters['gc_max'],
            'PRIMER_EXPLAIN_FLAG=1',
        );
        $configPath = $this->resolvePrimer3ConfigPath();
        if ($configPath !== '') {
            $primer3Input[] = 'PRIMER_THERMODYNAMIC_PARAMETERS_PATH=' . $configPath;
        }
        $primer3Input[] = '=';
        $primer3Input[] = '';

        return implode("\n", $primer3Input);
    }

    private function runPrimer3(string $input): string
    {
        $command = $this->resolvePrimer3Command();
        $descriptorSpec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = proc_open(escapeshellcmd($command), $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动 primer3_core。');
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($error) !== '' ? trim($error) : 'Primer3 执行失败。');
        }

        return (string) $output;
    }

    private function parsePrimer3Output(string $output, array $parameters): array
    {
        $values = array();
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '=') {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $values[$parts[0]] = $parts[1];
            }
        }

        $count = (int) ($values['PRIMER_PAIR_NUM_RETURNED'] ?? 0);
        $pairs = array();
        for ($index = 0; $index < $count; $index++) {
            $pair = array(
                'rank' => $index + 1,
                'forward_sequence' => $values["PRIMER_LEFT_{$index}_SEQUENCE"] ?? '',
                'reverse_sequence' => $values["PRIMER_RIGHT_{$index}_SEQUENCE"] ?? '',
                'product_size' => (int) ($values["PRIMER_PAIR_{$index}_PRODUCT_SIZE"] ?? 0),
                'forward_tm' => $this->roundValue($values["PRIMER_LEFT_{$index}_TM"] ?? null),
                'reverse_tm' => $this->roundValue($values["PRIMER_RIGHT_{$index}_TM"] ?? null),
                'forward_gc' => $this->roundValue($values["PRIMER_LEFT_{$index}_GC_PERCENT"] ?? null),
                'reverse_gc' => $this->roundValue($values["PRIMER_RIGHT_{$index}_GC_PERCENT"] ?? null),
                'self_complementarity' => $this->maxMetric($values, $index, array('SELF_ANY_TH', 'SELF_ANY')),
                'end_complementarity' => $this->maxMetric($values, $index, array('SELF_END_TH', 'SELF_END', 'PAIR_COMPL_END_TH', 'PAIR_COMPL_END')),
            );
            $pair['recommendation'] = $this->recommendPair($pair, $parameters);
            $pairs[] = $pair;
        }

        return $pairs;
    }

    private function recommendPair(array $pair, array $parameters): string
    {
        $tmDelta = abs((float) $pair['forward_tm'] - (float) $pair['reverse_tm']);
        $selfRisk = (float) $pair['self_complementarity'];
        $endRisk = (float) $pair['end_complementarity'];
        $gcOk = (float) $pair['forward_gc'] >= (float) $parameters['gc_min']
            && (float) $pair['forward_gc'] <= (float) $parameters['gc_max']
            && (float) $pair['reverse_gc'] >= (float) $parameters['gc_min']
            && (float) $pair['reverse_gc'] <= (float) $parameters['gc_max'];

        if ($tmDelta <= 1.5 && $selfRisk <= 35.0 && $endRisk <= 30.0 && $gcOk) {
            return '优先推荐';
        }
        if ($tmDelta <= 3.0 && $selfRisk <= 45.0 && $endRisk <= 40.0) {
            return '可用';
        }
        return '谨慎使用';
    }

    private function maxMetric(array $values, int $index, array $suffixes): float
    {
        $metrics = array();
        foreach (array('LEFT', 'RIGHT') as $side) {
            foreach ($suffixes as $suffix) {
                if (str_starts_with($suffix, 'PAIR_')) {
                    $key = sprintf('PRIMER_PAIR_%d_%s', $index, str_replace('PAIR_', '', $suffix));
                } else {
                    $key = sprintf('PRIMER_%s_%d_%s', $side, $index, $suffix);
                }
                if (array_key_exists($key, $values)) {
                    $metrics[] = (float) $values[$key];
                }
            }
        }
        return $metrics === array() ? 0.0 : round(max($metrics), 2);
    }

    private function resolvePrimer3Command(): string
    {
        $configured = trim((string) getenv('SMARTLAB_PRIMER3_BIN'));
        $candidates = array($configured, '/elabftw/bin/primer3_core', '/usr/local/bin/primer3_core', '/usr/bin/primer3_core', 'primer3_core');
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (str_contains($candidate, '/') && !$this->isAllowedByOpenBasedir($candidate)) {
                continue;
            }
            if (str_contains($candidate, '/') && !is_executable($candidate)) {
                continue;
            }
            if (str_contains($candidate, '/') || $this->commandExistsInPath($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('未找到 primer3_core，请在本地安装 Primer3 或设置 SMARTLAB_PRIMER3_BIN。');
    }

    private function commandExistsInPath(string $command): bool
    {
        $descriptorSpec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = proc_open('command -v ' . escapeshellarg($command), $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && trim((string) $output) !== '';
    }

    private function resolvePrimer3ConfigPath(): string
    {
        $configured = trim((string) getenv('SMARTLAB_PRIMER3_CONFIG'));
        $candidates = array($configured, '/elabftw/share/primer3_config', '/opt/primer3/primer3_config', '/usr/local/share/primer3_config');
        foreach ($candidates as $candidate) {
            if ($candidate === '' || !$this->isAllowedByOpenBasedir($candidate)) {
                continue;
            }
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function isAllowedByOpenBasedir(string $path): bool
    {
        $openBasedir = (string) ini_get('open_basedir');
        if ($openBasedir === '') {
            return true;
        }
        foreach (explode(':', $openBasedir) as $allowedPath) {
            if ($allowedPath !== '' && str_starts_with($path, $allowedPath)) {
                return true;
            }
        }

        return false;
    }

    private function assertExperimentInTeam(int $experimentId): void
    {
        $sql = 'SELECT id FROM experiments WHERE team = :team AND id = :id LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        try {
            $this->Db->fetch($req);
        } catch (ResourceNotFoundException) {
            throw new RuntimeException('关联实验不存在或不属于当前团队。');
        }
    }

    private function normalizeRange(string $range, string $default, int $minAllowed, int $maxAllowed): string
    {
        if (preg_match('/^\s*(\d+)\s*[-,]\s*(\d+)\s*$/', $range, $matches) !== 1) {
            return $default;
        }
        $minValue = max($minAllowed, min($maxAllowed, (int) $matches[1]));
        $maxValue = max($minAllowed, min($maxAllowed, (int) $matches[2]));
        if ($minValue > $maxValue) {
            [$minValue, $maxValue] = [$maxValue, $minValue];
        }
        return sprintf('%d-%d', $minValue, $maxValue);
    }

    private function boundInt(int $value, int $minValue, int $maxValue): int
    {
        return max($minValue, min($maxValue, $value));
    }

    private function boundFloat(float $value, float $minValue, float $maxValue): float
    {
        return round(max($minValue, min($maxValue, $value)), 2);
    }

    private function normalizeMinOptMaxInt(int $minValue, int $optValue, int $maxValue, int $allowedMin, int $allowedMax): array
    {
        $minValue = $this->boundInt($minValue, $allowedMin, $allowedMax);
        $optValue = $this->boundInt($optValue, $allowedMin, $allowedMax);
        $maxValue = $this->boundInt($maxValue, $allowedMin, $allowedMax);
        if ($minValue > $maxValue) {
            [$minValue, $maxValue] = [$maxValue, $minValue];
        }
        $optValue = $this->boundInt($optValue, $minValue, $maxValue);

        return array($minValue, $optValue, $maxValue);
    }

    private function normalizeMinOptMaxFloat(float $minValue, float $optValue, float $maxValue, float $allowedMin, float $allowedMax): array
    {
        $minValue = $this->boundFloat($minValue, $allowedMin, $allowedMax);
        $optValue = $this->boundFloat($optValue, $allowedMin, $allowedMax);
        $maxValue = $this->boundFloat($maxValue, $allowedMin, $allowedMax);
        if ($minValue > $maxValue) {
            [$minValue, $maxValue] = [$maxValue, $minValue];
        }
        $optValue = $this->boundFloat($optValue, $minValue, $maxValue);

        return array($minValue, $optValue, $maxValue);
    }

    private function normalizeMinMaxFloat(float $minValue, float $maxValue, float $allowedMin, float $allowedMax): array
    {
        $minValue = $this->boundFloat($minValue, $allowedMin, $allowedMax);
        $maxValue = $this->boundFloat($maxValue, $allowedMin, $allowedMax);
        if ($minValue > $maxValue) {
            [$minValue, $maxValue] = [$maxValue, $minValue];
        }

        return array($minValue, $maxValue);
    }

    private function roundValue(?string $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
