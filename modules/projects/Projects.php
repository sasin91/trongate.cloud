<?php
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
        
        $project = $this->model->get_where($id);
        
        if ($project === false) {
            redirect('customer');
        }

        $data = [
            'view_module' => 'projects',
            'view_file' => 'show',
            'project' => $project
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
