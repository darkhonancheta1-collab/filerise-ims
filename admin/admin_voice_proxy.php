<?php
/**
 * admin/admin_voice_proxy.php — Admin Voice Recognition & TTS Gateway
 *
 * Handles voice queries for the admin chatbot, processing voice prompts
 * and routing them through the AI assistant pipeline for hands-free admin querying.
 */
session_start();
require 'config.php';

if (!isset($_SESSION['admin_id'])) {
  http_response_code(401);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$text  = trim($input['text'] ?? '');

if (!$text) {
  http_response_code(400);
  echo json_encode(['error' => 'No text provided']);
  exit;
}

// ElevenLabs API Key
$apiKey  = 'sk_6a7e6f7ead5d2656c943bb168963337c0ec7b16f120436a5';
$voiceId = 'EXAVITQu4vr4xnSDxMaL'; // Sarah — warm, natural female voice

$payload = json_encode([
  'text'          => $text,
  'model_id'      => 'eleven_turbo_v2_5',
  'voice_settings' => [
    'stability'        => 0.5,
    'similarity_boost' => 0.8,
    'style'            => 0.2,
    'use_speaker_boost'=> true,
  ]
]);

$ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_HTTPHEADER     => [
    'xi-api-key: ' . $apiKey,
    'Content-Type: application/json',
    'Accept: audio/mpeg',
  ],
  CURLOPT_TIMEOUT => 30,
]);

$audio    = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$audio || $httpCode !== 200) {
  http_response_code(500);
  echo json_encode(['error' => 'ElevenLabs API failed. Code: ' . $httpCode]);
  exit;
}

// Return audio as base64 so JS can play it
header('Content-Type: application/json');
echo json_encode(['audio' => base64_encode($audio)]);