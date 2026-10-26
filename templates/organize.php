<?php
defined( 'ABSPATH' ) || exit;

// Organizer surface: full-page marketing + live calendar / volunteer tools.
$host_uuid = isset( $_GET['host'] ) ? sanitize_text_field( (string) $_GET['host'] ) : '';
if ( $host_uuid === '' && class_exists( 'HBC_Prod_Store' ) ) {
	$host_uuid = HBC_Prod_Store::host_uuid();
}
$agreement_version    = '2026.09-v2.4.8';
$agreement_terms_path = HBC_LAUGH_DIR . 'templates/host-agreement-terms.php';
$agreement_checksum   = is_readable( $agreement_terms_path ) ? hash_file( 'sha256', $agreement_terms_path ) : '';
$events_payload       = ( $host_uuid && class_exists( 'HBC_Prod_Store' ) ) ? HBC_Prod_Store::list_host_events( $host_uuid ) : array( 'events' => array(), 'host_name' => '' );
$volunteers_payload   = ( $host_uuid && class_exists( 'HBC_Prod_Store' ) ) ? HBC_Prod_Store::list_volunteers( $host_uuid ) : array( 'volunteers' => array() );
$host_name            = (string) ( $events_payload['host_name'] ?? '' );
$org_code             = ( $host_uuid && class_exists( 'HBC_Prod_Orgs' ) ) ? HBC_Prod_Orgs::public_code_for_host( $host_uuid ) : '';
$can_manage           = is_user_logged_in() && current_user_can( 'hbc_admin' ) && $host_uuid !== ''
	&& (bool) apply_filters( 'hbc_user_can_access_host', false, get_current_user_id(), $host_uuid );
$miner_checkout       = class_exists( 'HBC_Production_Adapters' )
	? HBC_Production_Adapters::miner_checkout_url()
	: add_query_arg( 'level', '2', home_url( '/membership-checkout/' ) );

$hbc_to_datetime_local = static function ( $iso ): string {
	$iso = trim( (string) $iso );
	if ( $iso === '' ) {
		return '';
	}
	try {
		$dt = new DateTimeImmutable( $iso );
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		return $dt->setTimezone( $tz )->format( 'Y-m-d\TH:i' );
	} catch ( Exception $e ) {
		return '';
	}
};

$hbc_format_when = static function ( $iso ): string {
	$iso = trim( (string) $iso );
	if ( $iso === '' ) {
		return '—';
	}
	$ts = strtotime( $iso );
	if ( ! $ts ) {
		return $iso;
	}
	return function_exists( 'wp_date' )
		? (string) wp_date( 'D, M j, Y · g:i A', $ts )
		: gmdate( 'Y-m-d H:i', $ts ) . ' UTC';
};

add_filter(
	'body_class',
	static function ( array $classes ): array {
		$classes[] = 'hbc-page';
		$classes[] = 'hbc-page--site';
		$classes[] = 'hbc-page--organize';
		return $classes;
	}
);

add_filter(
	'document_title_parts',
	static function ( array $parts ) use ( $host_name ): array {
		$parts['title'] = $host_name !== '' ? $host_name . ' · Organize' : 'Organize with Human Gold';
		return $parts;
	}
);

