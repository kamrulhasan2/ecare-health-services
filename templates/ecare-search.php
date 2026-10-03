<?php
/**
 * Site search results (ECare_Search): the theme's header and footer around
 * E-Care's grouped results.
 */
defined('ABSPATH') || exit;

get_header();
echo ECare_Search::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
get_footer();
