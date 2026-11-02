<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/**
 * Text — lightweight text similarity for ticket titles and descriptions (no external service, no AI).
 *
 *  1. normalize: strip HTML, lower-case, fold diacritics (Turkish ı/İ/ğ/ş/ç/ö/ü and Latin accents) so that
 *     "İnternet YOK" and "internet yok" become the same words
 *  2. tokenize: words of 3+ characters, minus stop words (English + Turkish + service-desk filler)
 *  3. stem: keep the first 6 characters — crude, but it maps most suffix variations of agglutinative languages
 *     ("bağlanamıyorum", "bağlantı" → "baglan") and English plurals / tenses ("printers", "printing" → "printe")
 *  4. weight: title words count twice; TF-IDF over the tickets being compared
 *  5. compare: cosine similarity (0 … 1)
 */
final class Text
{
    public const STEM = 6;

    private const STOP = [
        // English
        'the', 'and', 'for', 'with', 'from', 'this', 'that', 'have', 'has', 'are', 'was', 'were', 'but', 'not', 'can', 'cannot',
        'any', 'all', 'our', 'you', 'your', 'please', 'hello', 'thanks', 'thank', 'regards', 'dear', 'team', 'since', 'today',
        'there', 'when', 'what', 'which', 'some', 'also', 'still', 'again', 'just', 'get', 'got', 'help', 'need', 'issue',
        'problem', 'error', 'request', 'ticket', 'does', 'doesn', 'don', 'won', 'isn', 'into', 'about', 'after', 'before',
        // Turkish (folded)
        've', 'ile', 'icin', 'bir', 'bu', 'su', 'da', 'de', 'mi', 'mu', 'ama', 'fakat', 'gibi', 'daha', 'cok', 'olan', 'olarak',
        'merhaba', 'iyi', 'gunler', 'tesekkurler', 'tesekkur', 'ederim', 'lutfen', 'rica', 'sayin', 'hanim', 'bey', 'konu',
        'sorun', 'problem', 'hata', 'talep', 'kayit', 'yardim', 'acil', 'bugun', 'simdi', 'hala', 'tekrar', 'yine', 'ben',
        'benim', 'bizim', 'biz', 'siz', 'sizin', 'olarak', 'oldu', 'olur', 'var', 'icinde', 'kadar', 'sonra', 'once', 'neden',
        // French (folded)
        'les', 'des', 'une', 'est', 'pas', 'pour', 'que', 'qui', 'dans', 'sur', 'avec', 'par', 'plus', 'mais', 'sont', 'ont',
        'aux', 'ces', 'cette', 'mon', 'mes', 'nous', 'vous', 'elle', 'ils', 'leur', 'bonjour', 'merci', 'cordialement', 'svp',
        'probleme', 'erreur', 'demande', 'depuis', 'toujours', 'encore', 'aussi', 'tres', 'fait', 'faire', 'peut', 'aide',
        // German (folded)
        'der', 'die', 'das', 'und', 'ist', 'nicht', 'ein', 'eine', 'einen', 'mit', 'von', 'fur', 'auf', 'bei', 'sich', 'ich',
        'wir', 'sie', 'ihr', 'den', 'dem', 'zum', 'zur', 'auch', 'noch', 'nur', 'bitte', 'danke', 'hallo', 'gruss', 'grusse',
        'fehler', 'seit', 'kann', 'geht', 'hilfe', 'anfrage',
        // Spanish (folded)
        'los', 'las', 'unos', 'del', 'para', 'con', 'como', 'pero', 'esta', 'este', 'estos', 'esa', 'ese', 'hola', 'gracias',
        'favor', 'problema', 'desde', 'todavia', 'tambien', 'muy', 'hace', 'puede', 'ayuda', 'solicitud',
        // Portuguese (folded)
        'uma', 'uns', 'dos', 'com', 'mas', 'isso', 'ola', 'obrigado', 'obrigada', 'erro', 'ainda', 'muito', 'pode', 'nao',
        'pedido',
        // Italian (folded)
        'gli', 'uno', 'della', 'per', 'che', 'non', 'sono', 'ciao', 'grazie', 'errore', 'ancora', 'anche', 'molto', 'aiuto',
    ];

    private const FOLD = [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ğ' => 'g', 'ğ' => 'g', 'Ş' => 's', 'ş' => 's', 'Ç' => 'c', 'ç' => 'c',
        'Ö' => 'o', 'ö' => 'o', 'Ü' => 'u', 'ü' => 'u', 'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u',
        'ñ' => 'n', 'ß' => 'ss', 'É' => 'e', 'Á' => 'a', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n',
        'ô' => 'o', 'ò' => 'o', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y', 'ý' => 'y', 'č' => 'c', 'š' => 's', 'ž' => 'z', 'ł' => 'l',
        'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ń' => 'n', 'ą' => 'a', 'ę' => 'e', 'ő' => 'o', 'ű' => 'u', 'ř' => 'r', 'ě' => 'e',
    ];

    /** @var array<string,true>|null */
    private static ?array $stop = null;

    public static function normalize(string $s): string
    {
        $s = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>', '</div>'], [' <br', ' </p>', ' </li>', ' </div>'], $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strtr($s, self::FOLD); // first: Turkish İ / I must be folded before lower-casing
        return strtr(mb_strtolower($s, 'UTF-8'), self::FOLD); // then: lower-cased accents of other languages (É → é → e)
    }

