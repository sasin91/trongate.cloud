<?php

/**
 * @property-read Projects_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 */
class Projects extends Trongate {
 
    function create(): void {
        $this->_make_sure_allowed();
        $data = [
            'view_module' => 'projects',
            'view_file' => 'create'
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function show(): void {
        $id = segment(3, 'int');
        $this->_make_sure_allowed();
        
        $project = $this->model->find($id);
        
        if ($project === false) {
            redirect('customer');
        }

        $team_id = $this->model->get_current_team_id();
        $servers = $this->model->get_team_servers($team_id);

        $data = [
            'view_module' => 'projects',
            'view_file' => 'show',
            'project' => $project,
            'servers' => $servers
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function _make_sure_allowed(): void {
        $this->module('trongate_tokens');
        $token = $this->trongate_tokens->attempt_get_valid_token(2);
        
        if ($token === false) {
            redirect('customer/login');
        }
    }
}
