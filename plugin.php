<?php

/**
 * Plugin Name: HM ACM HTTPS
 */

namespace HM\ACM;

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/admin/namespace.php';

add_action( 'admin_menu', __NAMESPACE__ . '\\Admin\\bootstrap' );
