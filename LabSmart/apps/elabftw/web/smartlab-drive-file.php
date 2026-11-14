<?php

/**
 * Download a file from the current user's "我的空间" (session auth).
 *
 * @author SmartLab
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Elabftw;

use Elabftw\Enums\Storage;
use Elabftw\Models\SmartlabDrive;

require_once 'app/init.inc.php';

try {
    $drive = new SmartlabDrive($App->Users);
    $file = $drive->readFile($App->Request->query->getInt('id'));
    if ($file === null) {
        throw new \Elabftw\Exceptions\ResourceNotFoundException();
    }
    header('Content-Type: ' . ($file['mime'] !== '' ? (string) $file['mime'] : 'application/octet-stream'));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string) $file['original_name']));
    header('Content-Length: ' . (int) $file['filesize']);
    $stream = Storage::from((int) $file['storage'])->getStorage()->getFs()->readStream((string) $file['long_name']);
    fpassthru($stream);
    fclose($stream);
} catch (\Throwable) {
    http_response_code(404);
    echo '文件不存在或无权访问。';
}
exit;
