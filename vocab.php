<?php
/**
 * WordWise &#8211; Multi-tenant English/Hebrew Vocabulary Quiz
 * Single PHP file. No database. Students each have their own word list + progress.
 */
session_start();

// --- Config -------------------------------------------------------------------
define('DATA_DIR',     __DIR__ . '/wordwise_data/');
define('STUDENTS_FILE', DATA_DIR . 'students.json');
// -- Load credentials from config file ----------------------------------------
$_configFile = __DIR__ . '/wordwise_config.php';
if (!file_exists($_configFile)) {
    die('&#10060; Missing wordwise_config.php &#8212; please create it next to vocab_quiz.php. See instructions.');
}
require_once $_configFile;

foreach ([DATA_DIR] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}

// --- Student registry helpers -------------------------------------------------

function loadStudents(): array {
    if (!file_exists(STUDENTS_FILE)) return [];
    return json_decode(file_get_contents(STUDENTS_FILE), true) ?? [];
}

function saveStudents(array $s): void {
    file_put_contents(STUDENTS_FILE, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function studentSlug(string $name): string {
    return preg_replace('/[^a-z0-9]/', '_', strtolower(trim($name)));
}

function wordsFile(string $slug): string  { return DATA_DIR . $slug . '_words.json'; }
function progressFile(string $slug): string { return DATA_DIR . $slug . '_progress.json'; }

function loadWords(string $slug): array {
    $f = wordsFile($slug);
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?? [];
}

function saveWords(string $slug, array $words): void {
    file_put_contents(wordsFile($slug), json_encode($words, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function loadProgress(string $slug): array {
    $f = progressFile($slug);
    $default = ['level'=>1,'current_set'=>[],'level_missed'=>[],'known'=>[]];
    if (!file_exists($f)) return $default;
    return array_merge($default, json_decode(file_get_contents($f), true) ?? []);
}

function saveProgress(string $slug, array $p): void {
    file_put_contents(progressFile($slug), json_encode($p, JSON_UNESCAPED_UNICODE));
}

// --- Practice / API helpers --------------------------------------------------

function practiceCacheFile(string $slug, array $indexes): string {
    return DATA_DIR . $slug . '_pq_' . md5(implode(',', $indexes)) . '.json';
}

function loadPracticeCache(string $slug, array $indexes): ?array {
    $f = practiceCacheFile($slug, $indexes);
    if (!file_exists($f)) return null;
    if (time() - filemtime($f) > 86400 * 30) { unlink($f); return null; } // 30-day expiry
    return json_decode(file_get_contents($f), true);
}

function savePracticeCache(string $slug, array $indexes, array $data): void {
    file_put_contents(practiceCacheFile($slug, $indexes), json_encode($data, JSON_UNESCAPED_UNICODE));
}

function rateLimitFile(string $slug): string { return DATA_DIR . $slug . '_ratelimit.json'; }
function globalRateLimitFile(): string       { return DATA_DIR . 'global_ratelimit.json'; }

function checkRateLimit(string $slug): array {
    $today = date('Y-m-d');
    // Per-student
    $sf = rateLimitFile($slug);
    $sd = file_exists($sf) ? json_decode(file_get_contents($sf), true) : [];
    $studentCalls = ($sd['date'] ?? '') === $today ? (int)($sd['calls'] ?? 0) : 0;
    // Global
    $gf = globalRateLimitFile();
    $gd = file_exists($gf) ? json_decode(file_get_contents($gf), true) : [];
    $globalCalls  = ($gd['date'] ?? '') === $today ? (int)($gd['calls'] ?? 0) : 0;
    return [
        'student_calls'  => $studentCalls,
        'global_calls'   => $globalCalls,
        'student_ok'     => $studentCalls < DAILY_API_PER_STUDENT,
        'global_ok'      => $globalCalls  < DAILY_API_GLOBAL,
        'ok'             => $studentCalls < DAILY_API_PER_STUDENT && $globalCalls < DAILY_API_GLOBAL,
    ];
}

function incrementRateLimit(string $slug): void {
    $today = date('Y-m-d');
    foreach ([rateLimitFile($slug), globalRateLimitFile()] as $f) {
        $d = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        if (($d['date'] ?? '') !== $today) $d = ['date' => $today, 'calls' => 0];
        $d['calls']++;
        file_put_contents($f, json_encode($d));
    }
}

function resetGlobalRateLimit(): void {
    $f = globalRateLimitFile();
    file_put_contents($f, json_encode(['date' => date('Y-m-d'), 'calls' => 0]));
}

function callAnthropicAPI(array $wordPairs): ?array {
    if (ANTHROPIC_API_KEY === 'YOUR_API_KEY_HERE') return null;
    $pairs = implode(', ', array_map(fn($w) => $w['english'].'='.$w['hebrew'], $wordPairs));
    $prompt = 'For these English=Hebrew word pairs, return ONLY a JSON array, no markdown, no explanation.
For each word provide:
1. A fill-in-the-blank sentence in English (blank="_____", max 10 words, child-friendly)
2. Exactly 3 Hebrew DISTRACTOR words. Critical rules:
   - Must NOT be synonyms or near-synonyms of the correct Hebrew translation
   - Must be clearly different things (e.g. for apple=&#1514;&#1508;&#1493;&#1495; use chair/book/dog, NOT banana/pear)
   - All 3 must be different from each other
Pairs: ' . $pairs . '
JSON format (exactly): [{"e":"word","s":"sentence with _____","d":["h1","h2","h3"]},...]';

    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 800,
        'messages'   => [['role'=>'user','content'=>$prompt]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return null;
    $data = json_decode($resp, true);
    $text = $data['content'][0]['text'] ?? '';
    // Strip markdown fences if present
    $text = preg_replace('/```json|```/', '', $text);
    $questions = json_decode(trim($text), true);
    if (!is_array($questions)) return null;
    return $questions;
}

function selectPracticeWords(string $slug, string $mode, int $count = 10): array {
    $words   = loadWords($slug);
    $p       = loadProgress($slug);
    $ever    = array_map('intval', $p['ever_missed'] ?? []);
    $allIdx  = array_keys($words);
    // Use all available words if fewer than requested
    $count   = min($count, count($words));
    $selected = [];

    if ($mode === 'hard' && !empty($ever)) {
        $pool = $ever;
        shuffle($pool);
        $selected = array_slice($pool, 0, $count);
        if (count($selected) < $count) {
            $rest = array_diff($allIdx, $selected);
            shuffle($rest);
            $selected = array_merge($selected, array_slice(array_values($rest), 0, $count - count($selected)));
        }
    } elseif ($mode === 'mix' && !empty($ever)) {
        $hardPick = array_slice(array_values($ever), 0, (int)ceil($count / 2));
        $rest     = array_diff($allIdx, $hardPick); shuffle($rest);
        $selected = array_merge($hardPick, array_slice(array_values($rest), 0, $count - count($hardPick)));
        shuffle($selected);
    } else {
        $pool = $allIdx; shuffle($pool);
        $selected = array_slice($pool, 0, $count);
    }
    return array_map('intval', $selected);
}

// --- Excel / CSV parser -------------------------------------------------------

function parseExcel(string $filePath, string $ext): array {
    $words = [];
    if (strtolower($ext) === 'csv') {
        $handle = fopen($filePath, 'r');
        if (!$handle) return [];
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        $first = fgetcsv($handle);
        if ($first && isset($first[1]) && strtolower(trim($first[0])) !== 'english') {
            $words[] = ['english' => trim($first[0]), 'hebrew' => trim($first[1])];
        }
        while (($row = fgetcsv($handle)) !== false) {
            if (!empty($row[0]) && !empty($row[1]))
                $words[] = ['english' => trim($row[0]), 'hebrew' => trim($row[1])];
        }
        fclose($handle);
    } else {
        if (!class_exists('ZipArchive')) return [];
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return [];
        $strings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ssXml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $ssXml);
            $ssXml = preg_replace('/<\?xml[^>]*\?>/i', '', $ssXml);
            libxml_use_internal_errors(true);
            $ss = simplexml_load_string($ssXml);
            libxml_clear_errors();
            if ($ss) {
                foreach ($ss->si as $si) {
                    preg_match_all('/<t[^>]*>([^<]*)<\/t>/u', $si->asXML(), $m);
                    $strings[] = html_entity_decode(implode('', $m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml')
                 ?: $zip->getFromName('xl/worksheets/Sheet1.xml');
        $zip->close();
        if (!$sheetXml) return [];
        $sheetXml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $sheetXml);
        $sheetXml = preg_replace('/<\?xml[^>]*\?>/i', '', $sheetXml);
        libxml_use_internal_errors(true);
        $sheet = simplexml_load_string($sheetXml);
        libxml_clear_errors();
        if (!$sheet) return [];
        $sheetData = $sheet->sheetData ?? null;
        if (!$sheetData) return [];
        $isHeader = true;
        foreach ($sheetData->row as $row) {
            $cols = [];
            foreach ($row->c as $cell) {
                $t = (string)($cell['t'] ?? '');
                $v = isset($cell->v) ? (string)$cell->v : '';
                if ($t === 's') $cols[] = $strings[(int)$v] ?? '';
                elseif ($t === 'inlineStr') $cols[] = isset($cell->is->t) ? (string)$cell->is->t : '';
                else $cols[] = $v;
            }
            if ($isHeader) { $isHeader = false; continue; }
            if (!empty($cols[0]) && !empty($cols[1]))
                $words[] = ['english' => trim($cols[0]), 'hebrew' => trim($cols[1])];
        }
    }
    return $words;
}

// --- Routing ------------------------------------------------------------------

$action  = $_GET['action'] ?? ($_POST['action'] ?? '');
$message = '';
$students = loadStudents();
$currentSlug = $_SESSION['student_slug'] ?? '';
$currentStudent = $currentSlug ? ($students[$currentSlug] ?? null) : null;

// -- Logout --------------------------------------------------------------------
if ($action === 'logout') {
    unset($_SESSION['student_slug']);
    header('Location: ?'); exit;
}

// -- Login ---------------------------------------------------------------------
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = studentSlug($_POST['slug'] ?? '');
    $pin  = trim($_POST['pin'] ?? '');
    if ($slug && isset($students[$slug])) {
        $storedPin = $students[$slug]['pin'] ?? '';
        if ($storedPin === '' || $storedPin === $pin) {
            $_SESSION['student_slug'] = $slug;
            header('Location: ?action=quiz'); exit;
        } else {
            $message = '&#10060; Wrong PIN. Try again.';
        }
    } else {
        $message = '&#10060; Student not found.';
    }
}

// -- Admin: add student --------------------------------------------------------
if ($action === 'add_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['student_name'] ?? '');
    $pin  = trim($_POST['student_pin'] ?? '');
    $slug = studentSlug($name);
    if ($name && $slug) {
        if (!isset($students[$slug])) {
            $emoji = !empty($_POST['emoji']) ? $_POST['emoji'] : '&#128102;';
            $students[$slug] = ['name' => $name, 'pin' => $pin, 'slug' => $slug, 'emoji' => $emoji];
            saveStudents($students);
            $students = loadStudents(); // reload so subsequent renders are fresh
            $message = '&#9989; Student "' . htmlspecialchars($name) . '" added!';
        } else {
            $message = '&#9888;&#65039; A student with that name already exists.';
        }
    } else {
        $message = '&#10060; Please enter a valid name.';
    }
    $action = 'admin';
}

// -- Admin: delete student -----------------------------------------------------
if ($action === 'delete_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = studentSlug($_POST['slug'] ?? '');
    if ($slug && isset($students[$slug])) {
        unset($students[$slug]);
        saveStudents($students);
        $students = loadStudents(); // reload
        foreach ([wordsFile($slug), progressFile($slug)] as $f)
            if (file_exists($f)) unlink($f);
        $message = '&#9989; Student deleted.';
    }
    $action = 'admin';
}

// -- Admin: upload words for a student ----------------------------------------
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $students = loadStudents(); // always fresh before checking
    $slug = studentSlug($_POST['slug'] ?? '');
    if (!$slug || !isset($students[$slug])) {
        $message = '&#10060; Unknown student.';
    } elseif (!isset($_FILES['wordlist']) || $_FILES['wordlist']['error'] !== 0) {
        $errCodes = [1=>'File too large',2=>'File too large',3=>'Partial upload',4=>'No file selected',6=>'No temp folder',7=>'Cannot write'];
        $message = '&#10060; Upload error: ' . ($errCodes[$_FILES['wordlist']['error'] ?? 0] ?? 'Unknown');
    } else {
        $ext = strtolower(pathinfo($_FILES['wordlist']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','csv'])) {
            $message = '&#10060; Please upload .xlsx or .csv';
        } elseif ($ext === 'xlsx' && !class_exists('ZipArchive')) {
            $message = '&#10060; ZipArchive not available. Use .csv or run: sudo apt install php-zip && sudo systemctl restart apache2';
        } else {
            $words = parseExcel($_FILES['wordlist']['tmp_name'], $ext);
            if (count($words) > 0) {
                saveWords($slug, $words);
                // Reset this student's progress when new words uploaded
                if (file_exists(progressFile($slug))) unlink(progressFile($slug));
                $message = '&#9989; Uploaded ' . count($words) . ' words for ' . htmlspecialchars($students[$slug]['name']) . '!';
            } else {
                $message = '&#10060; No words found. Ensure two columns: English | Hebrew with a header row.';
            }
        }
    }
    $action = 'admin';
}

// -- Quiz: answer --------------------------------------------------------------
if ($action === 'answer' && $currentSlug && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = loadProgress($currentSlug);
    $knew = ($_POST['knew'] ?? '0') === '1';
    $wordIndex = (int)($_POST['word_index'] ?? -1);
    $p['current_set'] = array_values(array_filter($p['current_set'], fn($i) => $i !== $wordIndex));
    if (!isset($p['ever_missed'])) $p['ever_missed'] = [];
    if ($knew) {
        if (!in_array($wordIndex, $p['known'])) $p['known'][] = $wordIndex;
        $p['level_missed'] = array_values(array_filter($p['level_missed'], fn($i) => $i !== $wordIndex));
    } else {
        if (!in_array($wordIndex, $p['level_missed'])) $p['level_missed'][] = $wordIndex;
        if (!in_array($wordIndex, $p['ever_missed'])) $p['ever_missed'][] = $wordIndex;
    }
    saveProgress($currentSlug, $p);
    header('Location: ?action=quiz'); exit;
}

// -- Quiz: next level ----------------------------------------------------------
if ($action === 'next_level' && $currentSlug) {
    $p = loadProgress($currentSlug);
    $missed = $p['level_missed'];
    shuffle($missed);
    $p['level']++;
    $p['current_set'] = $missed;
    $p['level_missed'] = [];
    saveProgress($currentSlug, $p);
    header('Location: ?action=quiz'); exit;
}

// -- Quiz: reset progress (full start over) -----------------------------------
if ($action === 'reset' && $currentSlug) {
    if (file_exists(progressFile($currentSlug))) unlink(progressFile($currentSlug));
    header('Location: ?action=quiz'); exit;
}

// -- Quiz: restart with only ever-missed words (level 2 mode) -----------------
if ($action === 'restart_hard' && $currentSlug) {
    $p = loadProgress($currentSlug);
    $hard = $p['ever_missed'] ?? [];
    if (empty($hard)) {
        header('Location: ?action=quiz'); exit;
    }
    shuffle($hard);
    $newP = ['level'=>1,'current_set'=>$hard,'level_missed'=>[],'known'=>$p['known'],'ever_missed'=>$hard,'mode'=>'hard'];
    saveProgress($currentSlug, $newP);
    header('Location: ?action=quiz'); exit;
}

// -- Admin: reset a student's progress ----------------------------------------
if ($action === 'reset_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $students = loadStudents(); // always fresh
    $slug = studentSlug($_POST['slug'] ?? '');
    if ($slug && isset($students[$slug])) {
        if (file_exists(progressFile($slug))) unlink(progressFile($slug));
        $message = '&#9989; Progress reset for ' . htmlspecialchars($students[$slug]['name']) . '.';
    }
    $action = 'admin';
}

// -- Admin: reset global rate limit -------------------------------------------
if ($action === 'reset_ratelimit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    resetGlobalRateLimit();
    $message = '&#9989; API rate limit reset.';
    $action = 'admin';
}

// Save new rate limit values
if ($action === 'save_ratelimit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfgFile   = __DIR__ . '/wordwise_config.php';
    $disable   = !empty($_POST['disable_limit']);
    $perSt     = $disable ? 99999 : max(0, (int)($_POST['per_student'] ?? 3));
    $globalLim = $disable ? 99999 : max(0, (int)($_POST['global_limit'] ?? 10));
    $cfg = file_get_contents($cfgFile);
    $cfg = preg_replace("/define\('DAILY_API_PER_STUDENT',\s*\d+\)/", "define('DAILY_API_PER_STUDENT', $perSt)", $cfg);
    $cfg = preg_replace("/define\('DAILY_API_GLOBAL',\s*\d+\)/",      "define('DAILY_API_GLOBAL', $globalLim)", $cfg);
    file_put_contents($cfgFile, $cfg);
    $message = '&#9989; Rate limits saved. Reload page to see updated values.';
    $action  = 'admin';
}

// -- Practice: word selection form ---------------------------------------------
// (just sets session vars and redirects to the actual practice page)
if ($action === 'practice_start' && $currentSlug && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pMode     = $_POST['pmode']     ?? 'all';   // all|hard|mix
    $pSubMode  = $_POST['submode']   ?? 'memory'; // memory|fitb|mc
    $indexes   = selectPracticeWords($currentSlug, $pMode);
    $_SESSION['practice'] = [
        'slug'    => $currentSlug,
        'mode'    => $pSubMode,
        'indexes' => $indexes,
        'regen'   => false,
    ];
    header('Location: ?action=practice'); exit;
}

// -- Practice: regenerate questions (clears cache, new API call) ---------------
if ($action === 'practice_regen' && $currentSlug) {
    $ps = $_SESSION['practice'] ?? [];
    if (!empty($ps['indexes'])) {
        $cacheFile = practiceCacheFile($currentSlug, $ps['indexes']);
        if (file_exists($cacheFile)) unlink($cacheFile);
        $_SESSION['practice']['regen'] = true;
    }
    header('Location: ?action=practice'); exit;
}

// -- Practice: new words (same mode, new random selection) ---------------------
if ($action === 'practice_newwords' && $currentSlug) {
    $ps   = $_SESSION['practice'] ?? [];
    $pMode = $ps['pmode_sel'] ?? 'all';
    $indexes = selectPracticeWords($currentSlug, $pMode);
    $_SESSION['practice']['indexes'] = $indexes;
    $_SESSION['practice']['regen']   = false;
    header('Location: ?action=practice'); exit;
}

// -- Quiz: init ----------------------------------------------------------------
$words = [];
$p     = ['level'=>1,'current_set'=>[],'level_missed'=>[],'known'=>[]];
$currentWord  = null;
$currentIndex = -1;
$totalWords = $knownCount = $missedCount = $remainingCount = 0;
$levelDone = $allDone = false;

if ($currentSlug && $currentStudent) {
    $words = loadWords($currentSlug);
    $p     = loadProgress($currentSlug);

    $isHardMode = ($p['mode'] ?? '') === 'hard';
    if (!empty($words) && empty($p['current_set']) && empty($p['level_missed']) && $p['level'] === 1 && !$isHardMode) {
        $allIdx = array_keys($words); shuffle($allIdx);
        $p['current_set'] = array_values($allIdx);
        saveProgress($currentSlug, $p);
    }

    if (!empty($p['current_set'])) {
        $currentIndex = $p['current_set'][0];
        $currentWord  = $words[$currentIndex] ?? null;
    }

    $totalWords     = count($words);
    $knownCount     = count($p['known']);
    $missedCount    = count($p['level_missed']);
    $remainingCount = count($p['current_set']);
    $levelDone      = !empty($words) && empty($p['current_set']);
    $allDone        = $levelDone && empty($p['level_missed']);
}

// -- Practice: init data -------------------------------------------------------
$practiceData    = null; // generated questions from API/cache
$practiceWords   = [];   // the actual word objects for this session
$practiceIndexes = [];
$practiceSubMode = '';
$practiceError   = '';
$rateLimit       = ['ok'=>true,'student_calls'=>0,'global_calls'=>0,'student_ok'=>true,'global_ok'=>true];

// -- AJAX: check alternative answer ----------------------------
if ($action === 'api_check_alt') {
    header('Content-Type: application/json');
    if (!$currentSlug) {
        echo json_encode(['error' => 'Not logged in - session lost', 'slug' => session_id()]);
        exit;
    }
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_API_KEY_HERE') {
        echo json_encode([
            'rating'      => 'partial',
            'explanation' => '&#1504;&#1491;&#1512;&#1513; &#1502;&#1508;&#1514;&#1495; API &#1499;&#1491;&#1497; &#1500;&#1489;&#1491;&#1493;&#1511; &#1514;&#1513;&#1493;&#1489;&#1493;&#1514;. &#1506;&#1491;&#1499;&#1503; &#1488;&#1514; wordwise_config.php'
        ]);
        exit;
    }
    // Rate limit: share global limit, separate per-student alt counter (5/day)
    $altLimitFile = DATA_DIR . $currentSlug . '_altlimit.json';
    $today = date('Y-m-d');
    $altData = file_exists($altLimitFile) ? json_decode(file_get_contents($altLimitFile), true) : [];
    $altToday = ($altData['date'] ?? '') === $today ? (int)($altData['calls'] ?? 0) : 0;
    $altMax   = defined('DAILY_ALT_PER_STUDENT') ? DAILY_ALT_PER_STUDENT : 15;
    if ($altToday >= $altMax) {
        echo json_encode(['error' => 'daily_limit', 'rating' => 'limit',
            'explanation' => '&#1492;&#1490;&#1506;&#1514; &#1500;&#1502;&#1490;&#1489;&#1500;&#1514; &#1492;&#1489;&#1491;&#1497;&#1511;&#1493;&#1514; &#1492;&#1497;&#1493;&#1502;&#1497;&#1514;. &#1504;&#1505;&#1492; &#1502;&#1495;&#1512;!']);
        exit;
    }
    $rl = checkRateLimit($currentSlug);
    if (!$rl['global_ok']) {
        echo json_encode(['error' => 'global_limit', 'rating' => 'limit',
            'explanation' => '&#1492;&#1502;&#1490;&#1489;&#1500;&#1492; &#1492;&#1499;&#1500;&#1500;&#1497;&#1514; &#1492;&#1497;&#1493;&#1502;&#1497;&#1514; &#1492;&#1493;&#1513;&#1490;&#1492;. &#1504;&#1505;&#1492; &#1502;&#1495;&#1512;!']);
        exit;
    }
    $english = trim($_POST['english'] ?? '');
    $correct = trim($_POST['correct'] ?? '');
    $alt     = trim($_POST['alt']     ?? '');
    if (!$english || !$correct || !$alt) {
        echo json_encode(['error' => 'Missing fields']); exit;
    }
    $prompt = 'The English word/phrase is: "' . $english . '". The standard Hebrew translation is: "' . $correct . '". '
        . 'The student suggested: "' . $alt . '". '
        . 'Is this alternative a valid Hebrew translation? Rate it: "perfect", "good", "partial", or "wrong". '
        . 'Reply in JSON only: {"rating":"good","explanation":"One sentence explanation in Hebrew, max 15 words."} '
        . 'No markdown. Friendly tone for a child.';
    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 120,
        'messages'   => [['role' => 'user', 'content' => $prompt]]
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '         . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$resp || $curlErr) {
        echo json_encode(['error' => 'curl: ' . $curlErr]); exit;
    }
    $data = json_decode($resp, true);
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? "HTTP $httpCode";
        echo json_encode(['error' => $errMsg]); exit;
    }
    $text   = preg_replace('/```json|```/', '', $data['content'][0]['text'] ?? '');
    $result = json_decode(trim($text), true);
    if (!$result || !isset($result['rating'])) {
        echo json_encode(['error' => 'Bad response: ' . substr($text, 0, 80)]); exit;
    }
    // Increment alt counter
    $altData = ['date' => $today, 'calls' => $altToday + 1];
    file_put_contents($altLimitFile, json_encode($altData));
    // Also count against global
    incrementRateLimit($currentSlug);
    echo json_encode($result);
    exit;
}

// -- AJAX: generate questions -------------------------------------------------------
// -- AJAX: save practice missed words to ever_missed
if ($action === 'api_save_missed' && $currentSlug) {
    header('Content-Type: application/json');
    $missedIndexes = json_decode($_POST['missed'] ?? '[]', true);
    if (is_array($missedIndexes) && count($missedIndexes) > 0) {
        $p = loadProgress($currentSlug);
        $ever = $p['ever_missed'] ?? [];
        foreach ($missedIndexes as $idx) {
            $i2 = (int)$idx;
            if (!in_array($i2, $ever)) $ever[] = $i2;
        }
        $p['ever_missed'] = $ever;
        saveProgress($currentSlug, $p);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => true]);
    }
    exit;
}

if ($action === 'api_questions' && $currentSlug) {
    header('Content-Type: application/json');
    $ps = $_SESSION['practice'] ?? [];
    if (empty($ps['indexes']) || ($ps['slug'] ?? '') !== $currentSlug) {
        echo json_encode(['error' => 'No practice session']);
        exit;
    }
    $practiceIndexes = $ps['indexes'];
    $allWords = loadWords($currentSlug);
    $practiceWords = [];
    foreach ($practiceIndexes as $i) {
        if (isset($allWords[$i])) $practiceWords[$i] = $allWords[$i];
    }
    $rateLimit = checkRateLimit($currentSlug);
    // Check cache first
    $cached = loadPracticeCache($currentSlug, $practiceIndexes);
    if ($cached) {
        echo json_encode(['questions' => $cached, 'cached' => true, 'rateLimit' => $rateLimit]);
        exit;
    }
    if (!$rateLimit['ok']) {
        // Build fallback
        $allHebrews = array_column(array_values($allWords), 'hebrew');
        $fallback = [];
        foreach ($practiceWords as $idx => $w) {
            $others = array_values(array_filter($allHebrews, fn($h) => $h !== $w['hebrew']));
            shuffle($others);
            $fallback[] = ['e'=>$w['english'],'h'=>$w['hebrew'],'i'=>$idx,
                's'=>'The word _____ translates to ' . $w['hebrew'] . '.',
                'd'=>array_slice($others,0,3)];
        }
        echo json_encode(['questions'=>$fallback,'fallback'=>true,'rateLimitHit'=>true,'rateLimit'=>$rateLimit]);
        exit;
    }
    $apiResult = callAnthropicAPI(array_values($practiceWords));
    if ($apiResult) {
        foreach ($apiResult as $qi => $q) {
            $idx = $practiceIndexes[$qi] ?? null;
            if ($idx !== null && isset($allWords[$idx])) {
                $apiResult[$qi]['h'] = $allWords[$idx]['hebrew'];
                $apiResult[$qi]['i'] = $idx;
            }
        }
        savePracticeCache($currentSlug, $practiceIndexes, $apiResult);
        incrementRateLimit($currentSlug);
        $rateLimit = checkRateLimit($currentSlug);
        echo json_encode(['questions'=>$apiResult,'cached'=>false,'rateLimit'=>$rateLimit]);
    } else {
        // Fallback
        $allHebrews = array_column(array_values($allWords), 'hebrew');
        $fallback = [];
        foreach ($practiceWords as $idx => $w) {
            $others = array_values(array_filter($allHebrews, fn($h) => $h !== $w['hebrew']));
            shuffle($others);
            $fallback[] = ['e'=>$w['english'],'h'=>$w['hebrew'],'i'=>$idx,
                's'=>'The word _____ translates to ' . $w['hebrew'] . '.',
                'd'=>array_slice($others,0,3)];
        }
        echo json_encode(['questions'=>$fallback,'fallback'=>true,'rateLimit'=>$rateLimit]);
    }
    exit;
}

if ($action === 'practice' && $currentSlug) {
    $ps = $_SESSION['practice'] ?? [];
    if (!empty($ps['indexes']) && ($ps['slug'] ?? '') === $currentSlug) {
        $practiceIndexes = $ps['indexes'];
        $practiceSubMode = $ps['mode'] ?? 'memory';
        $allWords        = loadWords($currentSlug);
        foreach ($practiceIndexes as $i) {
            if (isset($allWords[$i])) $practiceWords[$i] = $allWords[$i];
        }
        $rateLimit = checkRateLimit($currentSlug);
        // For memory mode, no API needed. For others, JS will fetch async.
        if ($practiceSubMode !== 'memory' && $practiceSubMode !== 'spell') {
            // Check if already cached so we can pass it directly (no loading screen needed)
            $practiceData = loadPracticeCache($currentSlug, $practiceIndexes);
        }
    } else {
        $action = 'practice_pick';
    }
}

// Default action
if (!$action) $action = $currentSlug ? 'quiz' : 'login';

// -- Admin report: load data for a specific student ----------------------------
$arSlug    = '';
$arStudent = null;
$arWords   = [];
$arP       = ['level'=>1,'current_set'=>[],'level_missed'=>[],'known'=>[],'ever_missed'=>[]];
if ($action === 'admin_report') {
    $students  = loadStudents();
    $arSlug    = studentSlug($_GET['slug'] ?? '');
    $arStudent = $students[$arSlug] ?? null;
    if ($arStudent) {
        $arWords = loadWords($arSlug);
        $arP     = loadProgress($arSlug);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WordWise</title>
<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800&family=Rubik:wght@400;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0f1c2e; --card:#162236; --card2:#1e3050;
  --accent:#f9c846; --accent2:#ff6b6b; --green:#4ecb71; --blue:#4a9eff;
  --text:#e8f4fd; --muted:#7a9bbf; --radius:20px; --shadow:0 8px 32px rgba(0,0,0,0.4);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;
  background-image:radial-gradient(ellipse at 20% 20%,rgba(74,158,255,.08) 0%,transparent 60%),
                   radial-gradient(ellipse at 80% 80%,rgba(249,200,70,.06) 0%,transparent 60%);}
.container{max-width:700px;margin:0 auto;padding:20px;}

/* Nav */
nav{display:flex;align-items:center;justify-content:space-between;padding:14px 24px;
  background:var(--card);border-bottom:1px solid rgba(255,255,255,.06);position:sticky;top:0;z-index:100;}
.nav-logo{font-family:'Fredoka One',cursive;font-size:1.6rem;color:var(--accent);}
.nav-right{display:flex;align-items:center;gap:12px;}
.nav-student{font-weight:800;font-size:.9rem;color:var(--muted);}
.nav-links a{color:var(--muted);text-decoration:none;font-weight:700;font-size:.9rem;margin-left:16px;transition:color .2s;}
.nav-links a:hover,.nav-links a.active{color:var(--accent);}

/* Cards */
.card{background:var(--card);border-radius:var(--radius);padding:32px;box-shadow:var(--shadow);
  border:1px solid rgba(255,255,255,.05);margin-bottom:20px;}
h1{font-family:'Fredoka One',cursive;font-size:2.2rem;color:var(--accent);margin-bottom:8px;}
h2{font-family:'Fredoka One',cursive;font-size:1.5rem;color:var(--text);margin-bottom:14px;}
h3{font-size:1rem;font-weight:800;color:var(--muted);margin-bottom:10px;}
p{color:var(--muted);line-height:1.6;margin-bottom:10px;}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:8px;padding:13px 26px;border-radius:50px;
  border:none;cursor:pointer;font-family:'Nunito',sans-serif;font-weight:800;font-size:.95rem;
  transition:transform .15s,filter .15s;text-decoration:none;}
.btn:hover{transform:translateY(-2px);filter:brightness(1.1);}
.btn:active{transform:translateY(0);}
.btn-primary{background:var(--accent);color:#1a1000;box-shadow:0 4px 16px rgba(249,200,70,.3);}
.btn-green{background:var(--green);color:#001a09;box-shadow:0 4px 16px rgba(78,203,113,.3);}
.btn-red{background:var(--accent2);color:#1a0000;box-shadow:0 4px 16px rgba(255,107,107,.3);}
.btn-blue{background:var(--blue);color:#00081a;box-shadow:0 4px 16px rgba(74,158,255,.3);}
.btn-ghost{background:transparent;color:var(--muted);border:2px solid rgba(255,255,255,.1);}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent);}
.btn-danger{background:rgba(255,107,107,.15);color:var(--accent2);border:1.5px solid var(--accent2);}
.btn-danger:hover{background:var(--accent2);color:#1a0000;}
.btn-sm{padding:8px 16px;font-size:.82rem;}

/* Level badge */
.level-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(249,200,70,.12);
  border:1.5px solid var(--accent);color:var(--accent);border-radius:50px;
  padding:5px 14px;font-weight:800;font-size:.85rem;margin-bottom:18px;}

/* Progress */
.progress-wrap{margin:16px 0;}
.progress-label{display:flex;justify-content:space-between;font-size:.82rem;color:var(--muted);margin-bottom:6px;}
.progress-bar{height:10px;background:rgba(255,255,255,.07);border-radius:10px;overflow:hidden;}
.progress-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--green),var(--blue));transition:width .5s;}

/* Stats */
.stats-row{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;}
.stat-box{flex:1;min-width:90px;background:var(--card2);border-radius:14px;padding:14px;
  text-align:center;border:1px solid rgba(255,255,255,.06);}
.stat-num{font-family:'Fredoka One',cursive;font-size:1.8rem;}
.stat-label{font-size:.72rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-top:2px;}
.stat-green .stat-num{color:var(--green);}
.stat-red .stat-num{color:var(--accent2);}
.stat-blue .stat-num{color:var(--blue);}

/* Word card */
.word-card{background:var(--card2);border-radius:var(--radius);padding:44px 32px;text-align:center;
  border:2px solid rgba(74,158,255,.15);margin-bottom:24px;position:relative;overflow:hidden;}
.word-card::before{content:'';position:absolute;top:-40px;right:-40px;width:120px;height:120px;
  background:radial-gradient(circle,rgba(74,158,255,.12),transparent 70%);border-radius:50%;}
.english-word{font-family:'Fredoka One',cursive;font-size:2.8rem;color:var(--text);margin-bottom:14px;}
.speak-btn{background:rgba(74,158,255,.15);border:2px solid var(--blue);color:var(--blue);
  border-radius:50px;padding:9px 20px;cursor:pointer;font-family:'Nunito',sans-serif;
  font-weight:800;font-size:.9rem;display:inline-flex;align-items:center;gap:7px;transition:all .2s;margin-bottom:18px;}
.speak-btn:hover{background:var(--blue);color:#00081a;transform:scale(1.05);}
.speak-btn svg{width:17px;height:17px;}
.reveal-area{margin-top:6px;}
.hebrew-word{font-size:1.9rem;font-weight:800;color:var(--accent);direction:rtl;margin:14px 0;min-height:2.2rem;font-family:'Rubik',sans-serif;}
.hidden{opacity:0;pointer-events:none;}
.reveal-btn{background:transparent;border:2px dashed rgba(255,255,255,.2);color:var(--muted);
  border-radius:50px;padding:9px 22px;cursor:pointer;font-family:'Nunito',sans-serif;font-weight:700;transition:all .2s;}
.reveal-btn:hover{border-color:var(--accent);color:var(--accent);}
.answer-btns{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;}
.answer-btns .btn{min-width:150px;justify-content:center;font-size:1rem;padding:14px 28px;}
/* Hebrew font applied to all RTL text */
[dir="rtl"], .hebrew-word, [lang="he"], .fitb-back .flip-word {font-family:'Rubik',sans-serif;}
.flip-back .flip-word{font-family:'Rubik',sans-serif;font-size:2rem;}
.mc-btn{font-family:'Rubik',sans-serif;}
.word-chip{font-family:'Rubik',sans-serif;}

/* Login screen */
.student-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin:20px 0;}
.student-card{background:var(--card2);border:2px solid rgba(255,255,255,.07);border-radius:16px;
  padding:24px 16px;text-align:center;cursor:pointer;transition:border-color .2s,transform .15s;}
.student-card:hover{border-color:var(--accent);transform:translateY(-3px);}
.student-avatar{font-size:2.6rem;margin-bottom:8px;}
.student-name{font-weight:800;font-size:1rem;color:var(--text);}
.student-sub{font-size:.78rem;color:var(--muted);margin-top:3px;}

/* PIN modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;
  align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal{background:var(--card);border-radius:var(--radius);padding:32px;width:90%;max-width:360px;
  box-shadow:var(--shadow);text-align:center;}
.pin-input{width:100%;padding:13px 18px;border-radius:50px;background:var(--card2);
  border:2px solid rgba(255,255,255,.1);color:var(--text);font-family:'Nunito',sans-serif;
  font-weight:700;font-size:1.1rem;outline:none;text-align:center;letter-spacing:4px;
  margin:14px 0;transition:border-color .2s;}
.pin-input:focus{border-color:var(--accent);}

/* Forms */
.form-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.text-input{flex:1;min-width:140px;padding:12px 18px;border-radius:50px;background:var(--card2);
  border:2px solid rgba(255,255,255,.1);color:var(--text);font-family:'Nunito',sans-serif;
  font-weight:700;font-size:.95rem;outline:none;transition:border-color .2s;}
.text-input:focus{border-color:var(--accent);}
.text-input::placeholder{color:var(--muted);}

/* Upload zone */
.upload-zone{display:block;border:2.5px dashed rgba(74,158,255,.4);border-radius:var(--radius);
  padding:32px 20px;text-align:center;transition:border-color .2s,background .2s;cursor:pointer;
  background:rgba(74,158,255,.03);}
.upload-zone:hover{border-color:var(--blue);background:rgba(74,158,255,.08);}
.upload-zone input[type=file]{display:none;}
.upload-zone strong{display:block;font-size:1rem;color:var(--text);margin-bottom:5px;}
.upload-zone p{margin:0;font-size:.85rem;}
.upload-icon{font-size:2.4rem;display:block;margin-bottom:10px;line-height:1;}

/* Messages */
.msg-box{padding:13px 18px;border-radius:12px;margin-bottom:18px;font-weight:700;font-size:.9rem;}
.msg-ok{background:rgba(78,203,113,.15);border:1.5px solid var(--green);color:var(--green);}
.msg-err{background:rgba(255,107,107,.15);border:1.5px solid var(--accent2);color:var(--accent2);}
.msg-warn{background:rgba(249,200,70,.12);border:1.5px solid var(--accent);color:var(--accent);}

/* Admin student list */
.admin-student-row{display:flex;align-items:center;gap:14px;padding:14px;
  background:var(--card2);border-radius:14px;margin-bottom:10px;}
.admin-student-row .avatar{font-size:1.8rem;}
.admin-student-info{flex:1;}
.admin-student-info strong{display:block;font-size:.95rem;}
.admin-student-info span{font-size:.78rem;color:var(--muted);}
.admin-student-actions{display:flex;gap:8px;flex-wrap:wrap;}

/* Tables */
.data-table{width:100%;border-collapse:collapse;margin-top:10px;}
.data-table th{text-align:left;color:var(--muted);font-size:.78rem;text-transform:uppercase;
  letter-spacing:1px;padding:8px 12px;}
.data-table td{padding:9px 12px;border-top:1px solid rgba(255,255,255,.05);}

/* Celebration */
.celebration{text-align:center;padding:16px 0;}
.big-emoji{font-size:3.5rem;display:block;margin-bottom:10px;animation:bounce 1s infinite alternate;}
@keyframes bounce{from{transform:translateY(0);}to{transform:translateY(-10px);}}

/* Logout btn in nav */
.btn-logout{background:rgba(255,107,107,.12);color:var(--accent2);border:1.5px solid rgba(255,107,107,.3);
  border-radius:50px;padding:7px 16px;font-family:'Nunito',sans-serif;font-weight:800;
  font-size:.8rem;text-decoration:none;transition:all .2s;cursor:pointer;}
.btn-logout:hover{background:var(--accent2);color:#1a0000;}

.emoji-opt.selected{border-color:var(--accent) !important;background:rgba(249,200,70,.15) !important;transform:scale(1.15);}
.emoji-opt:hover{border-color:var(--muted) !important;}

@media(max-width:500px){
  .english-word{font-size:2.1rem;}
  .answer-btns .btn{min-width:130px;}
  .card{padding:22px 16px;}
  .student-grid{grid-template-columns:1fr 1fr;}
}

/* Report page */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:800;}
.badge-green{background:rgba(78,203,113,.15);color:var(--green);border:1px solid rgba(78,203,113,.3);}
.badge-red{background:rgba(255,107,107,.15);color:var(--accent2);border:1px solid rgba(255,107,107,.3);}
.badge-muted{background:rgba(255,255,255,.06);color:var(--muted);border:1px solid rgba(255,255,255,.1);}
.speak-mini{background:none;border:none;cursor:pointer;font-size:.9rem;opacity:.5;
  margin-left:6px;padding:2px 4px;border-radius:6px;transition:opacity .2s;}
.speak-mini:hover{opacity:1;background:rgba(74,158,255,.15);}
#wordTable tr[data-status].hidden-row{display:none;}
#arTable tr.hidden-row{display:none;}

/* Practice */
.mode-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin:20px 0;}
.mode-card{background:var(--card2);border:2px solid rgba(255,255,255,.07);border-radius:18px;
  padding:24px 18px;text-align:center;cursor:pointer;transition:border-color .2s,transform .15s;}
.mode-card:hover,.mode-card.selected{border-color:var(--accent);transform:translateY(-3px);}
.mode-card.selected{background:rgba(249,200,70,.08);}
.mode-icon{font-size:2.4rem;display:block;margin-bottom:10px;}
.mode-title{font-weight:800;font-size:1rem;color:var(--text);}
.mode-desc{font-size:.78rem;color:var(--muted);margin-top:5px;}
.word-sel-btn{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;
  border:2px solid rgba(255,255,255,.1);background:var(--card2);cursor:pointer;width:100%;
  margin-bottom:10px;transition:border-color .2s,background .2s;text-align:left;}
.word-sel-btn:hover,.word-sel-btn.selected{border-color:var(--accent);background:rgba(249,200,70,.07);}
.word-sel-icon{font-size:1.5rem;}
.word-sel-label{font-weight:800;font-size:.95rem;color:var(--text);}
.word-sel-sub{font-size:.78rem;color:var(--muted);}
/* Progress bar for practice */
.pq-bar{display:flex;align-items:center;gap:10px;margin-bottom:18px;}
.pq-bar-track{flex:1;height:8px;background:rgba(255,255,255,.07);border-radius:8px;overflow:hidden;}
.pq-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--blue));border-radius:8px;transition:width .4s;}
/* MC buttons */
.mc-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:20px;}
.mc-btn{padding:18px 14px;border-radius:16px;border:2px solid rgba(255,255,255,.1);background:var(--card2);
  cursor:pointer;font-family:'Rubik','Nunito',sans-serif;font-weight:700;font-size:1.1rem;
  color:var(--text);direction:rtl;transition:all .2s;line-height:1.4;}
