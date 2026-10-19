<?php
/**
 * Related_records Controller
 *
 * Read-only panels for tg-admin show pages: a card below the record that
 * lists the records related to it, each linking to its own tg-admin page.
 * The calling module's model builds the panel; this module only draws it.
 *
 * A panel is an array:
 *   heading        Card heading, e.g. 'Projects'
 *   url_path       Each row links to url_path . its id, e.g. 'project/show/'
 *   link_column    The column whose value is the link
 *   columns        Record field => column label, in display order
 *   rows           The records as fetched (objects with an id)
 *   empty_message  Shown when there are no rows
 */
class Related_records extends Trongate {

    /**
     * Render one panel. Called via Modules::run() from a show view.
     *
     * @param array<string, mixed> $panel The panel (see the class comment).
     * @return void
     */
    public function draw_panel(array $panel): void {
        block_url('related_records/draw_panel');

        $data = $panel;
        $data['view_module'] = 'related_records';
        $this->view('panel', $data);
    }
}
