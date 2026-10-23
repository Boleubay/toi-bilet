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

// Запросы только с вашего сайта (если allowed_origin задан)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !empty($cfg['allowed_origin']) && $origin !== $cfg['allowed_origin']) {
    out(403, ['ok' => false, 'error' => 'Forbidden']);
}

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
if (!in_array($attend, ['Иә, келемін', 'Өкінішке қарай, келе алмаймын'], true)) {
    $attend = '—';
}

// Ограничение: не более 5 отправок в час с одного IP
$limit = 5; $window = 3600; $now = time();
$file = sys_get_temp_dir() . '/toi_rl_' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($cfg['token'])) . '.json';
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

$e = fn(string $s): string => htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
$text = "💌 <b>Жаңа жауап</b>\n\n"
      . "👤 <b>Аты-жөні:</b> " . $e($name) . "\n"
      . "✅ <b>Қатысу:</b> " . $e($attend) . "\n"
      . "👥 <b>Қонақ саны:</b> " . $guests
      . ($wish !== '' ? "\n\n💬 <b>Тілек:</b>\n" . $e($wish) : '');

$ch = curl_init('https://api.telegram.org/bot' . $cfg['token'] . '/sendMessage');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'chat_id' => $cfg['chat_id'],
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status !== 200) {
    out(502, ['ok' => false, 'error' => 'Жіберу мүмкін болмады. Кейінірек қайталаңыз.']);
}
out(200, ['ok' => true]);
