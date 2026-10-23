<?php
declare(strict_types=1);
// Помощник первой настройки: находит chat_id и показывает его на экране.
// Открывать: https://ваш-сайт/api/setup.php
// Сам отключается, как только в config.php заполнен chat_id. После настройки файл можно удалить.
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$cfg = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : null;
if (!is_array($cfg) || empty($cfg['token'])) {
    http_response_code(500);
    exit('В config.php не указан token.');
}
if (!empty($cfg['chat_id'])) {
    http_response_code(404);
    exit('Not found');
}

function tg(string $token, string $method, array $params = []): array {
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return is_array($json) ? $json : ['ok' => false, 'description' => $err ?: 'Нет ответа от Telegram (проверьте, что на хостинге открыт доступ к api.telegram.org)'];
}

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$me = tg($cfg['token'], 'getMe');
$upd = $me['ok'] ? tg($cfg['token'], 'getUpdates', ['limit' => 100, 'timeout' => 0]) : ['ok' => false];

$chats = [];
if (!empty($upd['ok'])) {
    foreach ($upd['result'] as $u) {
        foreach (['message', 'edited_message', 'channel_post', 'my_chat_member'] as $k) {
            $c = $u[$k]['chat'] ?? null;
            if (is_array($c) && isset($c['id'])) {
                $title = $c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                if (!empty($c['username'])) { $title .= ' (@' . $c['username'] . ')'; }
                $chats[(string) $c['id']] = ['type' => $c['type'] ?? '?', 'title' => $title];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Настройка Telegram</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:32px auto;padding:0 16px}
code{background:#eee;padding:2px 6px;border-radius:4px;font-size:1.1em}
.ok{color:#0a7a2f}.bad{color:#b00020}li{margin:8px 0}</style></head><body>
<h1>Настройка Telegram</h1>
<?php if (!$me['ok']): ?>
  <p class="bad"><b>Токен не работает:</b> <?= $h((string) ($me['description'] ?? 'неизвестная ошибка')) ?></p>
  <p>Проверьте <code>token</code> в config.php (его выдаёт @BotFather).</p>
<?php else: ?>
  <p class="ok">✅ Бот найден: <b>@<?= $h((string) ($me['result']['username'] ?? '')) ?></b></p>
  <?php if (empty($upd['ok'])): ?>
    <p class="bad"><b>getUpdates не сработал:</b> <?= $h((string) ($upd['description'] ?? '')) ?></p>
    <p>Если там сказано про webhook, откройте в браузере
      <code>https://api.telegram.org/bot&lt;ТОКЕН&gt;/deleteWebhook</code> и обновите эту страницу.</p>
  <?php elseif (!$chats): ?>
    <p class="bad">Бот пока не получал сообщений.</p>
    <ol><li>Откройте бота <b>@<?= $h((string) ($me['result']['username'] ?? '')) ?></b> в Telegram.</li>
    <li>Нажмите <b>Start</b> (или отправьте <code>/start</code>). Бот не может написать первым, пока вы не начнёте диалог.</li>
    <li>Обновите эту страницу.</li></ol>
  <?php else: ?>
    <p>Найденные чаты. Скопируйте нужный <b>chat_id</b> в <code>api/config.php</code>:</p>
    <ul><?php foreach ($chats as $id => $c): ?>
      <li><code><?= $h((string) $id) ?></code> — <?= $h($c['type']) ?>, <?= $h($c['title']) ?></li>
    <?php endforeach; ?></ul>
    <p>Для личного чата это обычно положительное число, для группы — отрицательное (<code>-100…</code>).</p>
  <?php endif; ?>
<?php endif; ?>
</body></html>
