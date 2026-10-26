<?php

require_once __DIR__ . '/lib/Pages.php';

/**
 * Public documentation for customers: pages from Markdown files in pages/ and sample modules
 * to download from samples/. To add a page, add pages/slug.md (see lib/Pages.php for the front matter).
 *
 * @property-read Templates $templates
 */
class Docs extends Trongate {

    /**
     * docs: every page with its summary.
     */
    public function index(): void {
        $this->view('index', ['pages' => Pages::all()]);
    }

    /**
     * docs/{slug}: one page.
     */
    public function show(): void {
        $page = Pages::find((string) segment(3));
        if ($page === null) {
            $this->not_found();
            return;
        }

        $this->view('show', ['page' => $page, 'pages' => Pages::all()]);
    }

    /**
     * docs/samples/{name}.zip: a sample module as a zip, to unzip into an app's root.
     */
    public function sample(): void {
        if (!preg_match('/^([a-z0-9-]+)\.zip$/', (string) segment(3), $match) || !Sample::exists($match[1])) {
            $this->not_found();
            return;
        }

        $zip = (string) tempnam(sys_get_temp_dir(), 'sample');
        try {
            Sample::zip($match[1], $zip);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $match[1] . '.zip"');
            header('Content-Length: ' . filesize($zip));
            readfile($zip);
        } finally {
            unlink($zip);
        }
    }

    /**
     * The site's 404 page.
     */
    private function not_found(): void {
        http_response_code(404);
        $this->templates->error_404();
    }

}