.mc-btn:hover:not(:disabled){border-color:var(--blue);background:rgba(74,158,255,.1);}
.mc-btn.correct{border-color:var(--green)!important;background:rgba(78,203,113,.2)!important;color:var(--green)!important;}
.mc-btn.wrong{border-color:var(--accent2)!important;background:rgba(255,107,107,.15)!important;color:var(--accent2)!important;}
.mc-btn:disabled{cursor:default;}
/* FITB */
.fitb-sentence{font-size:1.2rem;font-weight:700;line-height:2;color:var(--text);margin-bottom:8px;}
.fitb-blank{display:inline-block;min-width:100px;border-bottom:3px solid var(--blue);
  padding:2px 8px;margin:0 4px;border-radius:4px 4px 0 0;background:rgba(74,158,255,.08);
  vertical-align:middle;text-align:center;font-weight:800;color:var(--accent);transition:all .2s;}
.fitb-blank.drag-over{background:rgba(249,200,70,.2);border-color:var(--accent);}
.fitb-blank.correct{border-color:var(--green);background:rgba(78,203,113,.15);color:var(--green);}
.fitb-blank.wrong{border-color:var(--accent2);background:rgba(255,107,107,.15);color:var(--accent2);}
.word-bank{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;min-height:44px;
  padding:14px;background:var(--card2);border-radius:14px;border:2px dashed rgba(255,255,255,.1);}
