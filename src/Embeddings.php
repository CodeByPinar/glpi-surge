<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/**
 * Embeddings — optional semantic similarity on top of TF-IDF.
 *
 * Off by default. When enabled, ticket texts are sent to an OpenAI-compatible `/v1/embeddings` endpoint that YOU run
 * (Ollama, llama.cpp `--embeddings`, LocalAI, vLLM…), vectors are cached per ticket, and the similarity used for
 * detection becomes  (1 − w) · TF-IDF + w · embedding-cosine.  This catches paraphrases with no shared words
 * ("can't log in to the VPN" ↔ "remote access authentication failed"). If the endpoint is unreachable, detection simply
 * falls back to TF-IDF and the error is logged in files/_log/surge.log.
 */
final class Embeddings
{
    public const TABLE = 'glpi_plugin_surge_embeddings';
    public const BATCH = 32;

    /** @var string|null last error (for the settings page test) */
    public static ?string $error = null;

    public static function enabled(): bool
    {
        return Config::get('embed_enabled') === '1' && Config::get('embed_url') !== '' && Config::get('embed_model') !== '';
    }

    public static function weight(): float
    {
        return max(0.0, min(1.0, (float) Config::get('embed_weight')));
    }

    public static function text(string $title, string $content): string
    {
        $c = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return mb_substr(trim($title) . "\n" . $c, 0, 600);
    }

    /**
     * Vectors for tickets (cached). @param array<int,array{name:string,content:string}> $tickets
     * @return array<int,list<float>> L2-normalised; tickets that could not be embedded are missing
     */
    public static function vectors(array $tickets): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$tickets) {
            return [];
        }
        $model = Config::get('embed_model');
        $want = [];
        foreach ($tickets as $id => $t) {
            $want[(int) $id] = self::text((string) $t['name'], (string) $t['content']);
        }
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['tickets_id' => array_keys($want), 'model' => $model]]) as $r) {
            $id = (int) $r['tickets_id'];
            if ($r['hash'] === md5($want[$id])) {
                $v = json_decode((string) $r['vector'], true);
                if (is_array($v) && $v) {
                    $out[$id] = array_map('floatval', $v);
                }
            }
        }
        $missing = array_diff_key($want, $out);
        foreach (array_chunk($missing, self::BATCH, true) as $chunk) {
            $vecs = self::fetch(array_values($chunk));
            if ($vecs === null) {
                break; // endpoint down: TF-IDF only
            }
            $i = 0;
            foreach ($chunk as $id => $text) {
                $v = self::norm($vecs[$i++] ?? []);
                if (!$v) {
                    continue;
                }
                $out[$id] = $v;
                $DB->delete(self::TABLE, ['tickets_id' => $id, 'model' => $model]);
                $DB->insert(self::TABLE, ['tickets_id' => $id, 'model' => $model, 'hash' => md5($text),
                    'vector' => json_encode(array_map(static fn($x) => round($x, 6), $v)), 'date_creation' => date('Y-m-d H:i:s')]);
            }
        }
        return $out;
    }

    /** Calls the endpoint. @param list<string> $inputs @return list<list<float>>|null */
    public static function fetch(array $inputs): ?array
    {
        self::$error = null;
        $url = rtrim(Config::get('embed_url'), '/');
        if (!preg_match('~/embeddings$~', $url)) {
            $url .= preg_match('~/v1$~', $url) ? '/embeddings' : '/v1/embeddings';
        }
        $key = Config::secret('embed_key');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['model' => Config::get('embed_model'), 'input' => $inputs], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array_values(array_filter(['Content-Type: application/json', $key !== '' ? 'Authorization: Bearer ' . $key : null])),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_PROXY          => '', // local service: never through GLPI's proxy
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if ($raw === false || $code !== 200 || !isset($j['data']) || !is_array($j['data'])) {
            self::$error = $raw === false ? $err : sprintf('HTTP %d %s', $code, mb_substr((string) ($j['error']['message'] ?? $raw), 0, 200));
            \Toolbox::logInFile('surge', 'Embeddings: ' . self::$error . "\n");
            return null;
        }
        $data = $j['data'];
        usort($data, static fn($a, $b) => (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0));
        return array_map(static fn($d) => array_map('floatval', (array) ($d['embedding'] ?? [])), $data);
    }

    /** @param list<float> $v @return list<float> */
    public static function norm(array $v): array
    {
        $n = sqrt(array_sum(array_map(static fn($x) => $x * $x, $v)));
        return $n > 0 ? array_map(static fn($x) => $x / $n, $v) : [];
    }

    /** Dot product of normalised vectors (cosine). */
    public static function cosine(array $a, array $b): float
    {
        $s = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i] * $b[$i];
        }
        return max(0.0, min(1.0, $s));
    }

    /** @param list<list<float>> $vs */
    public static function centroid(array $vs): array
    {
        if (!$vs) {
            return [];
        }
        $c = array_fill(0, count($vs[0]), 0.0);
        foreach ($vs as $v) {
            foreach ($v as $i => $x) {
                $c[$i] += $x;
            }
        }
        return self::norm($c);
    }
}
