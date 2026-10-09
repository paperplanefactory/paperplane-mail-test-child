<?php
/**
 * Plugin Name: PaperPlane Mail Test Child
 * Description: Exposes a REST endpoint for mail function testing. Install on each monitored site.
 * Version: 1.3.2
 * Author: Paper Plane Factory
 * Text Domain: paperplane-mail-test-child
 * Domain Path: /languages
 * Update URI: https://github.com/paperplanefactory/paperplane-mail-test-child/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PP_MT_REST_NS', 'pp-mail-test/v1' );

// ─── Auto-aggiornamenti da GitHub ─────────────────────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';

add_action( 'init', function () {
	load_plugin_textdomain( 'paperplane-mail-test-child', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/paperplanefactory/paperplane-mail-test-child/',
		__FILE__,
		'paperplane-mail-test-child'
	);
	$checker->setBranch( 'main' );
} );

// ─── Chiave segreta ───────────────────────────────────────────────────────────
// Letta da wp-config.php se definita, altrimenti da un valore temporaneo
// generato e mostrato nella pagina opzioni (da copiare manualmente in wp-config).

function pp_mt_get_secret() {
	if ( defined( 'PP_MAIL_TEST_SECRET' ) && PP_MAIL_TEST_SECRET ) {
		return PP_MAIL_TEST_SECRET;
	}
	return '';
}

function pp_mt_get_suggested_secret() {
	$s = get_transient( 'pp_mt_suggested_secret' );
	if ( ! $s ) {
		$s = wp_generate_password( 32, false );
		set_transient( 'pp_mt_suggested_secret', $s, HOUR_IN_SECONDS );
	}
	return $s;
}

// ─── REST endpoint ────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
	register_rest_route( PP_MT_REST_NS, '/check', array(
		'methods'             => 'POST',
		'callback'            => 'pp_mt_handle_check',
		'permission_callback' => 'pp_mt_auth',
	) );
} );

function pp_mt_auth( WP_REST_Request $request ) {
	$secret = pp_mt_get_secret();
	if ( ! $secret ) {
		return false;
	}
	$auth  = $request->get_header( 'authorization' );
	// Legge la chiave dal body POST (priorità) o dall'header Authorization
	$token = sanitize_text_field( $request->get_param( 'pp_secret' ) ?? '' );
	if ( ! $token ) {
		$auth = $request->get_header( 'authorization' );
		if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
			$token = substr( $auth, 7 );
		}
	}
	return hash_equals( $secret, $token );
}

function pp_mt_handle_check( WP_REST_Request $request ) {
	$raw         = sanitize_text_field( $request->get_param( 'test_email' ) ?? '' );
	$test_email  = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $raw ) ) ) );
	if ( empty( $test_email ) ) {
		$test_email = get_option( 'admin_email' );
	}

	$subject = sprintf( __( '[Mail Test] %s — %s', 'paperplane-mail-test-child' ), get_bloginfo( 'name' ), date_i18n( 'd/m/Y H:i' ) );
	$body    = sprintf( __( 'Automatic mail function test from %s.', 'paperplane-mail-test-child' ), home_url() );
	$result  = wp_mail( $test_email, $subject, $body );

	return new WP_REST_Response( array(
		'success' => $result,
		'message' => $result ? __( 'Mail sent successfully.', 'paperplane-mail-test-child' ) : __( 'wp_mail() returned false.', 'paperplane-mail-test-child' ),
		'site'    => home_url(),
		'time'    => current_time( 'mysql' ),
	), 200 );
}

// ─── Pagina opzioni ───────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_management_page(
		__( 'PaperPlane Mail Test', 'paperplane-mail-test-child' ),
		__( 'PaperPlane Mail Test', 'paperplane-mail-test-child' ),
		'manage_options',
		'pp-mail-test',
		'pp_mt_render_options'
	);
} );

function pp_mt_render_options() {
	$secret_active    = pp_mt_get_secret();
	$secret_suggested = pp_mt_get_suggested_secret();
	$endpoint         = rest_url( PP_MT_REST_NS . '/check' );

	// Gestione rigenera chiave suggerita
	if ( isset( $_GET['pp_mt_regen'] ) ) {
		delete_transient( 'pp_mt_suggested_secret' );
		$secret_suggested = pp_mt_get_suggested_secret();
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'PaperPlane Mail Test', 'paperplane-mail-test-child' ); ?></h1>
		<p><?php esc_html_e( 'This plugin exposes a REST endpoint that the PaperPlane assistance site can call to verify the mail function works correctly.', 'paperplane-mail-test-child' ); ?></p>

		<h2><?php esc_html_e( '1. Secret key', 'paperplane-mail-test-child' ); ?></h2>

		<?php if ( $secret_active ) : ?>
			<div class="notice notice-success inline"><p>&#10003; <?php printf( __( 'Key active — %s is defined in %s.', 'paperplane-mail-test-child' ), '<code>PP_MAIL_TEST_SECRET</code>', '<code>wp-config.php</code>' ); ?></p></div>
			<p style="margin-top:12px">
				<strong><?php esc_html_e( 'Active key:', 'paperplane-mail-test-child' ); ?></strong>
				<code id="pp-mt-key-active"><?php echo esc_html( $secret_active ); ?></code>
				<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-active', this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
			</p>
		<?php else : ?>
			<div class="notice notice-warning inline">
				<p>&#9888; <?php printf( __( 'Warning: %s is not yet defined in %s. The plugin will not respond to requests until you add it.', 'paperplane-mail-test-child' ), '<code>PP_MAIL_TEST_SECRET</code>', '<code>wp-config.php</code>' ); ?></p>
			</div>
			<p style="margin-top:12px"><?php printf( __( 'Suggested key (copy and paste it into %s):', 'paperplane-mail-test-child' ), '<code>wp-config.php</code>' ); ?></p>
			<p>
				<code id="pp-mt-key-suggested"><?php echo esc_html( $secret_suggested ); ?></code>
				<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-suggested', this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
				<a href="<?php echo esc_url( add_query_arg( 'pp_mt_regen', '1' ) ); ?>" class="button button-secondary" style="margin-left:4px"
					onclick="return confirm('<?php echo esc_js( __( 'Generate a new suggested key?', 'paperplane-mail-test-child' ) ); ?>')"><?php esc_html_e( 'Regenerate', 'paperplane-mail-test-child' ); ?></a>
			</p>
			<p><?php printf( __( 'Add this line to %s before %s:', 'paperplane-mail-test-child' ), '<code>wp-config.php</code>', '<code>/* That\'s all, stop editing! */</code>' ); ?></p>
			<pre style="background:#f6f7f7;padding:12px;display:inline-block">define( 'PP_MAIL_TEST_SECRET', '<?php echo esc_html( $secret_suggested ); ?>' );</pre>
		<?php endif; ?>

		<h2 style="margin-top:2em"><?php esc_html_e( '2. Assistance site data', 'paperplane-mail-test-child' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Site URL', 'paperplane-mail-test-child' ); ?></th>
				<td>
					<code id="pp-mt-url"><?php echo esc_html( home_url() ); ?></code>
					<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-url', this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Key to use', 'paperplane-mail-test-child' ); ?></th>
				<td>
					<?php $key_to_use = $secret_active ?: $secret_suggested; ?>
					<code id="pp-mt-key-use"><?php echo esc_html( $key_to_use ); ?></code>
					<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-use', this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
					<?php if ( ! $secret_active ) : ?>
						<span style="color:#d63638;margin-left:8px">&#9888; <?php esc_html_e( 'Add the key to wp-config.php first', 'paperplane-mail-test-child' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</table>

	</div>

	<script>
	function pp_mt_copy(id, btn) {
		var text = document.getElementById(id).textContent;
		navigator.clipboard.writeText(text).then(function() {
			var orig = btn.textContent;
			btn.textContent = 'Copiato!';
			setTimeout(function() { btn.textContent = orig; }, 2000);
		});
	}
	</script>
	<?php
}
