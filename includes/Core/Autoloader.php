<?php
namespace DNE\Core;

/**
 * Plugin Autoloader
 */
class Autoloader {
    
    /**
     * Plugin namespace
     */
    private $namespace = 'DNE';
    
    /**
     * Base directory for classes
     */
    private $base_dir;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->base_dir = DNE_PLUGIN_DIR . 'includes/';
    }
    
    /**
     * Register autoloader
     */
    public function register() {
        spl_autoload_register([$this, 'autoload']);
    }
    
    /**
     * Autoload classes
     */
    public function autoload($class) {
        // Check if class uses our namespace
        $len = strlen($this->namespace);
        if (strncmp($this->namespace, $class, $len) !== 0) {
            return;
        }
        
        // Get relative class name
        $relative_class = substr($class, $len);
        
        // Replace namespace separator with directory separator
        $file = $this->base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        // Load file if exists
        if (file_exists($file)) {
            require $file;
        }
    }
}