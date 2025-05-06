<?php

/**
 * Laravel API - Redirect to the public directory entry point
 */

// Get the requested URI
$request_uri = $_SERVER['REQUEST_URI'];

// Forward all requests to the public/index.php entry point
require_once __DIR__ . '/public/index.php';