    /** Stemmed tokens of a text. @return list<string> */
    public static function tokens(string $s): array
    {
        if (self::$stop === null) {
            self::$stop = array_fill_keys(self::STOP, true);
        }
        $out = [];
        foreach (preg_split('~[^\p{L}\p{N}]+~u', self::normalize($s)) ?: [] as $w) {
            if (mb_strlen($w) < 3 || isset(self::$stop[$w]) || ctype_digit($w)) {
                continue;
            }
            $out[] = mb_substr($w, 0, self::STEM);
        }
        return $out;
    }

    /**
     * Readable word for each stem: the most frequent original (lower-case, not folded) word in the texts.
     * @param list<string> $texts @return array<string,string> stem => word
     */
    public static function words(array $texts): array
    {
        $seen = [];
        foreach ($texts as $s) {
            $plain = mb_strtolower(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
            foreach (preg_split('~[^\p{L}\p{N}]+~u', $plain) ?: [] as $w) {
                $t = self::tokens($w);
                if ($t) {
                    $seen[$t[0]][$w] = ($seen[$t[0]][$w] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($seen as $stem => $forms) {
            arsort($forms);
            $out[$stem] = (string) array_key_first($forms);
        }
        return $out;
    }

    /** Term counts of a ticket: title words weigh twice, description is capped. @return array<string,float> */
    public static function terms(string $title, string $content, int $contentChars = 600): array
    {
        $tf = [];
        foreach (self::tokens($title) as $t) {
            $tf[$t] = ($tf[$t] ?? 0) + 2.0;
        }
        foreach (self::tokens(mb_substr(self::normalize($content), 0, $contentChars)) as $t) {
            $tf[$t] = ($tf[$t] ?? 0) + 1.0;
        }
        return $tf;
    }

    /**
     * Document frequencies of a background corpus (e.g. the last 1000 tickets). With a background, a word that is rare
     * in everyday tickets but shared by the whole burst ("outlook") weighs a lot; without one, IDF computed on the
     * burst alone would treat the shared word as noise.
     * @param iterable<array<string,float>> $docs @return array{n:int,df:array<string,int>}
     */
    public static function background(iterable $docs): array
    {
        $n = 0;
        $df = [];
        foreach ($docs as $tf) {
            $n++;
            foreach (array_keys($tf) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        return ['n' => $n, 'df' => $df];
    }

    /**
     * TF-IDF vectors (L2-normalised) for a set of documents.
     * @param array<int,array<string,float>> $docs id => term counts
     * @param array{n:int,df:array<string,int>}|null $bg background statistics (default: the documents themselves)
     * @return array<int,array<string,float>>
     */
    public static function vectors(array $docs, ?array $bg = null): array
    {
        $bg ??= self::background($docs);
        $n = $bg['n'];
        $df = $bg['df'];
        $out = [];
        foreach ($docs as $id => $tf) {
            $v = [];
            $norm = 0.0;
            foreach ($tf as $t => $c) {
                // smoothed IDF: terms shared by every ticket still count (that is exactly what a surge looks like)
                $w = (1 + log($c)) * (1 + log((1 + $n) / (1 + ($df[$t] ?? 0))));
                $v[$t] = $w;
                $norm += $w * $w;
            }
            $norm = sqrt($norm) ?: 1.0;
            foreach ($v as $t => $w) {
                $v[$t] = $w / $norm;
            }
            $out[$id] = $v;
        }
        return $out;
    }

    /** Cosine similarity of two normalised vectors. */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) > count($b)) {
            [$a, $b] = [$b, $a];
        }
        $s = 0.0;
        foreach ($a as $t => $w) {
            if (isset($b[$t])) {
                $s += $w * $b[$t];
            }
        }
        return min(1.0, $s);
    }

    /** Mean of normalised vectors, re-normalised (cluster centroid). @param list<array<string,float>> $vs */
    public static function centroid(array $vs): array
    {
        $c = [];
        foreach ($vs as $v) {
            foreach ($v as $t => $w) {
                $c[$t] = ($c[$t] ?? 0) + $w;
            }
        }
        $norm = sqrt(array_sum(array_map(static fn($w) => $w * $w, $c))) ?: 1.0;
        foreach ($c as $t => $w) {
            $c[$t] = $w / $norm;
        }
        arsort($c);
        return $c;
    }

    /**
     * Single-linkage clustering: two documents are in the same group when a chain of pairs with similarity ≥ $min
     * connects them (union-find). $sim(idA, idB) overrides the similarity (hybrid TF-IDF + embeddings).
     * @param array<int,array<string,float>> $vecs @return list<list<int>>
     */
    public static function cluster(array $vecs, float $min, ?callable $sim = null): array
    {
        $ids = array_keys($vecs);
        $parent = array_combine($ids, $ids);
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $s = $sim ? $sim($ids[$i], $ids[$j]) : self::cosine($vecs[$ids[$i]], $vecs[$ids[$j]]);
                if ($s >= $min) {
                    $parent[$find($ids[$i])] = $find($ids[$j]);
                }
            }
        }
        $groups = [];
        foreach ($ids as $id) {
            $groups[$find($id)][] = $id;
        }
        return array_values($groups);
    }
}
