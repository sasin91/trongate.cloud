<?php
class Bench extends Trongate {

    public function index(): void {
        echo 'Hello World';
    }

    // Session behaviour checks for the lazy-session patch
    public function set(): void {
        $_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
        echo 'n=' . $_SESSION['n'];
    }

    public function get(): void {
        echo 'n=' . ($_SESSION['n'] ?? 'none');
    }

    public function late(): void {
        echo str_repeat('x', 100000);
        $_SESSION['late'] = 'yes';
        echo 'done';
    }

    public function flash(): void {
        set_flashdata('saved');
        redirect('bench/show');
    }

    public function show(): void {
        echo 'flash=' . flashdata();
    }

    public function form(): void {
        echo form_open('bench/submit');
        echo form_close();
    }

    public function submit(): void {
        $this->validation->set_rules('x', 'X', 'max_length[5]');
        echo $this->validation->run() === true ? 'csrf ok' : 'csrf FAIL';
    }

}
