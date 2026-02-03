<?php
require_once APPPATH . 'modules/customer/traits/Current_customer.php';

class Servers_model extends Model {
    use Current_customer;

    function get_customer_team_id(): int|false {
        return $this->_get_current_team_id();
    }
}
