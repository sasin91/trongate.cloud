<?php

/**
 * The small Markdown subset news posts are written in, rendered to HTML.
 *
 * Blocks: ## and ### headings, paragraphs, - and 1. lists, > quotes, ``` code fences
 * and images on a line of their own: ![What it shows](/images/news/file.webp), served from public/.
 * Inline: `code`, **bold**, *italic* and [links](https://...).
 * Everything is escaped before it is formatted, so raw HTML in a post shows as text,
 * and links only keep http(s), mailto, site-relative and #anchor targets.
 */
class Markdown {

    /** An image on a line of its own, with a site-relative path */
    const IMAGE = '#^!\[([^\]]*)\]\((/[^/)\s][^)\s]*)\)$#';

    /** Where site-relative image paths are found on disk */
    const PUBLIC_DIRECTORY = __DIR__ . '/../../../public';

    /**
     * Render a Markdown document.
     *
     * @param string $markdown The post body
     * @param string $link The post's own address; headings then link to themselves (news/{slug})
     * @return string HTML
     */
    public static function render(string $markdown, string $link = ''): string {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $html = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (trim($line) === '') {
                continue;
            }

            if (str_starts_with($line, '```')) {
                $code = [];
                while (++$i < $count && !str_starts_with($lines[$i], '```')) {
                    $code[] = $lines[$i];
                }
                $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            if (preg_match('/^(#{2,3}) (.+)$/', $line, $match)) {
                $tag = 'h' . strlen($match[1]);
                $id = self::anchor($match[2]);
                $self = $link !== '' ? ' <a class="lp-anchor" href="' . self::escape("{$link}#{$id}") . '" aria-label="Link to this section">#</a>' : '';
                $html[] = "<{$tag} id=\"{$id}\">" . self::inline($match[2]) . $self . "</{$tag}>";
                continue;
            }

            if (preg_match(self::IMAGE, $line, $match)) {
                $html[] = self::figure($match[2], $match[1]);
                continue;
            }

            $list = match (true) {
                (bool) preg_match('/^- /', $line) => ['ul', '/^- (.*)$/'],
                (bool) preg_match('/^\d+\. /', $line) => ['ol', '/^\d+\. (.*)$/'],
                default => null,
            };

            if ($list !== null) {
                [$tag, $pattern] = $list;
                $items = [];
                for (; $i < $count; $i++) {
                    if (preg_match($pattern, $lines[$i], $match)) {
                        $items[] = $match[1];
                    } elseif ($items && preg_match('/^ {2,}(\S.*)$/', $lines[$i], $match)) {
                        // An indented line continues the item above it
                        $items[count($items) - 1] .= ' ' . $match[1];
                    } else {
                        break;
                    }
                }
                $i--;
                $html[] = "<{$tag}>" . implode('', array_map(fn($item) => '<li>' . self::inline($item) . '</li>', $items)) . "</{$tag}>";
                continue;
            }

            if (str_starts_with($line, '>')) {
                $quote = [];
                for (; $i < $count && str_starts_with($lines[$i], '>'); $i++) {
                    $quote[] = ltrim(substr($lines[$i], 1));
                }
                $i--;
                $html[] = '<blockquote>' . self::render(implode("\n", $quote)) . '</blockquote>';
                continue;
            }

            // A paragraph runs until a blank line or the start of another block
            $paragraph = [];
            for (; $i < $count; $i++) {
                if (trim($lines[$i]) === '' || preg_match('/^(```|#{2,3} |- |\d+\. |>)/', $lines[$i]) || preg_match(self::IMAGE, $lines[$i])) {
                    break;
                }
                $paragraph[] = trim($lines[$i]);
            }
            $i--;
            $html[] = '<p>' . self::inline(implode(' ', $paragraph)) . '</p>';
        }

        return implode("\n", $html);
    }

    /**
     * The ## headings of a document, for a table of contents.
     *
     * @param string $markdown The post body
     * @return array<string, string> Heading text by its id
     */
    public static function headings(string $markdown): array {
        $headings = [];
        $fenced = false;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
            if (str_starts_with($line, '```')) {
                $fenced = !$fenced;
            } elseif (!$fenced && preg_match('/^## (.+)$/', $line, $match)) {
                $headings[self::anchor($match[1])] = $match[1];
            }
        }

        return $headings;
    }

    /**
     * An image on its own line. Its size is read from the file, so the page doesn't jump
     * while it loads; tall images (phone screenshots) are shown narrower. Clicking one opens it full size.
     *
     * @param string $src Site-relative path to a file in public/
     * @param string $alt What the image shows
     * @return string HTML
     */
    private static function figure(string $src, string $alt): string {
        $size = '';
        $class = 'lp-figure';
        $path = self::PUBLIC_DIRECTORY . $src;
        if (!str_contains($src, '..') && is_file($path) && ($info = getimagesize($path))) {
            $size = " width=\"{$info[0]}\" height=\"{$info[1]}\"";
            $class .= $info[1] > $info[0] ? ' lp-figure-tall' : '';
        }

        $src = self::escape($src);
        return "<figure class=\"{$class}\"><a href=\"{$src}\" target=\"_blank\" rel=\"noopener\"><img src=\"{$src}\" alt=\"" . self::escape($alt) . "\"{$size} loading=\"lazy\"></a></figure>";
    }

    /**
     * Render one line of inline Markdown.
     *
     * @param string $text Raw text
     * @return string HTML
     */
    public static function inline(string $text): string {
        // Code spans first, parked as placeholders so nothing inside them gets formatted
        $code = [];
        $text = preg_replace_callback('/`([^`]+)`/', function (array $match) use (&$code): string {
            $code[] = '<code>' . self::escape($match[1]) . '</code>';
            return "\x00" . (count($code) - 1) . "\x00";
        }, $text);

        $text = self::escape($text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $match): string {
            $href = html_entity_decode($match[2], ENT_QUOTES);
            if (!preg_match('#^(https?://|mailto:|/(?!/)|\#)#i', $href)) {
                return $match[1];
            }
            $external = preg_match('#^https?://#i', $href) ? ' target="_blank" rel="noopener"' : '';
            return '<a href="' . self::escape($href) . '"' . $external . '>' . $match[1] . '</a>';
        }, $text);

        return preg_replace_callback('/\x00(\d+)\x00/', fn(array $match) => $code[(int) $match[1]], $text);
    }

    /**
     * The id a heading gets, so sections can be linked to.
     *
     * @param string $heading Raw heading text
     * @return string Lowercase, dash separated
     */
    public static function anchor(string $heading): string {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags($heading))), '-');
    }

    /**
     * Escape text for HTML.
     *
     * @param string $text Raw text
     * @return string Escaped text
     */
    private static function escape(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

}
