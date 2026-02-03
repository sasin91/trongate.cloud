<?php
class Servers extends Trongate {
    
    function create(): void {
        $this->_make_sure_allowed();
        
        // Get team ID
        $team_id = $this->model->get_customer_team_id();
        
        if ($team_id === false) {
            // Handle error - user not in a team?
             redirect('customer');
        }

        // Get Hetzner API Key
        $this->module('secrets');
        $secret = $this->secrets->get('team', $team_id, 'hetzner');
        $token = $secret['token'] ?? null;

        if (empty($token)) {
             // Redirect to secrets/settings page or show error
             // For now, let's just die or redirect to dashboard
             die('No Hetzner API key found for this team.');
        }

        // Init Hetzner
        $this->module('services');
        $hetzner = $this->services->hetzner($token);
        $regions = $hetzner->list_regions();
        $server_types = $hetzner->list_server_types();

        $data = [
            'view_module' => 'servers',
            'view_file' => 'create',
            'regions' => $regions,
            'server_types' => $server_types
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function server_types_options(): void {
        $this->_make_sure_allowed();
        
        $location = $_GET['location'] ?? 'fsn1';
        
        // Get team ID
        $team_id = $this->model->get_customer_team_id();
        
        if ($team_id === false) {
            http_response_code(403);
            return;
        }

        // Get Hetzner API Key
        $this->module('secrets');
        $secret = $this->secrets->get('team', $team_id, 'hetzner');
        $token = $secret['token'] ?? null;

        if (empty($token)) {
            http_response_code(403);
            return;
        }

        // Get server types for this location
        $this->module('services');
        $hetzner = $this->services->hetzner($token);
        $server_types = $hetzner->list_server_types($location);

        // Output just the options using shared partial
        header('Content-Type: text/html');
        $this->view('_server_type_options', ['server_types' => $server_types]);
    }

    function _make_sure_allowed(): void {
        $this->module('trongate_tokens');
        $token = $this->trongate_tokens->attempt_get_valid_token(2);
        
        if ($token === false) {
            redirect('customer/login');
        }
    }
}
