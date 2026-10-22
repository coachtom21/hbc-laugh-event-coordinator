<?php
defined('ABSPATH') || exit;

add_filter(
	'body_class',
	static function (array $classes): array {
		$classes[] = 'hbc-page';
		$classes[] = 'hbc-page--site';
		return $classes;
	}
);

add_filter(
	'document_title_parts',
	static function (array $parts): array {
		$parts['title'] = 'Human Gold RSVP';
		return $parts;
	}
);

get_header();
?>
<div class="hbc-page-wrap">
	<header class="hbc-hero">
		<div class="hbc-hero-inner">
			<span class="hbc-kicker">Human Gold</span>
			<h1>RSVP with the device you bring with you.</h1>
			<p id="hbc-status">Loading current Human Gold events…</p>
			<p class="hbc-trust">Choose Observe or Participate for a published event, or Walk Away with no record. Nuggets RSVP for free. Miner organizer access is $12 per year. Registering this device completes only this RSVP.</p>
			<p class="hbc-trust"><a href="https://www.megavoters.com/start/" target="_blank" rel="noopener noreferrer">Explore Practice FAITH</a> (optional — after you select an event and RSVP, you can return to the same organization and event).</p>
		</div>
	</header>
	<main class="hbc-shell" data-hbc-respondent data-canonical-entry="<?php echo esc_url( home_url( '/r' ) ); ?>">
		<section class="hbc-card" id="hbc-content" aria-live="polite">
			<noscript>JavaScript is required to complete device registration.</noscript>
		</section>
	</main>
</div>
<?php
get_footer();
