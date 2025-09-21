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
        // Normalize leading backslash and ensure namespace matches
        $class = ltrim($class, '\\');
        $ns    = $this->namespace;
        $nsLen = strlen($ns);
        if (strncmp($ns, $class, $nsLen) !== 0) {
            return;
        }

        // Get relative class name within our namespace
        $relative = substr($class, $nsLen); // e.g. "\\Notifications\\Engine"
        $relative = ltrim($relative, '\\'); // "Notifications\\Engine"

        if ($relative === '') {
            return; // nothing to load at the namespace root
        }

        // Build file path
        $path = $this->base_dir . str_replace('\\', '/', $relative) . '.php';

        // Load file if exists
        if (file_exists($path)) {
            require $path;
        }
    }
}
