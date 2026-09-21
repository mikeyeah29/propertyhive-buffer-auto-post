<?php
/**
 * WordPress administration surface.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Admin;

use Homer\PropertyHiveBufferAutoPost\Buffer\Client;
use Homer\PropertyHiveBufferAutoPost\Content\TemplateRenderer;
use Homer\PropertyHiveBufferAutoPost\Contracts\Hookable;
use Homer\PropertyHiveBufferAutoPost\Dependency;
use Homer\PropertyHiveBufferAutoPost\Events\EventFactory;
use Homer\PropertyHiveBufferAutoPost\Events\EventRepository;
use Homer\PropertyHiveBufferAutoPost\Logger;
use Homer\PropertyHiveBufferAutoPost\Settings;

class AdminPage implements Hookable {
	const SLUG = 'phbap';

	private $dependency;
	private $factory;
	private $repository;
	private $logger;

	public function __construct( Dependency $dependency, EventFactory $factory, EventRepository $repository, Logger $logger ) {
		$this->dependency = $dependency;
		$this->factory    = $factory;
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_phbap_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_phbap_clear_log', array( $this, 'clear_log' ) );
		add_action( 'wp_ajax_phbap_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_phbap_property_search', array( $this, 'property_search' ) );
		add_action( 'wp_ajax_phbap_preview', array( $this, 'preview' ) );
		add_action( 'wp_ajax_phbap_send_test', array( $this, 'send_test' ) );
	}

	public function menu() {
		$parent = post_type_exists( 'property' ) ? 'propertyhive' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Buffer Auto Post', 'propertyhive-buffer-auto-post' ),
			__( 'Buffer Auto Post', 'propertyhive-buffer-auto-post' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'phbap-admin', PHBAP_URL . 'assets/css/admin.css', array(), PHBAP_VERSION );
		wp_enqueue_script( 'phbap-admin', PHBAP_URL . 'assets/js/admin.js', array(), PHBAP_VERSION, true );
		wp_localize_script(
			'phbap-admin',
			'phbapAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'phbap_admin' ),
				'strings' => array(
					'working'        => __( 'Working…', 'propertyhive-buffer-auto-post' ),
					'requestFailed'  => __( 'The request could not be completed. Try again.', 'propertyhive-buffer-auto-post' ),
					'chooseOverlay'  => __( 'Choose sold overlay', 'propertyhive-buffer-auto-post' ),
					'noProperties'   => __( 'No matching properties were found.', 'propertyhive-buffer-auto-post' ),
					'noOverlay'      => __( 'No overlay selected', 'propertyhive-buffer-auto-post' ),
					'selectProperty' => __( 'Select a property', 'propertyhive-buffer-auto-post' ),
					'chooseProperty' => __( 'Choose a property, then preview the post.', 'propertyhive-buffer-auto-post' ),
					/* translators: %d: Image position in the property gallery. */
					'imageAlt'       => __( 'Property image %d', 'propertyhive-buffer-auto-post' ),
					'previewReady'   => __( 'Preview ready. The send action will create Buffer drafts.', 'propertyhive-buffer-auto-post' ),
				),
			)
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Buffer Auto Post.', 'propertyhive-buffer-auto-post' ) );
		}
		$tabs = array(
			'settings'  => __( 'Settings', 'propertyhive-buffer-auto-post' ),
			'templates' => __( 'Templates', 'propertyhive-buffer-auto-post' ),
			'test'      => __( 'Test', 'propertyhive-buffer-auto-post' ),
			'log'       => __( 'Log', 'propertyhive-buffer-auto-post' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'settings';
		?>
		<div class="wrap phbap-wrap">
			<div class="phbap-heading">
				<div>
					<h1><?php esc_html_e( 'PropertyHive Buffer Auto Post', 'propertyhive-buffer-auto-post' ); ?></h1>
					<p><?php esc_html_e( 'Publish traceable property updates to selected Facebook and Instagram channels.', 'propertyhive-buffer-auto-post' ); ?></p>
				</div>
				<?php $this->render_readiness(); ?>
			</div>
			<?php $this->render_notice(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Buffer Auto Post sections', 'propertyhive-buffer-auto-post' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $key ) ); ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<main class="phbap-content">
				<?php
				if ( 'settings' === $tab ) {
					$this->render_settings();
				} elseif ( 'templates' === $tab ) {
					$this->render_templates();
				} elseif ( 'test' === $tab ) {
					$this->render_test();
				} else {
					$this->render_log();
				}
				?>
			</main>
		</div>
		<?php
	}

	private function render_readiness() {
		$baseline = get_option( 'phbap_baseline_status', 'pending' );
		$settings = Settings::all();
		$ready    = $this->dependency->compatible()
			&& 'ready' === $baseline
			&& '' !== Settings::api_key()
			&& '' !== $settings['buffer_organisation_id']
			&& ! empty( Settings::selected_channels() );
		?>
		<div class="phbap-readiness <?php echo $ready ? 'is-ready' : 'needs-attention'; ?>">
			<span aria-hidden="true"></span>
			<?php echo esc_html( $ready ? __( 'Ready for automation', 'propertyhive-buffer-auto-post' ) : __( 'Setup needs attention', 'propertyhive-buffer-auto-post' ) ); ?>
		</div>
		<?php
	}

	private function render_notice() {
		$key    = 'phbap_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
		if ( 'ready' !== get_option( 'phbap_baseline_status', 'pending' ) ) {
			printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html__( 'Existing properties are being baselined. No automatic posts will be created until this completes.', 'propertyhive-buffer-auto-post' ) );
		}
	}

	private function render_settings() {
		$settings = Settings::all();
		$orgs     = get_option( Settings::ORGS_OPTION, array() );
		$channels = get_option( Settings::CHANNEL_OPTION, array() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="phbap-form">
			<input type="hidden" name="action" value="phbap_save_settings">
			<input type="hidden" name="section" value="settings">
			<?php wp_nonce_field( 'phbap_save_settings' ); ?>
			<section class="phbap-section" aria-labelledby="phbap-events-heading">
				<h2 id="phbap-events-heading"><?php esc_html_e( 'Post to Buffer when', 'propertyhive-buffer-auto-post' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Events are recorded only after a property write has settled and each real transition is queued once.', 'propertyhive-buffer-auto-post' ); ?></p>
				<?php $this->checkbox( 'event_new_listing', __( 'A sales property is listed for the first time', 'propertyhive-buffer-auto-post' ), $settings['event_new_listing'] ); ?>
				<?php $this->checkbox( 'event_price_reduction', __( 'The asking price of a live sales property drops', 'propertyhive-buffer-auto-post' ), $settings['event_price_reduction'] ); ?>
				<?php $this->checkbox( 'event_sold', __( 'A property first enters Sold STC or Sold', 'propertyhive-buffer-auto-post' ), $settings['event_sold'] ); ?>
			</section>

			<section class="phbap-section" aria-labelledby="phbap-buffer-heading">
				<div class="phbap-section-heading">
					<div><h2 id="phbap-buffer-heading"><?php esc_html_e( 'Buffer connection', 'propertyhive-buffer-auto-post' ); ?></h2><p class="description"><?php esc_html_e( 'The key is used only on the server and is never returned to this page.', 'propertyhive-buffer-auto-post' ); ?></p></div>
					<button type="button" class="button" id="phbap-test-connection"><?php esc_html_e( 'Test connection and refresh', 'propertyhive-buffer-auto-post' ); ?></button>
				</div>
				<div class="phbap-field">
					<label for="phbap-buffer-key"><?php esc_html_e( 'Buffer API key', 'propertyhive-buffer-auto-post' ); ?></label>
					<?php if ( Settings::api_key_is_constant() ) : ?>
						<input id="phbap-buffer-key" type="password" value="••••••••••••" disabled><p class="description"><?php esc_html_e( 'Managed by PHBAP_BUFFER_API_KEY in wp-config.php.', 'propertyhive-buffer-auto-post' ); ?></p>
					<?php else : ?>
						<input id="phbap-buffer-key" name="phbap[buffer_api_key]" type="password" value="" autocomplete="new-password" placeholder="<?php echo Settings::api_key() ? esc_attr__( 'Saved — enter a new key to replace it', 'propertyhive-buffer-auto-post' ) : ''; ?>">
						<?php
						if ( Settings::api_key() ) :
							?>
							<label class="phbap-inline-check"><input type="checkbox" name="phbap[remove_buffer_api_key]" value="1"> <?php esc_html_e( 'Remove the saved key', 'propertyhive-buffer-auto-post' ); ?></label><?php endif; ?>
					<?php endif; ?>
				</div>
				<div class="phbap-field">
					<label for="phbap-org"><?php esc_html_e( 'Organisation', 'propertyhive-buffer-auto-post' ); ?></label>
					<select id="phbap-org" name="phbap[buffer_organisation_id]"><option value=""><?php esc_html_e( 'Select an organisation', 'propertyhive-buffer-auto-post' ); ?></option>
					<?php
					foreach ( (array) $orgs as $org ) :
						?>
						<option value="<?php echo esc_attr( $org['id'] ); ?>" <?php selected( $settings['buffer_organisation_id'], $org['id'] ); ?>><?php echo esc_html( $org['name'] ); ?></option><?php endforeach; ?></select>
				</div>
				<fieldset class="phbap-field"><legend><?php esc_html_e( 'Facebook and Instagram channels', 'propertyhive-buffer-auto-post' ); ?></legend>
					<?php
					if ( empty( $channels ) ) :
						?>
						<p class="description"><?php esc_html_e( 'Save an API key, then test the connection to load channels.', 'propertyhive-buffer-auto-post' ); ?></p><?php endif; ?>
					<div class="phbap-channel-list">
					<?php
					foreach ( (array) $channels as $channel ) :
						?>
						<label><input type="checkbox" name="phbap[buffer_channel_ids][]" value="<?php echo esc_attr( $channel['id'] ); ?>" <?php checked( in_array( (string) $channel['id'], (array) $settings['buffer_channel_ids'], true ) ); ?>><span><strong><?php echo esc_html( $channel['displayName'] ? $channel['displayName'] : $channel['name'] ); ?></strong><small><?php echo esc_html( ucfirst( strtolower( $channel['service'] ) ) ); ?></small></span></label><?php endforeach; ?></div>
				</fieldset>
				<div id="phbap-connection-status" class="phbap-live" aria-live="polite"></div>
			</section>

			<section class="phbap-section" aria-labelledby="phbap-schedule-heading">
				<h2 id="phbap-schedule-heading"><?php esc_html_e( 'Delivery timing', 'propertyhive-buffer-auto-post' ); ?></h2>
				<label class="phbap-choice"><input type="radio" name="phbap[delivery_mode]" value="queue" <?php checked( $settings['delivery_mode'], 'queue' ); ?>><span><strong><?php esc_html_e( 'Next queue slot', 'propertyhive-buffer-auto-post' ); ?></strong><small><?php esc_html_e( 'Let Buffer choose the next available time for each channel.', 'propertyhive-buffer-auto-post' ); ?></small></span></label>
				<label class="phbap-choice"><input type="radio" name="phbap[delivery_mode]" value="daily" <?php checked( $settings['delivery_mode'], 'daily' ); ?>><span><strong><?php esc_html_e( 'Next daily time', 'propertyhive-buffer-auto-post' ); ?></strong><small><?php esc_html_e( 'Schedule all channel posts for the next occurrence in the WordPress timezone.', 'propertyhive-buffer-auto-post' ); ?></small></span></label>
				<div class="phbap-field phbap-time-field"><label for="phbap-daily-time"><?php esc_html_e( 'Daily time', 'propertyhive-buffer-auto-post' ); ?></label><input id="phbap-daily-time" type="time" name="phbap[daily_time]" value="<?php echo esc_attr( $settings['daily_time'] ); ?>"><p class="description"><?php echo esc_html( wp_timezone_string() ? wp_timezone_string() : 'UTC' ); ?></p></div>
			</section>
			<?php submit_button( __( 'Save settings', 'propertyhive-buffer-auto-post' ) ); ?>
		</form>
		<?php
	}

	private function render_templates() {
		$settings = Settings::all();
		$overlay  = $settings['sold_overlay_id'] ? wp_get_attachment_image_url( $settings['sold_overlay_id'], 'medium' ) : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="phbap-form">
			<input type="hidden" name="action" value="phbap_save_settings"><input type="hidden" name="section" value="templates"><?php wp_nonce_field( 'phbap_save_settings' ); ?>
			<section class="phbap-section"><h2><?php esc_html_e( 'Caption templates', 'propertyhive-buffer-auto-post' ); ?></h2><p class="description"><?php esc_html_e( 'Line breaks are preserved. Unsupported placeholders are rejected when you save.', 'propertyhive-buffer-auto-post' ); ?></p>
				<?php $this->textarea( 'new_listing_template', __( 'New listing', 'propertyhive-buffer-auto-post' ), $settings['new_listing_template'] ); ?>
				<?php $this->textarea( 'price_reduction_template', __( 'Price reduction', 'propertyhive-buffer-auto-post' ), $settings['price_reduction_template'] ); ?>
				<?php $this->textarea( 'sold_template', __( 'Property sold', 'propertyhive-buffer-auto-post' ), $settings['sold_template'] ); ?>
				<div class="phbap-placeholders"><h3><?php esc_html_e( 'Supported placeholders', 'propertyhive-buffer-auto-post' ); ?></h3>
				<?php
				foreach ( TemplateRenderer::PLACEHOLDERS as $placeholder ) :
					?>
					<code><?php echo esc_html( $placeholder ); ?></code><?php endforeach; ?></div>
			</section>
			<section class="phbap-section"><h2><?php esc_html_e( 'Sold overlay', 'propertyhive-buffer-auto-post' ); ?></h2><p class="description"><?php esc_html_e( 'Choose a transparent PNG. It is scaled over a derived copy of the first property image; the Media Library original is never changed.', 'propertyhive-buffer-auto-post' ); ?></p>
				<input type="hidden" id="phbap-overlay-id" name="phbap[sold_overlay_id]" value="<?php echo esc_attr( $settings['sold_overlay_id'] ); ?>">
				<div class="phbap-overlay-preview" id="phbap-overlay-preview">
				<?php
				if ( $overlay ) :
					?>
					<img src="<?php echo esc_url( $overlay ); ?>" alt="<?php esc_attr_e( 'Current sold overlay', 'propertyhive-buffer-auto-post' ); ?>">
					<?php
else :
	?>
					<span><?php esc_html_e( 'No overlay selected', 'propertyhive-buffer-auto-post' ); ?></span><?php endif; ?></div>
				<p><button type="button" class="button" id="phbap-choose-overlay"><?php esc_html_e( 'Choose PNG', 'propertyhive-buffer-auto-post' ); ?></button> <button type="button" class="button-link-delete" id="phbap-remove-overlay"><?php esc_html_e( 'Remove', 'propertyhive-buffer-auto-post' ); ?></button></p>
			</section>
			<?php submit_button( __( 'Save templates', 'propertyhive-buffer-auto-post' ) ); ?>
		</form>
		<?php
	}

	private function render_test() {
		?>
		<section class="phbap-section phbap-test" aria-labelledby="phbap-test-heading">
			<h2 id="phbap-test-heading"><?php esc_html_e( 'Send a Buffer draft', 'propertyhive-buffer-auto-post' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Preview real Property Hive data, then create a clearly logged draft on every selected channel. Tests never alter automatic-event history.', 'propertyhive-buffer-auto-post' ); ?></p>
			<div class="phbap-search-row">
				<div class="phbap-field">
					<label for="phbap-property-query"><?php esc_html_e( 'Find a property', 'propertyhive-buffer-auto-post' ); ?></label>
					<input type="search" id="phbap-property-query" autocomplete="off">
				</div>
				<button type="button" class="button" id="phbap-search-properties"><?php esc_html_e( 'Search', 'propertyhive-buffer-auto-post' ); ?></button>
			</div>

			<div class="phbap-field">
				<label for="phbap-property-id"><?php esc_html_e( 'Property', 'propertyhive-buffer-auto-post' ); ?></label>
				<select id="phbap-property-id">
					<option value=""><?php esc_html_e( 'Search and select a property', 'propertyhive-buffer-auto-post' ); ?></option>
				</select>
			</div>

			<div class="phbap-field">
				<label for="phbap-event-type"><?php esc_html_e( 'Template', 'propertyhive-buffer-auto-post' ); ?></label>
				<select id="phbap-event-type">
					<option value="new_listing"><?php esc_html_e( 'New listing', 'propertyhive-buffer-auto-post' ); ?></option>
					<option value="price_reduction"><?php esc_html_e( 'Price reduction', 'propertyhive-buffer-auto-post' ); ?></option>
					<option value="sold"><?php esc_html_e( 'Property sold', 'propertyhive-buffer-auto-post' ); ?></option>
				</select>
			</div>

			<div class="phbap-field" id="phbap-previous-price-field" hidden>
				<label for="phbap-previous-price"><?php esc_html_e( 'Previous price', 'propertyhive-buffer-auto-post' ); ?></label>
				<input type="number" min="1" step="1" id="phbap-previous-price">
			</div>

			<p>
				<button type="button" class="button" id="phbap-preview-test"><?php esc_html_e( 'Preview', 'propertyhive-buffer-auto-post' ); ?></button>
				<button type="button" class="button button-primary" id="phbap-send-test" disabled><?php esc_html_e( 'Create Buffer drafts', 'propertyhive-buffer-auto-post' ); ?></button>
			</p>

			<div id="phbap-test-status" class="phbap-live" aria-live="polite"></div>

			<div id="phbap-test-preview" class="phbap-test-preview" hidden>
				<div>
					<h3><?php esc_html_e( 'Caption', 'propertyhive-buffer-auto-post' ); ?></h3>
					<pre id="phbap-preview-caption"></pre>
				</div>
				<div>
					<h3><?php esc_html_e( 'Images', 'propertyhive-buffer-auto-post' ); ?></h3>
					<div id="phbap-preview-images" class="phbap-image-strip"></div>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_log() {
		$rows = $this->logger->recent();
		?>
		<section class="phbap-section phbap-log">
			<div class="phbap-section-heading">
				<div>
					<h2><?php esc_html_e( 'Recent delivery log', 'propertyhive-buffer-auto-post' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The newest 100 sanitised destination attempts are retained.', 'propertyhive-buffer-auto-post' ); ?></p>
				</div>
				<?php if ( $rows ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the visible delivery log?', 'propertyhive-buffer-auto-post' ) ); ?>');">
						<input type="hidden" name="action" value="phbap_clear_log">
						<?php wp_nonce_field( 'phbap_clear_log' ); ?>
						<button class="button" type="submit"><?php esc_html_e( 'Clear log', 'propertyhive-buffer-auto-post' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<?php if ( ! $rows ) : ?>
				<div class="phbap-empty">
					<h3><?php esc_html_e( 'No deliveries yet', 'propertyhive-buffer-auto-post' ); ?></h3>
					<p><?php esc_html_e( 'Automatic and manual test attempts will appear here with a status for each Buffer channel.', 'propertyhive-buffer-auto-post' ); ?></p>
				</div>
			<?php else : ?>
				<div class="phbap-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'propertyhive-buffer-auto-post' ); ?></th>
								<th><?php esc_html_e( 'Property / event', 'propertyhive-buffer-auto-post' ); ?></th>
								<th><?php esc_html_e( 'Destination', 'propertyhive-buffer-auto-post' ); ?></th>
								<th><?php esc_html_e( 'Status', 'propertyhive-buffer-auto-post' ); ?></th>
								<th><?php esc_html_e( 'Details', 'propertyhive-buffer-auto-post' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$channel_name = $row->channel_name ? $row->channel_name : $row->channel_id;
								$event_name   = ucwords( str_replace( '_', ' ', $row->event_type ) );
								if ( 'manual_test' === $row->source ) {
									$event_name .= ' · ' . __( 'Test', 'propertyhive-buffer-auto-post' );
								}
								$datetime = gmdate( 'c', strtotime( $row->created_at_gmt . ' UTC' ) );
								?>
								<tr>
									<td><time datetime="<?php echo esc_attr( $datetime ); ?>"><?php echo esc_html( get_date_from_gmt( $row->created_at_gmt, 'j F Y H:i' ) ); ?></time></td>
									<td>
										<?php /* translators: %d: Property post ID. */ ?>
										<strong><?php echo esc_html( sprintf( __( 'Property %d', 'propertyhive-buffer-auto-post' ), $row->property_id ) ); ?></strong><br>
										<small><?php echo esc_html( $event_name ); ?></small>
									</td>
									<td><?php echo esc_html( $channel_name ); ?><br><small><?php echo esc_html( ucfirst( $row->service ) ); ?></small></td>
									<td>
										<span class="phbap-status phbap-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span><br>
										<?php /* translators: %d: Delivery attempt number. */ ?>
										<small><?php echo esc_html( sprintf( __( 'Attempt %d', 'propertyhive-buffer-auto-post' ), $row->attempt ) ); ?></small>
									</td>
									<td>
										<?php echo esc_html( $row->message ); ?>
										<?php if ( $row->buffer_post_id ) : ?>
											<br><code><?php echo esc_html( $row->buffer_post_id ); ?></code>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	public function save_settings() {
		$this->guard_post( 'phbap_save_settings' );
		// Nonce verification is performed by guard_post() immediately above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$section = isset( $_POST['section'] ) && 'templates' === sanitize_key( wp_unslash( $_POST['section'] ) ) ? 'templates' : 'settings';
		$input   = isset( $_POST['phbap'] ) ? $_POST['phbap'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Settings::save() unslashes and sanitizes every supported field.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = Settings::save( $input, $section );
		$this->set_notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Settings saved.', 'propertyhive-buffer-auto-post' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $section ) );
		exit;
	}

	public function clear_log() {
		$this->guard_post( 'phbap_clear_log' );
		$this->logger->clear();
		$this->set_notice( 'success', __( 'The visible delivery log was cleared. Event history and duplicate protection were retained.', 'propertyhive-buffer-auto-post' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=log' ) );
		exit;
	}

	public function test_connection() {
		$this->guard_ajax();
		$client = new Client( Settings::api_key() );
		$orgs   = $client->organisations();
		if ( is_wp_error( $orgs ) ) {
			wp_send_json_error( array( 'message' => $orgs->get_error_message() ), 400 );
		}
		update_option( Settings::ORGS_OPTION, $orgs, false );
		$settings = Settings::all();
		$ids      = array_map( 'strval', wp_list_pluck( $orgs, 'id' ) );
		// Nonce verification is performed by guard_ajax() before reading the requested organisation.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$requested_org_id = isset( $_POST['organisation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['organisation_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$org_id = in_array( $requested_org_id, $ids, true ) ? $requested_org_id : (string) $settings['buffer_organisation_id'];
		if ( ! $org_id || ! in_array( $org_id, $ids, true ) ) {
			$org_id = isset( $orgs[0]['id'] ) ? (string) $orgs[0]['id'] : '';
		}
		$settings['buffer_organisation_id'] = $org_id;
		update_option( Settings::OPTION, $settings, false );
		$channels = $org_id ? $client->channels( $org_id ) : array();
		if ( is_wp_error( $channels ) ) {
			wp_send_json_error( array( 'message' => $channels->get_error_message() ), 400 );
		}
		update_option( Settings::CHANNEL_OPTION, $channels, false );
		/* translators: 1: Number of Buffer organisations, 2: Number of supported channels. */
		$message = sprintf( __( 'Connected. Found %1$d organisation(s) and %2$d supported channel(s). Reloading…', 'propertyhive-buffer-auto-post' ), count( $orgs ), count( $channels ) );
		wp_send_json_success( array( 'message' => $message ) );
	}

	public function property_search() {
		$this->guard_ajax();
		// Nonce verification is performed by guard_ajax() immediately above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$posts = get_posts(
			array(
				'post_type'      => 'property',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				's'              => $query,
				'posts_per_page' => 20,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array_map(
			static function ( $post ) {
				/* translators: %d: Property post ID. */
				$fallback = sprintf( __( 'Property %d', 'propertyhive-buffer-auto-post' ), $post->ID );
				return array(
					'id'    => $post->ID,
					'title' => $post->post_title ? $post->post_title : $fallback,
				);
			},
			$posts
		);
		wp_send_json_success( array( 'items' => $items ) );
	}

	public function preview() {
		$this->guard_ajax();
		$payload = $this->test_payload();
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'caption' => $payload['caption'],
				'images'  => wp_list_pluck( $payload['images'], 'url' ),
				'overlay' => $payload['overlay_id'] ? wp_get_attachment_image_url( $payload['overlay_id'], 'full' ) : '',
			)
		);
	}

	public function send_test() {
		$this->guard_ajax();
		$payload = $this->test_payload();
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ), 400 );
		}
		$channels = Settings::selected_channels();
		if ( empty( $channels ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one Buffer channel before sending a test.', 'propertyhive-buffer-auto-post' ) ), 400 );
		}
		$key    = 'test:' . wp_generate_uuid4();
		$result = $this->repository->create( $key, $payload, $channels );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		wp_send_json_success( array( 'message' => __( 'The test drafts were queued. Their destination results will appear in the Log tab.', 'propertyhive-buffer-auto-post' ) ) );
	}

	private function test_payload() {
		if ( ! $this->dependency->compatible() ) {
			return new \WP_Error( 'property_hive_dependency', __( 'Property Hive 2.0.16 or newer is required for property previews and tests.', 'propertyhive-buffer-auto-post' ) );
		}
		// Callers verify the phbap_admin nonce with guard_ajax() before reaching this method.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$property_id    = isset( $_POST['property_id'] ) ? absint( $_POST['property_id'] ) : 0;
		$event_type     = isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : '';
		$previous_price = isset( $_POST['previous_price'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['previous_price'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $property_id || ! in_array( $event_type, array( 'new_listing', 'price_reduction', 'sold' ), true ) ) {
			return new \WP_Error( 'test_fields', __( 'Select a property and template.', 'propertyhive-buffer-auto-post' ) );
		}
		if ( 'price_reduction' === $event_type && $previous_price <= 0 ) {
			return new \WP_Error( 'previous_price', __( 'Enter the previous price for a price-reduction test.', 'propertyhive-buffer-auto-post' ) );
		}
		return $this->factory->create( $property_id, $event_type, $previous_price, 'manual_test' );
	}

	private function guard_post( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'propertyhive-buffer-auto-post' ) );
		}
		check_admin_referer( $action );
	}

	private function guard_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'propertyhive-buffer-auto-post' ) ), 403 );
		}
		check_ajax_referer( 'phbap_admin', 'nonce' );
	}

	private function set_notice( $type, $message ) {
		set_transient(
			'phbap_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function checkbox( $name, $label, $checked ) {
		printf( '<label class="phbap-choice"><input type="checkbox" name="phbap[%1$s]" value="1" %2$s><span><strong>%3$s</strong></span></label>', esc_attr( $name ), checked( $checked, true, false ), esc_html( $label ) );
	}

	private function textarea( $name, $label, $value ) {
		printf( '<div class="phbap-field"><label for="phbap-%1$s">%2$s</label><textarea id="phbap-%1$s" name="phbap[%1$s]" rows="6">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( $value ) );
	}
}
