<?php
/**
 * Settings → DeploySeal: paste key, save, confirm.
 *
 * Essentials (Site key, Enabled) → "What this site will send" → Advanced (folded) → Platform
 * inventory (folded). A folded section opens itself when something inside it is not the default.
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * The settings page, its registration and the "Send inventory now" action.
 */
final class Settings_Page {

	/** Menu slug. */
	const SLUG = 'deployseal';

	/** The admin-post.php action of "Send inventory now". */
	const SEND_ACTION = 'deployseal_send_inventory';

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( __CLASS__, 'handle_send' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEPLOYSEAL_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings API registration: one option, one sanitize callback.
	 */
	public static function register_setting() {
		register_setting(
			Options::GROUP,
			Options::NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'default'           => Options::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Settings → DeploySeal.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'DeploySeal', 'deployseal' ),
			__( 'DeploySeal', 'deployseal' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * "Settings" beside Deactivate on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'deployseal' ) . '</a>' );
		return $links;
	}

	/**
	 * The page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * "Send inventory now": nonce, capability, send, back to the page with the result.
	 */
	public static function handle_send() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'deployseal' ), 403 );
		}
		check_admin_referer( self::SEND_ACTION );
		Inventory::send( Options::get() );
		wp_safe_redirect( self::url( array( 'deployseal-inventory' => 'sent' ) ) . '#deployseal-platform-inventory' );
		exit;
	}

	/**
	 * Field name inside the option array.
	 *
	 * @param string $key Option key.
	 * @return string
	 */
	private static function name( $key ) {
		return Options::NAME . '[' . $key . ']';
	}

	/**
	 * The page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options     = Options::get();
		$defaults    = Options::defaults();
		$marker      = Build_Marker::resolve( $options );
		$label       = Options::effective_environment_label( $options );
		$derived     = Options::derived_environment_label();
		$origins     = Origins::for_site();
		$snippet     = Snippet::build( $options['site_key'], $label, $marker['marker'] );
		$key_warning = Options::site_key_warning( $options['site_key'] );
		$api_key_set = '' !== (string) $options['api_key'];
		$can_send    = Inventory::can_send( $options );
		$snapshot    = Inventory::snapshot( $options );
		$items       = $snapshot['body']['items'];
		$enabled_n   = count( array_filter( wp_list_pluck( $items, 'enabled' ) ) );
		$last        = get_option( Inventory::LAST_RESULT_OPTION );
		$theme       = wp_get_theme();

		$advanced_open  = '' !== $options['environment_label']
			|| $defaults['build_source'] !== $options['build_source']
			|| '' !== $options['git_sha_file']
			|| '' !== $options['manual_build']
			|| ! empty( $options['load_in_admin'] )
			|| Contract::DEFAULT_API_BASE !== Options::api_base( $options );
		$inventory_open = $api_key_set || ! empty( $options['send_inventory'] ) || isset( $_GET['deployseal-inventory'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		?>
		<div class="wrap deployseal-settings">
			<style>
				.deployseal-settings details.deployseal-fold { margin-top: 24px; border-top: 1px solid #c3c4c7; }
				.deployseal-settings details.deployseal-fold > summary { cursor: pointer; padding: 12px 0 0; }
				.deployseal-settings details.deployseal-fold > summary h2 { display: inline; }
				.deployseal-settings .deployseal-facts code { font-size: 13px; }
				.deployseal-settings pre.deployseal-snippet { white-space: pre-wrap; word-break: break-all; background: #f6f7f7; border: 1px solid #dcdcde; padding: 8px 10px; margin: 0; max-width: 60em; }
				.deployseal-settings .deployseal-warn { color: #996800; }
				.deployseal-settings .deployseal-bad { color: #b32d2e; }
				.deployseal-settings .deployseal-good { color: #007017; }
			</style>

			<h1><?php esc_html_e( 'DeploySeal', 'deployseal' ); ?></h1>

			<?php
			if ( isset( $_GET['deployseal-inventory'] ) && is_array( $last ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
				?>
				<div class="notice <?php echo ! empty( $last['ok'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible" id="deployseal-inventory-result">
					<p><?php echo esc_html( $last['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="deployseal-instructions">
				<p>
					<?php esc_html_e( 'DeploySeal loads a small tester widget on your site so testers can pin issues on the page and your readiness report can prove which environment and build were tested. Only two settings below are required — Site key and Enabled — everything else already has a sensible default.', 'deployseal' ); ?>
				</p>
				<ol>
					<li><?php esc_html_e( 'In DeploySeal, create an environment for this site and register the origin(s) shown under "What this site will send".', 'deployseal' ); ?></li>
					<li><?php esc_html_e( 'Paste the environment\'s public key into Site key.', 'deployseal' ); ?></li>
					<li><?php esc_html_e( 'Tick Enabled and Save.', 'deployseal' ); ?></li>
					<li><?php esc_html_e( 'Confirm: open the site, then check the environment shows as Live in DeploySeal. Remove any DeploySeal snippet you pasted into the theme or a header plugin, or DeploySeal will warn that two install paths are live.', 'deployseal' ); ?></li>
				</ol>
			</div>

			<form method="post" action="options.php" id="deployseal-settings-form">
				<?php settings_fields( Options::GROUP ); ?>

				<h2><?php esc_html_e( 'Essentials', 'deployseal' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="deployseal-site-key"><?php esc_html_e( 'Site key', 'deployseal' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="deployseal-site-key" name="<?php echo esc_attr( self::name( 'site_key' ) ); ?>" value="<?php echo esc_attr( $options['site_key'] ); ?>" placeholder="ls_…" autocomplete="off" spellcheck="false" />
							<p class="description"><?php esc_html_e( 'The environment\'s public key, starts with "ls_" (legacy keys start with "ds_"). Not a secret: it only works from the environment\'s registered origins. One key per environment: a staging copy of this site gets its own.', 'deployseal' ); ?></p>
							<?php if ( '' !== $key_warning ) : ?>
								<p class="deployseal-warn" id="deployseal-site-key-warning"><?php echo esc_html( $key_warning ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'deployseal' ); ?></th>
						<td>
							<label for="deployseal-enabled">
								<input type="checkbox" id="deployseal-enabled" name="<?php echo esc_attr( self::name( 'enabled' ) ); ?>" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?> />
								<?php esc_html_e( 'Render the DeploySeal widget tag on the site. Nothing is emitted while this is off.', 'deployseal' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'deployseal' ), 'primary', 'save', true, array( 'id' => 'deployseal-save' ) ); ?>

				<h2><?php esc_html_e( 'What this site will send', 'deployseal' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Compare these values with the environment in DeploySeal. Origins must match exactly; the build marker must be copied verbatim into the campaign\'s release identifier.', 'deployseal' ); ?></p>
				<table class="form-table deployseal-facts" role="presentation" id="deployseal-facts">
					<tr>
						<th scope="row"><?php esc_html_e( 'Origins to register', 'deployseal' ); ?></th>
						<td>
							<?php if ( empty( $origins ) ) : ?>
								<span class="deployseal-bad"><?php esc_html_e( 'The Site Address is not an absolute http(s) URL, so no origin could be derived. Fix it under Settings → General.', 'deployseal' ); ?></span>
							<?php else : ?>
								<?php foreach ( $origins as $origin ) : ?>
									<div><code class="deployseal-origin"><?php echo esc_html( $origin ); ?></code></div>
								<?php endforeach; ?>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Taken from the Site Address and the WordPress Address under Settings → General. If visitors reach the site on another scheme, host or port, register that too.', 'deployseal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Declared environment label', 'deployseal' ); ?></th>
						<td>
							<code id="deployseal-environment"><?php echo esc_html( $label ); ?></code>
							<p class="description"><?php esc_html_e( 'Guessed from the Site Address', 'deployseal' ); ?>: <code><?php echo esc_html( $derived ); ?></code> — <?php esc_html_e( 'change it under Advanced.', 'deployseal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Build marker emitted right now', 'deployseal' ); ?></th>
						<td>
							<?php if ( '' === $marker['marker'] ) : ?>
								<span class="deployseal-bad" id="deployseal-build"><?php esc_html_e( '(none — the data-ds-build attribute will be omitted)', 'deployseal' ); ?></span>
							<?php else : ?>
								<code id="deployseal-build" style="font-size:1.1em"><?php echo esc_html( $marker['marker'] ); ?></code>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Source', 'deployseal' ); ?>: <span id="deployseal-build-source"><?php echo esc_html( $marker['description'] ); ?></span></p>
							<?php if ( '' !== $marker['sha_path'] ) : ?>
								<p class="description">
									<?php esc_html_e( 'SHA file', 'deployseal' ); ?>: <code><?php echo esc_html( $marker['sha_path'] ); ?></code> —
									<?php if ( $marker['sha_found'] ) : ?>
										<span class="deployseal-good"><?php esc_html_e( 'found and readable', 'deployseal' ); ?></span>
									<?php else : ?>
										<span class="deployseal-bad"><?php esc_html_e( 'missing or not a valid SHA', 'deployseal' ); ?></span>
									<?php endif; ?>
								</p>
							<?php endif; ?>
							<?php if ( '' !== $marker['warning'] ) : ?>
								<p class="deployseal-warn"><?php esc_html_e( 'No SHA — see Advanced.', 'deployseal' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Versions', 'deployseal' ); ?></th>
						<td>
							WordPress <code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code>
							&middot; <?php esc_html_e( 'theme', 'deployseal' ); ?> <code><?php echo esc_html( $theme->get_stylesheet() . ' ' . $theme->get( 'Version' ) ); ?></code>
							<?php if ( defined( 'WC_VERSION' ) ) : ?>
								&middot; WooCommerce <code><?php echo esc_html( (string) constant( 'WC_VERSION' ) ); ?></code>
							<?php endif; ?>
							&middot; <?php esc_html_e( 'plugin', 'deployseal' ); ?> <code><?php echo esc_html( DEPLOYSEAL_VERSION ); ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tag that will be rendered', 'deployseal' ); ?></th>
						<td>
							<?php if ( '' === $snippet ) : ?>
								<span class="description" id="deployseal-snippet"><?php esc_html_e( 'Nothing will be rendered until a site key is entered.', 'deployseal' ); ?></span>
							<?php else : ?>
								<pre class="deployseal-snippet"><code id="deployseal-snippet"><?php echo esc_html( $snippet ); ?></code></pre>
								<?php if ( empty( $options['enabled'] ) ) : ?>
									<p class="deployseal-warn"><?php esc_html_e( 'The plugin is not enabled, so this tag is not rendered yet.', 'deployseal' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'This must be the only DeploySeal tag on the page: remove any snippet pasted into the theme or a header plugin, or DeploySeal will warn that two install paths are live.', 'deployseal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"></th>
						<td>
							<a href="<?php echo esc_url( Contract::APP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open DeploySeal to see this environment\'s Live / Stale / Not seen state', 'deployseal' ); ?></a>
							&middot;
							<a href="<?php echo esc_url( Contract::DOCS_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Plugin documentation', 'deployseal' ); ?></a>
						</td>
					</tr>
				</table>

				<details class="deployseal-fold" id="deployseal-advanced" <?php echo $advanced_open ? 'open' : ''; ?>>
					<summary><h2><?php esc_html_e( 'Advanced', 'deployseal' ); ?></h2></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="deployseal-environment-label"><?php esc_html_e( 'Environment label', 'deployseal' ); ?></label></th>
							<td>
								<input type="text" class="regular-text code" id="deployseal-environment-label" name="<?php echo esc_attr( self::name( 'environment_label' ) ); ?>" value="<?php echo esc_attr( $options['environment_label'] ); ?>" placeholder="<?php echo esc_attr( $derived ); ?>" maxlength="64" />
								<p class="description"><?php esc_html_e( 'Usually fine as guessed from the Site Address (leave empty to use the guess). Change it only if this site should declare a different label: lower-case letters, digits and hyphens, up to 32 characters. It is a declaration, not an identifier — the site key decides where evidence lands.', 'deployseal' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="deployseal-build-source-select"><?php esc_html_e( 'Build marker source', 'deployseal' ); ?></label></th>
							<td>
								<select id="deployseal-build-source-select" name="<?php echo esc_attr( self::name( 'build_source' ) ); ?>">
									<?php foreach ( Build_Marker::sources() as $value => $text ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['build_source'], $value ); ?>><?php echo esc_html( $text ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Where the "which code is running" marker comes from. Prefer a git SHA when your deploy can write one, or define DEPLOYSEAL_BUILD in wp-config.php from your pipeline.', 'deployseal' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="deployseal-git-sha-file"><?php esc_html_e( 'Git SHA file path', 'deployseal' ); ?></label></th>
							<td>
								<input type="text" class="regular-text code" id="deployseal-git-sha-file" name="<?php echo esc_attr( self::name( 'git_sha_file' ) ); ?>" value="<?php echo esc_attr( $options['git_sha_file'] ); ?>" placeholder="wp-content/build-sha.txt" />
								<p class="description"><?php esc_html_e( 'A file that holds the deployed commit SHA (7 to 40 hex characters on its first line), written by your deploy pipeline. Relative paths are resolved from the WordPress root; "wp-content/build-sha.txt" is a good place.', 'deployseal' ); ?></p>
								<?php if ( '' !== $marker['warning'] ) : ?>
									<div class="notice notice-warning inline"><p id="deployseal-build-warning"><?php echo esc_html( $marker['warning'] ); ?></p></div>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="deployseal-manual-build"><?php esc_html_e( 'Manual build marker', 'deployseal' ); ?></label></th>
							<td>
								<input type="text" class="regular-text code" id="deployseal-manual-build" name="<?php echo esc_attr( self::name( 'manual_build' ) ); ?>" value="<?php echo esc_attr( $options['manual_build'] ); ?>" maxlength="64" />
								<p class="description"><?php esc_html_e( 'Used when the source is "Manual". One token, no spaces, up to 64 characters. Change it on every deployment.', 'deployseal' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Also load in the admin area', 'deployseal' ); ?></th>
							<td>
								<label for="deployseal-load-in-admin">
									<input type="checkbox" id="deployseal-load-in-admin" name="<?php echo esc_attr( self::name( 'load_in_admin' ) ); ?>" value="1" <?php checked( ! empty( $options['load_in_admin'] ) ); ?> />
									<?php esc_html_e( 'Off by default. Turn on only if testers need to pin issues inside the administration area too.', 'deployseal' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'The login page never loads the widget.', 'deployseal' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="deployseal-api-base"><?php esc_html_e( 'API base', 'deployseal' ); ?></label></th>
							<td>
								<input type="url" class="regular-text code" id="deployseal-api-base" name="<?php echo esc_attr( self::name( 'api_base' ) ); ?>" value="<?php echo esc_attr( Options::api_base( $options ) ); ?>" />
								<p class="description"><?php esc_html_e( 'The DeploySeal API host the platform inventory is posted to. Leave at https://api.deployseal.com unless you self-host DeploySeal.', 'deployseal' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save', 'deployseal' ), 'secondary', 'save-advanced', true, array( 'id' => 'deployseal-save-advanced' ) ); ?>
				</details>

				<details class="deployseal-fold" id="deployseal-platform-inventory" <?php echo $inventory_open ? 'open' : ''; ?>>
					<summary><h2><?php esc_html_e( 'Platform inventory', 'deployseal' ); ?></h2></summary>
					<p class="description"><?php esc_html_e( 'DeploySeal can record which plugins and themes, at which versions, were installed when a release was tested — release evidence only the site itself can provide. What is sent: every installed plugin and theme (name, version, active or not), must-use plugins, the WordPress and PHP versions, WooCommerce when installed, and the build marker above. Nothing else: no settings, no users, no orders.', 'deployseal' ); ?></p>
					<table class="form-table" role="presentation" id="deployseal-inventory">
						<tr>
							<th scope="row"><label for="deployseal-api-key"><?php esc_html_e( 'DeploySeal API key', 'deployseal' ); ?></label></th>
							<td>
								<input type="password" class="regular-text code" id="deployseal-api-key" name="<?php echo esc_attr( self::name( 'api_key' ) ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $api_key_set ? '••••••••••••••••' : 'ds_live_…' ); ?>" />
								<p class="description"><?php esc_html_e( 'An organisation API key with the Write scope (DeploySeal → Settings → Integrations → API keys). Only used to send the platform inventory, server to server. Leave empty to keep the stored key.', 'deployseal' ); ?></p>
								<?php if ( $api_key_set ) : ?>
									<p class="description" id="deployseal-apikey-stored">
										<?php esc_html_e( 'An API key is stored. Leave the box empty to keep it, or tick the box to remove it.', 'deployseal' ); ?>
										<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'clear_api_key' ) ); ?>" value="1" /> <?php esc_html_e( 'Remove the stored API key', 'deployseal' ); ?></label>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Send inventory on a schedule', 'deployseal' ); ?></th>
							<td>
								<label for="deployseal-send-inventory">
									<input type="checkbox" id="deployseal-send-inventory" name="<?php echo esc_attr( self::name( 'send_inventory' ) ); ?>" value="1" <?php checked( ! empty( $options['send_inventory'] ) ); ?> />
									<?php esc_html_e( 'Post the inventory to DeploySeal every 6 hours (WP-Cron), so the readiness report can print exactly what was installed when a campaign was tested. Needs the site key and the API key.', 'deployseal' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Items that will be reported', 'deployseal' ); ?></th>
							<td>
								<code id="deployseal-inventory-count"><?php echo esc_html( (string) count( $items ) ); ?></code>
								<span class="description">(<span id="deployseal-inventory-enabled"><?php echo esc_html( (string) $enabled_n ); ?></span> <?php esc_html_e( 'active', 'deployseal' ); ?>)</span>
								<p class="description"><?php esc_html_e( 'Fingerprint', 'deployseal' ); ?>: <code id="deployseal-inventory-sha" style="word-break:break-all">sha256:<?php echo esc_html( $snapshot['sha256'] ); ?></code></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sent to', 'deployseal' ); ?></th>
							<td><code id="deployseal-inventory-endpoint" style="word-break:break-all"><?php echo esc_html( Inventory::endpoint( $options ) ); ?></code></td>
						</tr>
						<?php if ( is_array( $last ) && ! empty( $last['message'] ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Last send', 'deployseal' ); ?></th>
								<td id="deployseal-inventory-last">
									<?php echo esc_html( $last['message'] ); ?>
									<span class="description">(<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['at'] ) ); ?>)</span>
								</td>
							</tr>
						<?php endif; ?>
					</table>
					<?php if ( ! $can_send ) : ?>
						<div class="notice notice-warning inline" id="deployseal-inventory-notready"><p><?php esc_html_e( 'Enter the site key and a DeploySeal API key (Write scope) above and save before sending.', 'deployseal' ); ?></p></div>
					<?php endif; ?>
					<p>
						<?php submit_button( __( 'Save', 'deployseal' ), 'secondary', 'save-inventory', false, array( 'id' => 'deployseal-save-inventory' ) ); ?>
						<?php
						// Submits the separate form below: "form" assigns the button to it wherever it sits.
						$send_attrs = array(
							'id'   => 'deployseal-send-now',
							'form' => 'deployseal-send-inventory-form',
						);
						if ( ! $can_send ) {
							$send_attrs['disabled'] = 'disabled';
						}
						submit_button( __( 'Send inventory now', 'deployseal' ), 'secondary', 'send-inventory', false, $send_attrs );
						?>
					</p>
					<p class="description"><?php esc_html_e( 'The scheduled send posts the same snapshot every 6 hours while "Send inventory on a schedule" is on. Resending an unchanged list does not grow the record.', 'deployseal' ); ?></p>
				</details>
			</form>

			<form id="deployseal-send-inventory-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SEND_ACTION ); ?>" />
				<?php wp_nonce_field( self::SEND_ACTION ); ?>
			</form>
		</div>
		<?php
	}
}
