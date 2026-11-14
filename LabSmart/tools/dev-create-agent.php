<?php
/**
 * 开发/演示用：清理并创建一个 Agent 设备令牌。
 * 令牌写入 /tmp/agent-token.txt（不回显），供 systemd 服务配置使用。
 *   docker cp tools/dev-create-agent.php smartlab-workbench-web:/elabftw/cache/
 *   docker exec smartlab-workbench-web php /elabftw/cache/dev-create-agent.php "CFX96-01 仪器电脑" 2
 */
require '/elabftw/vendor/autoload.php';

use Elabftw\Elabftw\Db;
use Elabftw\Models\SmartlabDataCenter;
use Elabftw\Models\Users\Users;

$name = $argv[1] ?? 'CFX96-01 仪器电脑';
$recipient = (int) ($argv[2] ?? 2);

$Db = Db::getConnection();
// 演示环境：清理同名旧设备（无数据包/回执时）
$cleanRecipients = $Db->prepare('DELETE r FROM smartlab_agent_recipients r JOIN smartlab_agents a ON a.id = r.agent_id WHERE a.name = :name');
$cleanRecipients->bindValue(':name', $name);
$Db->execute($cleanRecipients);
$cleanAgents = $Db->prepare('DELETE FROM smartlab_agents WHERE name = :name');
$cleanAgents->bindValue(':name', $name);
$Db->execute($cleanAgents);

$center = new SmartlabDataCenter(new Users(1, 1));
$created = $center->createAgent($name, $recipient);
file_put_contents('/tmp/agent-token.txt', $created['token']);
echo 'agent created id=' . $created['id'] . ', token saved to /tmp/agent-token.txt' . PHP_EOL;
