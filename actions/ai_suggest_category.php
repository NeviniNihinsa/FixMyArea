<?php
declare(strict_types=1);

header('Content-Type: application/json');

// ── Session & DB only — no auth.php (it can redirect and break JSON response) ──
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

// Basic session guard — just check logged in, no redirect
if (empty($_SESSION['user_id'])) {
    echo json_encode(['category_id' => null, 'category_name' => null]);
    exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['category_id' => null, 'category_name' => null]);
    exit;
}

// ── Input ────────────────────────────────────────────────────
$title       = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');

if (mb_strlen($title) < 4 && mb_strlen($description) < 8) {
    echo json_encode(['category_id' => null, 'category_name' => null]);
    exit;
}

// ── Fetch categories from DB ─────────────────────────────────
$categories = $pdo->query("
    SELECT category_id, category_name
    FROM issue_categories
    ORDER BY category_name
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($categories)) {
    echo json_encode(['category_id' => null, 'category_name' => null]);
    exit;
}

// ── Keyword map: tells Gemini what each category covers ──────
$categoryDescriptions = [
    'Plumbing & Water'      => 'pipe burst, leaking pipe, water supply issue, no water, low water pressure, water meter, tap leak, plumbing repair, blocked toilet, sewage smell from pipe',
    'Electrical & Power'    => 'power outage, electrical fault, short circuit, tripped breaker, no electricity, wiring issue, power fluctuation, socket not working, electrical hazard',
    'Lift / Elevator'       => 'elevator not working, lift stuck, lift broken, lift door issue, elevator out of service, lift maintenance, lift makes noise, elevator malfunction',
    'Cleaning & Hygiene'    => 'dirty common area, unclean hallway, unhygienic toilet, cleaning not done, foul smell in building, dirty floor, mold, dusty, stains, poor sanitation',
    'Structural & Civil'    => 'wall crack, ceiling crack, building damage, cracked floor, structural damage, broken wall, leaking roof, ceiling collapse, concrete damage, foundation issue',
    'Pest Control'          => 'cockroach, rat, mice, mosquito, ant infestation, pest problem, insects, termite, bug infestation, rodent, pest sighting',
    'Security & CCTV'       => 'CCTV not working, security camera broken, security concern, unauthorized access, broken lock, door security issue, safety concern, camera offline, surveillance',
    'Air Conditioning'      => 'AC not working, air conditioning broken, AC leaking, aircon noise, no cooling, AC unit faulty, HVAC problem, split unit broken, air conditioner smell',
    'Landscaping'           => 'overgrown grass, tree branch, garden maintenance, fallen tree, plants overgrown, lawn not cut, bush overgrown, garden waste, tree cutting needed',
    'Fire Safety'           => 'fire extinguisher missing, fire alarm not working, fire hazard, blocked fire exit, sprinkler faulty, fire safety equipment, smoke detector, emergency exit blocked',
    'Waste & Bin Management'=> 'bin full, overflowing bin, uncollected garbage, waste not collected, rubbish bin, litter, dumping, trash can full, waste management, garbage smell, bin broken',
    'Other'                 => 'general issue, miscellaneous, not listed, other problem, unspecified issue',
];

// Build category lines WITH descriptions for the prompt
$categoryLines = [];
foreach ($categories as $cat) {
    $name = $cat['category_name'];
    $desc = $categoryDescriptions[$name] ?? $name;
    $categoryLines[] = "- {$name}: {$desc}";
}
$categoryList    = implode(', ', array_column($categories, 'category_name'));
$categoryDetails = implode("\n", $categoryLines);

// ── Prompt ───────────────────────────────────────────────────
$prompt = <<<PROMPT
You are a municipal issue classifier for a Sri Lankan city complaint system called FixMyArea.

Here are the available categories and the types of issues they cover:
{$categoryDetails}

Task: Read the issue title and description below, then pick the SINGLE most relevant category.

STRICT RULES:
- Reply with ONLY the exact category name from this list: [{$categoryList}]
- The name must match EXACTLY including punctuation (e.g. "Plumbing & Water" not "Plumbing and Water")
- No quotes, no punctuation around it, no explanation, no extra words whatsoever.
- If nothing fits well, reply with: Other

Issue Title: {$title}
Issue Description: {$description}

Category:
PROMPT;

// ── Call Gemini API ──────────────────────────────────────────
$apiKey = 'AIzaSyBz_XCZ_-jKgenfQdihmPI2FoJ3GNlXNJ4';
$url    = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

