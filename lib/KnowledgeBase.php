<?php
/**
 * KnowledgeBase — what is known about how the PLATFORM behaves, for an agent that is stuck.
 *
 * A project's notebook (app\Notebook) holds what is true of that project. This holds what is
 * true of Tiknix itself, in every project: "the seed remote is read-only by design", "seeds in
 * database/seeds never run". An app's agent looks here (the `ask_tiknix` tool → POST
 * /brokerinfo/kb) BEFORE it asks its user about escalating to support — so a question with a
 * known answer costs seconds, not a ticket and a wait.
 *
 * An entry is a symptom, its cause and what to do, and it is only ever handed out when a PERSON
 * published it. It grows from two places, both as candidates the owner approves or dismisses on
 * /knowledge: an answered support ticket, and a lesson some project's agent wrote in its
 * notebook that is really about the platform (looksPlatform).
 *
 * Search is words, not vectors: an agent arrives with an error string or a symptom, and those
 * match on their words. No model runs for it. The scoring is a pure function (score()), so what
 * matches is something a test can pin.
 *
 * No Fallbacks: a weak match is NOT returned as an answer. An entry believed wrongly is worse
 * than "nothing known" — which sends the agent on to ask its user, as before.
 */
namespace app;

final class KnowledgeBase {

    public const STATUSES = ['candidate', 'published', 'retired', 'dismissed'];
    public const SOURCES  = ['seed', 'support', 'notebook', 'manual'];
    /** Below this an entry is not an answer to the question (score() — tuned by the tests). */
    public const MIN_SCORE = 0.34;
    /** Words that say nothing about what a question is about. */
    private const STOP = ['the','a','an','is','are','was','were','be','to','of','in','on','at','for','and','or','it','its','this','that','with','as','by','from','not','no',
                          'i','my','we','our','you','your','when','what','why','how','does','do','did','can','cannot','after','before','has','have','had','but','so','if','then',
                          'there','their','which','will','would','should','get','gets','got','says','say','still','just','into','out','up','any','all','one','am','than','too'];

    // ---- search ------------------------------------------------------------------------------

    /**
     * The published entries that answer $question, best first — [] when nothing does.
     * @return array<int,array{id:int,title:string,symptom:string,cause:string,fix:string,reviewed:string,score:float}>
     */
    public static function search(string $question, int $limit = 3): array {
        $question = trim($question);
        if ($question === '') return [];
        $found = [];
        foreach (Bean::getAll("SELECT id, title, symptom, cause, fix, reviewed_at FROM kbentry WHERE status = 'published'") as $r) {
            $s = self::score($question, $r);
            if ($s >= self::MIN_SCORE) $found[] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'symptom' => (string) $r['symptom'], 'cause' => (string) $r['cause'],
                                                   'fix' => (string) $r['fix'], 'reviewed' => substr((string) $r['reviewed_at'], 0, 10), 'score' => round($s, 3)];
        }
        usort($found, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
        // A second entry is worth an agent's attention only when it matches nearly as well as the
        // first; a distant runner-up is noise that reads like an answer.
        if ($found) $found = array_values(array_filter($found, fn($f) => $f['score'] >= $found[0]['score'] * 0.7));
        return array_slice($found, 0, max(1, $limit));
    }

