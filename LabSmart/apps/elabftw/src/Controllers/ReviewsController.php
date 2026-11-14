<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2026 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\SmartlabReviews;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Override;

final class ReviewsController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canAccessReviewCenter($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '审核中心仅对助理端和导师端开放。');
            return new RedirectResponse('/dashboard.php');
        }

        if ($this->app->Request->isMethod('POST')) {
            if (!SmartlabPortalView::canManageReviewDecisions($this->app->Users, $portalView)) {
                $this->app->Session->getFlashBag()->add('ko', '审核裁决仅由导师端执行。');
                return new RedirectResponse('/reviews.php?smartlab_view=' . $portalView);
            }

            $flowId = $this->app->Request->request->getInt('flow_id');
            $decision = trim($this->app->Request->request->getString('decision'));
            $comment = trim($this->app->Request->request->getString('comment'));

            if ($flowId > 0 && !empty($decision)) {
                $Reviews = new SmartlabReviews($this->app->Users);
                $Db = \Elabftw\Elabftw\Db::getConnection();
                $Db->q('START TRANSACTION');
                try { $Reviews->applyDecision($flowId, $decision, $comment); $Db->q('COMMIT'); }
                catch (\Throwable $e) { $Db->q('ROLLBACK'); throw $e; }
                $this->app->Session->getFlashBag()->add('ok', '审核结果已保存。');
            }

            return new RedirectResponse('/reviews.php?smartlab_view=' . $portalView);
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'reviews.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '导师待办';
    }

    #[Override]
    protected function getData(): array
    {
        $history = $this->app->Request->query->getBoolean('history');
        $teamScope = $this->app->Users->isAdmin && $this->app->Request->query->getString('scope') === 'team';
        $rows = (new SmartlabReviews($this->app->Users))->readAll(!$teamScope, $history);
        if (!$history) { $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === 'submitted')); }
        return array_merge(parent::getData(), array('reviewsArr' => $rows, 'notebookReviewHistory' => $history, 'notebookReviewTeam' => $teamScope));
    }
}
