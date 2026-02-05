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
            redirect('servers/deployments/manage');
        }

        $data = [
            'view_module' => 'servers',
            'view_file' => 'deployments/show',
            'deployment' => $deployment
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }
}