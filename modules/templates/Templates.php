<?php
/**
 * Template management class for rendering application templates.
 * Handles theme displays with support for additional CSS/JS includes.
 * Provides secure template rendering with URL invocation blocking and error handling.
 */
class Templates extends Trongate {

    /**
     * Class constructor.
     *
     * Prevents direct URL access to the templates module while allowing
     * internal template rendering via application code.
     *
     * @param string|null $module_name The module name (auto-provided by framework)
     */
    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url('templates');
    }

    /**
     * Display admin theme template with provided data.
     *
     * @param array $data The data to pass to the template view
     * @return void
     */
    public function admin(array $data): void {
        $data['theme'] = $data['theme'] ?? 'default';
        $this->display('admin', $data);
    }

    /**
     * Display public theme template with provided data.
     *
     * @param array $data The data to pass to the template view
     * @return void
     */
    public function public(array $data): void {
        $this->display('public', $data);
    }

    /**
     * Display customer dashboard template with provided data.
     * Optional $data['crumbs']: list of [href, label] shown after the logo (project, app).
     *
     * @param array $data The data to pass to the template view
     * @return void
     */
    public function customer(array $data): void {
        $data['crumbs'] = $data['crumbs'] ?? [];
        $this->display('customer', $data);
    }

    /**
     * Display the full-page module details template.
     * Used by the Flo code generator in an overlay iframe.
     *
     * @param array $data The data to pass to the template view
     * @return void
     */
    public function module_details(array $data): void {
        $this->display('module_details', $data);
    }

    /**
     * Display 404 error page.
     *
     * @return void
     */
    public function error_404(): void {
        $this->display('error_404');
    }

    /**
     * Display a template file from this module.
     * Builds the additional top/bottom includes before rendering.
     *
     * @param string $template_name The name of the template file (without .php extension)
     * @param array $data Associative array of data to extract into template scope
     * @return void
     * @throws Exception If template file is not found
     */
    private function display(string $template_name, array $data = []): void {
        $template_path = __DIR__ . "/views/{$template_name}.php";

        if (!file_exists($template_path)) {
            throw new Exception("Template '{$template_name}' not found at {$template_path}");
        }

        foreach (['additional_includes_top', 'additional_includes_btm'] as $key) {
            $data[$key] = $this->build_additional_includes($data[$key] ?? []);
        }

        extract($data);
        require $template_path;
    }

    /**
     * Builds HTML code for additional includes based on file types.
     *
     * @param array|string|null $files Array of file names.
     * @return string HTML code for additional includes.
     */
    private function build_additional_includes(array|string|null $files): string {
        if (!is_array($files) || empty($files)) {
            return '';
        }

        $lines = array_map(fn($file) => match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => $this->build_js_include_code($file),
            'css' => $this->build_css_include_code($file),
            default => $file,
        }, array_values($files));

        return implode(PHP_EOL . '    ', $lines) . PHP_EOL;
    }

    /**
     * Builds JavaScript include code for the given file.
     *
     * @param string $file File path for JavaScript include.
     * @return string JavaScript include code.
     */
    private function build_js_include_code(string $file): string {
        return '<script src="' . $file . '"></script>';
    }

    /**
     * Builds CSS include code for the given file.
     *
     * @param string $file File path for CSS include.
     * @return string CSS include code.
     */
    private function build_css_include_code(string $file): string {
        return '<link rel="stylesheet" href="' . $file . '">';
    }

}
