<?php

/**
 * Weekly / biweekly report work area (V3).
 *
 * @author SmartLab
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Elabftw\Exceptions\AppException;
use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\SmartlabPeriodReports;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SmartlabReportsController extends AbstractHtmlController
{
    protected function getTemplate(): string
    {
        return 'smartlab-reports.html';
    }

    protected function getPageTitle(): string
    {
        return '周报汇报';
    }

    #[Override]
    public function getResponse(): Response
    {
        if ($this->app->Request->isMethod('POST')) {
            $Reports = new SmartlabPeriodReports($this->app->Users);
            $action = $this->app->Request->request->getString('action');
            $reportId = $this->app->Request->request->getInt('report_id');
            try {
                if ($action === 'report_feedback') {
                    $Reports->addFeedback($reportId, $this->app->Request->request->getString('feedback'));
                    $this->app->Session->getFlashBag()->add('ok', '反馈已保存，学生可在本汇报中查看。');
                    return new RedirectResponse('/smartlab-reports.php?id=' . $reportId);
                }
                if ($action === 'save_report' || $action === 'submit_report') {
                    $newId = $Reports->save($this->app->Request->request->all(), $reportId > 0 ? $reportId : null);
                    if ($action === 'submit_report') {
                        $Reports->submit($newId);
                        $this->app->Session->getFlashBag()->add('ok', '汇报已提交，当前内容已冻结。');
                    } else {
                        $this->app->Session->getFlashBag()->add('ok', '汇报草稿已保存。');
                    }
                    return new RedirectResponse('/smartlab-reports.php?id=' . $newId);
                }
            } catch (AppException $e) {
                $this->app->Session->getFlashBag()->add('ko', $e->getMessage());
                return new RedirectResponse('/smartlab-reports.php' . ($reportId > 0 ? '?id=' . $reportId : ''));
            }
        }
        return parent::getResponse();
    }

    protected function getData(): array
    {
        $Reports = new SmartlabPeriodReports($this->app->Users);
        $timezone = new DateTimeZone('Asia/Shanghai');
        $report = null;
        $reportId = $this->app->Request->query->getInt('id');
        if ($reportId > 0) {
            $report = $Reports->readOne($reportId);
        }
        if ($report !== null) {
            $periodStart = (string) $report['period_start'];
            $periodEnd = (string) $report['period_end'];
        } else {
            $periodStart = $this->validDate($this->app->Request->query->getString('from'));
            $periodEnd = $this->validDate($this->app->Request->query->getString('to'));
            if ($periodStart === '' || $periodEnd === '' || $periodStart > $periodEnd) {
                $now = new DateTimeImmutable('now', $timezone);
                $periodEnd = $now->format('Y-m-d');
                $periodStart = $now->modify('-13 day')->format('Y-m-d');
            }
        }
        $startUtc = (new DateTimeImmutable($periodStart . ' 00:00:00', $timezone))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = (new DateTimeImmutable($periodEnd . ' 00:00:00', $timezone))
            ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $selectedFigureIds = array();
        foreach ($report['figures'] ?? array() as $figure) {
            $selectedFigureIds[] = (int) $figure['upload_id'];
        }

        return array_merge(parent::getData(), array(
            'report' => $report,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'workItems' => $Reports->readPeriodWork($startUtc, $endUtc),
            'figureCandidates' => $Reports->readPeriodUploads($startUtc, $endUtc),
            'savedReports' => $Reports->readMine(),
            'selectedFigureIds' => $selectedFigureIds,
            'reviewReports' => $Reports->readForReview(),
            'reportFeedback' => $report !== null && $report['status'] === 'submitted' ? $Reports->readFeedback($reportId) : array(),
            'reportMentorView' => SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users) === SmartlabPortalView::Mentor,
        ));
    }

    private function validDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }
}
