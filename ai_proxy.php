<?php
/**
 * ai_proxy.php — AI Forecast Backend (JSON API)
 *
 * Server-side proxy for the AI Forecast Dashboard (ai_forecast.php).
 * Receives a sales data prompt from the frontend, fetches live trending
 * data from multiple sources (Google Trends PH, DuckDuckGo, Reddit PH),
 * augments the prompt with this market intelligence, and sends it to
 * Groq LLAMA-3.3-70b API for analysis.
 *
 * Returns: { "text": "...", "trends_found": N, "trends_source": "live"|"fallback" }
 *
 * Auth: requires active staff or admin session.
 */
session_start();
require 'config.php';
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$prompt = trim($input['prompt'] ?? '');

if (!$prompt) {
  http_response_code(400);
  echo json_encode(['error' => 'No prompt provided']);
  exit;
}

$apiKey = GROQ_API_KEY;

/* ============================================================
   STEP 1: FETCH TRENDING DATA FROM THE WEB
   We scrape Google Trends RSS for Philippines beauty/health
   and also fetch a Shopee/Lazada trending search via
   free public APIs — no API key needed
============================================================ */

function fetchTrendingData() {
  $trends = [];

  // --- Source 1: Google Trends RSS for Philippines ---
  $googleTrendsUrl = 'https://trends.google.com/trends/trendingsearches/daily/rss?geo=PH';
  $ch = curl_init($googleTrendsUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_SSL_VERIFYPEER => false,
  ]);
  $rss = curl_exec($ch);
  curl_close($ch);

  if ($rss) {
    // Extract trending titles from RSS
    preg_match_all('/<title><!\[CDATA\[(.+?)\]\]><\/title>/', $rss, $matches);
    if (!empty($matches[1])) {
      // Filter for beauty/health/wellness keywords
      $keywords = ['beauty','skin','soap','cream','lotion','vitamin','health','wellness',
                   'whitening','serum','coffee','supplement','herbal','cologne','perfume',
                   'lipstick','makeup','skincare','hair','tawas','bleach','moisturizer'];
      foreach ($matches[1] as $title) {
        $titleLower = strtolower($title);
        foreach ($keywords as $kw) {
          if (strpos($titleLower, $kw) !== false) {
            $trends[] = trim($title);
            break;
          }
        }
      }
    }
  }

  // --- Source 2: DuckDuckGo Instant Answer for PH beauty trends ---
  $queries = [
    'trending beauty products Philippines 2025',
    'best selling skincare Philippines 2025',
    'trending health supplements Philippines',
  ];

  foreach ($queries as $q) {
    $url = 'https://api.duckduckgo.com/?q=' . urlencode($q) . '&format=json&no_html=1&skip_disambig=1';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT        => 6,
      CURLOPT_USERAGENT      => 'Mozilla/5.0',
      CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result) {
      $data = json_decode($result, true);
      if (!empty($data['AbstractText'])) {
        $trends[] = substr($data['AbstractText'], 0, 200);
      }
      if (!empty($data['RelatedTopics'])) {
        foreach (array_slice($data['RelatedTopics'], 0, 3) as $topic) {
          if (!empty($topic['Text'])) {
            $trends[] = $topic['Text'];
          }
        }
      }
    }
  }

  // --- Source 3: Reddit Philippines via public JSON ---
  $redditUrl = 'https://www.reddit.com/r/Philippines/search.json?q=beauty+skincare+health&sort=hot&limit=5&t=week';
  $ch = curl_init($redditUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; FIL-RISE/1.0)',
    CURLOPT_SSL_VERIFYPEER => false,
  ]);
  $redditResult = curl_exec($ch);
  curl_close($ch);

  if ($redditResult) {
    $redditData = json_decode($redditResult, true);
    if (!empty($redditData['data']['children'])) {
      foreach ($redditData['data']['children'] as $post) {
        $title = $post['data']['title'] ?? '';
        if ($title) $trends[] = $title;
      }
    }
  }

  return array_unique(array_filter($trends));
}

/* ============================================================
   STEP 2: BUILD ENHANCED PROMPT WITH TREND DATA
============================================================ */

$trendingData = fetchTrendingData();

$trendContext = '';
if (!empty($trendingData)) {
  $trendContext = "\n\nCURRENT TRENDING IN THE PHILIPPINES (from Google Trends, DuckDuckGo, Reddit PH):\n";
  foreach (array_slice($trendingData, 0, 10) as $i => $trend) {
    $trendContext .= ($i + 1) . ". " . $trend . "\n";
  }
} else {
  // Fallback: inject known PH beauty/health market knowledge context
  $trendContext = "\n\nPHILIPPINE MARKET CONTEXT (based on current market knowledge):\n";
  $trendContext .= "1. Glutathione and whitening products remain top sellers in Visayas and Mindanao\n";
  $trendContext .= "2. Herbal and organic health supplements are growing in demand nationwide\n";
  $trendContext .= "3. Coffee-based wellness products (like Bloom and Shape) are trending on social media\n";
  $trendContext .= "4. Korean-inspired skincare routines (toner, serum, sunscreen) are popular on TikTok PH\n";
  $trendContext .= "5. Deodorant tawas (alum) is gaining traction as a natural alternative\n";
  $trendContext .= "6. Lip tints and affordable makeup are trending among Gen Z in provinces\n";
  $trendContext .= "7. MLM health products like Immuno boosters see spikes during rainy season\n";
  $trendContext .= "8. Cologne and perfume sales spike before holidays and fiestas\n";
}

// Append trending context to the original prompt
$enhancedPrompt = $prompt . $trendContext . "

IMPORTANT INSTRUCTIONS:
- Your response must ONLY recommend products that match the RISING or STABLE trend from the sales data above.
- Do NOT recommend any product marked as FALLING in the data.
- Cross-reference the sales data trends WITH the Philippine market trends above.
- If a product in the sales data is rising AND also trending in the market, highlight it as a strong opportunity.
- If a product is rising in sales but NOT trending externally, mention it as a local opportunity.
- Add a section: '5. Community Trends' — mention 2-3 things currently trending in Philippine beauty/health/wellness market that the branch should be aware of, and if any of their products match those trends.
- Keep the total response under 300 words. No emojis.";

/* ============================================================
   STEP 3: SEND TO GROQ LLAMA AI
============================================================ */

$payload = json_encode([
  'model'    => 'llama-3.3-70b-versatile',
  'messages' => [
    [
      'role'    => 'system',
      'content' => 'You are an expert business analyst for a direct-selling company in the Philippines. You have knowledge of current Philippine beauty, health, and wellness market trends. Always base recommendations strictly on the provided sales data. Never contradict the trend labels in the data.',
    ],
    ['role' => 'user', 'content' => $enhancedPrompt]
  ],
  'max_tokens'  => 1200,
  'temperature' => 0.6,
]);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
  ],
  CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
curl_close($ch);

if (!$response) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to connect to Groq API.']);
  exit;
}

$data = json_decode($response, true);
$text = $data['choices'][0]['message']['content'] ?? null;

if (!$text) {
  $errorMsg = $data['error']['message'] ?? 'Unknown error';
  http_response_code(500);
  echo json_encode(['error' => 'Groq API error: ' . $errorMsg]);
  exit;
}

header('Content-Type: application/json');
echo json_encode([
  'text'          => $text,
  'trends_found'  => count($trendingData),
  'trends_source' => !empty($trendingData) ? 'live' : 'fallback',
]);