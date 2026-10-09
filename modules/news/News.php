<?php

require_once __DIR__ . '/lib/Posts.php';

/**
 * Public news: the changelog and longer writing, from Markdown files in posts/.
 * To publish, add posts/YYYY-MM-DD-slug.md (see lib/Posts.php for the front matter).
 *
 * @property-read Templates $templates
 */
class News extends Trongate {

    /**
     * news, news/changelog and news/writing: the posts, newest first.
     */
    public function index(): void {
        $kind = segment(3) ?: null;
        if ($kind !== null && !isset(Posts::KINDS[$kind])) {
            $this->not_found();
            return;
        }

        $this->view('index', [
            'posts' => Posts::all($kind),
            'kind' => $kind,
            'kinds' => Posts::KINDS,
        ]);
    }

    /**
     * news/{slug}: one post.
     */
    public function show(): void {
        $post = Posts::find((string) segment(3));
        if ($post === null) {
            $this->not_found();
            return;
        }

        $this->view('show', ['post' => $post]);
    }

    /**
     * news/feed: an Atom feed of every post, for feed readers.
     */
    public function feed(): void {
        $posts = array_slice(Posts::all(), 0, 20);
        foreach ($posts as $post) {
            $post->html = Markdown::render($post->body);
        }

        header('Content-Type: application/atom+xml; charset=UTF-8');
        $this->view('feed', ['posts' => $posts]);
    }

    /**
     * The site's 404 page.
     */
    private function not_found(): void {
        http_response_code(404);
        $this->templates->error_404();
    }

}