.word-chip{padding:8px 18px;border-radius:50px;background:var(--card);border:2px solid rgba(74,158,255,.3);
  color:var(--text);font-weight:800;font-size:.9rem;cursor:grab;transition:all .2s;user-select:none;}
.word-chip:hover{border-color:var(--blue);transform:translateY(-2px);}
.word-chip.dragging{opacity:.4;}
/* Memory flip */
.flip-card{perspective:800px;height:180px;margin-bottom:20px;cursor:pointer;}
.flip-inner{position:relative;width:100%;height:100%;transition:transform .5s;transform-style:preserve-3d;}
.flip-card.flipped .flip-inner{transform:rotateY(180deg);}
.flip-front,.flip-back{position:absolute;inset:0;border-radius:var(--radius);
  display:flex;align-items:center;justify-content:center;backface-visibility:hidden;flex-direction:column;gap:10px;}
.flip-front{background:var(--card2);border:2px solid rgba(74,158,255,.2);}
.flip-back{background:rgba(249,200,70,.08);border:2px solid var(--accent);transform:rotateY(180deg);}
.flip-word{font-family:'Fredoka One',cursive;font-size:2.4rem;}
.flip-hint{font-size:.8rem;color:var(--muted);}
/* Result */
.result-item{display:flex;align-items:center;gap:12px;padding:10px 14px;
  border-radius:12px;margin-bottom:8px;background:var(--card2);}
.result-item.right{border-left:3px solid var(--green);}
.result-item.wrong{border-left:3px solid var(--accent2);}
.api-info{font-size:.75rem;color:var(--muted);text-align:right;margin-top:4px;}
/* FITB split layout */
.fitb-layout{display:grid;grid-template-columns:1fr 220px;gap:16px;align-items:start;}
.fitb-bank-sticky{position:sticky;top:70px;}
.fitb-bank-card{background:var(--card);border:2px solid rgba(74,158,255,.25);border-radius:var(--radius);padding:16px;}
.fitb-bank-card.drag-over{border-color:var(--accent);background:rgba(249,200,70,.05);}
.word-bank{display:flex;flex-wrap:wrap;gap:8px;min-height:44px;}
.word-bank.drag-over{border-color:var(--accent);background:rgba(249,200,70,.08);}
@media(max-width:640px){
  .fitb-layout{grid-template-columns:1fr;}
  .fitb-bank-sticky{position:static;}
}
.spell-correct{border-color:var(--green)!important;background:rgba(78,203,113,.1)!important;}
/* Matching pairs */
.match-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:16px 0;}
.match-col{display:flex;flex-direction:column;gap:8px;}
.match-item{padding:12px 16px;border-radius:12px;border:2px solid rgba(255,255,255,.1);
  background:var(--card2);cursor:pointer;font-weight:700;font-size:.9rem;
  transition:all .2s;text-align:center;user-select:none;}
.match-item:hover{border-color:var(--blue);}
.match-item.selected{border-color:var(--accent);background:rgba(249,200,70,.1);}
.match-item.matched{border-color:var(--green);background:rgba(78,203,113,.15);
  color:var(--green);cursor:default;animation:none;}
.match-item.wrong{border-color:var(--accent2);background:rgba(255,107,107,.15);
  animation:shake .3s ease;}
