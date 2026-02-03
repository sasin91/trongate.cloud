<?php

class Metrics extends Trongate {
    
    /**
     * GET /metrics
     * Prometheus metrics endpoint with strict access control
     */
    public function index() {
        // Restrict access to Prometheus only (Docker network)
        if (!$this->is_prometheus_request()) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
        
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        
        $metrics = $this->collect_metrics();
        echo $metrics;
        exit;
    }
    
    /**
     * Verify request is from Prometheus
     * Checks: source IP on Docker network, X-Prometheus-Scrape header, or localhost
     */
    private function is_prometheus_request(): bool {
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Allow from localhost/127.0.0.1 (useful for testing)
        if ($client_ip === '127.0.0.1' || $client_ip === '::1') {
            return true;
        }
        
        // Check if request has Prometheus scrape marker header
        if (!empty($_SERVER['HTTP_X_PROMETHEUS_SCRAPE'])) {
            return true;
        }
        
        // Allow from Docker network range (172.17.0.0/16 for default bridge, 172.18.0.0/16+ for custom)
        // Since tcloud_prometheus is on tcloud_net, it will be in 172.18.0.0/16 or similar
        if (preg_match('/^172\.(1[7-9]|2[0-9]|3[01])\./', $client_ip)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Collect all metrics in Prometheus format
     */
    private function collect_metrics(): string {
        $output = "# HELP process_request_duration_seconds Request processing duration\n";
        $output .= "# TYPE process_request_duration_seconds gauge\n";
        
        // Request duration
        $request_time = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $output .= "process_request_duration_seconds {$request_time}\n";
        
        $output .= "\n# HELP app_php_errors_total Total PHP errors encountered\n";
        $output .= "# TYPE app_php_errors_total counter\n";
        
        // Error count from this request
        $error_count = count($_SERVER['APP_ERRORS'] ?? []);
        $output .= "app_php_errors_total {$error_count}\n";
        
        $output .= "\n# HELP app_php_exceptions_total Total exceptions encountered\n";
        $output .= "# TYPE app_php_exceptions_total counter\n";
        
        // Exception count from this request
        $exception_count = count($_SERVER['APP_EXCEPTIONS'] ?? []);
        $output .= "app_php_exceptions_total {$exception_count}\n";
        
        return $output;
    }
}
