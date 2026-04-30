<?php
/**
 * Funzioni tema Area_Dealer
 *
 * Questo file gestisce il redirect degli utenti dopo il login.
 * Gli amministratori restano nel flusso standard.
 * Tutti gli altri utenti vengono mandati alla Dashboard Dealer.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect utenti non amministratori alla Dashboard Dealer dopo il login.
 */
add_filter('login_redirect', function ($redirect_to, $request, $user) {

    if (is_wp_error($user) || !isset($user->roles) || !is_array($user->roles)) {
        return $redirect_to;
    }

    if (!in_array('administrator', $user->roles, true)) {
        return site_url('/dashboard-dealer/');
    }

    return $redirect_to;

}, 10, 3);
