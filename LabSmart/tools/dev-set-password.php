<?php
/**
 * 开发/演示用：为指定邮箱设置密码（使用应用自身的密码哈希逻辑）。
 *   docker cp tools/dev-set-password.php smartlab-workbench-web:/elabftw/cache/
 *   docker exec smartlab-workbench-web php /elabftw/cache/dev-set-password.php <email> <password>
 */
require '/elabftw/vendor/autoload.php';

use Elabftw\Elabftw\Db;
use Elabftw\Params\UserParams;

$email = $argv[1] ?? 'preview.student@local.test';
$password = $argv[2] ?? 'SmartLabDemo2026!';

$Db = Db::getConnection();
$hash = (new UserParams('password', $password))->getStringContent();
$req = $Db->prepare('UPDATE users SET password_hash = :hash, password_modified_at = NOW() WHERE email = :email');
$req->bindValue(':hash', $hash);
$req->bindValue(':email', $email);
$Db->execute($req);
echo 'password set for ' . $email . PHP_EOL;
