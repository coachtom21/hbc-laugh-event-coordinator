<?php
defined( 'ABSPATH' ) || exit;
add_filter(
	'body_class',
	static function ( array $classes ): array {
		$classes[] = 'hbc-page';
		return $classes;
	}
);
get_header();
$community = class_exists( 'HBC_Prod_Store' ) ? HBC_Prod_Store::community_event_uuid() : '';
$trade     = class_exists( 'HBC_Prod_Store' ) ? HBC_Prod_Store::trade_status() : array();
$platform  = ! empty( $trade['platform_on'] );
$emergency = ! empty( $trade['emergency_locked'] );
?>
<div class="hbc-page-wrap">
	<header class="hbc-hero">
		<div class="hbc-hero-inner">
			<span class="hbc-kicker">Canonical Human Gold Trade QR</span>
			<h1>Trade is live on this platform.</h1>
			<p>Authorized Miner sellers may offer a $30 Trade encounter. Dual confirm (attendee + operator) is required. XP issues once per confirmation — never twice.</p>
		</div>
	</header>
	<main class="hbc-shell" data-hbc-trade-active data-event-uuid="<?php echo esc_attr( $community ); ?>">
		<section class="hbc-card">
			<span class="hbc-kicker">Staging proof gates</span>
			<h2>Seller gate · two-scan · no duplicate XP · emergency OFF</h2>
			<ul>
				<li><strong>Platform:</strong> <?php echo $platform ? 'ON' : 'OFF'; ?><?php echo $emergency ? ' (emergency lock active)' : ''; ?></li>
				<li><strong>Seller authorization:</strong> required per identity in Super Admin before Offer works.</li>
				<li><strong>Two-scan:</strong> attendee Y/Y/Y on <code>/e/{token}</code>, then operator Confirm with proximity.</li>
				<li><strong>XP:</strong> keyed to confirmation id — re-confirm returns issued, does not double-post.</li>
				<li><strong>Emergency OFF:</strong> Super Admin → forces Trade OFF for 24 hours; new offers denied.</li>
			</ul>
			<p class="hbc-trust"><a href="<?php echo esc_url( home_url( '/super-admin' ) ); ?>">Open Super Admin Trade controls</a></p>
		</section>
		<section class="hbc-card" id="hbc-trade-panel">
			<h2>Offer $30 Trade</h2>
			<p>Requires: platform Trade ON, your seller authorization, active Miner membership, and event operator assignment. The attendee scans your Encounter QR — you never scan theirs.</p>
			<div class="hbc-actions">
				<button type="button" class="hbc-button" id="hbc-trade-offer">Offer $30 Trade</button>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( home_url( '/r' ) ); ?>">Open Human Gold RSVP</a>
			</div>
			<div id="hbc-trade-result" aria-live="polite"></div>
			<?php if ( $community === '' ) : ?>
				<p class="hbc-trust">Community event UUID is not set yet — Trade offers cannot start until it is configured in Human Gold admin.</p>
			<?php endif; ?>
		</section>
	</main>
</div>
<?php
get_footer();