@keyframes shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-6px);}75%{transform:translateX(6px);}}
/* Speed round */
.speed-timer-bar{height:8px;background:rgba(255,255,255,.07);border-radius:8px;overflow:hidden;margin-bottom:20px;}
.speed-timer-fill{height:100%;border-radius:8px;background:linear-gradient(90deg,var(--green),var(--accent));
  transition:width .1s linear;}
.speed-timer-fill.urgent{background:linear-gradient(90deg,var(--accent2),#ff4500);}
.speed-answer-btn{padding:28px 16px;border-radius:16px;border:2px solid rgba(255,255,255,.12);
  background:var(--card2);cursor:pointer;text-align:center;transition:all .15s;
  display:flex;align-items:center;justify-content:center;min-height:90px;}
.speed-answer-btn:hover{border-color:var(--blue);background:rgba(74,158,255,.1);transform:translateY(-2px);}
.spell-wrong{border-color:var(--accent2)!important;background:rgba(255,107,107,.08)!important;}
.rate-limit-note{font-size:.8rem;color:var(--accent2);margin-top:6px;text-align:center;}
</style>
</head>
<body>

<nav>
  <span class="nav-logo">&#128218; WordWise</span>
  <div class="nav-right">
    <?php if ($currentStudent): ?>
      <span class="nav-student">&#128100; <?= htmlspecialchars($currentStudent['name']) ?></span>
      <a href="?action=logout" class="btn-logout">Log out</a>
    <?php endif; ?>
    <div class="nav-links">
      <?php if ($currentStudent): ?>
        <a href="?action=quiz" <?= $action==='quiz'?'class="active"':'' ?>>Quiz</a>
        <a href="?action=practice_pick" <?= in_array($action,['practice_pick','practice'])?'class="active"':'' ?>>Practice</a>
        <a href="?action=report" <?= $action==='report'?'class="active"':'' ?>>Report</a>
      <?php else: ?>
        <a href="?" <?= (!$action||$action==='login')?'class="active"':'' ?>>&#127968; Home</a>
      <?php endif; ?>
      <a href="?action=admin" <?= $action==='admin'?'class="active"':'' ?>>Admin</a>
    </div>
  </div>
</nav>

<div class="container">

<?php /* ====================== LOGIN / STUDENT PICKER ====================== */
if ($action === 'login' || (!$currentSlug && $action !== 'admin')): ?>

<div style="margin-top:32px;">
  <h1>Who's studying? &#128075;</h1>
  <p>Pick your name to start the quiz.</p>

  <?php if ($message): ?>
    <div class="msg-box msg-err"><?= $message ?></div>
  <?php endif; ?>

  <?php if (empty($students)): ?>
    <div class="card" style="text-align:center;">
      <span style="font-size:2.5rem;display:block;margin-bottom:10px;">&#127979;</span>
      <p>No students set up yet. Ask a parent to add students in the <a href="?action=admin" style="color:var(--accent)">Admin</a> panel.</p>
    </div>
  <?php else: ?>
    <div class="student-grid">
      <?php foreach ($students as $slug => $s):
        $av    = $s['emoji'] ?? '&#128102;';
        $words = loadWords($slug);
        $prog  = loadProgress($slug);
        $wc    = count($words);
        $kc    = count($prog['known']);
        $hasPin = $s['pin'] !== '';
      ?>
      <?php if (!$hasPin): ?>
        <form method="post" action="?action=login" style="display:contents;">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES) ?>">
          <input type="hidden" name="pin" value="">
          <button type="submit" class="student-card" style="background:var(--card2);border:2px solid rgba(255,255,255,.07);cursor:pointer;font-family:inherit;width:100%;">
            <div class="student-avatar"><?= $av ?></div>
            <div class="student-name"><?= htmlspecialchars($s['name']) ?></div>
            <div class="student-sub"><?= $wc ?> words &#183; <?= $kc ?> known</div>
          </button>
        </form>
      <?php else: ?>
        <div class="student-card"
          data-slug="<?= htmlspecialchars($slug, ENT_QUOTES) ?>"
          data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>"
          onclick="openLogin(this.dataset.slug, this.dataset.name, '1')">
          <div class="student-avatar"><?= $av ?></div>
          <div class="student-name"><?= htmlspecialchars($s['name']) ?></div>
          <div class="student-sub"><?= $wc ?> words &#183; <?= $kc ?> known</div>
        </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- PIN Modal -->
<div class="modal-bg" id="loginModal">
  <div class="modal">
    <div style="font-size:2rem;margin-bottom:8px;">&#128272;</div>
    <h2 id="modalName" style="margin-bottom:4px;"></h2>
    <form method="post" action="?action=login">
      <input type="hidden" name="slug" id="modalSlug">
      <div id="pinSection">
        <p style="font-size:.85rem;">Enter your PIN:</p>
        <input class="pin-input" type="password" name="pin" id="pinInput" maxlength="8" inputmode="numeric" placeholder="&#8226;&#8226;&#8226;&#8226;">
      </div>
      <div style="display:flex;gap:10px;justify-content:center;margin-top:8px;">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeLogin()">Cancel</button>
        <button type="submit" class="btn btn-primary">Go! &#128640;</button>
      </div>
    </form>
  </div>
</div>

<?php /* ====================== QUIZ ====================== */
elseif ($action === 'quiz' && $currentSlug && $currentStudent): ?>

<div style="margin-top:22px;">

  <?php if (empty($words)): ?>
    <div class="card" style="text-align:center;">
      <span style="font-size:2.5rem;display:block;margin-bottom:10px;">&#128194;</span>
      <h2>No words yet!</h2>
      <p>Ask a parent to upload a word list for you in the <a href="?action=admin" style="color:var(--accent)">Admin</a> panel.</p>
    </div>

  <?php elseif ($allDone):
    $everMissed  = $p['ever_missed'] ?? [];
    $isHardMode  = ($p['mode'] ?? '') === 'hard';
  ?>
    <div class="card celebration">
      <span class="big-emoji">&#127942;</span>
      <?php if ($isHardMode): ?>
        <h1>Hard words done!</h1>
        <p>You practiced all <?= count($everMissed) ?> difficult word<?= count($everMissed)!==1?'s':'' ?>. Great work!</p>
      <?php else: ?>
        <h1>You know all the words!</h1>
        <p>Amazing! You mastered all <?= $totalWords ?> words.</p>
        <?php if (!empty($everMissed)): ?>
          <p style="font-size:.9rem;">You had difficulty with <strong style="color:var(--accent2)"><?= count($everMissed) ?></strong> word<?= count($everMissed)!==1?'s':'' ?> along the way.</p>
        <?php endif; ?>
      <?php endif; ?>
      <br>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px;">
        <a href="?action=reset" class="btn btn-primary">&#128260; Start over (all words)</a>
        <?php if (!empty($everMissed) && !$isHardMode): ?>
          <a href="?action=restart_hard" class="btn btn-red">&#127919; Practice difficult words (<?= count($everMissed) ?>)</a>
        <?php endif; ?>
        <a href="?action=report" class="btn btn-ghost">&#128202; My report</a>
      </div>
    </div>

  <?php elseif ($levelDone): ?>
    <div class="card celebration">
      <span class="big-emoji">&#127881;</span>
      <h2>Level <?= $p['level'] ?> complete!</h2>
      <p>
        <?php if ($missedCount > 0): ?>
          You missed <strong style="color:var(--accent2)"><?= $missedCount ?></strong> word<?= $missedCount!==1?'s':'' ?> &#8212; let's practice them again!
        <?php else: ?>
          Perfect round!
        <?php endif; ?>
      </p>
      <br>
      <a href="?action=next_level" class="btn btn-primary">Level <?= $p['level']+1 ?> &#8594;</a>
    </div>

  <?php else: ?>

    <div class="level-badge">&#11088; Level <?= $p['level'] ?></div>

    <div class="stats-row">
      <div class="stat-box stat-green"><div class="stat-num"><?= $knownCount ?></div><div class="stat-label">&#9989; Known</div></div>
      <div class="stat-box stat-red"><div class="stat-num"><?= $missedCount ?></div><div class="stat-label">&#10060; Missed</div></div>
      <div class="stat-box stat-blue"><div class="stat-num"><?= $remainingCount ?></div><div class="stat-label">&#128214; Left</div></div>
    </div>

    <div class="progress-wrap">
      <div class="progress-label"><span>Overall progress</span><span><?= $knownCount ?> / <?= $totalWords ?></span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:<?= $totalWords>0?round($knownCount/$totalWords*100):0 ?>%"></div></div>
    </div>

    <?php if ($currentWord): ?>
    <div class="word-card" id="wordCard" data-word="<?= htmlspecialchars($currentWord['english'], ENT_QUOTES) ?>">
      <div class="english-word"><?= htmlspecialchars($currentWord['english']) ?></div>
      <button class="speak-btn" onclick="speakCurrent()">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
        Say it
      </button>
      <div class="reveal-area">
        <div class="hebrew-word hidden" id="hebrew"><?= htmlspecialchars($currentWord['hebrew']) ?></div>
        <button class="reveal-btn" id="revealBtn" onclick="reveal()">&#128065; Show Hebrew</button>
      </div>
    </div>

    <div class="answer-btns" id="answerBtns" style="display:none;">
      <form method="post" action="?action=answer" style="display:contents;">
        <input type="hidden" name="word_index" value="<?= $currentIndex ?>">
        <input type="hidden" name="knew" value="0">
        <button type="submit" class="btn btn-red">&#10060; &#1500;&#1488; &#1497;&#1491;&#1506;&#1514;&#1497;</button>
      </form>
      <form method="post" action="?action=answer" style="display:contents;">
        <input type="hidden" name="word_index" value="<?= $currentIndex ?>">
        <input type="hidden" name="knew" value="1">
        <button type="submit" class="btn btn-green">&#9989; &#1497;&#1491;&#1506;&#1514;&#1497;!</button>
      </form>
    </div>

    <!-- Alt answer panel for quiz -->
    <div id="quizAltWrap" style="display:none;margin-top:16px;text-align:center;">
      <div class="card" style="padding:16px;text-align:right;direction:rtl;">
        <p style="font-size:.85rem;margin-bottom:8px;">&#1497;&#1513; &#1500;&#1498; &#1514;&#1513;&#1493;&#1489;&#1492; &#1488;&#1495;&#1512;&#1514;? &#1499;&#1514;&#1493;&#1489; &#1488;&#1493;&#1514;&#1492; &#1499;&#1488;&#1503;:</p>
        <div style="display:flex;gap:8px;align-items:center;">
          <button class="btn btn-blue btn-sm" onclick="quizCheckAlt()" style="flex-shrink:0;">&#10003; &#1489;&#1491;&#1493;&#1511;</button>
          <input id="quizAltInput" class="text-input" type="text" dir="rtl"
            placeholder="&#1500;&#1502;&#1513;&#1500;: &#1500;&#1502;&#1494;&#1493;&#1490;..."
            style="flex:1;text-align:right;">
        </div>
        <div id="quizAltResult" style="margin-top:10px;display:none;"></div>
      </div>
    </div>

    <div style="text-align:center;margin-top:10px;" id="quizAltToggleWrap" style="display:none;">
      <button class="btn btn-ghost btn-sm" id="quizAltToggle" onclick="toggleQuizAlt()" style="display:none;">
        &#129300; &#1492;&#1488;&#1501; &#1514;&#1513;&#1493;&#1489;&#1492; &#1488;&#1495;&#1512;&#1514; &#1492;&#1497;&#1488; &#1489;&#1505;&#1491;&#1512;?
      </button>
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <a href="?action=report" class="btn btn-ghost btn-sm">&#128202; My report</a>
      <a href="?action=reset" class="btn btn-ghost btn-sm" onclick="return confirm('Start completely over?')">&#128260; Start over</a>
      <?php if (!empty($p['ever_missed'] ?? [])): ?>
        <a href="?action=restart_hard" class="btn btn-ghost btn-sm" onclick="return confirm('Practice only your difficult words?')">&#127919; Difficult words only</a>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<?php /* ====================== REPORT ====================== */
elseif ($action === 'report' && $currentSlug && $currentStudent):
  $rWords = loadWords($currentSlug);
  $rP     = loadProgress($currentSlug);
  $rEver  = $rP['ever_missed'] ?? [];
  $rKnown = $rP['known'] ?? [];
?>
<div style="margin-top:24px;">
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap;">
    <h1 style="margin:0;">&#128202; My Report</h1>
    <span class="level-badge" style="margin:0;">&#128100; <?= htmlspecialchars($currentStudent['name']) ?></span>
  </div>

  <?php if (empty($rWords)): ?>
    <div class="card" style="text-align:center;"><p>No words loaded yet.</p></div>
  <?php else:
    $totalR   = count($rWords);
    $knownR   = count(array_intersect(array_map('intval', array_keys($rWords)), array_map('intval', $rKnown)));
    $hardR    = count($rEver);
    $pctKnown = $totalR > 0 ? round($knownR / $totalR * 100) : 0;
    $pctHard  = $totalR > 0 ? round($hardR  / $totalR * 100) : 0;
  ?>
  <!-- Summary stats -->
  <div class="stats-row">
    <div class="stat-box stat-green">
      <div class="stat-num"><?= $knownR ?></div>
      <div class="stat-label">&#9989; Mastered</div>
    </div>
    <div class="stat-box stat-red">
      <div class="stat-num"><?= $hardR ?></div>
      <div class="stat-label">&#127919; Hard words</div>
    </div>
    <div class="stat-box stat-blue">
      <div class="stat-num"><?= $totalR ?></div>
      <div class="stat-label">&#128218; Total</div>
    </div>
    <div class="stat-box" style="background:var(--card2);">
      <div class="stat-num" style="color:var(--accent);"><?= $pctKnown ?>%</div>
      <div class="stat-label">&#128200; Score</div>
    </div>
  </div>

  <div class="progress-wrap">
    <div class="progress-label"><span>Mastered</span><span><?= $knownR ?> / <?= $totalR ?></span></div>
    <div class="progress-bar"><div class="progress-fill" style="width:<?= $pctKnown ?>%"></div></div>
  </div>
  <?php if ($hardR > 0): ?>
  <div class="progress-wrap">
    <div class="progress-label"><span style="color:var(--accent2);">Had difficulty with</span><span><?= $hardR ?> / <?= $totalR ?></span></div>
    <div class="progress-bar"><div class="progress-fill" style="width:<?= $pctHard ?>%;background:linear-gradient(90deg,var(--accent2),#ff9f43);"></div></div>
  </div>
  <?php endif; ?>

  <!-- Word table -->
  <div class="card" style="padding:20px;">
    <h2>All Words</h2>

    <!-- Filter tabs -->
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
      <button class="btn btn-sm" id="tabAll"    onclick="filterTable('all')"   style="background:var(--accent);color:#1a1000;">All (<?= $totalR ?>)</button>
      <button class="btn btn-ghost btn-sm" id="tabKnown"  onclick="filterTable('known')">&#9989; Mastered (<?= $knownR ?>)</button>
      <button class="btn btn-ghost btn-sm" id="tabHard"   onclick="filterTable('hard')">&#127919; Hard (<?= $hardR ?>)</button>
      <?php $notYet = $totalR - $knownR - count(array_diff($rP['current_set'] ?? [], $rKnown)); ?>
    </div>

    <div style="overflow-x:auto;">
      <table class="data-table" id="wordTable">
        <thead>
          <tr>
            <th>#</th>
            <th>English</th>
            <th>Hebrew</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php
          $rKnownInts = array_map('intval', $rKnown);
          $rEverInts  = array_map('intval', $rEver);
          foreach ($rWords as $i => $w):
          $isKnown = in_array((int)$i, $rKnownInts, true);
          $isHard  = in_array((int)$i, $rEverInts,  true);
          if ($isHard && $isKnown) { $status = 'hard';    $badge = '<span class="badge badge-red">&#127919; Hard (learned)</span>'; }
          elseif ($isHard)         { $status = 'hard';    $badge = '<span class="badge badge-red">&#127919; Difficult</span>'; }
          elseif ($isKnown)        { $status = 'known';   $badge = '<span class="badge badge-green">&#9989; Mastered</span>'; }
          else                     { $status = 'pending'; $badge = '<span class="badge badge-muted">&#9203; Pending</span>'; }
        ?>
          <tr data-status="<?= $status ?>" data-word="<?= htmlspecialchars($w['english'], ENT_QUOTES) ?>">
            <td style="color:var(--muted);font-size:.8rem;"><?= $i+1 ?></td>
            <td>
              <span style="font-weight:700;"><?= htmlspecialchars($w['english']) ?></span>
              <button class="speak-mini" onclick="speak(this.closest('tr').dataset.word)" title="Listen">&#128266;</button>
            </td>
            <td dir="rtl" style="font-weight:700;color:var(--accent);"><?= htmlspecialchars($w['hebrew']) ?></td>
            <td><?= $badge ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Action buttons -->
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">
    <a href="?action=quiz" class="btn btn-primary">&#9654;&#65039; Back to Quiz</a>
    <?php if ($hardR > 0): ?>
      <a href="?action=restart_hard" class="btn btn-red">&#127919; Practice difficult words</a>
    <?php endif; ?>
    <a href="?action=reset" class="btn btn-ghost" onclick="return confirm('Start completely over?')">&#128260; Start over</a>
  </div>

  <?php endif; ?>
</div>

<?php /* ====================== PRACTICE PICKER ====================== */
elseif ($action === 'practice_pick' && $currentSlug && $currentStudent):
  $pWords   = loadWords($currentSlug);
  $pProg    = loadProgress($currentSlug);
  $hasHist  = !empty($pProg['ever_missed']) || !empty($pProg['known']);
  $hardCnt  = count($pProg['ever_missed'] ?? []);
  $totalCnt = count($pWords);
?>
<div style="margin-top:24px;">
  <h1>Practice &#127918;</h1>
  <p>Choose a mode and which words to practice.</p>
  <?php if ($totalCnt < 1): ?>
    <div class="card" style="text-align:center;"><p>No words loaded yet. Ask a parent to upload words first.</p></div>
  <?php else: ?>
  <form method="post" action="?action=practice_start" id="practiceForm">
    <input type="hidden" name="pmode"   id="pmodeInput"   value="all">
    <input type="hidden" name="submode" id="submodeInput" value="memory">
    <div class="card">
      <h2>1. Pick a mode</h2>
      <div class="mode-grid">
        <div class="mode-card selected" id="modeMemory" onclick="pickMode('memory')">
          <span class="mode-icon">&#129504;</span>
          <div class="mode-title">Memory</div>
          <div class="mode-desc">Flip cards, test yourself</div>
        </div>
        <div class="mode-card" id="modeSpell" onclick="pickMode('spell')">
          <span class="mode-icon">&#9999;&#65039;</span>
          <div class="mode-title">Spell It</div>
          <div class="mode-desc">Type the Hebrew translation</div>
        </div>
        <div class="mode-card" id="modeFitb" onclick="pickMode('fitb')">
          <span class="mode-icon">&#9997;&#65039;</span>
          <div class="mode-title">Fill in the Blank</div>
          <div class="mode-desc">Drag words into sentences</div>
        </div>
        <div class="mode-card" id="modeMc" onclick="pickMode('mc')">
          <span class="mode-icon">&#127919;</span>
          <div class="mode-title">Multiple Choice</div>
          <div class="mode-desc">Pick the correct Hebrew</div>
        </div>
        <div class="mode-card" id="modeMatch" onclick="pickMode('match')">
          <span class="mode-icon">&#128279;</span>
          <div class="mode-title">Matching Pairs</div>
          <div class="mode-desc">Connect English to Hebrew</div>
        </div>
        <div class="mode-card" id="modeSpeed" onclick="pickMode('speed')">
          <span class="mode-icon">&#9889;</span>
          <div class="mode-title">Speed Round</div>
          <div class="mode-desc">Race against the clock</div>
        </div>
      </div>
    </div>
    <div class="card">
      <h2>2. Which words?</h2>
      <?php if (!$hasHist): ?>
        <p style="font-size:.9rem;">You haven't done the quiz yet &#8212; we'll use all <?= $totalCnt ?> words.</p>
        <input type="hidden" name="pmode" value="all">
      <?php else: ?>
        <button type="button" class="word-sel-btn selected" id="wAll" onclick="pickWords('all')">
          <span class="word-sel-icon">&#127922;</span>
          <div><div class="word-sel-label">All words</div><div class="word-sel-sub"><?= $totalCnt ?> words</div></div>
        </button>
        <?php if ($hardCnt > 0): ?>
        <button type="button" class="word-sel-btn" id="wHard" onclick="pickWords('hard')">
          <span class="word-sel-icon">&#127919;</span>
          <div><div class="word-sel-label">Difficult words only</div><div class="word-sel-sub"><?= $hardCnt ?> words you struggled with</div></div>
        </button>
        <?php if ($hardCnt >= 5): ?>
        <button type="button" class="word-sel-btn" id="wMix" onclick="pickWords('mix')">
          <span class="word-sel-icon">&#128256;</span>
          <div><div class="word-sel-label">Mix</div><div class="word-sel-sub">Half difficult, half random</div></div>
        </button>
        <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div style="text-align:center;">
      <button type="submit" class="btn btn-primary" style="font-size:1.1rem;padding:16px 40px;">
        Start Practice &#128640;
      </button>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php /* ====================== PRACTICE SESSION ====================== */
elseif ($action === 'practice' && $currentSlug && $currentStudent): ?>
<div style="margin-top:24px;" id="practiceApp"
  data-mode="<?= htmlspecialchars($practiceSubMode) ?>"
  data-words='<?= htmlspecialchars(json_encode(array_values($practiceWords), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
  data-questions='<?= $practiceData ? htmlspecialchars(json_encode($practiceData, JSON_UNESCAPED_UNICODE), ENT_QUOTES) : "null" ?>'
  data-ratelimit-ok="<?= $rateLimit['ok'] ? '1' : '0' ?>"
  data-student-calls="<?= $rateLimit['student_calls'] ?>"
  data-global-calls="<?= $rateLimit['global_calls'] ?>"
  data-per-student-limit="<?= DAILY_API_PER_STUDENT ?>">

  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?action=practice_pick" class="btn btn-ghost btn-sm">&#8592; Back</a>
    <h1 style="margin:0;" id="practiceTitle"></h1>
    <span class="level-badge" style="margin:0;" id="practiceCount"></span>
  </div>

  <!-- Loading screen (shown while API call is in progress) -->
  <div id="practiceLoading" style="display:none;">
    <div class="card" style="text-align:center;padding:48px 32px;">
      <div style="font-size:3rem;margin-bottom:16px;">&#129302;</div>
      <h2 id="loadingMsg">Generating questions...</h2>
      <p style="margin-bottom:20px;">Claude is writing your questions</p>
      <div style="background:rgba(255,255,255,.07);border-radius:50px;height:12px;overflow:hidden;max-width:300px;margin:0 auto;">
        <div id="loadingBar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--accent),var(--blue));border-radius:50px;transition:width .4s;"></div>
      </div>
      <p id="loadingStep" style="font-size:.8rem;margin-top:12px;color:var(--muted);"></p>
    </div>
  </div>

  <!-- Main practice container -->
  <div id="practiceContainer"></div>

  <div class="api-info" id="apiInfo" style="margin-top:8px;display:none;">
    API usage today: <span id="apiStudentCalls"><?= $rateLimit['student_calls'] ?></span>/<?= DAILY_API_PER_STUDENT ?> (you) &#183;
    <span id="apiGlobalCalls"><?= $rateLimit['global_calls'] ?></span>/<?= DAILY_API_GLOBAL ?> (global)
  </div>
</div>

<?php /* ====================== ADMIN REPORT ====================== */
elseif ($action === 'admin_report' && $arStudent):
  $arKnown   = $arP['known'] ?? [];
  $arEver    = $arP['ever_missed'] ?? [];
  $arTotal   = count($arWords);
  $arKnownC  = count(array_intersect(array_map('intval', array_keys($arWords)), array_map('intval', $arKnown)));
  $arHardC   = count($arEver);
  $arPct     = $arTotal > 0 ? round($arKnownC / $arTotal * 100) : 0;
?>
<div style="margin-top:24px;">
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?action=admin" class="btn btn-ghost btn-sm">&#8592; Back to Admin</a>
    <h1 style="margin:0;">&#128202; <?= htmlspecialchars($arStudent['name']) ?>'s Report</h1>
    <span class="level-badge" style="margin:0;">Level <?= $arP['level'] ?></span>
  </div>

  <?php if (empty($arWords)): ?>
    <div class="card" style="text-align:center;"><p>No words uploaded for this student yet.</p></div>
  <?php else: ?>

  <div class="stats-row">
    <div class="stat-box stat-green"><div class="stat-num"><?= $arKnownC ?></div><div class="stat-label">&#9989; Mastered</div></div>
    <div class="stat-box stat-red"><div class="stat-num"><?= $arHardC ?></div><div class="stat-label">&#127919; Hard words</div></div>
    <div class="stat-box stat-blue"><div class="stat-num"><?= $arTotal ?></div><div class="stat-label">&#128218; Total</div></div>
    <div class="stat-box"><div class="stat-num" style="color:var(--accent);"><?= $arPct ?>%</div><div class="stat-label">&#128200; Score</div></div>
  </div>

  <div class="progress-wrap">
    <div class="progress-label"><span>Mastered</span><span><?= $arKnownC ?> / <?= $arTotal ?></span></div>
    <div class="progress-bar"><div class="progress-fill" style="width:<?= $arPct ?>%"></div></div>
  </div>
  <?php if ($arHardC > 0): ?>
  <div class="progress-wrap">
    <div class="progress-label"><span style="color:var(--accent2);">Had difficulty with</span><span><?= $arHardC ?> / <?= $arTotal ?></span></div>
    <div class="progress-bar"><div class="progress-fill" style="width:<?= round($arHardC/$arTotal*100) ?>%;background:linear-gradient(90deg,var(--accent2),#ff9f43);"></div></div>
  </div>
  <?php endif; ?>

  <div class="card" style="padding:20px;">
    <h2>All Words</h2>
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
      <button class="btn btn-sm ar-tab" id="arTabAll"   onclick="arFilter('all')"   style="background:var(--accent);color:#1a1000;">All (<?= $arTotal ?>)</button>
      <button class="btn btn-ghost btn-sm ar-tab" id="arTabKnown" onclick="arFilter('known')">&#9989; Mastered (<?= $arKnownC ?>)</button>
      <button class="btn btn-ghost btn-sm ar-tab" id="arTabHard"  onclick="arFilter('hard')">&#127919; Hard (<?= $arHardC ?>)</button>
    </div>
    <div style="overflow-x:auto;">
      <table class="data-table" id="arTable">
        <thead><tr><th>#</th><th>English</th><th>Hebrew</th><th>Status</th></tr></thead>
        <tbody>
        <?php
          $arKnownInts = array_map('intval', $arKnown);
          $arEverInts  = array_map('intval', $arEver);
          foreach ($arWords as $i => $w):
          $isKnown = in_array((int)$i, $arKnownInts, true);
          $isHard  = in_array((int)$i, $arEverInts,  true);
          if ($isHard && $isKnown) { $st = 'hard';    $badge = '<span class="badge badge-red">&#127919; Hard (learned)</span>'; }
          elseif ($isHard)         { $st = 'hard';    $badge = '<span class="badge badge-red">&#127919; Difficult</span>'; }
          elseif ($isKnown)        { $st = 'known';   $badge = '<span class="badge badge-green">&#9989; Mastered</span>'; }
          else                     { $st = 'pending'; $badge = '<span class="badge badge-muted">&#9203; Pending</span>'; }
        ?>
          <tr data-ar-status="<?= $st ?>" data-word="<?= htmlspecialchars($w['english'], ENT_QUOTES) ?>">
            <td style="color:var(--muted);font-size:.8rem;"><?= $i+1 ?></td>
            <td><span style="font-weight:700;"><?= htmlspecialchars($w['english']) ?></span>
              <button class="speak-mini" onclick="speak(this.closest('tr').dataset.word)">&#128266;</button></td>
            <td dir="rtl" style="font-weight:700;color:var(--accent);"><?= htmlspecialchars($w['hebrew']) ?></td>
            <td><?= $badge ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php /* ====================== ADMIN ====================== */
elseif ($action === 'admin'):
$students = loadStudents(); // always render with freshest data
?>

<div style="margin-top:24px;">
  <h1>Admin Panel</h1>

  <?php if ($message): ?>
    <div class="msg-box <?= str_starts_with($message,'&#9989;')?'msg-ok':(str_starts_with($message,'&#9888;&#65039;')?'msg-warn':'msg-err') ?>"><?= $message ?></div>
  <?php endif; ?>

  <!-- Add student -->
  <div class="card">
    <h2>&#10133; Add Student</h2>
    <form method="post" action="?action=add_student">
      <input type="hidden" name="emoji" id="selectedEmoji" value="&#128102;">
      <!-- Emoji picker -->
      <div style="margin-bottom:14px;">
        <div style="font-size:.8rem;color:var(--muted);font-weight:700;margin-bottom:8px;">CHOOSE AVATAR</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
          <?php foreach (['&#128102;','&#128103;','&#129490;','&#128118;','&#128104;','&#128105;','&#129489;','&#128116;','&#128117;','&#129491;','&#129492;','&#128113;','&#129493;','&#127891;','&#11088;','&#129409;','&#128047;','&#128059;','&#129418;','&#128056;'] as $e): ?>
          <button type="button" class="emoji-opt" data-emoji="<?= $e ?>" onclick="pickEmoji(this)"
            style="font-size:1.6rem;padding:6px 10px;border-radius:10px;border:2px solid transparent;background:var(--card2);cursor:pointer;transition:all .15s;"><?= $e ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-row">
        <input class="text-input" type="text" name="student_name" placeholder="Name (e.g. Yoav)" required maxlength="30">
        <input class="text-input" type="text" name="student_pin" placeholder="PIN (optional)" maxlength="8" inputmode="numeric" style="max-width:160px;">
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
      <p style="font-size:.8rem;margin:0;">Leave PIN blank if you don't want a password.</p>
    </form>
  </div>

  <!-- Student list -->
  <?php if (!empty($students)): ?>
  <div class="card">
    <h2>&#128101; Students</h2>
    <?php foreach ($students as $slug => $s):
      $wc  = count(loadWords($slug));
      $prg = loadProgress($slug);
      $kc  = count($prg['known']);
      $lv  = $prg['level'];
    ?>
    <div class="admin-student-row">
      <div class="avatar"><?= $s['emoji'] ?? '&#128102;' ?></div>
      <div class="admin-student-info">
        <strong><?= htmlspecialchars($s['name']) ?></strong>
        <span><?= $wc ?> words &#183; Level <?= $lv ?> &#183; <?= $kc ?>/<?= $wc ?> known<?= $s['pin']!==''?' &#183; &#128274; PIN set':'' ?></span>
      </div>
      <div class="admin-student-actions">
        <!-- Upload words for this student -->
        <form method="post" action="?action=upload" enctype="multipart/form-data" style="display:inline;">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
          <label class="btn btn-blue btn-sm" style="cursor:pointer;">
            &#128228; Words
            <input type="file" name="wordlist" accept=".xlsx,.csv" style="display:none;" onchange="this.closest('form').submit()">
          </label>
        </form>
        <!-- View report -->
        <a href="?action=admin_report&slug=<?= htmlspecialchars($slug) ?>" class="btn btn-ghost btn-sm">&#128202; Report</a>
        <!-- Reset progress -->
        <form method="post" action="?action=reset_student" style="display:inline;">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
          <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Reset progress for <?= htmlspecialchars($s['name']) ?>?')">&#128260; Reset</button>
        </form>
        <!-- Delete student -->
        <form method="post" action="?action=delete_student" style="display:inline;">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
          <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete <?= htmlspecialchars($s['name']) ?> and all their data?')">&#128465;</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- API usage -->
  <?php
    $todayRL = checkRateLimit('_admin_view_');
    $gf2 = globalRateLimitFile();
    $gd2 = file_exists($gf2) ? json_decode(file_get_contents($gf2), true) : [];
    $gCallsToday = ($gd2['date'] ?? '') === date('Y-m-d') ? (int)($gd2['calls'] ?? 0) : 0;
  ?>
  <div class="card">
    <h2>&#129302; API Usage Today</h2>
    <div class="stats-row" style="margin-bottom:12px;">
      <div class="stat-box stat-blue">
        <div class="stat-num"><?= $gCallsToday ?></div>
        <div class="stat-label">Used today</div>
      </div>
      <div class="stat-box">
        <div class="stat-num" style="color:var(--accent);"><?= DAILY_API_GLOBAL ?></div>
        <div class="stat-label">Daily limit</div>
      </div>
      <div class="stat-box">
        <div class="stat-num" style="color:var(--green);"><?= max(0, DAILY_API_GLOBAL - $gCallsToday) ?></div>
        <div class="stat-label">Remaining</div>
      </div>
    </div>
    <?php
      $cfgFile = __DIR__ . '/wordwise_config.php';
      $cfgContent = file_get_contents($cfgFile);
    ?>
    <form method="post" action="?action=save_ratelimit" style="margin-top:12px;">
      <div class="form-row" style="align-items:center;flex-wrap:wrap;gap:10px;">
        <label style="font-size:.85rem;font-weight:700;color:var(--muted);white-space:nowrap;">Per student / day:</label>
        <input class="text-input" type="number" name="per_student" min="0" max="100"
          value="<?= DAILY_API_PER_STUDENT ?>" style="max-width:80px;">
        <label style="font-size:.85rem;font-weight:700;color:var(--muted);white-space:nowrap;">Global / day:</label>
        <input class="text-input" type="number" name="global_limit" min="0" max="1000"
          value="<?= DAILY_API_GLOBAL ?>" style="max-width:80px;">
        <label style="font-size:.85rem;font-weight:700;color:var(--muted);white-space:nowrap;">
          <input type="checkbox" name="disable_limit" value="1" <?= DAILY_API_GLOBAL >= 9999 ? 'checked' : '' ?>>
          Disable limit
        </label>
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
      </div>
      <p style="font-size:.75rem;margin-top:6px;">Set to 0 or check "Disable limit" to remove restrictions.</p>
      <p style="font-size:.75rem;margin-top:4px;">&#128172; Alt-answer checks: <?= defined('DAILY_ALT_PER_STUDENT') ? DAILY_ALT_PER_STUDENT : 15 ?>/student/day (set in wordwise_config.php as DAILY_ALT_PER_STUDENT).</p>
    </form>
    <form method="post" action="?action=reset_ratelimit" style="margin-top:8px;">
      <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Reset the global API usage count for today?')">&#128260; Reset today's usage</button>
    </form>
  </div>

  <!-- Format hint -->
  <div class="card">
    <h2>&#128203; File Format</h2>
    <p>Upload a <strong>.xlsx</strong> or <strong>.csv</strong> for each student. Two columns, header row:</p>
    <table class="data-table">
      <tr><th>Column A</th><th>Column B</th></tr>
      <tr><td>English</td><td>Hebrew</td></tr>
      <tr><td>apple</td><td>&#1514;&#1508;&#1493;&#1495;</td></tr>
      <tr><td>book</td><td>&#1505;&#1508;&#1512;</td></tr>
      <tr><td>...</td><td>...</td></tr>
    </table>
  </div>
</div>

<?php endif; ?>
</div><!-- /container -->

<!-- PIN Modal JS -->
<script>
function openLogin(slug, name, hasPin) {
  if (hasPin !== '1') {
    // No PIN \u2014 submit directly without showing modal
    var f = document.createElement('form');
    f.method = 'post';
    f.action = '?action=login';
    var s = document.createElement('input'); s.type='hidden'; s.name='slug'; s.value=slug; f.appendChild(s);
    var p = document.createElement('input'); p.type='hidden'; p.name='pin';  p.value='';   f.appendChild(p);
    document.body.appendChild(f);
    f.submit();
    return;
  }
  document.getElementById('modalSlug').value = slug;
  document.getElementById('modalName').textContent = name;
  document.getElementById('pinSection').style.display = 'block';
  document.getElementById('loginModal').classList.add('open');
  setTimeout(function() {
    var pi = document.getElementById('pinInput');
    if (pi) { pi.value = ''; pi.focus(); }
  }, 100);
}
function closeLogin() {
  document.getElementById('loginModal').classList.remove('open');
}
var lm = document.getElementById('loginModal');
if (lm) lm.addEventListener('click', function(e) {
  if (e.target === this) closeLogin();
});

// Global escape helper (used by quiz alt-answer and practice engine)
function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) {
  return String(s||'').replace(/'/g,"\'").replace(/"/g,'&quot;');
}

function speak(word) {
  if (!window.speechSynthesis) {
    alert('Your browser does not support text-to-speech.');
    return;
  }
  // Stop anything currently playing
  window.speechSynthesis.cancel();
  // Chrome bug: after cancel(), need a tiny delay before speaking
  setTimeout(function() {
    var u = new SpeechSynthesisUtterance(word);
    u.lang = 'en-US';
    u.rate = 0.85;
    u.onerror = function(e) { console.error('TTS error:', e.error); };
    window.speechSynthesis.speak(u);
  }, 100);
}

function reveal() {
  document.getElementById('hebrew').classList.remove('hidden');
  document.getElementById('revealBtn').style.display = 'none';
  document.getElementById('answerBtns').style.display = 'flex';
  var t = document.getElementById('quizAltToggle');
  if (t) t.style.display = 'inline-flex';
}
function toggleQuizAlt() {
  var wrap = document.getElementById('quizAltWrap');
  if (!wrap) return;
  var open = wrap.style.display !== 'none';
  wrap.style.display = open ? 'none' : 'block';
  if (!open) {
    var inp = document.getElementById('quizAltInput');
    if (inp) { inp.value = ''; inp.focus(); }
    var res = document.getElementById('quizAltResult');
    if (res) res.style.display = 'none';
  }
}
function quizCheckAlt() {
  var input = document.getElementById('quizAltInput');
  var result = document.getElementById('quizAltResult');
  if (!input || !result) return;
  var alt = input.value.trim();
  if (!alt) return;
  var card = document.getElementById('wordCard');
  var english = card ? card.dataset.word : '';
  var hebrew = document.getElementById('hebrew');
  var correct = hebrew ? hebrew.textContent.trim() : '';
  result.style.display = 'block';
  result.innerHTML = '<span style="color:var(--muted);font-size:.85rem;">&#1489;&#1493;&#1491;&#1511;... &#9203;</span>';
  var fd = new FormData();
  fd.append('english', english);
  fd.append('correct', correct);
  fd.append('alt', alt);
  fetch('?action=api_check_alt', {method:'POST', body:fd, credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(data) { renderAltResult(result, data); })
    .catch(function(err) {
      result.innerHTML = '<span style="color:var(--muted);font-size:.85rem;">&#1513;&#1490;&#1497;&#1488;&#1514;: ' + esc(String(err)) + '</span>';
    });
}

// Auto-speak current word on quiz page load
window.addEventListener('load', function() {
  var card = document.getElementById('wordCard');
  if (card) setTimeout(function() { speak(card.dataset.word); }, 600);
});

function speakCurrent() {
  var card = document.getElementById('wordCard');
  if (card) speak(card.dataset.word);
}

function filterTable(type) {
  document.querySelectorAll('#wordTable tbody tr').forEach(tr => {
    tr.classList.toggle('hidden-row', type !== 'all' && tr.dataset.status !== type);
  });
  [['tabAll','all'],['tabKnown','known'],['tabHard','hard']].forEach(([id, t]) => {
    const el = document.getElementById(id); if (!el) return;
    const active = t === type;
    el.className = 'btn btn-sm' + (active ? '' : ' btn-ghost');
    el.style.background = active ? 'var(--accent)' : '';
    el.style.color = active ? '#1a1000' : '';
  });
}


// PRACTICE ENGINE
(function() {
  var app = document.getElementById('practiceApp');
  if (!app) return;

  var mode        = app.dataset.mode;
  var words       = JSON.parse(app.dataset.words || '[]');
  var cachedQs    = JSON.parse(app.dataset.questions || 'null');
  var rateLimitOk = app.dataset.ratelimitOk === '1';
  var perStudentLim = parseInt(app.dataset.perStudentLimit) || 3;
  var container   = document.getElementById('practiceContainer');
  var loading     = document.getElementById('practiceLoading');
  var apiInfo     = document.getElementById('apiInfo');
  if (!container) return;

  var qs       = [];
  var qIndex   = 0;
  var score    = 0;
  var wrongs   = [];
  var answered = false;
  var fitbAnswers = {};
  var dragWord = null;
  var dragFromBlank = null;
  var selectedChip = null;
  var selectedChipWord = null;
  var selectedChipFromBlank = null;

  function setTitle(t) { var el=document.getElementById('practiceTitle'); if(el) el.textContent=t; }
  function setCount(t) { var el=document.getElementById('practiceCount'); if(el) el.textContent=t; }

  // \u2500\u2500 Loading progress animation \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  var loadSteps = [
    [10,  'Picking your words...'],
    [30,  'Thinking about categories...'],
    [55,  'Writing sentences...'],
    [75,  'Choosing distractors...'],
    [90,  'Almost ready...'],
  ];
  var loadTimer = null;
  function startLoading() {
    if (!loading) return;
    loading.style.display = 'block';
    container.style.display = 'none';
    var step = 0;
    var bar  = document.getElementById('loadingBar');
    var msg  = document.getElementById('loadingStep');
    loadTimer = setInterval(function() {
      if (step < loadSteps.length) {
        if (bar) bar.style.width = loadSteps[step][0] + '%';
        if (msg) msg.textContent  = loadSteps[step][1];
        step++;
      }
    }, 900);
  }
  function stopLoading() {
    clearInterval(loadTimer);
    var bar = document.getElementById('loadingBar');
    if (bar) bar.style.width = '100%';
    setTimeout(function() {
      if (loading) loading.style.display = 'none';
      container.style.display = 'block';
    }, 300);
  }

  // \u2500\u2500 Fetch questions async \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  function loadQuestions(cb) {
    if (mode === 'memory' || mode === 'spell' || mode === 'match') {
      qs = words.map(function(w) { return {english:w.english, hebrew:w.hebrew}; });
      cb();
      return;
    }
    if (cachedQs) {
      qs = cachedQs;
      cb();
      return;
    }
    startLoading();
    fetch('?action=api_questions', {credentials:'same-origin'})
      .then(function(r) { return r.json(); })
      .then(function(data) {
        stopLoading();
        if (data.questions) {
          qs = data.questions;
          // Update rate limit display
          if (data.rateLimit) {
            var sc = document.getElementById('apiStudentCalls');
            var gc = document.getElementById('apiGlobalCalls');
            if (sc) sc.textContent = data.rateLimit.student_calls;
            if (gc) gc.textContent = data.rateLimit.global_calls;
            rateLimitOk = data.rateLimit.ok;
          }
          cb();
        } else {
          container.style.display = 'block';
          container.innerHTML = '<div class="card" style="text-align:center;">'
            + '<p>Could not load questions. <a href="?action=practice_pick" class="btn btn-primary" style="margin-top:12px;">Try again</a></p></div>';
        }
      })
      .catch(function() {
        stopLoading();
        container.style.display = 'block';
        container.innerHTML = '<div class="card" style="text-align:center;">'
          + '<p>Network error. <a href="?action=practice_pick" class="btn btn-primary" style="margin-top:12px;">Back</a></p></div>';
      });
  }

  function pbar() {
    return '<div class="pq-bar"><div class="pq-bar-track"><div class="pq-bar-fill" style="width:'
      + Math.round(qIndex / Math.max(qs.length,1) * 100) + '%"></div></div></div>';
  }

  // \u2500\u2500 MEMORY \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  function renderMemory() {
    if (qIndex >= qs.length) { renderResult(); return; }
    var q = qs[qIndex];
    setCount((qIndex+1) + ' / ' + qs.length);
    container.innerHTML = pbar() +
      '<div class="flip-card" id="flipCard" onclick="flipCard()">' +
        '<div class="flip-inner">' +
          '<div class="flip-front"><span class="flip-word">' + esc(q.english) + '</span>' +
            '<span class="flip-hint">Click to reveal Hebrew</span></div>' +
          '<div class="flip-back"><span class="flip-word" style="direction:rtl">' + esc(q.hebrew) + '</span>' +
            '<span class="flip-hint">Did you know it?</span></div>' +
        '</div></div>' +
      '<div id="memBtns" style="display:none;">' +
        '<div class="answer-btns" style="flex-wrap:wrap;">' +
          '<button class="btn btn-red" onclick="memAnswer(false)">\u274C Did Not Know</button>' +
          '<button class="btn btn-green" onclick="memAnswer(true)">\u2705 Knew it!</button>' +
        '</div>' +
        '<div style="text-align:center;margin-top:12px;">' +
          '<button class="btn btn-ghost btn-sm" onclick="openAltCheck()">\u{1F914} Is another answer OK?</button>' +
        '</div>' +
        '<div id="altPanel" style="display:none;margin-top:14px;">' +
          '<div class="card" style="padding:16px;">' +
            '<p style="font-size:.85rem;margin-bottom:8px;">Type your alternative Hebrew answer:</p>' +
            '<div style="display:flex;gap:8px;align-items:center;">' +
              '<input id="altInput" class="text-input" type="text" placeholder="\u05DC\u05DE\u05E9\u05DC..." dir="rtl" style="flex:1;" onkeydown="if(event.keyCode===13)checkAlt()">' +
              '<button class="btn btn-blue btn-sm" onclick="checkAlt()">Check</button>' +
            '</div>' +
            '<div id="altResult" style="margin-top:12px;display:none;"></div>' +
          '</div>' +
        '</div>' +
      '</div>';
    speak(q.english);
  }
  window.flipCard = function() {
    var c = document.getElementById('flipCard'); if (!c) return;
    c.classList.toggle('flipped');
    if (c.classList.contains('flipped')) document.getElementById('memBtns').style.display = 'block';
  };
  window.memAnswer = function(knew) {
    if (knew) score++;
    else { var wq = Object.assign({}, qs[qIndex]); wq.i = qs[qIndex].i !== undefined ? qs[qIndex].i : qIndex; wrongs.push(wq); }
    qIndex++; renderMemory();
  };
  window.openAltCheck = function() {
    var p = document.getElementById('altPanel');
    if (!p) return;
    var open = p.style.display !== 'none';
    p.style.display = open ? 'none' : 'block';
    if (!open) setTimeout(function(){ var i=document.getElementById('altInput'); if(i) i.focus(); }, 50);
  };
  window.checkAlt = function() {
    var input = document.getElementById('altInput');
    var result = document.getElementById('altResult');
    if (!input || !result) return;
    var alt = input.value.trim();
    if (!alt) return;
    var q = qs[qIndex];
    result.style.display = 'block';
    result.innerHTML = '<span style="color:var(--muted);font-size:.85rem;">Checking with AI... \u23F3</span>';
    var fd = new FormData();
    fd.append('english', q.english || '');
    fd.append('correct', q.hebrew  || '');
    fd.append('alt',     alt);
    fetch('?action=api_check_alt', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (data.error) { result.innerHTML = '<span style="color:var(--muted);font-size:.85rem;">' + esc(data.error) + '</span>'; return; }
        var colors = {perfect:'var(--green)', good:'var(--green)', partial:'var(--accent)', wrong:'var(--accent2)'};
        var labels = {perfect:'\u2705 Perfect match!', good:'\u{1F44D} Pretty good match', partial:'\u26A0\uFE0F Partial match', wrong:'\u274C Not a match'};
        var col = colors[data.rating] || 'var(--muted)';
        var lbl = labels[data.rating] || data.rating;
        result.innerHTML =
          '<div style="border-left:3px solid '+col+';padding:8px 12px;border-radius:0 8px 8px 0;background:rgba(255,255,255,.04);">' +
            '<div style="font-weight:800;color:'+col+';margin-bottom:6px;">'+lbl+'</div>' +
            '<details><summary style="font-size:.8rem;color:var(--muted);cursor:pointer;">Why? (click to expand)</summary>' +
              '<p style="font-size:.85rem;margin-top:6px;direction:rtl;text-align:right;">'+esc(data.explanation||'')+'</p>' +
            '</details>' +
          '</div>';
      })
      .catch(function() { result.innerHTML = '<span style="color:var(--muted);font-size:.85rem;">Could not reach AI.</span>'; });
  };

  // \u2500\u2500 SPELL MODE \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  function renderSpell() {
    if (qIndex >= qs.length) { renderResult(); return; }
    var q = qs[qIndex];
    setCount((qIndex+1) + ' / ' + qs.length);
    container.innerHTML = pbar() +
      '<div class="word-card" style="text-align:center;padding:36px 24px;">' +
        '<p style="font-size:.9rem;color:var(--muted);margin-bottom:6px;">What is the English word for:</p>' +
        '<div style="font-family:\'Rubik\',sans-serif;font-size:2.8rem;font-weight:800;direction:rtl;color:var(--accent);margin:12px 0;letter-spacing:1px;">' + esc(q.hebrew) + '</div>' +
      '</div>' +
      '<div style="margin-top:16px;">' +
        '<div style="display:flex;gap:10px;align-items:center;max-width:400px;margin:0 auto;">' +
          '<input id="spellInput" class="text-input" type="text" placeholder="Type English here..." autocomplete="off" autocorrect="off" spellcheck="false" ' +
            'style="font-family:\'Fredoka One\',\'Nunito\',sans-serif;font-size:1.2rem;text-align:center;letter-spacing:1px;" ' +
            'onkeydown="if(event.keyCode===13)checkSpell()">' +
          '<button class="btn btn-primary" onclick="checkSpell()">\u2713</button>' +
        '</div>' +
        '<div id="spellResult" style="margin-top:14px;text-align:center;display:none;"></div>' +
        '<div id="spellNext" style="text-align:center;margin-top:14px;display:none;">' +
          '<button class="btn btn-primary" onclick="spellNext()">Next \u2192</button>' +
        '</div>' +
      '</div>';
    setTimeout(function(){ var i=document.getElementById('spellInput'); if(i) i.focus(); }, 80);
  }
  function normalizeSpell(s) {
    // Lowercase, strip parentheses+contents, strip punctuation, collapse spaces
    return (s || '')
      .replace(/\(.*?\)/g, '')   // remove (something) parts
      .replace(/[^a-zA-Z0-9 ]/g, ' ')  // strip special chars
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }
  window.checkSpell = function() {
    var input = document.getElementById('spellInput');
    var result = document.getElementById('spellResult');
    var nextBtn = document.getElementById('spellNext');
    if (!input || !result) return;
    var typed = input.value.trim();
    if (!typed) return;
    input.disabled = true;
    document.querySelector('button[onclick="checkSpell()"]').disabled = true;
    var q = qs[qIndex];
    var correct = (q.english || '').trim();
    var isCorrect = normalizeSpell(typed) === normalizeSpell(correct);
    result.style.display = 'block';
    nextBtn.style.display = 'block';
    if (isCorrect) {
      score++;
      result.innerHTML = '<div style="color:var(--green);font-size:1.1rem;font-weight:800;">\u2705 Correct!</div>';
    } else {
      var wq2 = Object.assign({}, q); wq2.i = q.i !== undefined ? q.i : qIndex; wrongs.push(wq2);
      result.innerHTML = '<div style="color:var(--accent2);font-size:1.1rem;font-weight:800;">\u274C ' + esc(typed) + '</div>' +
        '<div style="color:var(--muted);margin-top:4px;">Correct: <strong style="color:var(--text);">' + esc(correct) + '</strong></div>';
    }
  };
  window.spellNext = function() { qIndex++; renderSpell(); };

  // \u2500\u2500 MULTIPLE CHOICE \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  function renderMC() {
    if (qIndex >= qs.length) { renderResult(); return; }
    var q = qs[qIndex];
    setCount((qIndex+1) + ' / ' + qs.length);
    // Ensure all 4 options are unique
    var distractors = (q.d||[]).filter(function(d){ return d !== q.h; });
    var unique = [];
    distractors.forEach(function(d){ if (unique.indexOf(d)<0) unique.push(d); });
    var opts = shuffle([q.h].concat(unique.slice(0,3)));
    var btns = opts.map(function(opt,i) {
      return '<button class="mc-btn" id="mcb'+i+'" onclick="mcAnswer(this,\''+escAttr(opt)+'\',\''+escAttr(q.h)+'\')"'
        + ' style="font-family:\'Rubik\',sans-serif;font-size:1.15rem;line-height:1.4;">'
        + esc(opt) + '</button>';
    }).join('');
    container.innerHTML = pbar() +
      '<div class="card"><div class="english-word" style="font-size:2.2rem;margin-bottom:6px;">'
        + esc(q.e) + '</div>' +
        '<p style="font-size:.85rem;margin-bottom:0;">What is the Hebrew translation?</p></div>' +
      '<div class="mc-grid">' + btns + '</div>' +
      '<div id="mcNext" style="text-align:center;margin-top:20px;display:none;">' +
        '<button class="btn btn-primary" onclick="mcNext()">Next \u2192</button></div>';
    speak(q.e);
  }
  window.mcAnswer = function(btn, chosen, correct) {
    if (answered) return; answered = true;
    document.querySelectorAll('.mc-btn').forEach(function(b) {
      b.disabled = true;
      if (b.textContent.trim() === correct) b.classList.add('correct');
      else if (b === btn && chosen !== correct) b.classList.add('wrong');
    });
    if (chosen === correct) score++;
    else { var wqm = Object.assign({}, qs[qIndex]); wqm.i = qs[qIndex].i !== undefined ? qs[qIndex].i : qIndex; wrongs.push(wqm); }
    document.getElementById('mcNext').style.display = 'block';
  };
  window.mcNext = function() { answered = false; qIndex++; renderMC(); };

  // -- FILL IN THE BLANK
  function renderFITB() {
    if (!qs.length) { container.innerHTML = '<p>No questions.</p>'; return; }
    setCount(qs.length + ' sentences');

    var sentencesHtml = qs.map(function(q, i) {
      var parts = (q.s || '').split('_____');
      return '<div class="card fitb-item" style="padding:16px 20px;margin-bottom:10px;">'
        + '<div style="font-size:.75rem;color:var(--muted);margin-bottom:4px;">' + (i+1) + '.</div>'
        + '<div class="fitb-sentence">'
          + esc(parts[0]||'')
          + '<span class="fitb-blank" id="blank'+i+'" data-index="'+i+'">\u00a0</span>'
          + esc(parts[1]||'')
        + '</div></div>';
    }).join('');

    var wordList = qs.map(function(q) { return q.e; });
    var bankHtml = shuffle(wordList.slice()).map(function(w) {
      // Use safeId for id/data attributes (replace apostrophes with __), esc for display
      var sid = w.replace(/'/g, '__').replace(/[^a-zA-Z0-9_\- ]/g, '_');
      return '<div class="word-chip" draggable="true" id="chip_' + sid + '" data-word="' + esc(w) + '">' + esc(w) + '</div>';
    }).join('');

    // Side-by-side layout: sentences left, sticky bank right
    container.innerHTML =
      '<div class="fitb-layout">'
        + '<div id="fitbSentences">' + sentencesHtml + '</div>'
        + '<div class="fitb-bank-sticky">'
            + '<div class="fitb-bank-card" id="bankCard">'
              + '<p style="font-size:.75rem;font-weight:800;color:var(--muted);letter-spacing:1px;margin-bottom:8px;">WORD BANK</p>'
              + '<p style="font-size:.7rem;color:var(--muted);margin-bottom:10px;">Drag to blank \u2022 Drag filled word back here</p>'
              + '<div class="word-bank" id="wordBank">' + bankHtml + '</div>'
            + '</div>'
            + '<div style="margin-top:12px;">'
              + '<div id="checkBtn" class="btn btn-ghost" style="width:100%;text-align:center;cursor:not-allowed;opacity:.4;">Check \u2713</div>'
            + '</div>'
          + '</div>'
      + '</div>';

    setupFITBEvents();
  }

  function setupFITBEvents() {
    // Blanks: dragover / drop / click
    document.querySelectorAll('.fitb-blank').forEach(function(blank) {
      var idx = parseInt(blank.dataset.index);
      blank.addEventListener('dragover',  function(e){ e.preventDefault(); blank.classList.add('drag-over'); });
      blank.addEventListener('dragleave', function(){ blank.classList.remove('drag-over'); });
      blank.addEventListener('drop',      function(e){ e.preventDefault(); blank.classList.remove('drag-over'); doDropToBlank(idx); });
      blank.addEventListener('click',     function(){ doClickBlank(idx); });
    });

    // Word bank card: accept drops from filled blanks
    var bankCard = document.getElementById('bankCard');
    if (bankCard) {
      bankCard.addEventListener('dragover',  function(e){ e.preventDefault(); bankCard.classList.add('drag-over'); });
      bankCard.addEventListener('dragleave', function(){ bankCard.classList.remove('drag-over'); });
      bankCard.addEventListener('drop',      function(e){ e.preventDefault(); bankCard.classList.remove('drag-over'); doDropToBank(); });
    }

    // Chips in bank: dragstart / click
    document.querySelectorAll('.word-chip').forEach(function(chip) {
      var word = chip.dataset.word;
      chip.addEventListener('dragstart', function(){ doDragStart(word, null); });
      chip.addEventListener('dragend',   function(){ chip.classList.remove('dragging'); });
      chip.addEventListener('click',     function(){ doClickChip(chip, word, null); });
    });
    // Attach check button via addEventListener (onclick on disabled button is unreliable)
  }

  // Called when a filled blank starts being dragged
  function setupBlankDrag(blank, idx, word) {
    blank.setAttribute('draggable', 'true');
    blank.addEventListener('dragstart', function(e){
      e.stopPropagation();
      doDragStart(word, idx);
      blank.classList.add('dragging');
    });
    blank.addEventListener('dragend', function(){ blank.classList.remove('dragging'); });
  }

  function doDragStart(word, fromBlank) {
    dragWord = word;
    dragFromBlank = fromBlank !== undefined ? fromBlank : null;
    if (dragFromBlank === null) {
      // came from bank chip
      for (var i in fitbAnswers) { if (fitbAnswers[i] === word) { dragFromBlank = parseInt(i); break; } }
    }
    var chip = document.getElementById('chip_' + word.replace(/'/g,'__').replace(/[^a-zA-Z0-9_\- ]/g,'_'));
    if (chip) chip.classList.add('dragging');
  }
  function doDropToBlank(idx) {
    if (!dragWord) return;
    if (dragFromBlank !== null && dragFromBlank !== idx) clearBlank(dragFromBlank);
    fillBlank(idx, dragWord);
    dragWord = null; dragFromBlank = null;
  }
  function doDropToBank() {
    if (!dragWord) return;
    if (dragFromBlank !== null) clearBlank(dragFromBlank);
    dragWord = null; dragFromBlank = null;
  }
  function doClickChip(el, word, fromBlank) {
    if (selectedChip) selectedChip.style.outline = '';
    if (selectedChip === el) { selectedChip = null; selectedChipWord = null; return; }
    selectedChip = el; selectedChipWord = word; el.style.outline = '2px solid var(--accent)';
    selectedChipFromBlank = fromBlank;
    if (fromBlank === null || fromBlank === undefined) {
      selectedChipFromBlank = null;
      for (var i in fitbAnswers) { if (fitbAnswers[i] === word) { selectedChipFromBlank = parseInt(i); break; } }
    }
  }
  function doClickBlank(idx) {
    if (selectedChip) {
      var w = selectedChipWord;
      if (selectedChipFromBlank !== null && selectedChipFromBlank !== idx) clearBlank(selectedChipFromBlank);
      selectedChip.style.outline = ''; selectedChip = null; selectedChipWord = null; selectedChipFromBlank = null;
      fillBlank(idx, w); return;
    }
    if (fitbAnswers[idx]) clearBlank(idx);
  }
  function clearBlank(idx) {
    var word = fitbAnswers[idx]; if (!word) return;
    delete fitbAnswers[idx];
    var blank = document.getElementById('blank'+idx);
    if (blank) {
      blank.textContent = '\u00a0';
      blank.classList.remove('correct','wrong');
      blank.removeAttribute('draggable');
    }
    var chip = document.getElementById('chip_'+word.replace(/'/g,'__').replace(/[^a-zA-Z0-9_\- ]/g,'_')); if (chip) chip.style.display = '';
    updateCheckBtn();
  }
  function fillBlank(idx, word) {
    var prev = fitbAnswers[idx];
    if (prev && prev !== word) {
      var pcId = prev.replace(/'/g,'__').replace(/[^a-zA-Z0-9_\- ]/g,'_');
      var oc = document.getElementById('chip_'+pcId); if(oc) oc.style.display='';
    }
    fitbAnswers[idx] = word;
    var blank = document.getElementById('blank'+idx);
    if (blank) {
      blank.textContent = word;
      setupBlankDrag(blank, idx, word);
    }
    var chipId = word.replace(/'/g,'__').replace(/[^a-zA-Z0-9_\- ]/g,'_');
    var chip = document.getElementById('chip_'+chipId); if (chip) chip.style.display = 'none';
    updateCheckBtn();
  }
  function updateCheckBtn() {
    var btn = document.getElementById('checkBtn');
    if (!btn) return;
    var allFilled = Object.keys(fitbAnswers).length >= qs.length;
    if (allFilled) {
      btn.className = 'btn btn-primary';
      btn.style.cssText = 'width:100%;text-align:center;cursor:pointer;opacity:1;';
      btn.onclick = function() { checkFITB(); };
    } else {
      btn.className = 'btn btn-ghost';
      btn.style.cssText = 'width:100%;text-align:center;cursor:not-allowed;opacity:.4;';
      btn.onclick = null;
    }
  }
  function normalizeApos(s) {
    return (s||'').replace(/[\u2018\u2019\u201A\u201B\u2032\u0060\u00B4]/g, "'").replace(/\\/g, '');
  }
  window.checkFITB = function() {
    qs.forEach(function(q,i) {
      var blank = document.getElementById('blank'+i); if (!blank) return;
      var ans     = normalizeApos(fitbAnswers[i] || '');
      var correct = normalizeApos(q.e);
      if (ans.toLowerCase() === correct.toLowerCase()) { blank.classList.add('correct'); score++; }
      else { blank.classList.add('wrong'); blank.textContent = (fitbAnswers[i]||'') + ' (\u2717 ' + q.e + ')'; wrongs.push(q); }
    });
    var btn = document.getElementById('checkBtn'); if (btn) btn.style.display = 'none';
    var rb = document.createElement('div');
    rb.style.cssText = 'text-align:center;margin-top:16px;';
    rb.innerHTML = '<button class="btn btn-primary" onclick="practiceShowResult()">See Results \u2192</button>';
    container.appendChild(rb);
  };
  window.practiceShowResult = function() { renderResult(); };


  // \u2500\u2500 RESULT \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  function renderResult() {
    var fb = document.getElementById('floatingBank');
    if (fb) fb.remove();
    // Save missed words to server so they appear in ever_missed / hard words
    if (wrongs.length > 0) {
      var missedIdxs = wrongs.map(function(q) {
        return q.i !== undefined ? q.i : -1;
      }).filter(function(i) { return i >= 0; });
      if (missedIdxs.length > 0) {
        var fd = new FormData();
        fd.append('missed', JSON.stringify(missedIdxs));
        fetch('?action=api_save_missed', {method:'POST', body:fd, credentials:'same-origin'});
      }
    }
    var pct   = Math.round(score / Math.max(qs.length,1) * 100);
    var emoji = pct===100 ? '\u{1F3C6}' : pct>=70 ? '\u{1F389}' : pct>=40 ? '\u{1F4AA}' : '\u{1F4DA}';
    var wrongHtml = wrongs.length
      ? wrongs.map(function(q) {
          return '<div class="result-item wrong">'
            + '<span style="font-weight:800;">' + esc(q.e||q.english||'') + '</span>'
            + '<span style="margin-left:auto;direction:rtl;color:var(--accent);">' + esc(q.h||q.hebrew||'') + '</span>'
          + '</div>';
        }).join('')
      : '<div class="result-item right"><span>All correct! \u{1F31F}</span></div>';
    var regenBtn = (mode !== 'memory')
      ? '<a href="?action=practice_regen" class="btn '+(rateLimitOk?'btn-blue':'btn-ghost')+'" '
          +(rateLimitOk?'':'title="Daily limit reached"')+'>'
          +(rateLimitOk?'\u{1F504} New Questions':'\u{1F504} New Questions (limit reached)')+'</a>'
      : '';
    setCount('');
    container.innerHTML =
      '<div class="card celebration"><span class="big-emoji">'+emoji+'</span>'
        + '<h1>'+score+' / '+qs.length+'</h1><p>'+pct+'% correct</p></div>'
      + '<div class="card" style="padding:20px;"><h2>Results</h2>'+wrongHtml+'</div>'
      + '<div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:4px;">'
          + '<a href="?action=practice_pick" class="btn btn-ghost">\u2190 Switch Mode</a>'
          + '<a href="?action=practice_newwords" class="btn btn-primary">\u{1F3B2} New Words</a>'
          + regenBtn
          + '<a href="?action=quiz" class="btn btn-ghost">Back to Quiz</a>'
      + '</div>'
      + (!rateLimitOk && mode!=='memory'
          ? '<p class="rate-limit-note">Daily limit reached \u00B7 resets tomorrow</p>' : '');
  }

  // \u2500\u2500 Utils \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function escAttr(s) {
    return String(s||'').replace(/'/g,"\\'").replace(/"/g,'&quot;');
  }
  function shuffle(a) {
    for(var i=a.length-1;i>0;i--){var j=Math.floor(Math.random()*(i+1));var t=a[i];a[i]=a[j];a[j]=t;}
    return a;
  }

  // \u2500\u2500 Init \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  if (apiInfo && mode !== 'memory') apiInfo.style.display = 'block';
  var modeNames = {memory:'Memory', spell:'Spell It', fitb:'Fill in the Blank', mc:'Multiple Choice', match:'Matching Pairs', speed:'Speed Round'};
  setTitle(modeNames[mode] || 'Practice');

  if (!words || words.length === 0) {
    container.style.display = 'block';
    container.innerHTML = '<div class="card" style="text-align:center;">'  
      + '<p style="font-size:1.1rem;">&#128218; No words loaded for this practice session.</p>'
      + '<a href="?action=practice_pick" class="btn btn-primary" style="margin-top:12px;">&#8592; Back</a>'
      + '</div>';
    return;
  }
  // -- MATCHING PAIRS
  function renderMatch() {
    var total = qs.length;
    var matched = 0;
    var selEng = null; // selected english index
    var selHeb = null; // selected hebrew index
    // Build shuffled arrays
    var engItems = qs.map(function(q,i){ return {i:i, text:q.english||q.e, q:q}; });
    var hebItems = qs.map(function(q,i){ return {i:i, text:q.hebrew||q.h, q:q}; });
    hebItems = shuffle(hebItems.slice());
    setCount(matched + ' / ' + total);

    function render() {
      var engHtml = engItems.map(function(item) {
        return '<div class="match-item" id="eng_'+item.i+'" data-idx="'+item.i+'">' + esc(item.text) + '</div>';
      }).join('');
      var hebHtml = hebItems.map(function(item) {
        return '<div class="match-item" id="heb_'+item.i+'" data-idx="'+item.i+'" dir="rtl" style="font-family:Rubik,sans-serif;">' + esc(item.text) + '</div>';
      }).join('');
      container.innerHTML =
        '<div class="match-grid">'
        + '<div class="match-col" id="engCol">' + engHtml + '</div>'
        + '<div class="match-col" id="hebCol">' + hebHtml + '</div>'
        + '</div>';
      // Attach events
      document.querySelectorAll('#engCol .match-item').forEach(function(el) {
        el.addEventListener('click', function() { pickEng(parseInt(el.dataset.idx)); });
      });
      document.querySelectorAll('#hebCol .match-item').forEach(function(el) {
        el.addEventListener('click', function() { pickHeb(parseInt(el.dataset.idx)); });
      });
    }

    function pickEng(idx) {
      var el = document.getElementById('eng_'+idx);
      if (!el || el.classList.contains('matched')) return;
      if (selEng !== null) document.getElementById('eng_'+selEng).classList.remove('selected');
      selEng = idx; el.classList.add('selected');
      if (selHeb !== null) tryMatch();
    }
    function pickHeb(idx) {
      var el = document.getElementById('heb_'+idx);
      if (!el || el.classList.contains('matched')) return;
      if (selHeb !== null) document.getElementById('heb_'+selHeb).classList.remove('selected');
      selHeb = idx; el.classList.add('selected');
      if (selEng !== null) tryMatch();
    }
    function tryMatch() {
      var eEl = document.getElementById('eng_'+selEng);
      var hEl = document.getElementById('heb_'+selHeb);
      if (selEng === selHeb) {
        // Correct!
        score++; matched++;
        eEl.classList.remove('selected'); eEl.classList.add('matched');
        hEl.classList.remove('selected'); hEl.classList.add('matched');
        setCount(matched + ' / ' + total);
        selEng = null; selHeb = null;
        if (matched === total) { setTimeout(renderResult, 600); }
      } else {
        // Wrong!
        wrongs.push(qs[selEng]);
        eEl.classList.add('wrong'); hEl.classList.add('wrong');
        setTimeout(function() {
          eEl.classList.remove('wrong','selected');
          hEl.classList.remove('wrong','selected');
          selEng = null; selHeb = null;
        }, 600);
      }
    }
    render();
  }

  // -- SPEED ROUND (2 answers + countdown intro)
  var speedTimer = null;
  var speedTimePerCard = 5;
  var speedTimes = [];
  var speedStart = 0;
  var speedAnswered = false;

  function startSpeedCountdown(cb) {
    var steps = ['3', '2', '1', 'GO!'];
    var colors = ['var(--muted)', 'var(--accent)', 'var(--green)', 'var(--green)'];
    var i = 0;
    container.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:center;height:220px;">'
        + '<div id="cdNum" style="font-family:Fredoka One,cursive;font-size:6rem;color:var(--muted);'
          + 'transition:transform .15s,color .15s;"></div>'
      + '</div>';
    function tick() {
      var el = document.getElementById('cdNum');
      if (!el) return;
      el.textContent = steps[i];
      el.style.color = colors[i];
      el.style.transform = 'scale(1.3)';
      setTimeout(function(){ if(el) el.style.transform = 'scale(1)'; }, 150);
      i++;
      if (i < steps.length) setTimeout(tick, 750);
      else setTimeout(cb, 750);
    }
    tick();
  }

  function renderSpeedFirst() {
    // Show countdown before first card
    startSpeedCountdown(function() { renderSpeed(); });
  }

  function renderSpeed() {
    if (qIndex >= qs.length) { renderSpeedResult(); return; }
    var q = qs[qIndex];
    setCount((qIndex+1) + ' / ' + qs.length);
    speedAnswered = false;
    speedStart = Date.now();

    // Pick one wrong distractor
    var wrongOpts = (q.d||[]).filter(function(d){ return d && d !== q.h; });
    var wrongAns = wrongOpts.length > 0 ? wrongOpts[Math.floor(Math.random()*wrongOpts.length)] : (q.d||['???'])[0];
    var opts = shuffle([{val:q.h, correct:true}, {val:wrongAns, correct:false}]);

    var btns = opts.map(function(opt) {
      return '<div class="speed-answer-btn" data-val="'+esc(opt.val)+'" data-correct="'+esc(q.h)+'"'
        + ' onclick="speedPick(this)">'
        + '<span style="font-family:Rubik,sans-serif;font-size:1.4rem;font-weight:800;direction:rtl;">'
        + esc(opt.val) + '</span></div>';
    }).join('');

    container.innerHTML =
      '<div class="speed-timer-bar"><div class="speed-timer-fill" id="speedBar" style="width:100%;"></div></div>'
      + '<div class="card" style="text-align:center;padding:28px 24px;margin-bottom:16px;">'
        + '<div class="english-word" style="font-size:2.4rem;">'+esc(q.e||q.english)+'</div>'
        + '<p style="font-size:.8rem;color:var(--muted);margin-top:8px;">Which is the correct Hebrew?</p>'
      + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">'+btns+'</div>';

    speak(q.e||q.english);
    startSpeedTimer(q);
  }

  function startSpeedTimer(q) {
    clearInterval(speedTimer);
    var elapsed = 0;
    var bar = document.getElementById('speedBar');
    speedTimer = setInterval(function() {
      elapsed += 100;
      var pct = Math.max(0, 100 - (elapsed / (speedTimePerCard * 1000) * 100));
      if (bar) {
        bar.style.width = pct + '%';
        if (pct < 30) bar.classList.add('urgent'); else bar.classList.remove('urgent');
      }
      if (elapsed >= speedTimePerCard * 1000) {
        clearInterval(speedTimer);
        if (!speedAnswered) speedTimeout(q);
      }
    }, 100);
  }

  function speedTimeout(q) {
    speedAnswered = true;
    speedTimes.push(speedTimePerCard * 1000);
    var wq = Object.assign({}, q); wq.i = q.i !== undefined ? q.i : qIndex; wrongs.push(wq);
    document.querySelectorAll('.speed-answer-btn').forEach(function(b) {
      b.style.pointerEvents = 'none';
      if (b.dataset.val === q.h) b.style.background = 'rgba(78,203,113,.3)';
    });
    setTimeout(function() { qIndex++; renderSpeed(); }, 900);
  }

  window.speedPick = function(btn) {
    if (speedAnswered) return;
    speedAnswered = true;
    clearInterval(speedTimer);
    speedTimes.push(Date.now() - speedStart);
    var chosen  = btn.dataset.val;
    var correct = btn.dataset.correct;
    document.querySelectorAll('.speed-answer-btn').forEach(function(b) {
      b.style.pointerEvents = 'none';
      if (b.dataset.val === correct) b.style.cssText += ';border-color:var(--green);background:rgba(78,203,113,.2);';
      else if (b === btn && chosen !== correct) b.style.cssText += ';border-color:var(--accent2);background:rgba(255,107,107,.2);';
    });
    if (chosen === correct) score++;
    else { var wq = Object.assign({}, qs[qIndex]); wq.i = qs[qIndex].i !== undefined ? qs[qIndex].i : qIndex; wrongs.push(wq); }
    setTimeout(function() { qIndex++; renderSpeed(); }, 700);
  };

  function renderSpeedResult() {
    var avgMs  = speedTimes.length ? Math.round(speedTimes.reduce(function(a,b){return a+b;},0)/speedTimes.length) : 0;
    var avgSec = (avgMs / 1000).toFixed(1);
    container.innerHTML =
      '<div class="card" style="text-align:center;padding:24px;">'
      + '<h2>'+score+' / '+qs.length+'</h2>'
      + '<p style="color:var(--muted);margin-top:8px;">Avg response time: <strong style="color:var(--accent);">'+avgSec+'s</strong></p>'
      + '</div>';
    setTimeout(renderResult, 1500);
  }


  loadQuestions(function() {
    if (mode === 'memory')      renderMemory();
    else if (mode === 'spell')  renderSpell();
    else if (mode === 'mc')     renderMC();
    else if (mode === 'fitb')   renderFITB();
    else if (mode === 'match')  renderMatch();
    else if (mode === 'speed')  renderSpeedFirst();
  });

})();

// Shared alt-answer result renderer - Hebrew UI
function renderAltResult(result, data) {
  if (data.error && data.rating !== 'limit') {
    result.innerHTML = '<div style="color:var(--accent2);font-size:.85rem;direction:ltr;text-align:left;padding:8px;background:rgba(255,107,107,.1);border-radius:8px;">API error: ' + esc(data.error) + '</div>';
    return;
  }
  if (data.rating === 'limit') {
    result.innerHTML = '<div style="color:var(--accent);font-weight:800;">&#9203; ' + esc(data.explanation||'&#1492;&#1490;&#1506;&#1514; &#1500;&#1502;&#1490;&#1489;&#1500;&#1492; &#1492;&#1497;&#1493;&#1502;&#1497;&#1514;') + '</div>';
    return;
  }
  var rating = data.rating || 'wrong';
  var colors = {perfect:'var(--green)', good:'var(--green)', partial:'var(--accent)', wrong:'var(--accent2)'};
  var labels = {
    perfect: '&#9989; &#1492;&#1514;&#1488;&#1502;&#1492; &#1502;&#1493;&#1513;&#1500;&#1502;&#1514;!',
    good:    '&#128077; &#1492;&#1514;&#1488;&#1502;&#1492; &#1496;&#1493;&#1489;&#1492;',
    partial: '&#9888;&#65039; &#1492;&#1514;&#1488;&#1502;&#1492; &#1495;&#1500;&#1511;&#1497;&#1514;',
    wrong:   '&#10060; &#1500;&#1488; &#1502;&#1514;&#1488;&#1497;&#1501;'
  };
  var col = colors[rating] || 'var(--muted)';
  var lbl = labels[rating] || rating;
  result.style.direction = 'rtl';
  result.style.textAlign = 'right';
  result.innerHTML =
    '<div style="border-right:3px solid '+col+';padding:8px 12px;border-radius:8px 0 0 8px;background:rgba(255,255,255,.04);">'
    + '<div style="font-weight:800;color:'+col+';margin-bottom:6px;">'+lbl+'</div>'
    + '<details><summary style="font-size:.8rem;color:var(--muted);cursor:pointer;">&#1500;&#1502;&#1492;? (&#1500;&#1495;&#1509; &#1500;&#1508;&#1514;&#1497;&#1495;&#1492;)</summary>'
    + '<p style="font-size:.85rem;margin-top:6px;">'+esc(data.explanation||'')+'</p>'
    + '</details></div>';
}

function pickMode(m) {
  ['memory','spell','fitb','mc','match','speed'].forEach(function(x){
    var id = 'mode' + x.charAt(0).toUpperCase() + x.slice(1);
    var el = document.getElementById(id);
    if(el) el.classList.toggle('selected', x===m);
  });
  var si=document.getElementById('submodeInput');
  if(si) si.value=m;
}
function pickWords(w) {
  ['All','Hard','Mix'].forEach(function(x){
    var el=document.getElementById('w'+x);
    if(el) el.classList.toggle('selected', x.toLowerCase()===w);
  });
  var pi=document.getElementById('pmodeInput');
  if(pi) pi.value=w;
}

function pickEmoji(btn) {
  document.querySelectorAll('.emoji-opt').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('selectedEmoji').value = btn.dataset.emoji;
}
// Select first emoji by default
window.addEventListener('DOMContentLoaded', function() {
  var first = document.querySelector('.emoji-opt');
  if (first) pickEmoji(first);
});

function arFilter(type) {
  document.querySelectorAll('#arTable tbody tr').forEach(tr => {
    tr.classList.toggle('hidden-row', type !== 'all' && tr.dataset.arStatus !== type);
  });
  [['arTabAll','all'],['arTabKnown','known'],['arTabHard','hard']].forEach(([id, t]) => {
    const el = document.getElementById(id); if (!el) return;
    const active = t === type;
    el.className = 'btn btn-sm' + (active ? '' : ' btn-ghost');
    el.style.background = active ? 'var(--accent)' : '';
    el.style.color = active ? '#1a1000' : '';
  });
}
</script>

</body>
</html>
