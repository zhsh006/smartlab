<?php

declare(strict_types=1);

namespace Elabftw\Elabftw;

use Elabftw\Models\Users\Users;
use PDO;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SmartlabPortalView
{
    public const string Mentor = 'mentor';
    public const string Assistant = 'assistant';
    public const string Student = 'student';

    private const string SESSION_KEY = 'smartlab_portal_view';

    public static function canAccessMentorView(Users $Users): bool
    {
        return $Users->isAdmin || (($Users->userData['is_sysadmin'] ?? 0) === 1);
    }

    public static function canAccessAssistantView(Users $Users): bool
    {
        return self::canAccessMentorView($Users) || self::roleFromAccount($Users) === self::Assistant;
    }

    public static function canAccessReviewCenter(Users $Users, ?string $view = null): bool
    {
        if ($view !== null) {
            return self::canUseRequestedView($Users, $view) && in_array($view, array(self::Mentor, self::Assistant), true);
        }
        return self::canAccessMentorView($Users) || self::canAccessAssistantView($Users);
    }

    public static function canOperateExperiments(Users $Users, ?string $view = null): bool
    {
        return ($view ?? self::roleFromAccount($Users)) === self::Student;
    }

    public static function canManageProjects(Users $Users, ?string $view = null): bool
    {
        return self::canAccessMentorView($Users) && ($view ?? self::roleFromAccount($Users)) === self::Mentor;
    }

    public static function canManageReviewDecisions(Users $Users, ?string $view = null): bool
    {
        return self::canAccessMentorView($Users) && ($view ?? self::roleFromAccount($Users)) === self::Mentor;
    }

    public static function canManageInventory(Users $Users, ?string $view = null): bool
    {
        return self::canAccessAssistantView($Users) && ($view ?? self::roleFromAccount($Users)) === self::Assistant;
    }

    public static function canManageAssayCatalog(Users $Users, ?string $view = null): bool
    {
        return self::canAccessAssistantView($Users) && ($view ?? self::roleFromAccount($Users)) === self::Assistant;
    }

    public static function canManageResources(Users $Users, ?string $view = null): bool
    {
        return self::canAccessAssistantView($Users) && ($view ?? self::roleFromAccount($Users)) === self::Assistant;
    }

    public static function canAccessOperations(Users $Users, ?string $view = null): bool
    {
        return self::canAccessReviewCenter($Users, $view);
    }

    public static function resolve(Request $Request, SessionInterface $Session, Users $Users): string
    {
        $requested = self::normalize($Request->query->getString('smartlab_view'));
        if ($requested !== null && self::canUseRequestedView($Users, $requested)) {
            $Session->set(self::SESSION_KEY, $requested);
            return $requested;
        }

        $resolved = self::roleFromAccount($Users);
        $stored = self::normalize((string) $Session->get(self::SESSION_KEY, ''));
        if ($stored !== null && self::canUseRequestedView($Users, $stored)) {
            if ($stored === self::Student && $resolved !== self::Student) {
                $Session->set(self::SESSION_KEY, $resolved);
                return $resolved;
            }
            return $stored;
        }

        $Session->set(self::SESSION_KEY, $resolved);
        return $resolved;
    }

    public static function roleLabel(string $view): string
    {
        return match ($view) {
            self::Mentor => '导师端',
            self::Assistant => '助理端',
            default => '学生端',
        };
    }

    private static function normalize(string $view): ?string
    {
        return match ($view) {
            self::Mentor => self::Mentor,
            self::Assistant => self::Assistant,
            self::Student => self::Student,
            default => null,
        };
    }

    private static function roleFromAccount(Users $Users): string
    {
        $userid = (int) ($Users->userData['userid'] ?? $Users->userid ?? 0);
        $team = (int) ($Users->team ?? $Users->userData['team'] ?? 0);
        if (((int) ($Users->userData['is_sysadmin'] ?? 0) === 1)) {
            return self::Mentor;
        }

        $Db = Db::getConnection();
        $sql = 'SELECT is_owner, is_admin FROM users2teams WHERE users_id = :userid AND teams_id = :team LIMIT 1';
        $req = $Db->prepare($sql);
        $req->bindValue(':userid', $userid, PDO::PARAM_INT);
        $req->bindValue(':team', $team, PDO::PARAM_INT);
        $Db->execute($req);
        $row = $req->fetch();

        $isOwner = (int) ($row['is_owner'] ?? 0) === 1;
        $isAdmin = (int) ($row['is_admin'] ?? 0) === 1;

        if ($isOwner) {
            return self::Mentor;
        }

        if ($isAdmin) {
            return self::Assistant;
        }

        $teamCondition = $team > 0 ? 'AND p.team_id = :team' : '';
        $assistantReq = $Db->prepare('SELECT 1
            FROM smartlab_project_members AS pm
            INNER JOIN smartlab_projects AS p ON p.id = pm.project_id
            WHERE pm.user_id = :userid
              ' . $teamCondition . '
              AND pm.role = "助理"
            LIMIT 1');
        $assistantReq->bindValue(':userid', $userid, PDO::PARAM_INT);
        if ($team > 0) {
            $assistantReq->bindValue(':team', $team, PDO::PARAM_INT);
        }
        $Db->execute($assistantReq);
        if ($assistantReq->fetch() !== false) {
            return self::Assistant;
        }

        return self::Student;
    }

    private static function canUseRequestedView(Users $Users, string $view): bool
    {
        return match ($view) {
            self::Mentor => self::canAccessMentorView($Users),
            self::Assistant => self::canAccessAssistantView($Users),
            self::Student => true,
            default => false,
        };
    }
}
