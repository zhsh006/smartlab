<?php
declare(strict_types=1);
namespace Elabftw\Elabftw;
use Elabftw\Controllers\WbConditionsController;
use Elabftw\Exceptions\AppException;
use Symfony\Component\HttpFoundation\Response;
require_once 'app/init.inc.php';
$Response = new Response();
try { $Response = (new WbConditionsController($App))->getResponse(); }
catch (AppException $error) { $Response = $error->getResponseFromException($App); }
catch (\Exception $error) { $Response = $App->getResponseFromException($error); }
finally { $Response->send(); }
