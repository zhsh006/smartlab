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

use DateTimeImmutable;
use Elabftw\Enums\Action;
use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Models\Users\Users;
use PDO;
use RuntimeException;

use function preg_match;
use function sprintf;
use function trim;

final class SmartlabScheduler extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
    }

    public function readOverview(): array
    {
        $sql = 'SELECT
                (
                    SELECT COUNT(*)
                    FROM items
                    WHERE team = :team
                      AND state = 1
                      AND is_bookable = 1
                ) AS bookable_item_count,
                (
                    SELECT COUNT(*)
                    FROM team_events AS tev
                    INNER JOIN items AS i ON i.id = tev.item
                    WHERE tev.team = :team
                      AND i.is_bookable = 1
                      AND tev.start >= NOW()
                      AND tev.start < DATE_ADD(NOW(), INTERVAL 7 DAY)
                ) AS upcoming_booking_count,
                (
                    SELECT COUNT(*)
                    FROM team_events
                    WHERE team = :team
                      AND userid = :userid
                      AND start >= NOW()
                      AND start < DATE_ADD(NOW(), INTERVAL 7 DAY)
                ) AS my_upcoming_booking_count,
                (
                    SELECT COUNT(DISTINCT tev.item)
                    FROM team_events AS tev
                    INNER JOIN items AS i ON i.id = tev.item
                    WHERE tev.team = :team
                      AND i.is_bookable = 1
                      AND tev.start >= NOW()
                      AND tev.start < DATE_ADD(NOW(), INTERVAL 7 DAY)
                ) AS active_item_count';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetch();
    }

    public function readUpcomingBookings(int $limit = 6, bool $onlyMine = false): array
    {
        $userSql = $onlyMine ? 'AND tev.userid = :userid' : '';
        $sql = 'SELECT
                tev.id,
                tev.start,
                tev.end,
                tev.title,
                tev.userid,
                i.id AS item_id,
                i.title AS item_title,
                ic.title AS category_title,
                e.id AS experiment_id,
                e.title AS experiment_title,
                CONCAT(u.firstname, " ", u.lastname) AS booker_name
            FROM team_events AS tev
            INNER JOIN items AS i ON i.id = tev.item
            LEFT JOIN items_categories AS ic ON ic.id = i.category
            LEFT JOIN experiments AS e ON e.id = tev.experiment
            LEFT JOIN users AS u ON u.userid = tev.userid
            WHERE tev.team = :team
              AND i.is_bookable = 1
              AND tev.start >= NOW()
              ' . $userSql . '
            ORDER BY tev.start ASC, tev.id ASC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($onlyMine) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readHotItems(int $limit = 6): array
    {
        $sql = 'SELECT
                i.id,
                i.title,
                ic.title AS category_title,
                COUNT(tev.id) AS booking_count,
                MIN(tev.start) AS next_booking_at
            FROM items AS i
            LEFT JOIN items_categories AS ic ON ic.id = i.category
            LEFT JOIN team_events AS tev
                ON tev.item = i.id
               AND tev.team = i.team
               AND tev.start >= NOW()
               AND tev.start < DATE_ADD(NOW(), INTERVAL 30 DAY)
            WHERE i.team = :team
              AND i.state = 1
              AND i.is_bookable = 1
            GROUP BY i.id
            ORDER BY booking_count DESC, next_booking_at ASC, i.title ASC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readBookableResourcesForQuickForm(): array
    {
        $sql = 'SELECT
                i.id,
                i.title,
                ic.title AS category_title,
                i.book_max_minutes,
                i.book_max_slots,
                i.book_can_overlap,
                i.book_is_cancellable,
                i.book_cancel_minutes,
                i.booking_window_days,
                COUNT(tev.id) AS upcoming_booking_count,
                MIN(tev.start) AS next_booking_at
            FROM items AS i
            LEFT JOIN items_categories AS ic ON ic.id = i.category
            LEFT JOIN team_events AS tev
                ON tev.item = i.id
               AND tev.team = i.team
               AND tev.start >= NOW()
            WHERE i.team = :team
              AND i.state = 1
              AND i.is_bookable = 1
            GROUP BY i.id
            ORDER BY ic.title ASC, i.title ASC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function createBookableResource(
        string $title,
        string $categoryTitle,
        string $location,
        string $description,
        int $bookMaxMinutes,
        int $bookingWindowDays,
        int $bookMaxSlots,
        bool $canOverlap,
        bool $isCancellable,
    ): int {
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('请填写仪器/资源名称。');
        }

        $categoryId = $this->getOrCreateResourceCategory($categoryTitle !== '' ? $categoryTitle : '仪器设备');
        $bodyParts = array();
        if (trim($location) !== '') {
            $bodyParts[] = '<p><strong>位置：</strong>' . htmlspecialchars(trim($location), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        if (trim($description) !== '') {
            $bodyParts[] = '<p><strong>说明：</strong>' . nl2br(htmlspecialchars(trim($description), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }

        $itemId = (new Items($this->Users))->create(
            title: $title,
            body: implode('', $bodyParts),
            category: $categoryId,
        );

        $sql = 'UPDATE items
            SET is_bookable = 1,
                book_max_minutes = :book_max_minutes,
                book_max_slots = :book_max_slots,
                book_can_overlap = :book_can_overlap,
                book_is_cancellable = :book_is_cancellable,
                booking_window_days = :booking_window_days
            WHERE id = :id AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':id', $itemId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':book_max_minutes', max(0, $bookMaxMinutes), PDO::PARAM_INT);
        $req->bindValue(':book_max_slots', max(0, $bookMaxSlots), PDO::PARAM_INT);
        $req->bindValue(':book_can_overlap', $canOverlap ? 1 : 0, PDO::PARAM_INT);
        $req->bindValue(':book_is_cancellable', $isCancellable ? 1 : 0, PDO::PARAM_INT);
        $req->bindValue(':booking_window_days', min(255, max(0, $bookingWindowDays)), PDO::PARAM_INT);
        $this->Db->execute($req);

        return $itemId;
    }

    public function readLinkableExperiments(int $limit = 80): array
    {
        $userSql = $this->Users->isAdmin ? '' : 'AND e.userid = :userid';
        $sql = 'SELECT
                e.id,
                e.title,
                e.date,
                e.modified_at
            FROM experiments AS e
            WHERE e.team = :team
              AND e.state = 1
              ' . $userSql . '
            ORDER BY e.modified_at DESC, e.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if (!$this->Users->isAdmin) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function createQuickBooking(int $itemId, string $date, string $startTime, string $endTime, string $title, ?int $experimentId): int
    {
        $start = $this->buildDateTime($date, $startTime, '开始时间');
        $end = $this->buildDateTime($date, $endTime, '结束时间');
        $this->ensureEndAfterStart($start, $end);
        if ($experimentId !== null) {
            $this->assertExperimentInTeam($experimentId);
        }

        $Items = new Items($this->Users, $itemId);
        $Scheduler = new Scheduler($Items);
        $eventId = $Scheduler->postAction(Action::Create, array(
            'start' => $start,
            'end' => $end,
            'title' => $title,
        ));

        if ($experimentId !== null) {
            $this->setEventExperiment((int) $eventId, $experimentId);
        }

        return (int) $eventId;
    }

    public function updateQuickBooking(int $eventId, string $date, string $startTime, string $endTime, string $title, ?int $experimentId): void
    {
        $start = $this->buildDateTime($date, $startTime, '开始时间');
        $end = $this->buildDateTime($date, $endTime, '结束时间');
        $this->ensureEndAfterStart($start, $end);
        if ($experimentId !== null) {
            $this->assertExperimentInTeam($experimentId);
        }

        $Scheduler = new Scheduler(new Items($this->Users), $eventId);
        $Scheduler->patch(Action::Update, array(
            'target' => 'datetime',
            'start' => $start,
            'end' => $end,
        ));
        $Scheduler->patch(Action::Update, array(
            'target' => 'title',
            'content' => $title,
        ));
        $this->setEventExperiment($eventId, $experimentId);
    }

    public function cancelQuickBooking(int $eventId): void
    {
        $Scheduler = new Scheduler(new Items($this->Users), $eventId);
        $Scheduler->destroy();
    }

    public function readMyManageableBookings(int $limit = 20): array
    {
        $sql = 'SELECT
                tev.id,
                tev.start,
                tev.end,
                tev.title,
                tev.experiment,
                i.id AS item_id,
                i.title AS item_title,
                ic.title AS category_title,
                e.title AS experiment_title,
                TIMESTAMPDIFF(MINUTE, tev.start, tev.end) AS duration_minutes
            FROM team_events AS tev
            INNER JOIN items AS i ON i.id = tev.item
            LEFT JOIN items_categories AS ic ON ic.id = i.category
            LEFT JOIN experiments AS e ON e.id = tev.experiment
            WHERE tev.team = :team
              AND tev.userid = :userid
              AND tev.start >= NOW()
            ORDER BY tev.start ASC, tev.id ASC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readDayBookings(string $date): array
    {
        $date = $this->normalizeDate($date);
        $sql = 'SELECT
                tev.id,
                tev.start,
                tev.end,
                tev.title,
                i.id AS item_id,
                i.title AS item_title,
                ic.title AS category_title,
                CONCAT(u.firstname, " ", u.lastname) AS booker_name
            FROM team_events AS tev
            INNER JOIN items AS i ON i.id = tev.item
            LEFT JOIN items_categories AS ic ON ic.id = i.category
            LEFT JOIN users AS u ON u.userid = tev.userid
            WHERE tev.team = :team
              AND i.is_bookable = 1
              AND tev.start < DATE_ADD(:date_start, INTERVAL 1 DAY)
              AND tev.end > :date_end
            ORDER BY tev.start ASC, i.title ASC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':date_start', $date);
        $req->bindValue(':date_end', $date);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    private function buildDateTime(string $date, string $time, string $label): string
    {
        $date = $this->normalizeDate($date);
        $time = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            throw new RuntimeException(sprintf('%s格式不正确。', $label));
        }

        return $date . ' ' . $time . ':00';
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        $Date = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($Date === false) {
            throw new RuntimeException('日期格式不正确。');
        }

        return $Date->format('Y-m-d');
    }

    private function getOrCreateResourceCategory(string $title): int
    {
        $title = trim($title) !== '' ? trim($title) : '仪器设备';
        $sql = 'SELECT id FROM items_categories WHERE team = :team AND title = :title AND state = 1 LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':title', $title);
        $this->Db->execute($req);
        $id = (int) $req->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $sql = 'INSERT INTO items_categories (team, title, color, is_private) VALUES (:team, :title, :color, 0)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':title', $title);
        $req->bindValue(':color', '3366cc');
        $this->Db->execute($req);

        return $this->Db->lastInsertId();
    }

    private function ensureEndAfterStart(string $start, string $end): void
    {
        $Start = new DateTimeImmutable($start);
        $End = new DateTimeImmutable($end);
        if ($End <= $Start) {
            throw new RuntimeException('结束时间必须晚于开始时间。');
        }
    }

    private function assertExperimentInTeam(int $experimentId): void
    {
        $sql = 'SELECT id, userid FROM experiments WHERE team = :team AND state = 1 AND id = :id LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        try {
            $experiment = $this->Db->fetch($req);
        } catch (ResourceNotFoundException) {
            throw new RuntimeException('关联实验不存在或不属于当前团队。');
        }
        if (!$this->Users->isAdmin && (int) $experiment['userid'] !== $this->Users->userid) {
            throw new RuntimeException('只能关联你自己的实验记录。');
        }
    }

    private function setEventExperiment(int $eventId, ?int $experimentId): void
    {
        $sql = 'UPDATE team_events SET experiment = :experiment WHERE id = :id AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':id', $eventId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($experimentId === null) {
            $req->bindValue(':experiment', null, PDO::PARAM_NULL);
        } else {
            $req->bindValue(':experiment', $experimentId, PDO::PARAM_INT);
        }
        $this->Db->execute($req);
    }
}