    /** search(), and the entries handed out are counted: an entry that keeps being hit is guidance that is missing. */
    public static function ask(string $question, int $limit = 3): array {
        $found = self::search($question, $limit);
        foreach ($found as $f) Bean::exec('UPDATE kbentry SET hits = COALESCE(hits, 0) + 1, last_hit_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $f['id']]);
        return $found;
    }

    /**
     * How well an entry answers a question, 0..1. The share of the question's meaningful words
     * the entry contains — a word in the title or the symptom (what the agent SEES) counting more
     * than one in the cause or the fix — raised when a distinctive token of the question
     * (an error string's code, a file name, a flag) appears in the entry verbatim.
     *
     * @param array{title?:string,symptom?:string,cause?:string,fix?:string} $entry
     */
    public static function score(string $question, array $entry): float {
        $q = self::words($question);
        if (!$q) return 0.0;
        $seen  = array_flip(self::words(($entry['title'] ?? '') . ' ' . ($entry['symptom'] ?? '')));
        $about = array_flip(self::words(($entry['cause'] ?? '') . ' ' . ($entry['fix'] ?? '')));
        $got = 0.0; $distinct = 0;
        foreach ($q as $w) {
            $rare = (bool) preg_match('/[0-9_\/.\-]|^.{9,}$/', $w);          // 403, --update, database/seeds, upload-pack
            if (isset($seen[$w]))      { $got += 1.0; if ($rare) $distinct++; }
            elseif (isset($about[$w])) { $got += 0.5; if ($rare) $distinct++; }
        }
        $share = $got / count($q);
        // Two distinctive tokens in common is the same problem far more often than chance.
        return min(1.0, $share + min(2, $distinct) * 0.12);
    }

    /** A text's meaningful words: lowercased, stop words gone, plain plurals folded, each once. @return string[] */
    public static function words(string $text): array {
        preg_match_all('/[a-z0-9][a-z0-9_\/.\-]*[a-z0-9]|[a-z0-9]/', strtolower($text), $m);
        $out = [];
        foreach ($m[0] as $w) {
            $w = trim($w, '.-/');
            if ($w === '' || in_array($w, self::STOP, true) || (strlen($w) < 2)) continue;
            if (strlen($w) > 4 && !preg_match('/[0-9_\/.\-]/', $w)) $w = str_ends_with($w, 'ies') ? substr($w, 0, -3) . 'y' : preg_replace('/(?<![su])s$/', '', $w);
            $out[$w] = true;
        }
        return array_map('strval', array_keys($out));   // a key like '403' comes back as an int
    }

    // ---- writing -----------------------------------------------------------------------------

    /** What cannot be saved about an entry, as sentences. @return string[] */
    public static function problems(array $in): array {
        $p = [];
        if (mb_strlen(trim((string) ($in['title'] ?? ''))) < 8) $p['title'] = 'Say what the person or agent SEES, in a line (at least a few words).';
        if (mb_strlen((string) ($in['title'] ?? '')) > 160) $p['title'] = 'Keep the title to one line (160 characters).';
        if (trim((string) ($in['fix'] ?? '')) === '') $p['fix'] = 'An entry is only useful with what to do about it.';
        if (isset($in['status']) && !in_array($in['status'], self::STATUSES, true)) $p['status'] = 'Not a status.';
        return $p;
    }

    /**
     * Create or change an entry. Publishing (or saving a published one) marks it reviewed today:
     * a person just stood behind it. Returns the id.
     */
    public static function save(?int $id, array $in, int $memberId = 0): int {
        if ($p = self::problems($in)) throw new \InvalidArgumentException(implode(' ', $p));
        $b = $id ? Bean::load('kbentry', $id) : Bean::dispense('kbentry');
        if ($id && !$b->id) throw new \RuntimeException("No knowledge-base entry #{$id}.");
        $now = date('Y-m-d H:i:s');
        foreach (['title', 'symptom', 'cause', 'fix'] as $k) if (array_key_exists($k, $in)) $b->{$k} = trim((string) $in[$k]);
        if (!$b->id) {
            $b->status = 'candidate'; $b->hits = 0; $b->createdAt = $now; $b->createdBy = $memberId;
            $b->source = in_array($in['source'] ?? '', self::SOURCES, true) ? (string) $in['source'] : 'manual';
            $b->sourceNote = mb_substr(trim((string) ($in['source_note'] ?? '')), 0, 200);
        }
        if (isset($in['status'])) $b->status = (string) $in['status'];
        if ($b->status === 'published') $b->reviewedAt = $now;
        $b->updatedAt = $now;
        return (int) Bean::store($b);
    }

    /** Move an entry to a status (publish, retire, dismiss, back to candidate). */
    public static function setStatus(int $id, string $status): void {
        if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException("'{$status}' is not a status");
        $b = Bean::load('kbentry', $id);
        if (!$b->id) throw new \RuntimeException("No knowledge-base entry #{$id}.");
        if ($status === 'published' && ($p = self::problems(['title' => $b->title, 'fix' => $b->fix]))) throw new \InvalidArgumentException('It cannot be published yet: ' . implode(' ', $p));
        $b->status = $status; $b->updatedAt = date('Y-m-d H:i:s');
        if ($status === 'published') $b->reviewedAt = $b->updatedAt;
        Bean::store($b);
    }

    /**
     * Offer something as a candidate for a person to look at. Not added when the base already
     * knows it — an entry in ANY state (a dismissed one too: the owner said no once) that the
     * text matches closely. Returns the new id, or 0 when it was already known.
     */
    public static function propose(string $title, string $symptom, string $cause, string $fix, string $source, string $note): int {
        $title = mb_substr(trim(preg_replace('/\s+/', ' ', $title)), 0, 160);
        if (mb_strlen($title) < 8) return 0;
        foreach (Bean::getAll('SELECT title, symptom, cause, fix FROM kbentry') as $r) {
            if (self::score($title . ' ' . $symptom, $r) >= 0.75 || strcasecmp(trim((string) $r['title']), $title) === 0) return 0;
        }
        $b = Bean::dispense('kbentry');
        $now = date('Y-m-d H:i:s');
        $b->title = $title; $b->symptom = trim($symptom); $b->cause = trim($cause); $b->fix = trim($fix);
        $b->status = 'candidate'; $b->source = in_array($source, self::SOURCES, true) ? $source : 'manual';
        $b->sourceNote = mb_substr(trim($note), 0, 200); $b->hits = 0; $b->createdBy = 0; $b->createdAt = $now; $b->updatedAt = $now;
        return (int) Bean::store($b);
    }

    // ---- promotion from a project's notebook ---------------------------------------------------

    /**
     * Is a lesson an agent wrote in a project's notebook really about the PLATFORM — true in any
     * project — rather than about that app? Decided by what it names: the platform's own parts
     * (the runtime, clitool, the builder, seeds, the MCP server, updates, containers…). A wrong
     * yes costs the owner one dismissal; it never publishes anything.
     */
    public static function looksPlatform(string $lesson): bool {
        return (bool) preg_match('~\b(tiknix|runtime|clitool|--[a-z]+-?[a-z]*=?|agents\.md|claude\.md|vendor/tiknix|mcp|full_validation|reuse_digest|submit_plan|ui_pattern|'
            . 'seedrule|authcontrol|permissioncache|redbean|bean::|r::|flight(php)?|services/schema/seeds|database/seeds|overrides?\.lock|concepts?\.lock|--override|'
            . 'worktree|sandbox|builder|planner|audit|pipeline runtime|broker|status report|container|seed remote|git push|app\\\\ui|ui::|chrome::add|sidecar|apcu|query cache|'
            . 'composer|autoload|opcache|php 8\.\d)\b~i', $lesson);
    }

    /**
     * The lessons among a run's notebook entries that look like platform knowledge, offered as
     * candidates. $entries: [{kind, text}] as app\Notebook parses them. Returns how many were offered.
     */
    public static function proposeLessons(array $entries, string $projectSlug, string $source): int {
        $n = 0;
        foreach ($entries as $e) {
            $text = trim((string) ($e['text'] ?? ''));
            if (($e['kind'] ?? '') !== 'lesson' || $text === '' || !self::looksPlatform($text)) continue;
            // A lesson is one sentence: it is the title AND the starting point of the fix, for a person to split.
            if (self::propose(mb_substr($text, 0, 160), $text, '', $text, 'notebook', "{$projectSlug} — {$source}")) $n++;
        }
        return $n;
    }
}
