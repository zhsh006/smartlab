<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2025 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Elabftw;

use Elabftw\Exceptions\AppException;
use Elabftw\Models\Items;
use Elabftw\Models\ResourcesCategories;
use Elabftw\Models\SmartlabScheduler;
use Exception;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scheduler to book resources
 */
require_once 'app/init.inc.php';

$Response = new Response();

try {
    $Response->prepare($Request);
    $Items = new Items($App->Users);
    $ResourcesCategories = new ResourcesCategories($App->Teams);
    // only the bookable categories
    $bookableItemsArr = $Items->readBookable();
    $categoriesOfBookableItems = array_column($bookableItemsArr, 'category');
    $allCategories = $ResourcesCategories->readAll();
    $bookableCategories = array_filter(
        $allCategories,
        fn($a): bool => in_array($a['id'], $categoriesOfBookableItems, true),
    );
    $SmartlabScheduler = new SmartlabScheduler($App->Users);
    $portalView = SmartlabPortalView::resolve($App->Request, $App->Session, $App->Users);
    $isSmartlabAjax = $App->Request->request->getBoolean('smartlab_ajax') || $App->Request->query->getBoolean('smartlab_ajax');
    $bookingDay = $App->Request->request->getString('booking_date') ?: ($App->Request->query->getString('smartlab_day') ?: date('Y-m-d'));
    $buildAjaxPayload = static function (string $message = '', array $extra = array()) use ($SmartlabScheduler, &$bookingDay): array {
        return array_merge(array(
            'ok' => true,
            'message' => $message,
            'booking_day' => $bookingDay,
            'overview' => $SmartlabScheduler->readOverview(),
            'upcoming_bookings' => $SmartlabScheduler->readUpcomingBookings(),
            'my_upcoming_bookings' => $SmartlabScheduler->readUpcomingBookings(6, true),
            'hot_items' => $SmartlabScheduler->readHotItems(),
            'bookable_resources' => $SmartlabScheduler->readBookableResourcesForQuickForm(),
            'linkable_experiments' => $SmartlabScheduler->readLinkableExperiments(),
            'manageable_bookings' => $SmartlabScheduler->readMyManageableBookings(),
            'day_bookings' => $SmartlabScheduler->readDayBookings($bookingDay),
        ), $extra);
    };

    if ($App->Request->isMethod('GET') && $isSmartlabAjax) {
        $Response = new JsonResponse($buildAjaxPayload());
        $Response->send();
        exit;
    }

    if ($App->Request->isMethod('POST') && $App->Request->request->has('smartlab_action')) {
        $action = trim($App->Request->request->getString('smartlab_action'));
        try {
            if ($action === 'create_resource') {
                if (!SmartlabPortalView::canManageResources($App->Users, $portalView)) {
                    throw new RuntimeException('当前账号不能新建仪器资源。');
                }
                $itemId = $SmartlabScheduler->createBookableResource(
                    trim($App->Request->request->getString('resource_title')),
                    trim($App->Request->request->getString('resource_category')),
                    trim($App->Request->request->getString('resource_location')),
                    trim($App->Request->request->getString('resource_description')),
                    $App->Request->request->getInt('book_max_minutes'),
                    $App->Request->request->getInt('booking_window_days'),
                    $App->Request->request->getInt('book_max_slots'),
                    $App->Request->request->getBoolean('book_can_overlap'),
                    $App->Request->request->getBoolean('book_is_cancellable'),
                );
                $message = sprintf('仪器资源已创建，并已设为可预约（#%d）。', $itemId);
                if ($isSmartlabAjax) {
                    $Response = new JsonResponse($buildAjaxPayload($message, array('created_item_id' => $itemId)));
                    $Response->send();
                    exit;
                }
                $App->Session->getFlashBag()->add('ok', $message);
                $Response = new RedirectResponse('/scheduler.php?smartlab_view=' . $portalView . '&items[]=' . $itemId);
                $Response->send();
                exit;
            }

            if ($action === 'create_booking') {
                $eventId = $SmartlabScheduler->createQuickBooking(
                    $App->Request->request->getInt('item_id'),
                    $App->Request->request->getString('booking_date'),
                    $App->Request->request->getString('start_time'),
                    $App->Request->request->getString('end_time'),
                    trim($App->Request->request->getString('title')),
                    $App->Request->request->getInt('experiment_id') ?: null,
                );
                $message = sprintf('预约已创建（#%d）。', $eventId);
                if ($isSmartlabAjax) {
                    $Response = new JsonResponse($buildAjaxPayload($message, array('created_event_id' => $eventId)));
                    $Response->send();
                    exit;
                }
                $App->Session->getFlashBag()->add('ok', $message);
                $Response = new RedirectResponse('/scheduler.php?smartlab_view=' . $portalView . '&items[]=' . $App->Request->request->getInt('item_id') . '&start=' . $App->Request->request->getString('booking_date'));
                $Response->send();
                exit;
            }

            if ($action === 'update_booking') {
                $SmartlabScheduler->updateQuickBooking(
                    $App->Request->request->getInt('event_id'),
                    $App->Request->request->getString('booking_date'),
                    $App->Request->request->getString('start_time'),
                    $App->Request->request->getString('end_time'),
                    trim($App->Request->request->getString('title')),
                    $App->Request->request->getInt('experiment_id') ?: null,
                );
                $message = '预约已更新。';
                if ($isSmartlabAjax) {
                    $Response = new JsonResponse($buildAjaxPayload($message));
                    $Response->send();
                    exit;
                }
                $App->Session->getFlashBag()->add('ok', $message);
                $Response = new RedirectResponse('/scheduler.php?smartlab_view=' . $portalView . '&start=' . $App->Request->request->getString('booking_date'));
                $Response->send();
                exit;
            }

            if ($action === 'cancel_booking') {
                $SmartlabScheduler->cancelQuickBooking($App->Request->request->getInt('event_id'));
                $message = '预约已取消。';
                if ($isSmartlabAjax) {
                    $Response = new JsonResponse($buildAjaxPayload($message));
                    $Response->send();
                    exit;
                }
                $App->Session->getFlashBag()->add('ok', $message);
                $Response = new RedirectResponse('/scheduler.php?smartlab_view=' . $portalView);
                $Response->send();
                exit;
            }
        } catch (AppException | RuntimeException $e) {
            if ($isSmartlabAjax) {
                $Response = new JsonResponse(array(
                    'ok' => false,
                    'message' => $e->getMessage(),
                ), Response::HTTP_BAD_REQUEST);
                $Response->send();
                exit;
            }
            $App->Session->getFlashBag()->add('ko', $e->getMessage());
            $Response = new RedirectResponse('/scheduler.php?smartlab_view=' . $portalView);
            $Response->send();
            exit;
        }
    }
    $isMentorView = $portalView === SmartlabPortalView::Mentor;
    $template = 'scheduler.html';
    $renderArr = array(
        'bookableCategories' => $bookableCategories,
        'itemsArr' => $bookableItemsArr,
        'pageTitle' => _('Scheduler'),
        'smartlabSchedulerOverview' => $SmartlabScheduler->readOverview(),
        'smartlabUpcomingBookingsArr' => $SmartlabScheduler->readUpcomingBookings(),
        'smartlabMyUpcomingBookingsArr' => $SmartlabScheduler->readUpcomingBookings(6, true),
        'smartlabHotItemsArr' => $SmartlabScheduler->readHotItems(),
        'smartlabBookableResourcesArr' => $SmartlabScheduler->readBookableResourcesForQuickForm(),
        'smartlabLinkableExperimentsArr' => $SmartlabScheduler->readLinkableExperiments(),
        'smartlabManageableBookingsArr' => $SmartlabScheduler->readMyManageableBookings(),
        'smartlabDayBookingsArr' => $SmartlabScheduler->readDayBookings($bookingDay),
        'smartlabBookingDay' => $bookingDay,
        'smartlabIsMentorView' => $isMentorView,
        'smartlabShowAdvancedScheduler' => $App->Request->query->getBoolean('smartlab_advanced'),
    );

    $Response->setContent($App->render($template, $renderArr));
} catch (AppException $e) {
    $Response = $e->getResponseFromException($App);
} catch (Exception $e) {
    $Response = $App->getResponseFromException($e);
} finally {
    $Response->send();
}
