<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$title        = trim($_POST['title'] ?? '');
$description  = trim($_POST['description'] ?? '');
$commonAreaId = (int)($_POST['common_area_id'] ?? 0);

if ($title === '' || $commonAreaId <= 0) {
    echo json_encode(['duplicate' => false]);
    exit;
}

// Fetch existing open issues for the same common area
$stmt = $pdo->prepare("
    SELECT
        i.issue_id,
        i.title,
        i.description,
        i.status,
        i.created_at,
        ca.area_name
    FROM issues i
    LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
    WHERE i.is_common = 1
      AND i.common_area_id = ?
      AND i.status NOT IN ('COMPLETED', 'CLOSED', 'REJECTED')
    ORDER BY i.created_at DESC
    LIMIT 30
");
$stmt->execute([$commonAreaId]);
$existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($existing)) {
    echo json_encode(['duplicate' => false]);
    exit;
}

// --- Method 1: PHP similar_text() for fast local matching ---
$newText    = strtolower($title . ' ' . $description);
$duplicates = [];

foreach ($existing as $issue) {
    $existingText = strtolower($issue['title'] . ' ' . $issue['description']);

    similar_text($newText, $existingText, $percent);

    // Also check title-only similarity for short inputs
    similar_text(strtolower($title), strtolower($issue['title']), $titlePercent);

    $score = max($percent, $titlePercent * 0.9); // weight title slightly less

    if ($score >= 40) { // 40% similarity threshold
        $duplicates[] = [
            'issue_id'   => (int)$issue['issue_id'],
            'title'      => $issue['title'],
            'status'     => $issue['status'],
            'area_name'  => $issue['area_name'] ?? '',
            'created_at' => $issue['created_at'],
            'score'      => round($score, 1),
        ];
    }
}

if (empty($duplicates)) {
    echo json_encode(['duplicate' => false]);
    exit;
}

// --- Method 2: If we have matches, optionally verify with Gemini for accuracy ---
// Sort by score descending
usort($duplicates, fn($a, $b) => $b['score'] <=> $a['score']);
$topMatches = array_slice($duplicates, 0, 3);

// Only call Gemini if similarity is in the "uncertain" range (40-70%)
// High confidence (>70%) — trust local matching directly
$topScore = $topMatches[0]['score'] ?? 0;

if ($topScore >= 40 && $topScore < 70) {
    // Ask Gemini to confirm
    $apiKey = 'AIzaSyBz_XCZ_-jKgenfQdihmPI2FoJ3GNlXNJ4';
    $url    = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

    $existingTitles = implode("\n", array_map(
        fn($d) => "- #{$d['issue_id']}: {$d['title']}",
        $topMatches
    ));

    $prompt = "A citizen is reporting a new municipal issue. Check if any existing issue below "
            . "describes the SAME problem (not just a similar topic).\n\n"
            . "NEW ISSUE:\nTitle: {$title}\nDescription: {$description}\n\n"
            . "EXISTING ISSUES:\n{$existingTitles}\n\n"
            . "Reply with ONLY: YES or NO";

    $payload = json_encode([
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature'     => 0.1,
            'maxOutputTokens' => 5,
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data   = json_decode($response, true);
        $answer = strtoupper(trim($data['candidates'][0]['content']['parts'][0]['text'] ?? 'NO'));

        if (strpos($answer, 'YES') === false) {
            // Gemini says it's NOT a duplicate
            echo json_encode(['duplicate' => false]);
            exit;
        }
    }
    // If Gemini call fails, fall through and trust local matching
}

// Return duplicates
echo json_encode([
    'duplicate' => true,
    'matches'   => $topMatches,
]);