<?php

declare(strict_types=1);

namespace Elabftw\Elabftw;

use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

require_once 'app/init.inc.php';

$workerBaseUrl = rtrim((string) (
    getenv('SMARTLAB_IMAGEJ_URL')
    ?: ($_SERVER['SMARTLAB_IMAGEJ_URL'] ?? '')
    ?: ($_ENV['SMARTLAB_IMAGEJ_URL'] ?? '')
    ?: 'http://imagej-worker:8765'
), '/');

try {
    if ($workerBaseUrl === '') {
        throw new Exception('SMARTLAB_IMAGEJ_URL is not configured.');
    }

    $action = trim($Request->query->getString('action'));
    $allowedRoutes = array(
        'health' => '/health',
        'info' => '/api/v1/system/info',
        'ping' => '/health',
        'ping-imagej' => '/api/v1/system/ping-imagej',
        'ihc_analyze' => '/api/v1/analyze/ihc',
    );

    if (!array_key_exists($action, $allowedRoutes)) {
        throw new Exception('Unsupported smartlab imagej action.');
    }

    $targetUrl = $workerBaseUrl . $allowedRoutes[$action];
    $curl = curl_init($targetUrl);
    if ($curl === false) {
        throw new Exception('Unable to initialize request to imagej worker.');
    }

    $method = $Request->getMethod();
    $body = null;
    if ($method === 'POST') {
        $body = $Request->getContent();
    }

    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $action === 'ping-imagej' ? 60 : 12,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_filter(array(
            'Accept: application/json',
            $method === 'POST' ? 'Content-Type: application/json' : null,
        )),
    ));

    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($curl);
    if ($responseBody === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new Exception('ImageJ worker request failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        $decoded = array(
            'ok' => false,
            'status' => $statusCode,
            'raw' => $responseBody,
        );
    }

    $decoded['proxied_by'] = 'smartlab-imagej.php';
    $decoded['worker_action'] = $action;
    $decoded['worker_url'] = $targetUrl;

    (new JsonResponse($decoded, $statusCode > 0 ? $statusCode : Response::HTTP_BAD_GATEWAY))->send();
} catch (Exception $e) {
    (new JsonResponse(array(
        'ok' => false,
        'error' => $e->getMessage(),
        'proxied_by' => 'smartlab-imagej.php',
        'worker_url' => $workerBaseUrl ?? '',
    ), Response::HTTP_BAD_GATEWAY))->send();
}
