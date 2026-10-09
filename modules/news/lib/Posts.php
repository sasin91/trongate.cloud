<?php

require_once __DIR__ . '/Markdown.php';

/**
 * News posts: Markdown files in modules/news/posts, named YYYY-MM-DD-slug.md.
 *
 * Each file starts with front matter between --- lines:
 *   title:   the headline
 *   kind:    changelog or writing
 *   summary: one or two sentences for the list and the feed
 *   draft:   true to hide the post outside ENV=dev (optional)
 */
class Posts {

    /** Kinds a post can be, with the label the site shows */
    const KINDS = [
        'changelog' => 'Changelog',
        'writing' => 'Writing',
    ];

    /** Where the post files live */
    const DIRECTORY = __DIR__ . '/../posts';

    /**
     * Every published post, newest first.
     *
     * @param string|null $kind Only posts of this kind (a key of KINDS)
     * @return array<int, object> Posts without their rendered body
     */
    public static function all(?string $kind = null): array {
        $posts = [];

        foreach (glob(self::DIRECTORY . '/*.md') ?: [] as $path) {
            $post = self::parse($path);
            if ($post === null || ($post->draft && ENV !== 'dev')) {
                continue;
            }
            if ($kind === null || $post->kind === $kind) {
                $posts[] = $post;
            }
        }

        usort($posts, fn(object $a, object $b) => [$b->date, $b->slug] <=> [$a->date, $a->slug]);

        return $posts;
    }

    /**
     * One published post with its body rendered, or null when there is none by that slug.
     *
     * @param string $slug The URL slug (filename without the date and .md)
     * @return object|null
     */
    public static function find(string $slug): ?object {
        foreach (self::all() as $post) {
            if ($post->slug === $slug) {
                $post->html = Markdown::render($post->body);
                return $post;
            }
        }

        return null;
    }

    /**
     * Read one post file.
     *
     * @param string $path Path to a .md file
     * @return object|null Null when the name or front matter is not valid
     */
    private static function parse(string $path): ?object {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)\.md$/', basename($path), $name)) {
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

        $kind = $meta['kind'] ?? '';
        if (empty($meta['title']) || !isset(self::KINDS[$kind])) {
            return null;
        }

        return (object) [
            'slug' => $name[2],
            'date' => $name[1],
            'title' => $meta['title'],
            'kind' => $kind,
            'kind_label' => self::KINDS[$kind],
            'summary' => $meta['summary'] ?? '',
            'draft' => ($meta['draft'] ?? '') === 'true',
            'body' => trim($parts[2]),
        ];
    }

}
