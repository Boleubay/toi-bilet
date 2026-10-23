<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    out(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$cfg = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : null;
if (!is_array($cfg) || empty($cfg['token']) || empty($cfg['chat_id'])) {
    out(500, ['ok' => false, 'error' => 'Сервер бапталмаған.']);
}

// Имена служебных файлов содержат хеш, чтобы их нельзя было угадать, даже если .htaccess не работает
$suffix  = substr(hash('sha256', 'toi' . $cfg['token']), 0, 12);
$logFile = __DIR__ . '/errors_' . $suffix . '.log';
$dbFile  = __DIR__ . '/responses_' . $suffix . '.jsonl';
$log = function (string $m) use ($logFile): void {
    @file_put_contents($logFile, date('c') . ' ' . $m . "\n", FILE_APPEND | LOCK_EX);
};

$data = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
if (!is_array($data)) {
    out(400, ['ok' => false, 'error' => 'Қате сұраныс.']);
}

// Ловушка для ботов: настоящий человек это поле не видит и не заполняет
if (!empty($data['website'])) {
    out(200, ['ok' => true]);
}

function clean($v, int $max): string {
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '';
    return trim(mb_substr($v, 0, $max));
}

$name   = clean($data['name'] ?? '', 80);
$wish   = clean($data['wish'] ?? '', 500);
$attend = clean($data['attend'] ?? '', 60);
$guests = max(1, min(10, (int) ($data['guests'] ?? 1)));
if ($name === '') {
    out(400, ['ok' => false, 'error' => 'Аты-жөніңізді жазыңыз.']);
}
$yes = 'Иә, келемін';
$no  = 'Өкінішке қарай, келе алмаймын';
if (!in_array($attend, [$yes, $no], true)) {
    $attend = '—';
}

// Ограничение: не более 5 отправок в час с одного IP
$limit = 5; $window = 3600; $now = time();
$file = sys_get_temp_dir() . '/toi_rl_' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $cfg['token']) . '.json';
$fh = fopen($file, 'c+');
if ($fh && flock($fh, LOCK_EX)) {
    $times = json_decode((string) stream_get_contents($fh), true);
    $times = array_values(array_filter(is_array($times) ? $times : [], fn($t) => is_int($t) && $t > $now - $window));
    if (count($times) >= $limit) {
        flock($fh, LOCK_UN); fclose($fh);
        out(429, ['ok' => false, 'error' => 'Тым көп сұраныс. Кейінірек қайталаңыз.']);
    }
    $times[] = $now;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($times));
    flock($fh, LOCK_UN);
}
if ($fh) { fclose($fh); }

$time = (new DateTime('now', new DateTimeZone('Asia/Aqtau')))->format('d.m.Y H:i');

// Резервная копия: ответ гостя сохраняется на сервере, даже если Telegram временно недоступен
$saved = @file_put_contents($dbFile, json_encode([
    'time' => $time, 'name' => $name, 'attend' => $attend,
    'guests' => $attend === $no ? 0 : $guests, 'wish' => $wish,
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;

$e = fn(string $s): string => htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
$icon = $attend === $yes ? '✅' : ($attend === $no ? '❌' : '❔');
$text = "💌 <b>Жаңа жауап</b>\n\n"
      . "👤 <b>Аты-жөні:</b> " . $e($name) . "\n"
      . $icon . " <b>Қатысу:</b> " . $e($attend) . "\n"
      . "👥 <b>Қонақ саны:</b> " . ($attend === $no ? '—' : $guests)
      . ($wish !== '' ? "\n\n💬 <b>Тілек:</b>\n" . $e($wish) : '')
      . "\n\n🕒 " . $time;

function tg_send(string $token, string $chatId, string $text): array {
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    $ok = $status === 200 && is_array($json) && !empty($json['ok']);
    $err = $curlErr !== '' ? $curlErr : (is_array($json) ? (string) ($json['description'] ?? '') : '');
    return ['ok' => $ok, 'status' => $status, 'error' => $err];
}

$res = tg_send((string) $cfg['token'], (string) $cfg['chat_id'], $text);
if (!$res['ok']) {
    usleep(500000); // одна повторная попытка
    $res = tg_send((string) $cfg['token'], (string) $cfg['chat_id'], $text);
}

if (!$res['ok']) {
    $log('Telegram error: HTTP ' . $res['status'] . ' ' . $res['error']);
    // Гостю показываем успех, если ответ сохранён на сервере: он не потерян
    if ($saved) {
        out(200, ['ok' => true]);
    }
    out(502, ['ok' => false, 'error' => 'Жіберу мүмкін болмады. Кейінірек қайталаңыз.']);
}
out(200, ['ok' => true]);
