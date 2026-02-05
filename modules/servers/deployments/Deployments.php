<?php

class Deployments extends Trongate {
    function show(): void {
        $this->_make_sure_allowed();

        $deployment_id = segment(3, 'int');

        $deployment = $this->model->db->query_bind(
            'SELECT * FROM deployment WHERE id = :deployment_id LIMIT 1',
            ['deployment_id' => $deployment_id],
            'object'
        );

        if (empty($deployment)) {
            redirect('servers');
        }

        $data = [
            'view_file' => 'show',
            'deployment' => $deployment[0]
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function stream_logs(): void {
        $this->_make_sure_allowed();
        $deployment_id = segment(3, 'int');

        // Disable buffering and set unlimited execution time
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', 'off');
        ini_set('implicit_flush', '1');
        set_time_limit(0);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // Nginx
        header('Connection: keep-alive');

        // Initial buffer to start stream
        echo ":" . str_repeat(" ", 2048) . "\n\n";
        ob_flush();
        flush();

        $last_output_length = 0;
        $start_time = time();
        $max_duration = 3600; // 1 hour max

        while (true) {
            // Check for client disconnect
            if (connection_aborted()) {
                break;
            }

            // Timeout safety
            if (time() - $start_time > $max_duration) {
                break;
            }

            $deployment = $this->model->db->query_bind(
                'SELECT output, status FROM deployment WHERE id = :deployment_id LIMIT 1',
                ['deployment_id' => $deployment_id],
                'object'
            );

            if (empty($deployment)) {
                echo "event: error\ndata: Deployment not found\n\n";
                ob_flush();
                flush();
                break;
            }

            $row = $deployment[0];
            $current_length = strlen($row->output ?? '');

            if ($current_length > $last_output_length) {
                $new_content = substr($row->output, $last_output_length);
                // Sanitize valid UTF-8 for JSON safety
                $new_content = mb_convert_encoding($new_content, 'UTF-8', 'UTF-8');
                
                echo "data: " . json_encode(['text' => $new_content]) . "\n\n";
                ob_flush();
                flush();
                
                $last_output_length = $current_length;
            }

            if (in_array($row->status, ['success', 'failed'])) {
                echo "event: close\ndata: " . json_encode(['status' => $row->status]) . "\n\n";
                ob_flush();
                flush();
                break;
            }

            sleep(1);
        }
        exit;
    }
}