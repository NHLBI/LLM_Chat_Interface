<?php
// session_init.php

// Set session cookie to expire when the browser closes
ini_set('session.cookie_lifetime', 0);

// Start the session if it hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

