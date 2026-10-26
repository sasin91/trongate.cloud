<?php
/**
 * Admin Controller (backup)
 *
 * Manages backups records (read-only in tg-admin).
 */
class Admin extends Trongate {

    private int $default_limit = 20;
    private array $per_page_options = [10, 20, 50, 100];
    
    /**
     * Default entry point - redirects to manage page
     *
     * @return void
     */
    public function index(): void {
        redirect('backup-admin/manage');
    }

    /**
     * Display paginated list of backups.
     *
     * Shows records in a table with pagination controls. Includes
     * dropdown for selecting number of records per page.
     *
     * @return void
     */
    public function manage(): void {
        $this->trongate_security->make_sure_allowed();

        $search_query = $_GET['search_query'] ?? '';
        $search_column = $_GET['search_column'] ?? '';

        // Validate column against known searchable columns.
        $allowed_columns = $this->model->get_searchable_columns();
        if ($search_column !== '' && !in_array($search_column, $allowed_columns, true)) {
            $search_column = '';
        }

        $search_active = ($search_query !== '');

        if ($search_active) {
            $total_rows = $this->model->count_search_results($search_query, $search_column);
        } else {
            $total_rows = $this->model->count_all();
        }
        
        $limit = $this->get_limit();
        $offset = $this->get_offset();

        if ($search_active) {
            $rows = $this->model->search_records($search_query, $search_column, $limit, $offset);
        } else {
            $rows = $this->model->fetch_records($limit, $offset);
        }

        $data = [
            'rows' => $rows,
            'pagination_data' => $this->get_pagination_data($total_rows, $limit, $search_query, $search_column),
            'view_module' => 'backup/admin',
            'view_file' => 'manage',
            'per_page_options' => $this->per_page_options,
            'selected_per_page' => $this->get_selected_per_page()
        ];

        $data['search_query'] = $search_query;
        $data['search_column'] = $search_column;
        $data['search_active'] = $search_active;
        $this->templates->admin($data);
    }






    /**
     * Set number of records per page for pagination.
     *
     * Stores user preference in session for consistent pagination across requests.
     *
     * @return void
     */
    public function set_per_page(): void {
        $this->trongate_security->make_sure_allowed();

        $selected_index = segment(3, 'int');

        if (!isset($this->per_page_options[$selected_index])) {
            $selected_index = 1;
        }

        $_SESSION['selected_per_page'] = $selected_index;
        redirect('backup-admin/manage');
    }

    /**
     * Generate pagination configuration data.
     *
     * @param int    $total_rows    Total number of records
     * @param int    $limit         Number of records per page
     * @param string $search_query  The search query string
     * @param string $search_column The column being searched
     * @return array Pagination configuration for template
     */
    private function get_pagination_data(int $total_rows, int $limit, string $search_query = '', string $search_column = ''): array {
        $pagination_query = '';
        if ($search_query !== '' && $search_column !== '') {
            $pagination_query = 'search_query=' . urlencode($search_query) . '&search_column=' . urlencode($search_column);
        }

        return [
            'total_rows' => $total_rows,
            'limit' => $limit,
            'pagination_root' => 'backup-admin/manage',
            'pagination_query' => $pagination_query,
            'record_name_plural' => 'backups',
            'include_showing_statement' => true
        ];
    }



    /**
     * Get selected per-page index from session.
     *
     * @return int Index of selected per-page option
     */
    private function get_selected_per_page(): int {
        return $_SESSION['selected_per_page'] ?? 1;
    }

    /**
     * Get current pagination limit from session.
     *
     * @return int Number of records to display per page
     */
    private function get_limit(): int {
        if (isset($_SESSION['selected_per_page'])) {
            return $this->per_page_options[$_SESSION['selected_per_page']];
        }
        return $this->default_limit;
    }

    /**
     * Calculate pagination offset based on page number.
     *
     * @return int Database offset for current page
     */
    private function get_offset(): int {
        $page_num = segment(3, 'int');
        return ($page_num > 1) ? ($page_num - 1) * $this->get_limit() : 0;
    }

    /**
     * Handle search form submission for filtering backups.
     *
     * Preprocesses text parameters and triggers custom query filtering.
     *
     * @return void
     */
    public function submit_search(): void {
        $this->trongate_security->make_sure_allowed();

        $search_query = post('search_query', true);
        $search_column = post('search_column', true);

        // Validate column against known searchable columns.
        $allowed_columns = $this->model->get_searchable_columns();
        if ($search_column !== '' && !in_array($search_column, $allowed_columns, true)) {
            $search_column = '';
        }

        // Preprocess the query
        $search_query = trim($search_query);
        $search_query = preg_replace('/\s+/', ' ', $search_query);

        // Validate minimum 2 character length
        if (strlen($search_query) < 2) {
            set_flashdata('Search query must be at least 2 characters');
            redirect('backup-admin/manage');
        }

        redirect('backup-admin/manage?search_query=' . urlencode($search_query) . '&search_column=' . urlencode($search_column));
    }

    /**
     * Display the search modal form for filtering backups.
     *
     * Renders a form with a search input and a dropdown of searchable columns.
     *
     * @return void
     */
    public function search_modal(): void {
        $this->trongate_security->make_sure_allowed();

        $data['view_module'] = 'backup/admin';
        $data['view_file'] = 'search_modal';
        $this->view('search_modal', $data);
    }


}
