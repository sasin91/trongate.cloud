<?php

/**
 * @property-read Trongate_security $trongate_security
 **/
class Team extends Trongate {
	public function __construct($module_name = null) {
		parent::__construct($module_name);

		$this->module('trongate_security');
	}

	public function index(): void {
		$token = $this->trongate_security->make_sure_allowed('customer');
		$trongate_user = $this->


		$this->view('index');
	}
}