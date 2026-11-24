<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the admin method that requires this file.

$s          = WPHS_Writer::settings();
$content    = WPHS_Content_API::settings();
$has_key    = WPHS_Cloud::has_token();
$linked     = WPHS_Content_API::is_linked();
$standalone = WPHS_Content_API::standalone_connector_active();
$console    = WPHS_Settings::console_url();
$is_admin   = current_user_can( 'manage_options' );
$presets    = WPHS_AI_Provider::presets();
$base_url   = admin_url( 'admin.php?page=statwrite-writer' );
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view switches.
$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
$msg  = isset( $_GET['wphs_msg'] ) ? sanitize_key( wp_unslash( $_GET['wphs_msg'] ) ) : '';
$edit = isset( $_GET['edit'] );
// phpcs:enable WordPress.Security.NonceVerification.Recommended

if ( ! in_array( $tab, array( 'console', 'manual' ), true ) ) {
	$tab = 'manual' === $s['mode'] ? 'manual' : 'console';
}

$manual_ready = WPHS_Writer::manual_ready();
$show_form    = $is_admin && ( ! $manual_ready || $edit );
$tones        = WPHS_Writer::tones();
$lengths      = WPHS_Writer::lengths();
$authors      = $is_admin ? get_users(
	array(
		'capability' => 'edit_posts',
		'number'     => 100,
	)
) : array();
$masked_key   = '' !== $s['api_key'] ? substr( $s['api_key'], 0, 4 ) . str_repeat( '•', 10 ) . substr( $s['api_key'], -4 ) : '';
$preset       = isset( $presets[ $s['provider'] ] ) ? $presets[ $s['provider'] ] : $presets['custom'];

if ( '' === $s['base_url'] ) {
	$s['base_url'] = $preset['base'];
}

if ( '' === $s['model'] ) {
	$s['model'] = $preset['model'];
}