get_header();
?>
<div class="hbc-page-wrap hbc-organize-page">
	<header class="hbc-hero hbc-hero--organize">
		<div class="hbc-hero-inner hbc-organize-hero-grid">
			<div>
				<span class="hbc-kicker">For churches and community groups</span>
				<h1>One QR for every <span class="hbc-accent">event and RSVP.</span></h1>
				<p>Create and publish any church or community event, welcome Observe and Participate responses, recognize presence when people show up, and keep your postcard QR current.</p>
				<div class="hbc-actions">
					<a class="hbc-button" href="#hbc-organize-tools">Open organizer tools</a>
					<a class="hbc-button hbc-secondary" href="<?php echo esc_url( home_url( '/r' ) ); ?>">Open /r RSVP</a>
					<a class="hbc-button hbc-secondary" href="#hbc-organize-access">$12 Miner membership</a>
				</div>
				<p class="hbc-trust">Nuggets RSVP for free. Miner organizer access is $12 per year.</p>
			</div>
			<aside class="hbc-card hbc-organize-route">
				<span class="hbc-kicker">Universal Human Gold QR</span>
				<h2>One permanent route that stays current</h2>
				<ol class="hbc-organize-steps">
					<li><strong>Scan the Human Gold QR</strong><span>Opens this site’s /r RSVP entry.</span></li>
					<li><strong>Select an event</strong><span>Worship, meal, fundraiser, workshop, or gathering. Fundraiser is a label. It does not collect payment.</span></li>
					<li><strong>Observe or Participate</strong><span>Attend or send a volunteer request.</span></li>
					<li><strong>Show up and be recognized</strong><span>An authorized organizer confirms the encounter.</span></li>
				</ol>
			</aside>
		</div>
	</header>

	<main class="hbc-shell hbc-organize-shell">
		<section class="hbc-card" id="hbc-organize-access">
			<h2>Organizer access</h2>
			<p>Active $12 annual Miner membership includes organization-scoped Admin access, Human Gold licensing, event tools, volunteer review, and postcard print materials.</p>
			<div id="hbc-membership-return-status" data-login-url="<?php echo esc_url( wp_login_url( home_url( '/organize' ) ) ); ?>"><p>Checking your membership status&hellip;</p></div>
			<div class="hbc-actions">
				<a class="hbc-button" href="<?php echo esc_url( wp_login_url( home_url( '/organize' ) ) ); ?>">Register or sign in</a>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( $miner_checkout ); ?>">Start or confirm membership</a>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=hbc-human-gold' ) ); ?>">WP Human Gold</a>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( home_url( '/print-studio' ) ); ?>">Print Materials</a>
			</div>
			<?php if ( $host_name !== '' ) : ?>
				<p class="hbc-trust">Working organization: <strong><?php echo esc_html( $host_name ); ?></strong>
					<?php if ( $org_code !== '' ) : ?>
						· public code <code><?php echo esc_html( $org_code ); ?></code>
						· <a href="<?php echo esc_url( home_url( '/r?org=' . rawurlencode( $org_code ) ) ); ?>">/r?org=<?php echo esc_html( $org_code ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</section>

		<?php if ( $can_manage ) : ?>
		<?php
		$dash = class_exists( 'HBC_Prod_Store' ) ? HBC_Prod_Store::host_dashboard_snapshot( $host_uuid ) : array();
		$tot  = is_array( $dash['totals'] ?? null ) ? $dash['totals'] : array();
		$scan = (string) ( $dash['scan_url'] ?? home_url( '/r' ) );
		$doors = is_array( $dash['doors'] ?? null ) ? $dash['doors'] : array();
		?>
		<section class="hbc-card" id="hbc-organize-overview">
			<span class="hbc-kicker">Overview</span>
			<h2>Live organization workplace</h2>
			<div class="hbc-organize-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:16px 0">
				<div><strong><?php echo (int) ( $tot['published'] ?? 0 ); ?></strong><span style="display:block;opacity:.75">Published events</span></div>
				<div><strong><?php echo (int) ( $tot['rsvp_observe'] ?? 0 ); ?></strong><span style="display:block;opacity:.75">Observe</span></div>
				<div><strong><?php echo (int) ( $tot['rsvp_participate'] ?? 0 ); ?></strong><span style="display:block;opacity:.75">Participate</span></div>
				<div><strong><?php echo (int) ( $tot['encounters_done'] ?? 0 ); ?></strong><span style="display:block;opacity:.75">Encounters</span></div>
				<div><strong><?php echo (int) ( $tot['volunteers_open'] ?? 0 ); ?></strong><span style="display:block;opacity:.75">Open volunteers</span></div>
			</div>
			<p><strong>Permanent org QR target:</strong> <a href="<?php echo esc_url( $scan ); ?>"><code><?php echo esc_html( $scan ); ?></code></a></p>
			<div class="hbc-actions">
				<a class="hbc-button" href="<?php echo esc_url( home_url( '/print-studio' ) ); ?>">Postcard / Print Studio</a>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( $scan ); ?>">Open org /r</a>
				<?php if ( ! empty( $doors['trade']['url'] ) ) : ?>
					<a class="hbc-button hbc-secondary" href="<?php echo esc_url( $doors['trade']['url'] ); ?>">Trade door</a>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $doors ) ) : ?>
				<details style="margin-top:16px">
					<summary>Door QR destinations (self-hosted)</summary>
					<ul>
						<?php foreach ( $doors as $key => $door ) : ?>
							<li><strong><?php echo esc_html( (string) ( $door['label'] ?? $key ) ); ?></strong> — <a href="<?php echo esc_url( (string) ( $door['url'] ?? '#' ) ); ?>"><code><?php echo esc_html( (string) ( $door['url'] ?? '' ) ); ?></code></a></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<section class="hbc-card">
			<span class="hbc-kicker">Event-first by design</span>
			<h2>Use Human Gold for gatherings your community already holds</h2>
			<div class="hbc-organize-event-types">
				<article><strong>Worship &amp; services</strong><span>Sunday gatherings, prayer meetings, seasonal services.</span></article>
				<article><strong>Community activities</strong><span>Movie nights, meals, youth programs, neighborhood meetings.</span></article>
				<article><strong>Fundraising</strong><span>Sales, benefit events, markets, donation drives.</span></article>
				<article><strong>Service &amp; learning</strong><span>Volunteer projects, workshops, classes, outreach.</span></article>
			</div>
			<div class="hbc-organize-roles">
				<div><strong>Nuggets RSVP</strong><span>Free registered devices may Observe, Participate, and receive encounter recognition.</span></div>
				<div><strong>Miners organize</strong><span>$12 annual access includes events, volunteers, and print tools.</span></div>
			</div>
		</section>

		<section class="hbc-card" id="hbc-organize-tools">
			<span class="hbc-kicker">Upcoming calendar</span>
			<h2>The printed QR never becomes obsolete</h2>
			<p>Publish events here. The universal postcard QR still opens <code>/r</code> (or <code>/r?org=CODE</code> for your organization). Practice FAITH stays available as an optional explore path.</p>
			<?php if ( ! $can_manage ) : ?>
				<p class="hbc-trust">Sign in with Admin access for this organization to edit the live calendar and volunteer queue.</p>
			<?php else : ?>
				<p>Site timezone for date pickers: <strong><?php echo esc_html( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' ); ?></strong></p>
				<div data-hbc-organize-events data-host-uuid="<?php echo esc_attr( $host_uuid ); ?>">
					<ul class="hbc-event-edit-list">
						<?php foreach ( ( $events_payload['events'] ?? array() ) as $ev ) : ?>
							<?php
							$starts_local = $hbc_to_datetime_local( $ev['starts_at'] ?? '' );
							$ends_local   = $hbc_to_datetime_local( $ev['ends_at'] ?? '' );
							?>
							<li>
								<strong><?php echo esc_html( (string) ( $ev['name'] ?? '' ) ); ?></strong>
								— <?php echo esc_html( (string) ( $ev['status'] ?? '' ) ); ?>
								— <?php echo esc_html( $hbc_format_when( $ev['starts_at'] ?? '' ) ); ?>
								— <?php echo esc_html( (string) ( $ev['location'] ?? '—' ) ); ?>
								<br>
								<span class="hbc-trust">RSVP Observe <?php echo (int) ( $ev['rsvp_observe'] ?? 0 ); ?> · Participate <?php echo (int) ( $ev['rsvp_participate'] ?? 0 ); ?> · Walk Away <?php echo (int) ( $ev['rsvp_walk_away'] ?? 0 ); ?> · Encounters <?php echo (int) ( $ev['encounters_done'] ?? 0 ); ?></span>
								<details>
									<summary>Edit</summary>
									<form class="hbc-event-patch" data-event-uuid="<?php echo esc_attr( (string) ( $ev['event_uuid'] ?? '' ) ); ?>">
										<label>Name <input name="name" value="<?php echo esc_attr( (string) ( $ev['name'] ?? '' ) ); ?>" required></label>
										<label>Location <input name="location" value="<?php echo esc_attr( (string) ( $ev['location'] ?? '' ) ); ?>"></label>
										<label>Status
											<select name="status">
												<?php foreach ( array( 'published', 'draft', 'cancelled' ) as $st ) : ?>
													<option value="<?php echo esc_attr( $st ); ?>" <?php selected( (string) ( $ev['status'] ?? '' ), $st ); ?>><?php echo esc_html( $st ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label>Starts
											<input type="datetime-local" name="starts_at" value="<?php echo esc_attr( $starts_local ); ?>" step="60">
										</label>
										<label>Ends
											<input type="datetime-local" name="ends_at" value="<?php echo esc_attr( $ends_local ); ?>" step="60">
										</label>
										<label>Short code <input name="event_code" value="<?php echo esc_attr( (string) ( $ev['event_code'] ?? '' ) ); ?>" maxlength="16" pattern="[A-Za-z0-9-]{0,16}" placeholder="e.g. STONE"></label>
										<button class="hbc-button" type="submit">Save</button>
										<span class="hbc-patch-msg" aria-live="polite"></span>
									</form>
								</details>
							</li>
						<?php endforeach; ?>
						<?php if ( empty( $events_payload['events'] ) ) : ?>
							<li>No events yet. Create one in <a href="<?php echo esc_url( admin_url( 'admin.php?page=hbc-human-gold' ) ); ?>">WP Human Gold</a>.</li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>
		</section>

		<?php if ( $can_manage ) : ?>
		<section class="hbc-card">
			<h2>Volunteer requests</h2>
			<?php $vols = $volunteers_payload['volunteers'] ?? array(); ?>
			<?php if ( ! $vols ) : ?>
				<p>No volunteer requests yet. Participate RSVPs appear here.</p>
			<?php else : ?>
				<ul class="hbc-organize-vol-list">
					<?php foreach ( $vols as $v ) : ?>
						<li>
							<div>
								<strong><?php echo esc_html( (string) ( $v['event_name'] ?? '' ) ); ?></strong>
								— <?php echo esc_html( (string) ( $v['state'] ?? '' ) ); ?><br>
								<code><?php echo esc_html( (string) ( $v['identity_uuid'] ?? '' ) ); ?></code>
							</div>
							<?php if ( ( $v['state'] ?? '' ) === 'requested' ) : ?>
								<div class="hbc-actions">
									<button type="button" class="hbc-button hbc-vol-accept" data-uuid="<?php echo esc_attr( (string) $v['volunteer_request_uuid'] ); ?>">Accept</button>
									<button type="button" class="hbc-button hbc-secondary hbc-vol-decline" data-uuid="<?php echo esc_attr( (string) $v['volunteer_request_uuid'] ); ?>">Decline</button>
								</div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<section class="hbc-card" id="communications">
			<span class="hbc-kicker">Communications</span>
			<h2>Your community. Your control.</h2>
			<p>Keep control of sender identity, message content, providers, recipients, and budget. Human Gold supplies shared RSVP infrastructure without owning your member relationships.</p>
			<details class="hbc-organize-disclosure">
				<summary>See how communication control and funding work</summary>
				<div class="hbc-organize-control-grid">
					<div><strong>Your organization chooses the message.</strong><p>Admins decide audience, wording, timing, and reply-to.</p></div>
					<div><strong>Your organization chooses the provider.</strong><p>Connect email / SMS providers, or use a host-funded option when available.</p></div>
					<div><strong>Human Gold supplies safeguards.</strong><p>Templates, consent, opt-out, usage records, and delivery reporting.</p></div>
					<div><strong>Your relationships remain yours.</strong><p>Human Gold does not replace your church or membership system.</p></div>
				</div>
			</details>
		</section>

		<section class="hbc-card">
			<h2>Sponsorship handoff</h2>
			<p>Sponsorship and fundraising stay on the owner payment path. Organizers do not take platform money here.</p>
			<div class="hbc-actions">
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( $miner_checkout ); ?>">Open membership / sponsorship path</a>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( home_url( '/print-studio' ) ); ?>">Print Materials Studio</a>
			</div>
		</section>

		<section class="hbc-card hbc-organize-join" id="begin">
			<h2>Begin with your church or community</h2>
			<p>Become a Miner Host, register your organization in Human Gold, publish an event, and place the permanent Human Gold QR on RSVP postcards.</p>
			<div class="hbc-actions">
				<a class="hbc-button" href="<?php echo esc_url( $miner_checkout ); ?>">Become a Host — $12/year</a>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=hbc-human-gold' ) ); ?>">I already have Miner access</a>
				<a class="hbc-button hbc-secondary" href="<?php echo esc_url( home_url( '/r' ) ); ?>">Open public RSVP</a>
			</div>
			<p class="hbc-trust">Nugget RSVP and general volunteering remain free. Protected operating roles require active Miner access.</p>
		</section>

		<section class="hbc-card" data-hbc-agreement data-host-uuid="<?php echo esc_attr( $host_uuid ); ?>" data-agreement-key="host_communications_platform_terms" data-agreement-version="<?php echo esc_attr( $agreement_version ); ?>" data-document-checksum="<?php echo esc_attr( $agreement_checksum ); ?>" hidden>
			<h2>Host Communications and Platform Terms of Service Agreement</h2>
			<p>Review the complete signing copy below. Your signature applies only to the displayed version and checksum.</p>
			<details class="hbc-agreement-disclosure" open><summary>Complete agreement — <?php echo esc_html( $agreement_version ); ?></summary>
				<?php include $agreement_terms_path; ?>
			</details>
			<p id="hbc-agreement-status">Loading&hellip;</p>
			<div id="hbc-agreement-content"></div>
		</section>
	</main>
</div>
<?php if ( $can_manage ) : ?>
<script>
(function () {
  if (!window.HBC_LAUGH) return;
  const host = document.querySelector('[data-hbc-organize-events]')?.getAttribute('data-host-uuid') || '';
  document.querySelectorAll('.hbc-event-patch').forEach((form) => {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const eventUuid = form.getAttribute('data-event-uuid');
      const msg = form.querySelector('.hbc-patch-msg');
      const fd = new FormData(form);
      const body = Object.fromEntries(fd.entries());
      msg.textContent = 'Saving…';
      try {
        const res = await fetch(HBC_LAUGH.rest + 'admin/hosts/' + encodeURIComponent(host) + '/events/' + encodeURIComponent(eventUuid), {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': HBC_LAUGH.nonce },
          body: JSON.stringify(body)
        });
        const json = await res.json();
        if (!res.ok) throw new Error((json.error && json.error.message) || 'Update failed');
        msg.textContent = 'Saved.';
      } catch (e) {
        msg.textContent = e.message || 'Update failed';
      }
    });
  });
  async function review(uuid, decision) {
    const res = await fetch(HBC_LAUGH.rest + 'admin/hosts/' + encodeURIComponent(host) + '/volunteers/' + encodeURIComponent(uuid) + '/' + decision, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': HBC_LAUGH.nonce }
    });
    if (res.ok) location.reload();
    else alert('Could not update volunteer request.');
  }
  document.querySelectorAll('.hbc-vol-accept').forEach((b) => b.onclick = () => review(b.dataset.uuid, 'accept'));
  document.querySelectorAll('.hbc-vol-decline').forEach((b) => b.onclick = () => review(b.dataset.uuid, 'decline'));
})();
</script>
<?php endif; ?>
<?php
get_footer();
