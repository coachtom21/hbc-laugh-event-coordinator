<?php
defined( 'ABSPATH' ) || exit;

add_filter(
	'body_class',
	static function ( array $classes ): array {
		$classes[] = 'hbc-page';
		$classes[] = 'hbc-page--site';
		$classes[] = 'hbc-page--trade';
		return $classes;
	}
);

add_filter(
	'document_title_parts',
	static function ( array $parts ): array {
		$parts['title'] = 'What if a $30 Trade happens?';
		return $parts;
	}
);

get_header();
$organize = home_url( '/organize' );
$rsvp     = home_url( '/r' );
?>
<div class="hbc-page-wrap hbc-trade-explainer">
	<header class="hbc-hero">
		<div class="hbc-hero-inner">
			<span class="hbc-kicker">A question about intention and follow-through</span>
			<h1>What if a $30 Trade happens?</h1>
			<p>A Host may indicate an anticipated $30 obligation to a recipient. Registering that intention creates a <strong>$0 backorder</strong> — a demand signal, not a charge or a payment. We can later ask: <strong>Did the Host follow through?</strong></p>
			<p><span class="hbc-trade-status-pill">Trade is inactive in the pilot</span></p>
		</div>
	</header>

	<main class="hbc-shell">
		<section class="hbc-card">
			<h2>Three simple moments</h2>
			<div class="hbc-trade-cards">
				<article>
					<span class="hbc-kicker">01 · What if</span>
					<strong>Host states an intention</strong>
					<p>The event Host identifies a proposed $30 Trade value and the intended recipient. This records a pledge to consider, not a debt collected through the RSVP.</p>
				</article>
				<article>
					<span class="hbc-kicker">02 · Demand signal</span>
					<strong>A $0 backorder is recorded</strong>
					<p>The backorder shows interest and a proposed obligation. Its checkout amount is zero. No $30 is taken, transferred, or added to Play Dough.</p>
				</article>
				<article>
					<span class="hbc-kicker">03 · Did</span>
					<strong>Follow-through is reconciled</strong>
					<p>If real funds are later paid, authorized records can confirm the amount, recipient, date, and status with the separate money records. If nothing is paid, the record remains an unfulfilled intention, never a payment.</p>
				</article>
			</div>
		</section>

		<section class="hbc-card">
			<h2>Two records with different jobs</h2>
			<div class="hbc-trade-split">
				<article>
					<strong>Play Dough and XP</strong>
					<p>Play Dough is community recognition for confirmed presence — like Monopoly money. It only accrues; it cannot be spent or cashed in. XP records the confirmed encounter in the append-only presence ledger.</p>
				</article>
				<article>
					<strong>Real-money reconciliation</strong>
					<p>A real $30 payment, if it happens, belongs in the appropriate payment and financial records. The $0 backorder alone is never treated as money received or paid.</p>
				</article>
			</div>
			<p class="hbc-note"><strong>Today:</strong> Trade is OFF. This page explains the proposed mechanism; it does not activate Trade, collect $30, or promise payment.</p>
		</section>

		<section class="hbc-card" id="trade-poll">
			<h2>If Trade were made active, what would you choose?</h2>
			<p>This is a hypothetical question about your interest, not a pledge or an RSVP.</p>
			<form id="hbc-trade-poll">
				<label class="hbc-choice"><input type="radio" name="choice" value="observe" required> Observe — learn more</label>
				<label class="hbc-choice"><input type="radio" name="choice" value="participate"> Participate — I would consider taking part</label>
				<label class="hbc-choice"><input type="radio" name="choice" value="walk-away"> Walk Away — not for me</label>
				<p class="hbc-actions">
					<button class="hbc-button" type="submit">Record my choice</button>
					<a class="hbc-button hbc-secondary" href="<?php echo esc_url( $organize ); ?>">Explore hosting an event</a>
					<a class="hbc-button hbc-secondary" href="<?php echo esc_url( $rsvp ); ?>">Open Human Gold RSVP</a>
				</p>
				<p id="hbc-trade-poll-result" role="status" aria-live="polite"></p>
				<p class="hbc-trust">Prototype preview: your answer is shown only on this device and is not sent, counted, or saved. No choice creates a $0 backorder, activates Trade, charges money, or adds XP.</p>
			</form>
		</section>
	</main>
</div>
<script>
(function () {
  var form = document.getElementById('hbc-trade-poll');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var chosen = new FormData(form).get('choice');
    var labels = { observe: 'Observe', participate: 'Participate', 'walk-away': 'Walk Away' };
    var out = document.getElementById('hbc-trade-poll-result');
    out.textContent = chosen
      ? 'You chose ' + (labels[chosen] || chosen) + '. This is a preview; no response was submitted.'
      : 'Choose one option.';
  });
})();
</script>
<?php
get_footer();