$category_select = static function ( $name, $selected, $id ) {
	wp_dropdown_categories(
		array(
			'name'              => $name,
			'id'                => $id,
			'hide_empty'        => false,
			'selected'          => (int) $selected,
			'show_option_none'  => __( '— Default —', 'statwrite' ),
			'option_none_value' => 0,
			'hierarchical'      => true,
		)
	);
};
?>
<div class="wrap wphs wphs-writer" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<hr class="wp-header-end">
	<header class="wphs-hero">
		<div class="wphs-hero-brand">
			<span class="wphs-logo"><img src="<?php echo esc_url( WPHS_URL . 'assets/logo.png' ); ?>" alt="" width="38" height="38"></span>
			<div>
				<h1 class="wphs-title"><?php esc_html_e( 'AI Writer', 'statwrite' ); ?> <?php WPHS_Admin::byline(); ?></h1>
				<p class="wphs-sub"><?php esc_html_e( 'Write SEO-ready articles for this site and send them straight to your posts.', 'statwrite' ); ?></p>
			</div>
		</div>
	</header>

	<?php if ( 'saved' === $msg ) : ?>
		<div class="wphs-notice is-success"><?php esc_html_e( 'Settings saved.', 'statwrite' ); ?></div>
	<?php endif; ?>

	<nav class="wphs-choice" aria-label="<?php esc_attr_e( 'Writing method', 'statwrite' ); ?>">
		<a class="wphs-choice-card<?php echo 'console' === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'console', $base_url ) ); ?>">
			<span class="wphs-choice-top">
				<strong><?php WPHS_Admin::wphub_mark(); ?><?php esc_html_e( 'WP-Hub console', 'statwrite' ); ?></strong>
				<em class="wphs-pill is-blue"><?php esc_html_e( 'Recommended', 'statwrite' ); ?></em>
			</span>
			<span class="wphs-choice-desc"><?php esc_html_e( 'No API key needed. The console studies your existing posts, adds internal links and images from your media library, and fills in SEO fields.', 'statwrite' ); ?></span>
		</a>
		<a class="wphs-choice-card<?php echo 'manual' === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'manual', $base_url ) ); ?>">
			<span class="wphs-choice-top">
				<strong><?php esc_html_e( 'Your own AI provider', 'statwrite' ); ?></strong>
				<em class="wphs-pill"><?php esc_html_e( 'Advanced', 'statwrite' ); ?></em>
			</span>
			<span class="wphs-choice-desc"><?php esc_html_e( 'Use your own API key from OpenAI, Gemini, Claude, OpenRouter, AvalAI, GapGPT or any OpenAI-compatible service. You configure everything yourself.', 'statwrite' ); ?></span>
		</a>
	</nav>

	<div class="wphs-live-msg" role="status" aria-live="polite" hidden></div>

	<?php if ( 'console' === $tab ) : ?>

		<?php if ( $standalone ) : ?>
			<div class="wphs-notice is-error"><?php esc_html_e( 'The separate "WP-Hub Content Connector" plugin is active. Statwrite now includes it; deactivate it before connecting here.', 'statwrite' ); ?></div>
		<?php endif; ?>

		<?php if ( ! $has_key ) : ?>
			<section class="wphs-card">
				<div class="wphs-card-head">
					<h2><?php WPHS_Admin::wphub_mark(); ?><?php esc_html_e( 'Connect this site in three steps', 'statwrite' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( 'About two minutes', 'statwrite' ); ?></span>
				</div>
				<ol class="wphs-onboard">
					<li>
						<b>1</b>
						<div>
							<strong><?php esc_html_e( 'Create a free account and add this site', 'statwrite' ); ?></strong>
							<p><?php esc_html_e( 'Sign up in the WP-Hub console, add your domain and verify it.', 'statwrite' ); ?></p>
							<a class="wphs-btn is-secondary" href="<?php echo esc_url( $console ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the console', 'statwrite' ); ?> ↗</a>
						</div>
					</li>
					<li>
						<b>2</b>
						<div>
							<strong><?php esc_html_e( 'Copy the site key', 'statwrite' ); ?></strong>
							<p><?php esc_html_e( 'On your site page in the console, open "Connect WordPress" and copy the key that starts with wphs_.', 'statwrite' ); ?></p>
						</div>
					</li>
					<li>
						<b>3</b>
						<div>
							<strong><?php esc_html_e( 'Paste it here', 'statwrite' ); ?></strong>
							<?php if ( $is_admin ) : ?>
								<form class="wphs-inline-form" data-wphs-action="site_key">
									<input type="text" name="key" placeholder="wphs_..." dir="ltr" autocomplete="off" spellcheck="false" required>
									<button type="submit" class="wphs-btn is-primary"><?php esc_html_e( 'Connect', 'statwrite' ); ?></button>
								</form>
							<?php else : ?>
								<p><?php esc_html_e( 'Ask a site administrator to connect the console.', 'statwrite' ); ?></p>
							<?php endif; ?>
						</div>
					</li>
				</ol>
				<p class="wphs-hint"><?php esc_html_e( 'The same key also turns on speed, security and uptime reports on the Statwrite dashboard. You can remove it at any time in Settings.', 'statwrite' ); ?></p>
			</section>

		<?php elseif ( ! $linked ) : ?>
			<section class="wphs-card wphs-autolink"<?php echo ( $is_admin && ! $standalone ) ? ' data-wphs-autolink' : ''; ?>>
				<div class="wphs-card-head">
					<h2><?php WPHS_Admin::wphub_mark(); ?><?php esc_html_e( 'Connecting to the console', 'statwrite' ); ?></h2>
					<span class="wphs-pill is-green"><?php esc_html_e( 'Site key connected', 'statwrite' ); ?></span>
				</div>
				<?php if ( $is_admin ) : ?>
					<p data-wphs-autolink-text><?php esc_html_e( 'Finishing the connection so the console can write articles here. This takes a few seconds.', 'statwrite' ); ?></p>
					<div class="wphs-actions-row">
						<button type="button" class="wphs-btn is-primary" data-wphs-click="console_link" hidden<?php disabled( $standalone ); ?>><?php esc_html_e( 'Try again', 'statwrite' ); ?></button>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'A site administrator needs to open this page once to finish connecting.', 'statwrite' ); ?></p>
				<?php endif; ?>
			</section>

		<?php else : ?>
			<section class="wphs-quota" data-wphs-quota>
				<div class="wphs-quota-main">
					<span class="wphs-muted"><?php esc_html_e( 'Articles left this period', 'statwrite' ); ?></span>
					<strong data-q="articles">—</strong>
					<div class="wphs-quota-track"><i data-q="bar"></i></div>
				</div>
				<div class="wphs-quota-side">
					<span class="wphs-pill" data-q="beta" hidden><?php esc_html_e( 'Free public test period', 'statwrite' ); ?></span>
					<span><?php esc_html_e( 'Plan', 'statwrite' ); ?>: <b data-q="plan">—</b></span>
					<span><?php esc_html_e( 'Connection', 'statwrite' ); ?>: <b data-q="conn">—</b></span>
					<a href="<?php echo esc_url( $console . '/dashboard/content' ); ?>" target="_blank" rel="noopener" data-q="studio"><?php esc_html_e( 'Open Content Studio', 'statwrite' ); ?> ↗</a>
					<small class="wphs-muted"><?php esc_html_e( 'Competitor analysis, content health and topic ideas are available in the Content Studio on the console.', 'statwrite' ); ?></small>
				</div>
			</section>

			<div class="wphs-writer-grid">
				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><?php esc_html_e( 'New article', 'statwrite' ); ?></h2>
						<span class="wphs-muted"><?php WPHS_Admin::wphub_mark(); ?><?php esc_html_e( 'Written by the console', 'statwrite' ); ?></span>
					</div>
					<form class="wphs-form-grid" data-wphs-action="console_create">
						<label class="is-wide"><span><?php esc_html_e( 'Topic or title', 'statwrite' ); ?> *</span>
							<input type="text" name="title" maxlength="200" required placeholder="<?php esc_attr_e( 'e.g. How to choose a gaming laptop in 2026', 'statwrite' ); ?>"></label>
						<label><span><?php esc_html_e( 'Focus keyword', 'statwrite' ); ?></span>
							<input type="text" name="keyword" maxlength="120"></label>
						<label><span><?php esc_html_e( 'Category', 'statwrite' ); ?></span>
							<?php $category_select( 'category', $content['default_category'], 'wphs-c-cat' ); ?></label>
						<label><span><?php esc_html_e( 'Tone', 'statwrite' ); ?></span>
							<select name="tone">
								<?php foreach ( $tones as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></label>
						<label><span><?php esc_html_e( 'Length', 'statwrite' ); ?></span>
							<select name="length">
								<?php foreach ( $lengths as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, 'medium' ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></label>
						<details class="wphs-details is-wide">
							<summary><?php esc_html_e( 'More options', 'statwrite' ); ?></summary>
							<div class="wphs-form-grid">
								<label><span><?php esc_html_e( 'Audience', 'statwrite' ); ?></span>
									<input type="text" name="audience" maxlength="200"></label>
								<label><span><?php esc_html_e( 'Internal links', 'statwrite' ); ?></span>
									<input type="number" name="internal_links" min="0" max="10" value="4"></label>
								<label class="is-check"><input type="checkbox" name="include_faq" value="1" checked> <?php esc_html_e( 'Add an FAQ section with schema', 'statwrite' ); ?></label>
								<label class="is-check"><input type="checkbox" name="use_images" value="1" checked> <?php esc_html_e( 'Use images from the media library', 'statwrite' ); ?></label>
								<label class="is-wide"><span><?php esc_html_e( 'Extra instructions', 'statwrite' ); ?></span>
									<textarea name="instructions" rows="3" maxlength="1500"></textarea></label>
							</div>
						</details>
						<div class="is-wide wphs-form-actions">
							<button type="submit" class="wphs-btn is-primary"><?php esc_html_e( 'Write with the console', 'statwrite' ); ?></button>
						</div>
					</form>
				</section>

				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><?php esc_html_e( 'Your articles', 'statwrite' ); ?></h2>
						<button type="button" class="wphs-link-btn" data-wphs-click="console_articles"><?php esc_html_e( 'Refresh', 'statwrite' ); ?></button>
					</div>
					<div class="wphs-article-list" data-wphs-articles data-can-publish="<?php echo in_array( 'publish', WPHS_Content_API::allowed_statuses(), true ) && current_user_can( 'publish_posts' ) ? '1' : '0'; ?>">
						<p class="wphs-muted"><?php esc_html_e( 'Loading…', 'statwrite' ); ?></p>
					</div>
				</section>
			</div>

			<?php if ( $is_admin ) : ?>
				<details class="wphs-card wphs-details-card">
					<summary><h2><?php esc_html_e( 'Publishing permissions and connection', 'statwrite' ); ?></h2></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wphs_writer_save">
						<input type="hidden" name="section" value="console">
						<?php wp_nonce_field( 'wphs_writer_save' ); ?>
						<table class="wphs-field-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Direct publishing', 'statwrite' ); ?></th>
								<td>
									<label><input type="checkbox" name="allow_publish" value="1" <?php checked( $content['allow_publish'] ); ?>> <?php esc_html_e( 'Allow the console to publish and schedule posts', 'statwrite' ); ?></label>
									<p class="wphs-hint"><?php esc_html_e( 'When off, articles arrive only as drafts or pending review. Recommended: off.', 'statwrite' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wphs-c-author"><?php esc_html_e( 'Author', 'statwrite' ); ?></label></th>
								<td>
									<select id="wphs-c-author" name="author_id">
										<option value="0"><?php esc_html_e( 'First administrator', 'statwrite' ); ?></option>
										<?php foreach ( $authors as $user ) : ?>
											<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( (int) $content['author_id'], (int) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wphs-c-defcat"><?php esc_html_e( 'Default category', 'statwrite' ); ?></label></th>
								<td><?php $category_select( 'default_category', $content['default_category'], 'wphs-c-defcat' ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'SEO plugin', 'statwrite' ); ?></th>
								<td><?php echo esc_html( WPHS_Content_SEO::label() ); ?>
									<p class="wphs-hint"><?php esc_html_e( 'Yoast SEO, Rank Math and All in One SEO receive the SEO title, meta description and focus keyword automatically.', 'statwrite' ); ?></p></td>
							</tr>
						</table>
						<div class="wphs-actions-row">
							<button type="submit" class="wphs-btn is-primary"><?php esc_html_e( 'Save', 'statwrite' ); ?></button>
							<button type="button" class="wphs-btn is-danger" data-wphs-click="console_unlink"><?php esc_html_e( 'Disconnect', 'statwrite' ); ?></button>
						</div>
					</form>
					<?php if ( ! empty( $content['log'] ) ) : ?>
						<h3 class="wphs-subhead"><?php esc_html_e( 'Recent requests from the console', 'statwrite' ); ?></h3>
						<ul class="wphs-log">
							<?php foreach ( $content['log'] as $row ) : ?>
								<li class="<?php echo 'ok' === $row['result'] ? 'is-ok' : 'is-bad'; ?>">
									<span><?php echo esc_html( wp_date( 'Y/m/d H:i', (int) $row['time'] ) ); ?></span>
									<code dir="ltr"><?php echo esc_html( $row['route'] ); ?></code>
									<b><?php echo 'ok' === $row['result'] ? esc_html__( 'Accepted', 'statwrite' ) : esc_html__( 'Rejected', 'statwrite' ); ?></b>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		<?php endif; ?>

	<?php else : ?>

		<?php if ( $show_form ) : ?>
			<div class="wphs-notice is-info">
				<?php esc_html_e( 'You pay your provider directly and are responsible for its terms. Prefer not to manage keys?', 'statwrite' ); ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'console', $base_url ) ); ?>"><?php esc_html_e( 'Use the WP-Hub console instead', 'statwrite' ); ?></a>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wphs-manual-form" data-wphs-manual>
				<input type="hidden" name="action" value="wphs_writer_save">
				<input type="hidden" name="section" value="manual">
				<?php wp_nonce_field( 'wphs_writer_save' ); ?>

				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><span class="wphs-step-no">1</span> <?php esc_html_e( 'AI provider', 'statwrite' ); ?></h2>
						<span class="wphs-muted"><?php esc_html_e( 'Any OpenAI-compatible API', 'statwrite' ); ?></span>
					</div>
					<div class="wphs-form-grid">
						<label><span><?php esc_html_e( 'Provider', 'statwrite' ); ?></span>
							<select name="provider" data-wphs-provider>
								<?php if ( WPHS_AI_Provider::wp_ai_available() ) : ?>
									<option value="wordpress" <?php selected( $s['provider'], 'wordpress' ); ?>><?php echo esc_html( $presets['wordpress']['label'] ); ?></option>
								<?php endif; ?>
								<optgroup label="<?php esc_attr_e( 'International', 'statwrite' ); ?>">
									<?php foreach ( $presets as $id => $p ) : ?>
										<?php if ( 'intl' === $p['group'] ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['provider'], $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Iranian (no VPN needed)', 'statwrite' ); ?>">
									<?php foreach ( $presets as $id => $p ) : ?>
										<?php if ( 'local' === $p['group'] ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['provider'], $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Other', 'statwrite' ); ?>">
									<option value="ollama" <?php selected( $s['provider'], 'ollama' ); ?>><?php echo esc_html( $presets['ollama']['label'] ); ?></option>
									<option value="custom" <?php selected( $s['provider'], 'custom' ); ?>><?php echo esc_html( $presets['custom']['label'] ); ?></option>
								</optgroup>
							</select>
							<a class="wphs-field-link" data-wphs-signup href="<?php echo esc_url( $preset['signup'] ? $preset['signup'] : '#' ); ?>" target="_blank" rel="noopener"<?php echo $preset['signup'] ? '' : ' hidden'; ?>><span data-label-key><?php esc_html_e( 'Get an API key', 'statwrite' ); ?></span><span data-label-wp hidden><?php esc_html_e( 'Open Settings → Connectors', 'statwrite' ); ?></span> ↗</a>
						</label>
						<p class="wphs-hint is-wide" data-wphs-wp-only hidden><?php esc_html_e( 'WordPress sends the request with the AI provider and key you connected in Settings → Connectors. No key is stored by Statwrite.', 'statwrite' ); ?></p>
						<label data-wphs-http><span><?php esc_html_e( 'API base URL', 'statwrite' ); ?></span>
							<input type="url" name="base_url" value="<?php echo esc_attr( $s['base_url'] ); ?>" dir="ltr" placeholder="https://api.example.com/v1" required></label>
						<label data-wphs-http><span><?php esc_html_e( 'API key', 'statwrite' ); ?></span>
							<input type="password" name="api_key" value="" dir="ltr" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $masked_key ? $masked_key : 'sk-...' ); ?>">
							<?php if ( '' !== $masked_key ) : ?>
								<small class="wphs-hint"><?php esc_html_e( 'A key is saved. Leave empty to keep it.', 'statwrite' ); ?></small>
							<?php endif; ?>
						</label>
						<label data-wphs-http><span><?php esc_html_e( 'Model', 'statwrite' ); ?></span>
							<input type="text" name="model" value="<?php echo esc_attr( $s['model'] ); ?>" dir="ltr" list="wphs-models" required>
							<datalist id="wphs-models"></datalist>
						</label>
						<label><span><?php esc_html_e( 'Creativity (temperature)', 'statwrite' ); ?></span>
							<input type="number" name="temperature" value="<?php echo esc_attr( $s['temperature'] ); ?>" min="0" max="1.5" step="0.1"></label>
						<label><span><?php esc_html_e( 'Max output tokens', 'statwrite' ); ?></span>
							<input type="number" name="max_tokens" value="<?php echo esc_attr( $s['max_tokens'] ); ?>" min="1000" max="32000" step="500"></label>
						<div class="is-wide wphs-form-actions">
							<button type="button" class="wphs-btn is-secondary" data-wphs-click="ai_test"><?php esc_html_e( 'Test connection and load models', 'statwrite' ); ?></button>
							<span class="wphs-test-result" data-wphs-test></span>
						</div>
					</div>
				</section>

				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><span class="wphs-step-no">2</span> <?php esc_html_e( 'About your site', 'statwrite' ); ?></h2>
						<span class="wphs-muted"><?php esc_html_e( 'The more precise, the better the articles', 'statwrite' ); ?></span>
					</div>
					<div class="wphs-form-grid">
						<label><span><?php esc_html_e( 'Site name', 'statwrite' ); ?></span>
							<input type="text" name="site_name" value="<?php echo esc_attr( $s['site_name'] ); ?>" maxlength="120"></label>
						<label><span><?php esc_html_e( 'Domain', 'statwrite' ); ?></span>
							<input type="text" name="site_domain" value="<?php echo esc_attr( $s['site_domain'] ); ?>" dir="ltr" maxlength="120"></label>
						<label class="is-wide"><span><?php esc_html_e( 'Main topic or niche', 'statwrite' ); ?></span>
							<input type="text" name="site_topic" value="<?php echo esc_attr( $s['site_topic'] ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. Online store for laptops and gaming gear', 'statwrite' ); ?>"></label>
						<label class="is-wide"><span><?php esc_html_e( 'About the business or store', 'statwrite' ); ?></span>
							<textarea name="business" rows="3" maxlength="1500" placeholder="<?php esc_attr_e( 'Who you are, what makes you different, shipping, warranty, city…', 'statwrite' ); ?>"><?php echo esc_textarea( $s['business'] ); ?></textarea></label>
						<label class="is-wide"><span><?php esc_html_e( 'Main products or services', 'statwrite' ); ?></span>
							<textarea name="products" rows="2" maxlength="1500"><?php echo esc_textarea( $s['products'] ); ?></textarea></label>
						<label><span><?php esc_html_e( 'Target audience', 'statwrite' ); ?></span>
							<input type="text" name="audience" value="<?php echo esc_attr( $s['audience'] ); ?>" maxlength="300"></label>
						<label><span><?php esc_html_e( 'Region or country', 'statwrite' ); ?></span>
							<input type="text" name="region" value="<?php echo esc_attr( $s['region'] ); ?>" maxlength="120"></label>
						<label><span><?php esc_html_e( 'Brand voice', 'statwrite' ); ?></span>
							<select name="tone">
								<?php foreach ( $tones as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['tone'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></label>
						<label><span><?php esc_html_e( 'Article language', 'statwrite' ); ?></span>
							<input type="text" name="language" value="<?php echo esc_attr( $s['language'] ); ?>" maxlength="40"></label>
					</div>
				</section>

				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><span class="wphs-step-no">3</span> <?php esc_html_e( 'Writing rules', 'statwrite' ); ?></h2>
						<span class="wphs-muted"><?php esc_html_e( 'Applied to every article', 'statwrite' ); ?></span>
					</div>
					<div class="wphs-form-grid">
						<label class="is-wide"><span><?php esc_html_e( 'System prompt', 'statwrite' ); ?>
							<button type="button" class="wphs-link-btn" data-wphs-click="reset_prompt"><?php esc_html_e( 'Reset to default', 'statwrite' ); ?></button></span>
							<textarea name="system_prompt" rows="7" maxlength="4000" dir="ltr"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea>
							<small class="wphs-hint"><?php esc_html_e( 'Your site details above are added to this prompt automatically.', 'statwrite' ); ?></small></label>
						<label class="is-wide"><span><?php esc_html_e( 'House rules', 'statwrite' ); ?></span>
							<textarea name="rules" rows="3" maxlength="2000" placeholder="<?php esc_attr_e( 'e.g. Never mention competitors by name. Always end with a call to action to our shop.', 'statwrite' ); ?>"><?php echo esc_textarea( $s['rules'] ); ?></textarea></label>
						<label><span><?php esc_html_e( 'Default length', 'statwrite' ); ?></span>
							<select name="length">
								<?php foreach ( $lengths as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['length'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></label>
						<label><span><?php esc_html_e( 'Internal links per article', 'statwrite' ); ?></span>
							<input type="number" name="internal_links" value="<?php echo esc_attr( $s['internal_links'] ); ?>" min="0" max="10"></label>
						<label class="is-check is-wide"><input type="checkbox" name="include_faq" value="1" <?php checked( $s['include_faq'] ); ?>> <?php esc_html_e( 'Add an FAQ section with schema by default', 'statwrite' ); ?></label>
					</div>
				</section>

				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><span class="wphs-step-no">4</span> <?php esc_html_e( 'Saving articles', 'statwrite' ); ?></h2>
						<span class="wphs-muted"><?php esc_html_e( 'Nothing is published without you', 'statwrite' ); ?></span>
					</div>
					<div class="wphs-form-grid">
						<label><span><?php esc_html_e( 'Save as', 'statwrite' ); ?></span>
							<select name="default_status">
								<option value="draft" <?php selected( $s['default_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'statwrite' ); ?></option>
								<option value="pending" <?php selected( $s['default_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'statwrite' ); ?></option>
							</select></label>
						<label><span><?php esc_html_e( 'Default category', 'statwrite' ); ?></span>
							<?php $category_select( 'default_category', $s['default_category'], 'wphs-m-cat' ); ?></label>
						<label><span><?php esc_html_e( 'Author', 'statwrite' ); ?></span>
							<select name="author_id">
								<option value="0"><?php esc_html_e( 'The user who writes it', 'statwrite' ); ?></option>
								<?php foreach ( $authors as $user ) : ?>
									<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( (int) $s['author_id'], (int) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option>
								<?php endforeach; ?>
							</select></label>
					</div>
				</section>

				<div class="wphs-sticky-save">
					<button type="submit" class="wphs-btn is-primary"><?php esc_html_e( 'Save settings', 'statwrite' ); ?></button>
					<?php if ( $manual_ready ) : ?>
						<a class="wphs-btn is-secondary" href="<?php echo esc_url( add_query_arg( 'tab', 'manual', $base_url ) ); ?>"><?php esc_html_e( 'Cancel', 'statwrite' ); ?></a>
					<?php endif; ?>
				</div>
			</form>

		<?php elseif ( ! $manual_ready ) : ?>
			<section class="wphs-card">
				<p><?php esc_html_e( 'An administrator needs to set up the AI provider first.', 'statwrite' ); ?></p>
			</section>

		<?php else : ?>
			<section class="wphs-provider-bar">
				<span><?php esc_html_e( 'Provider', 'statwrite' ); ?>: <b><?php echo esc_html( $preset['label'] ); ?></b>
					<?php if ( 'wordpress' !== $s['provider'] ) : ?>
						· <code dir="ltr"><?php echo esc_html( $s['model'] ); ?></code>
					<?php endif; ?>
					<?php if ( ! $s['tested_at'] ) : ?>
						<em class="wphs-pill is-amber"><?php esc_html_e( 'Not tested', 'statwrite' ); ?></em>
					<?php endif; ?>
				</span>
				<?php if ( $is_admin ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'manual', 'edit' => 1 ), $base_url ) ); ?>"><?php esc_html_e( 'Edit settings', 'statwrite' ); ?></a>
				<?php endif; ?>
			</section>

			<div class="wphs-writer-grid">
				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><?php esc_html_e( 'New article', 'statwrite' ); ?></h2>
						<span class="wphs-muted"><?php esc_html_e( 'Saved as a draft for you to review', 'statwrite' ); ?></span>
					</div>
					<form class="wphs-form-grid" data-wphs-action="ai_generate">
						<label class="is-wide"><span><?php esc_html_e( 'Topic or title', 'statwrite' ); ?> *</span>
							<input type="text" name="title" maxlength="200" required></label>
						<label><span><?php esc_html_e( 'Focus keyword', 'statwrite' ); ?></span>
							<input type="text" name="keyword" maxlength="120"></label>
						<label><span><?php esc_html_e( 'Category', 'statwrite' ); ?></span>
							<?php $category_select( 'category', $s['default_category'], 'wphs-g-cat' ); ?></label>
						<label><span><?php esc_html_e( 'Tone', 'statwrite' ); ?></span>
							<select name="tone">
								<?php foreach ( $tones as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['tone'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></label>
						<label><span><?php esc_html_e( 'Length', 'statwrite' ); ?></span>
							<select name="length">
								<?php foreach ( $lengths as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['length'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></label>
						<details class="wphs-details is-wide">
							<summary><?php esc_html_e( 'More options', 'statwrite' ); ?></summary>
							<div class="wphs-form-grid">
								<label><span><?php esc_html_e( 'Audience', 'statwrite' ); ?></span>
									<input type="text" name="audience" maxlength="200" value="<?php echo esc_attr( $s['audience'] ); ?>"></label>
								<label><span><?php esc_html_e( 'Internal links', 'statwrite' ); ?></span>
									<input type="number" name="internal_links" min="0" max="10" value="<?php echo esc_attr( $s['internal_links'] ); ?>"></label>
								<label><span><?php esc_html_e( 'Save as', 'statwrite' ); ?></span>
									<select name="status">
										<option value="draft" <?php selected( $s['default_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'statwrite' ); ?></option>
										<option value="pending" <?php selected( $s['default_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'statwrite' ); ?></option>
									</select></label>
								<label class="is-check"><input type="checkbox" name="include_faq" value="1" <?php checked( $s['include_faq'] ); ?>> <?php esc_html_e( 'Add an FAQ section with schema', 'statwrite' ); ?></label>
								<label class="is-wide"><span><?php esc_html_e( 'Extra instructions', 'statwrite' ); ?></span>
									<textarea name="instructions" rows="3" maxlength="1500"></textarea></label>
							</div>
						</details>
						<div class="is-wide wphs-form-actions">
							<button type="submit" class="wphs-btn is-primary"><?php esc_html_e( 'Write article', 'statwrite' ); ?></button>
						</div>
					</form>
				</section>

				<section class="wphs-card">
					<div class="wphs-card-head">
						<h2><?php esc_html_e( 'Recent AI articles', 'statwrite' ); ?></h2>
					</div>
					<div class="wphs-article-list" data-wphs-manual-list>
						<?php $recent = WPHS_Writer::recent_manual_posts(); ?>
						<?php if ( ! $recent ) : ?>
							<p class="wphs-muted" data-wphs-empty><?php esc_html_e( 'No articles yet. Write your first one above.', 'statwrite' ); ?></p>
						<?php endif; ?>
						<?php foreach ( $recent as $row ) : ?>
							<div class="wphs-article">
								<div class="wphs-article-main">
									<strong><?php echo esc_html( $row['title'] ); ?></strong>
									<span class="wphs-muted"><?php echo esc_html( $row['status'] . ' · ' . $row['date'] ); ?></span>
								</div>
								<div class="wphs-article-actions">
									<a href="<?php echo esc_url( $row['preview'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'statwrite' ); ?></a>
									<a href="<?php echo esc_url( $row['edit'] ); ?>"><?php esc_html_e( 'Edit', 'statwrite' ); ?></a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="wphs-modal" data-wphs-modal hidden>
		<div class="wphs-modal-box" role="dialog" aria-modal="true">
			<div class="wphs-modal-head">
				<strong data-modal-title></strong>
				<button type="button" class="wphs-link-btn" data-wphs-click="modal_close"><?php esc_html_e( 'Close', 'statwrite' ); ?></button>
			</div>
			<div class="wphs-modal-meta" data-modal-meta></div>
			<div class="wphs-modal-body" data-modal-body></div>
			<div class="wphs-modal-foot" data-modal-foot></div>
		</div>
	</div>
</div>