$payload = json_encode([
    'contents' => [
        ['parts' => [['text' => $prompt]]]
    ],
    'generationConfig' => [
        'temperature'     => 0.0,
        'maxOutputTokens' => 15,
        'topP'            => 1,
        'topK'            => 1,
    ]
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr || !$response || $httpCode !== 200) {
    error_log("Gemini API error [{$httpCode}]: {$curlErr} | Response: {$response}");
    $suggestedName = '';
} else {
    $data = json_decode($response, true);
    $raw  = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Clean Gemini's response
    $suggestedName = trim($raw);
    $suggestedName = trim($suggestedName, " \t\n\r\0\x0B\"'.,:-");
    $suggestedName = preg_replace('/^(category|answer|result|the)[\s:]+/i', '', $suggestedName);
    $suggestedName = trim($suggestedName);
}

// ── Match to DB category ─────────────────────────────────────
$matched_id   = null;
$matched_name = null;

// Pass 1: exact case-insensitive match
foreach ($categories as $cat) {
    if (strcasecmp($cat['category_name'], $suggestedName) === 0) {
        $matched_id   = (int)$cat['category_id'];
        $matched_name = $cat['category_name'];
        break;
    }
}

// Pass 2: partial / ampersand-normalized match
if ($matched_id === null && $suggestedName !== '') {
    $normalizedSuggestion = str_ireplace([' & ', ' and '], ' ', $suggestedName);
    foreach ($categories as $cat) {
        $normalizedCat = str_ireplace([' & ', ' and '], ' ', $cat['category_name']);
        if (strcasecmp($normalizedCat, $normalizedSuggestion) === 0 ||
            stripos($suggestedName, $cat['category_name']) !== false ||
            stripos($cat['category_name'], $suggestedName) !== false) {
            $matched_id   = (int)$cat['category_id'];
            $matched_name = $cat['category_name'];
            break;
        }
    }
}

// Pass 3: keyword fallback — used if Gemini failed or returned garbage
if ($matched_id === null) {
    $inputLower = strtolower($title . ' ' . $description);

    $keywordMap = [
        'Plumbing & Water'      => ['pipe','water','leak','plumb','tap','toilet','sewage','pressure','supply','flush','no water'],
        'Electrical & Power'    => ['electric','power','light','socket','switch','wiring','circuit','outage','tripped','voltage','no power'],
        'Lift / Elevator'       => ['lift','elevator','stuck','cabin','elevator door','lift door'],
        'Cleaning & Hygiene'    => ['clean','dirty','hygiene','mold','smell','dust','stain','sanit','unhygienic','hallway'],
        'Structural & Civil'    => ['crack','ceiling','wall','roof','floor','structural','concrete','collapse','foundation','broken wall'],
        'Pest Control'          => ['pest','rat','mice','cockroach','insect','mosquito','ant','termite','bug','rodent','infestation'],
        'Security & CCTV'       => ['security','cctv','camera','lock','access','surveillance','unauthorized','safe'],
        'Air Conditioning'      => ['ac','air con','aircon','cooling','hvac','air condition','split unit','cold air','no cooling'],
        'Landscaping'           => ['grass','tree','garden','lawn','plant','branch','bush','landscape','overgrown','fallen tree'],
        'Fire Safety'           => ['fire','extinguisher','alarm','sprinkler','exit','smoke detector','fire hazard','emergency exit','fire safety'],
        'Waste & Bin Management'=> ['bin','garbage','trash','waste','rubbish','litter','dump','collect','overflowing','waste collection'],
        'Other'                 => [],
    ];

    $bestScore = 0;
    foreach ($categories as $cat) {
        $keywords = $keywordMap[$cat['category_name']] ?? [];
        $score    = 0;
        foreach ($keywords as $kw) {
            if (stripos($inputLower, $kw) !== false) $score++;
        }
        if ($score > $bestScore) {
            $bestScore    = $score;
            $matched_id   = (int)$cat['category_id'];
            $matched_name = $cat['category_name'];
        }
    }

    // Last resort: fall back to "Other"
    if ($matched_id === null || $bestScore === 0) {
        foreach ($categories as $cat) {
            if (strcasecmp($cat['category_name'], 'Other') === 0) {
                $matched_id   = (int)$cat['category_id'];
                $matched_name = $cat['category_name'];
                break;
            }
        }
    }
}

echo json_encode([
    'category_id'   => $matched_id,
    'category_name' => $matched_name,
]);