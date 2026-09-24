<?php
/**
 * Showcase FUSE Model — one curated "Built with tiknix" project on the flagship landing.
 *
 * Seeded by scripts/seed-showcase.php, screenshotted by scripts/capture-showcase.php.
 * An entry may also carry a founder STORY (story_json): who built it, what they started
 * with, what it does. Those render as the landing's founder section and on /stories.
 */

class Model_Showcase extends \RedBeanPHP\SimpleModel {

    /**
     * The founder story, or null when this entry has none.
     *
     * A story_json that does not decode is a broken seed, not "no story": it throws with
     * the slug, instead of quietly dropping a founder from the page.
     */
    public function story(): ?array {
        $raw = trim((string) ($this->bean->storyJson ?? ''));
        if ($raw === '') return null;
        try {
            $s = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("showcase '{$this->bean->slug}': story_json does not decode ({$e->getMessage()}) — fix it in scripts/seed-showcase.php and re-run it");
        }
        foreach (['founder', 'headline', 'summary', 'body'] as $k) {
            if (empty($s[$k])) throw new \RuntimeException("showcase '{$this->bean->slug}': story has no '{$k}' — see scripts/seed-showcase.php");
        }
        return $s + ['slug' => (string) $this->bean->slug, 'title' => (string) $this->bean->title, 'url' => (string) $this->bean->url];
    }

    /**
     * The founder stories among $entries, in story order.
     *
     * @param iterable<\RedBeanPHP\OODBBean> $entries showcase beans (enabled ones)
     * @return array<int, array>
     */
    public static function stories(iterable $entries): array {
        $out = [];
        foreach ($entries as $e) { if ($s = $e->box()->story()) $out[] = $s; }
        usort($out, fn($a, $b) => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        return $out;
    }
}
