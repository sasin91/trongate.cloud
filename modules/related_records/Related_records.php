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
 *   columns        Column labels; the first is the linked column
 *   rows           Each ['url' => ..., 'link_text' => ..., 'cells' => [...]]
 *                  with one cell per remaining column
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
