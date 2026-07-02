<?php
/**
 * STUDIZ — OpenAI cURL Service
 * -----------------------------
 * Centralises all communication with the OpenAI Chat
 * Completions API. The API key is read exclusively from
 * the server environment — it never appears in source code
 * and is never returned to the frontend.
 *
 * All public methods return a normalised result array:
 *   ['ok' => true,  'data' => mixed]
 *   ['ok' => false, 'error' => string, 'http_code' => int]
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

class OpenAI
{
    /**
     * Send a chat completion request.
     *
     * @param  array  $messages  OpenAI messages array [['role'=>..., 'content'=>...], ...]
     * @param  int    $maxTokens Maximum tokens for the response
     * @param  float  $temp      Temperature (0 = deterministic)
     * @return array             Normalised result array
     */
    public static function chat(
        array  $messages,
        int    $maxTokens = 2000,
        float  $temp      = 0.4
    ): array {
        $apiKey = OPENAI_API_KEY;

        if (empty($apiKey)) {
            self::logError('OPENAI_API_KEY environment variable is not set.');
            return ['ok' => false, 'error' => 'AI service is not configured.', 'http_code' => 503];
        }

        $payload = json_encode([
            'model'       => OPENAI_MODEL,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temp,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            // json_last_error_msg() returns internal PHP detail that must not
            // reach the client — log it server-side, return a generic message.
            self::logError('Failed to JSON-encode OpenAI request: ' . json_last_error_msg());
            return ['ok' => false, 'error' => 'Could not prepare the AI request. Please try again.', 'http_code' => 500];
        }

        // ── Initialise cURL ──────────────────────────────────
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => OPENAI_ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => CURL_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            // Security: verify TLS certificate chain — this call carries the
            // OpenAI secret key in the Authorization header, so a disabled
            // check here is a MITM key-exfiltration vector. Never disable.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // ── Execute & evaluate ───────────────────────────────
        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);                        // always close the resource

        // cURL transport error (timeout, DNS failure, etc.)
        if ($raw === false || !empty($curlErr)) {
            self::logError("cURL error (HTTP {$httpCode}): {$curlErr}");
            return [
                'ok'        => false,
                'error'     => 'The AI service could not be reached. Please try again.',
                'http_code' => 503,
            ];
        }

        $decoded = json_decode($raw, true);

        // Non-200 response from OpenAI
        if ($httpCode !== 200) {
            $msg = $decoded['error']['message'] ?? 'Unknown OpenAI error.';
            self::logError("OpenAI API error {$httpCode}: {$msg}");
            return [
                'ok'        => false,
                'error'     => 'AI service returned an error. Please try again.',
                'http_code' => $httpCode >= 500 ? 502 : 422,
            ];
        }

        // Extract the assistant's reply content
        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            self::logError('OpenAI response missing choices[0].message.content');
            return ['ok' => false, 'error' => 'Unexpected AI response format.', 'http_code' => 502];
        }

        return ['ok' => true, 'data' => trim($content)];
    }

    /**
     * Fetch YouTube video recommendations via the YouTube Data API v3.
     * Returns up to 4 video objects: [{title, videoId, thumbnail}].
     *
     * @param  string $query  Search topic extracted from the document summary
     * @return array          Normalised result array, data = array of video objects
     */
    public static function fetchYouTubeVideos(string $query): array
    {
        $apiKey = YOUTUBE_API_KEY;
        if (empty($apiKey)) {
            return ['ok' => false, 'error' => 'YouTube API key not configured.', 'http_code' => 503];
        }

        $params = http_build_query([
            'part'       => 'snippet',
            'q'          => $query,
            'type'       => 'video',
            'maxResults' => 4,
            'key'        => $apiKey,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => YOUTUBE_ENDPOINT . '?' . $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            // Security: this call also carries an API key in the query
            // string — same MITM rationale as the chat() call above.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || !empty($curlErr)) {
            self::logError("YouTube cURL error: {$curlErr}");
            return ['ok' => false, 'error' => 'Could not fetch YouTube videos.', 'http_code' => 503];
        }

        $decoded = json_decode($raw, true);
        $videos  = [];

        foreach ($decoded['items'] ?? [] as $item) {
            $videos[] = [
                'title'     => $item['snippet']['title']                ?? '',
                'videoId'   => $item['id']['videoId']                   ?? '',
                'thumbnail' => $item['snippet']['thumbnails']['high']['url'] ?? '',
                'channel'   => $item['snippet']['channelTitle']         ?? '',
            ];
        }

        return ['ok' => true, 'data' => $videos];
    }

    // ── Private helpers ──────────────────────────────────────

    private static function logError(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [OpenAI] ' . $msg . PHP_EOL;
        error_log($line, 3, ERROR_LOG_FILE);
    }
}
