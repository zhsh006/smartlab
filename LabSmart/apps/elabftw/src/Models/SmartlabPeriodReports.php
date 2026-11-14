<?php

/**
 * Weekly / biweekly report work area (V3): collect period work, pick figures,
 * write findings/problems/next plan; submitted reports are frozen.
 *
 * @author SmartLab
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Exceptions\AppException;
use Elabftw\Exceptions\ForbiddenException;
use Elabftw\Enums\AccessType;
use Elabftw\Models\Users\Users;
use PDO;
use PDOStatement;

final class SmartlabPeriodReports extends AbstractSmartlabModel
{
    private const IMAGE_EXTENSIONS = array('png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp');

    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        // Schema is installed explicitly by database/elabftw/004_period_report_feedback.sql.
    }

    private function query(string $sql, array $params = array()): PDOStatement
    {
        $req = $this->Db->prepare($sql);
        foreach ($params as $key => $value) {
            $req->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $this->Db->execute($req);
        return $req;
    }

    public function readMine(int $limit = 20): array
    {
        $rows = $this->query('SELECT * FROM smartlab_period_reports WHERE user_id = :user AND team_id = :team
            ORDER BY period_end DESC, id DESC LIMIT ' . (int) $limit,
            array('user' => (int) $this->Users->userid, 'team' => (int) $this->Users->team))->fetchAll();
        foreach ($rows as &$row) {
            $row['figures'] = $this->decodeFigures($row);
        }
        unset($row);
        return $rows;
    }

    public function readOne(int $id): array
    {
        $row = $this->query('SELECT r.*, CONCAT(u.firstname, " ", u.lastname) AS author_name
            FROM smartlab_period_reports r JOIN users u ON u.userid = r.user_id
            WHERE r.id = :id AND r.team_id = :team LIMIT 1',
            array('id' => $id, 'team' => (int) $this->Users->team))->fetch();
        if (!$row || ((int) $row['user_id'] !== (int) $this->Users->userid && !$this->canReview($row))) {
            throw new AppException('汇报不存在，或当前账号无权查看。');
        }
        $row['figures'] = $this->decodeFigures($row);
        $row['is_owner'] = (int) $row['user_id'] === (int) $this->Users->userid;
        $row['can_feedback'] = !$row['is_owner'] && $this->canReview($row);
        return $row;
    }

    /** Read only submitted reports whose complete figure sources the mentor can read. */
    public function readForReview(): array
    {
        $rows = $this->query('SELECT r.*, CONCAT(u.firstname, " ", u.lastname) AS author_name
            FROM smartlab_period_reports r JOIN users u ON u.userid = r.user_id
            WHERE r.team_id = :team AND r.user_id <> :user AND r.status = "submitted"
            ORDER BY r.submitted_at DESC, r.id DESC LIMIT 200',
            array('team' => (int) $this->Users->team, 'user' => (int) $this->Users->userid))->fetchAll();
        return array_values(array_filter($rows, fn(array $row): bool => $this->canReview($row)));
    }

    private function canReview(array $row): bool
    {
        if ($row['status'] !== 'submitted' || (int) $row['team_id'] !== (int) $this->Users->team) {
            return false;
        }
        $figures = json_decode((string) ($row['figure_json'] ?? '[]'), true);
        // No project context: keep the personal report private instead of guessing a recipient.
        if (!is_array($figures) || $figures === array()) {
            return false;
        }
        $ids = array();
        foreach ($figures as $figure) {
            $id = (int) ($figure['experiment_id'] ?? 0);
            if ($id <= 0) { return false; }
            $ids[$id] = $id;
        }
        foreach ($ids as $id) {
            $allowed = $this->query('SELECT e.id FROM experiments e
                WHERE e.id = :id AND e.team = :team AND e.userid = :author AND e.state = 1
                AND EXISTS (SELECT 1 FROM smartlab_project_entities pe
                    JOIN smartlab_projects p ON p.id = pe.project_id AND p.team_id = e.team
                    JOIN smartlab_project_members mentor ON mentor.project_id = p.id AND mentor.user_id = :reviewer
                    JOIN smartlab_project_members student ON student.project_id = p.id AND student.user_id = e.userid
                    WHERE pe.entity_type = "experiment" AND pe.entity_id = e.id
                      AND mentor.role IN ("负责人", "导师", "共同导师", "PI", "mentor", "supervisor"))',
                array('id' => $id, 'team' => (int) $this->Users->team,
                    'author' => (int) $row['user_id'], 'reviewer' => (int) $this->Users->userid))->fetchColumn();
            if (!$allowed) {
                return false;
            }
            try {
                (new Experiments($this->Users, $id))->canOrExplode(AccessType::Read);
            } catch (ForbiddenException) {
                return false;
            }
        }
        return true;
    }

    public function readFeedback(int $id): array
    {
        $this->readOne($id);
        return $this->query('SELECT f.*, CONCAT(u.firstname, " ", u.lastname) AS author_name
            FROM smartlab_period_report_feedback f JOIN users u ON u.userid = f.author_userid
            WHERE f.report_id = :id ORDER BY f.id ASC', array('id' => $id))->fetchAll();
    }

    public function addFeedback(int $id, string $body): void
    {
        $report = $this->readOne($id);
        if (!$report['can_feedback']) {
            throw new AppException('只有所关联课题的导师可以回复已提交汇报。');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new AppException('请填写 1–5000 字的反馈。');
        }
        $this->query('INSERT INTO smartlab_period_report_feedback (report_id, author_userid, body)
            VALUES (:id, :user, :body)', array('id' => $id, 'user' => (int) $this->Users->userid, 'body' => $body));
    }

    /** @return array<int, array<string, mixed>> */
    private function decodeFigures(array $row): array
    {
        $figures = json_decode((string) ($row['figure_json'] ?? '[]'), true) ?: array();
        foreach ($figures as &$figure) {
            $figure['url'] = self::downloadUrl((string) ($figure['long_name'] ?? ''), (string) ($figure['real_name'] ?? ''), (int) ($figure['storage'] ?? 1));
            $ext = strtolower(pathinfo((string) ($figure['real_name'] ?? ''), PATHINFO_EXTENSION));
            $figure['is_image'] = in_array($ext, self::IMAGE_EXTENSIONS, true);
        }
        unset($figure);
        return $figures;
    }

    public static function downloadUrl(string $longName, string $realName, int $storage): string
    {
        return 'app/download.php?' . http_build_query(array('f' => $longName, 'name' => $realName, 'storage' => $storage));
    }

    /**
     * Experiments with bench activity inside the period (UTC bounds, inclusive start, exclusive end).
     */
    public function readPeriodWork(string $startUtc, string $endUtc): array
    {
        $rows = $this->query('SELECT e.id, e.title, COUNT(b.id) AS event_count, MAX(b.recorded_at) AS last_recorded_at
            FROM experiments e
            JOIN smartlab_bench_records b ON b.experiment_id = e.id
            WHERE e.team = :team AND e.userid = :user AND b.recorded_at >= :start AND b.recorded_at < :end
            GROUP BY e.id, e.title
            ORDER BY last_recorded_at DESC
            LIMIT 20', array(
            'team' => (int) $this->Users->team,
            'user' => (int) $this->Users->userid,
            'start' => $startUtc,
            'end' => $endUtc,
        ))->fetchAll();
        foreach ($rows as &$row) {
            $row['last_label'] = self::formatTime((string) $row['last_recorded_at']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Uploads of the user's experiments inside the period; candidates for the report figures.
     */
    public function readPeriodUploads(string $startUtc, string $endUtc): array
    {
        $rows = $this->query('SELECT u.id, u.real_name, u.long_name, u.storage, u.created_at,
            e.id AS experiment_id, e.title AS experiment_title
            FROM uploads u
            JOIN experiments e ON e.id = u.item_id
            WHERE u.type = "experiments" AND e.team = :team AND e.userid = :user AND u.state = 1
              AND u.created_at >= :start AND u.created_at < :end
            ORDER BY u.created_at DESC
            LIMIT 100', array(
            'team' => (int) $this->Users->team,
            'user' => (int) $this->Users->userid,
            'start' => $startUtc,
            'end' => $endUtc,
        ))->fetchAll();
        foreach ($rows as &$row) {
            $row['url'] = self::downloadUrl((string) $row['long_name'], (string) $row['real_name'], (int) $row['storage']);
            $row['created_label'] = self::formatTime((string) $row['created_at']);
            $ext = strtolower(pathinfo((string) $row['real_name'], PATHINFO_EXTENSION));
            $row['is_image'] = in_array($ext, self::IMAGE_EXTENSIONS, true);
        }
        unset($row);
        return $rows;
    }

    public function save(array $input, ?int $id = null): int
    {
        $periodStart = $this->validDate((string) ($input['period_start'] ?? ''));
        $periodEnd = $this->validDate((string) ($input['period_end'] ?? ''));
        if ($periodStart === '' || $periodEnd === '' || $periodStart > $periodEnd) {
            throw new AppException('请填写有效的汇报周期。');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = $periodStart . ' – ' . $periodEnd . ' 周期汇报';
        }
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }
        $fields = array(
            'findings' => trim((string) ($input['findings'] ?? '')),
            'problems' => trim((string) ($input['problems'] ?? '')),
            'next_plan' => trim((string) ($input['next_plan'] ?? '')),
        );
        $figures = $this->collectFigures(is_array($input['figure_ids'] ?? null) ? $input['figure_ids'] : array());

        if ($id !== null && $id > 0) {
            $existing = $this->readOne($id);
            if (!$existing['is_owner']) {
                throw new AppException('只能修改自己的汇报。');
            }
            if (($existing['status'] ?? '') === 'submitted') {
                throw new AppException('已提交的汇报不可修改，请新建下一修订。');
            }
            $this->query('UPDATE smartlab_period_reports SET period_start = :start, period_end = :end, title = :title,
                findings = :findings, problems = :problems, next_plan = :next_plan, figure_json = :figures
                WHERE id = :id AND user_id = :user AND status = "draft"', array(
                'start' => $periodStart,
                'end' => $periodEnd,
                'title' => $title,
                'findings' => $fields['findings'],
                'problems' => $fields['problems'],
                'next_plan' => $fields['next_plan'],
                'figures' => json_encode($figures, JSON_UNESCAPED_UNICODE),
                'id' => $id,
                'user' => (int) $this->Users->userid,
            ));
            return $id;
        }
        $this->query('INSERT INTO smartlab_period_reports (team_id, user_id, period_start, period_end, title, findings, problems, next_plan, figure_json, status)
            VALUES (:team, :user, :start, :end, :title, :findings, :problems, :next_plan, :figures, "draft")', array(
            'team' => (int) $this->Users->team,
            'user' => (int) $this->Users->userid,
            'start' => $periodStart,
            'end' => $periodEnd,
            'title' => $title,
            'findings' => $fields['findings'],
            'problems' => $fields['problems'],
            'next_plan' => $fields['next_plan'],
            'figures' => json_encode($figures, JSON_UNESCAPED_UNICODE),
        ));
        return (int) $this->Db->lastInsertId();
    }

    public function submit(int $id): void
    {
        $existing = $this->readOne($id);
        if (!$existing['is_owner']) {
            throw new AppException('只能提交自己的汇报。');
        }
        if (($existing['status'] ?? '') === 'submitted') {
            throw new AppException('该汇报已经提交。');
        }
        if (trim((string) ($existing['findings'] ?? '')) === '' && $existing['figures'] === array()) {
            throw new AppException('请至少填写主要发现，或选择一张 Figure 后再提交。');
        }
        $this->query('UPDATE smartlab_period_reports SET status = "submitted", submitted_at = UTC_TIMESTAMP()
            WHERE id = :id AND user_id = :user AND status = "draft"', array('id' => $id, 'user' => (int) $this->Users->userid));
    }

    /** @param array<int, mixed> $uploadIds */
    private function collectFigures(array $uploadIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $uploadIds), static fn(int $id): bool => $id > 0)));
        if ($ids === array()) {
            return array();
        }
        $rows = $this->query('SELECT u.id, u.real_name, u.long_name, u.storage, e.id AS experiment_id, e.title AS experiment_title
            FROM uploads u
            JOIN experiments e ON e.id = u.item_id
            WHERE u.type = "experiments" AND u.state = 1 AND e.team = :team AND e.userid = :user
              AND u.id IN (' . implode(',', $ids) . ')', array(
            'team' => (int) $this->Users->team,
            'user' => (int) $this->Users->userid,
        ))->fetchAll();
        $figures = array();
        foreach ($rows as $row) {
            $figures[] = array(
                'upload_id' => (int) $row['id'],
                'real_name' => (string) $row['real_name'],
                'long_name' => (string) $row['long_name'],
                'storage' => (int) $row['storage'],
                'experiment_id' => (int) $row['experiment_id'],
                'experiment_title' => (string) $row['experiment_title'],
            );
        }
        return $figures;
    }

    private function validDate(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private static function formatTime(string $utc): string
    {
        if ($utc === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('m-d H:i');
        } catch (\Exception) {
            return '';
        }
    }
}
