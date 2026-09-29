<?php
/**
 * Pipeline\OpenAiChat — one chat completion against any OpenAI-compatible endpoint
 * (OpenAI, Groq, Ollama's /v1, a local server). What an `openai`-kind agent runs on.
 *
 * Deliberately small: POST <endpoint>/chat/completions with a system + user message,
 * return the assistant text and usage. Anything unexpected — a non-200, a body that is
 * not JSON, no choice in it — is a failure carrying the HTTP code and the body's first
 * line, so the person reading the step trace sees what the endpoint said. No retries, no
 * model fallback: the agent names its model and endpoint and that is what runs.
 */

namespace app\Pipeline;

class OpenAiChat {

    /**
     * @return array{ok:bool,text:string,usage:array,error:string,http:int}
     */
    public static function complete(string $endpoint, string $apiKey, string $model, string $system, string $user, int $timeout): array {
        $url = rtrim($endpoint, '/') . '/chat/completions';
        $messages = [];
        if (trim($system) !== '') $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $user];
        $body = json_encode(['model' => $model, 'messages' => $messages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, $timeout), CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        if ($resp === false) {
            return ['ok' => false, 'text' => '', 'usage' => [], 'http' => 0, 'error' => "request to {$url} failed: " . ($cerr ?: 'no response')];
        }
        return self::parse((string) $resp, $code, $url);
    }

    /** Separated so the parsing rules are testable without a network. */
    public static function parse(string $resp, int $code, string $url): array {
        $d = json_decode($resp, true);
        $firstLine = strtok(trim($resp), "\n") ?: '';
        if ($code !== 200) {
            $msg = is_array($d) ? ($d['error']['message'] ?? $d['message'] ?? $firstLine) : $firstLine;
            return ['ok' => false, 'text' => '', 'usage' => [], 'http' => $code, 'error' => "HTTP {$code} from {$url}: " . mb_substr((string) $msg, 0, 300)];
        }
        if (!is_array($d)) {
            return ['ok' => false, 'text' => '', 'usage' => [], 'http' => $code, 'error' => "{$url} answered 200 but not JSON: " . mb_substr($firstLine, 0, 200)];
        }
        $content = $d['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            return ['ok' => false, 'text' => '', 'usage' => [], 'http' => $code, 'error' => "{$url} answered without choices[0].message.content: " . mb_substr($firstLine, 0, 200)];
        }
        return ['ok' => true, 'text' => $content, 'usage' => is_array($d['usage'] ?? null) ? $d['usage'] : [], 'http' => $code, 'error' => ''];
    }
}
