<?php defined('ABSPATH') || exit; $encounter_token = sanitize_text_field((string) get_query_var('hbc_encounter_token')); ?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirm Attendance | Human Gold</title><?php wp_head(); ?></head>
<body class="hbc-page"><main class="hbc-shell" data-hbc-encounter data-encounter-token="<?php echo esc_attr($encounter_token); ?>">
<header><span class="hbc-kicker">Human Gold</span><h1>Confirm this encounter.</h1><p id="hbc-status">Loading…</p></header>
<section class="hbc-card" id="hbc-content" aria-live="polite"><noscript>JavaScript is required to confirm this encounter.</noscript></section>
</main><?php wp_footer(); ?></body></html>
