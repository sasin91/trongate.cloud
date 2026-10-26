<?php

require_once __DIR__ . '/../../news/lib/Markdown.php';
require_once __DIR__ . '/Sample.php';

/**
 * Documentation pages: Markdown files in modules/docs/pages, named slug.md.
 *
 * Each file starts with front matter between --- lines:
 *   title:   the page heading and its name in the sidebar
 *   summary: one or two sentences for the docs index and search results
 *   order:   where the page sits in the sidebar, lowest first
 *   sample:  the name of a folder in modules/docs/samples to offer as a download (optional)
 *   draft:   true to hide the page outside ENV=dev (optional)
 *
 * The body is written in the news module's Markdown subset (see modules/news/lib/Markdown.php).
 */
class Pages {

    /** Where the page files live */
    const DIRECTORY = __DIR__ . '/../pages';

    /**
     * Every published page, in sidebar order.
     *
     * @return array<int, object> Pages without their rendered body
     */
    public static function all(): array {
        $pages = [];

        foreach (glob(self::DIRECTORY . '/*.md') ?: [] as $path) {
            $page = self::parse($path);
            if ($page !== null && (!$page->draft || ENV === 'dev')) {
                $pages[] = $page;
            }
        }

        usort($pages, fn(object $a, object $b) => [$a->order, $a->title] <=> [$b->order, $b->title]);

        return $pages;
    }

    /**
     * One published page, ready to print: its body rendered, its sections, its sample's
     * files and the pages before and after it. Null when there is no page by that slug.
     *
     * @param string $slug The URL slug (filename without .md)
     * @return object|null
     */
    public static function find(string $slug): ?object {
        $pages = self::all();

        foreach ($pages as $i => $page) {
            if ($page->slug !== $slug) {
                continue;
            }

            $page->html = Markdown::render($page->body, 'docs/' . $page->slug);
            $page->sections = Markdown::headings($page->body);
            $page->sample_files = $page->sample !== '' ? Sample::files($page->sample) : [];
            $page->previous = $pages[$i - 1] ?? null;
            $page->next = $pages[$i + 1] ?? null;
            return $page;
        }

        return null;
    }

    /**
     * Read one page file.
     *
     * @param string $path Path to a .md file
     * @return object|null Null when the name or front matter is not valid
     */
    private static function parse(string $path): ?object {
        if (!preg_match('/^([a-z0-9-]+)\.md$/', basename($path), $name)) {
            return null;
        }

        $source = str_replace("\r\n", "\n", (string) file_get_contents($path));
        if (!preg_match('/^---\n(.*?)\n---\n(.*)$/s', $source, $parts)) {
            return null;
        }

        $meta = [];
        foreach (explode("\n", $parts[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $match)) {
                $meta[$match[1]] = trim($match[2]);
            }
        }

        if (empty($meta['title'])) {
            return null;
        }

        $sample = $meta['sample'] ?? '';

        return (object) [
            'slug' => $name[1],
            'title' => $meta['title'],
            'summary' => $meta['summary'] ?? '',
            'order' => (int) ($meta['order'] ?? 100),
            'sample' => Sample::exists($sample) ? $sample : '',
            'draft' => ($meta['draft'] ?? '') === 'true',
            'body' => trim($parts[2]),
        ];
    }

}
