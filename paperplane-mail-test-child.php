<?php
/**
 * Plugin Name: PaperPlane Mail Test Child
 * Description: Exposes a REST endpoint for mail function testing. Install on each monitored site.
 * Version: 1.4.7
 * Author: Paper Plane Factory
 * Text Domain: paperplane-mail-test-child
 * Domain Path: /languages
 * Update URI: https://github.com/paperplanefactory/paperplane-mail-test-child/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PP_MT_REST_NS',        'pp-mail-test/v1' );
define( 'PP_MT_OPTION_SECRET',  'pp_mt_secret_key' );
define( 'PP_MT_OPTION_COPIED',  'pp_mt_key_copied' );

// ─── Cifratura chiave in wp_options ──────────────────────────────────────────

function pp_mt_get_db_encryption_key(): string {
	$salt    = defined( 'AUTH_KEY' ) ? AUTH_KEY : ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
	$blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
	return hash( 'sha256', $salt . 'pp_mt_v1_' . $blog_id, true );
}

function pp_mt_encrypt_secret( string $plaintext ): string {
	if ( ! extension_loaded( 'openssl' ) || $plaintext === '' ) {
		return $plaintext;
	}
	$key = pp_mt_get_db_encryption_key();
	$iv  = openssl_random_pseudo_bytes( 16 );
	$enc = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	if ( $enc === false ) {
		return $plaintext;
	}
	return 'enc1:' . base64_encode( $iv . $enc );
}

function pp_mt_decrypt_secret( string $ciphertext ): string {
	if ( ! str_starts_with( $ciphertext, 'enc1:' ) ) {
		return $ciphertext;
	}
	if ( ! extension_loaded( 'openssl' ) ) {
		return '';
	}
	$key = pp_mt_get_db_encryption_key();
	$raw = base64_decode( substr( $ciphertext, 5 ) );
	if ( strlen( $raw ) <= 16 ) {
		return '';
	}
	$iv  = substr( $raw, 0, 16 );
	$enc = substr( $raw, 16 );
	$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return $dec !== false ? $dec : '';
}

function pp_mt_generate_and_save_secret(): string {
	$secret = wp_generate_password( 32, false );
	update_option( PP_MT_OPTION_SECRET, pp_mt_encrypt_secret( $secret ) );
	return $secret;
}

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
// Priorità: 1) costante PP_MAIL_TEST_SECRET in wp-config.php (retrocompatibilità)
//           2) chiave cifrata in wp_options (nuovo comportamento, supporta multisite)

function pp_mt_get_secret(): string {
	if ( defined( 'PP_MAIL_TEST_SECRET' ) && PP_MAIL_TEST_SECRET ) {
		return PP_MAIL_TEST_SECRET;
	}
	$stored = get_option( PP_MT_OPTION_SECRET, '' );
	if ( $stored ) {
		return pp_mt_decrypt_secret( $stored );
	}
	return '';
}

// Usata solo nel flusso legacy wp-config (siti già configurati con la costante).
function pp_mt_get_suggested_secret(): string {
	$s = get_transient( 'pp_mt_suggested_secret' );
	if ( ! $s ) {
		$s = wp_generate_password( 32, false );
		set_transient( 'pp_mt_suggested_secret', $s, DAY_IN_SECONDS );
	}
	return $s;
}

// ─── Azioni admin ────────────────────────────────────────────────────────────

add_action( 'admin_init', 'pp_mt_handle_actions' );

function pp_mt_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$action   = $_POST['pp_mt_action'] ?? '';
	$page_url = admin_url( 'tools.php?page=pp-mail-test' );

	// ── Verifica chiave wp-config (flusso legacy) ──────────────────────────────
	if ( $action === 'pp_mt_verify' && check_admin_referer( 'pp_mt_verify' ) ) {
		$pending = get_transient( 'pp_mt_suggested_secret' );

		if ( ! $pending ) {
			wp_safe_redirect( add_query_arg( 'pp_mt_result', 'no_pending', $page_url ) );
			exit;
		}
		if ( ! defined( 'PP_MAIL_TEST_SECRET' ) || ! PP_MAIL_TEST_SECRET ) {
			wp_safe_redirect( add_query_arg( 'pp_mt_result', 'not_defined', $page_url ) );
			exit;
		}
		if ( ! hash_equals( $pending, PP_MAIL_TEST_SECRET ) ) {
			wp_safe_redirect( add_query_arg( 'pp_mt_result', 'mismatch', $page_url ) );
			exit;
		}

		delete_transient( 'pp_mt_suggested_secret' );
		wp_safe_redirect( add_query_arg( 'pp_mt_result', 'ok', $page_url ) );
		exit;
	}

	// ── Marca chiave come copiata ─────────────────────────────────────────────
	if ( $action === 'mark_key_copied' && check_admin_referer( 'pp_mt_mark_copied' ) ) {
		update_option( PP_MT_OPTION_COPIED, 1 );
		wp_safe_redirect( $page_url );
		exit;
	}

	// ── Rigenera chiave database ───────────────────────────────────────────────
	if ( $action === 'regen_db_secret' && check_admin_referer( 'pp_mt_regen_db' ) ) {
		pp_mt_generate_and_save_secret();
		update_option( PP_MT_OPTION_COPIED, 0 ); // torna alla Fase 1
		wp_safe_redirect( add_query_arg( 'pp_mt_result', 'regen_ok', $page_url ) );
		exit;
	}
}

// ─── Rate limiting ────────────────────────────────────────────────────────────

define( 'PP_MT_RL_MAX',    20 );
define( 'PP_MT_RL_WINDOW', 5 * MINUTE_IN_SECONDS );

// Limite chiamate autenticate: protezione open relay se la secret viene compromessa.
define( 'PP_MT_RL_RELAY_MAX',    100 );
define( 'PP_MT_RL_RELAY_WINDOW', 5 * MINUTE_IN_SECONDS );

// Limiti input endpoint: il relay limit conta le chiamate, non i destinatari.
define( 'PP_MT_MAX_RECIPIENTS', 10 );
define( 'PP_MT_MAX_TOKEN_LEN',  32 );

function pp_mt_rl_key(): string {
	// Nota: in ambienti con reverse proxy, REMOTE_ADDR è l'IP del proxy e tutti i client
	// condividono lo stesso contatore. X-Forwarded-For non è usato perché falsificabile.
	return 'pp_mt_rl_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
}

function pp_mt_rl_is_blocked(): bool {
	return (int) get_transient( pp_mt_rl_key() ) >= PP_MT_RL_MAX;
}

function pp_mt_rl_record_failure(): void {
	$key   = pp_mt_rl_key();
	$count = (int) get_transient( $key );
	set_transient( $key, $count + 1, PP_MT_RL_WINDOW );
}

function pp_mt_rl_reset(): void {
	delete_transient( pp_mt_rl_key() );
}

function pp_mt_relay_key(): string {
	return 'pp_mt_relay_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
}

function pp_mt_relay_is_blocked(): bool {
	return (int) get_transient( pp_mt_relay_key() ) >= PP_MT_RL_RELAY_MAX;
}

function pp_mt_relay_record(): void {
	$key   = pp_mt_relay_key();
	$count = (int) get_transient( $key );
	set_transient( $key, $count + 1, PP_MT_RL_RELAY_WINDOW );
}

// ─── REST endpoint ────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
	register_rest_route( PP_MT_REST_NS, '/check', array(
		'methods'             => 'POST',
		'callback'            => 'pp_mt_handle_check',
		'permission_callback' => 'pp_mt_auth',
		'args'                => array(
			'test_email'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Comma-separated list of recipient addresses. Defaults to admin_email.',
			),
			'pp_check_token' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => function ( $value ) {
					return substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $value ), 0, PP_MT_MAX_TOKEN_LEN );
				},
				'description'       => 'Alphanumeric per-call token for traceability.',
			),
		),
	) );
} );

function pp_mt_auth( WP_REST_Request $request ) {
	if ( pp_mt_rl_is_blocked() ) {
		return false;
	}

	$secret = pp_mt_get_secret();
	if ( ! $secret ) {
		return false;
	}

	// Legge la chiave dal body POST (priorità) o dall'header Authorization.
	// Nessun sanitizing prima di hash_equals(): il token è solo confrontato, non usato altrove.
	// is_string(): un array (pp_secret[]=…) genererebbe un warning che espone il path del server.
	$param = $request->get_param( 'pp_secret' );
	$token = is_string( $param ) ? $param : '';
	if ( ! $token ) {
		$auth = $request->get_header( 'authorization' );
		if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
			$token = substr( $auth, 7 );
		}
	}

	$valid = hash_equals( $secret, $token );
	if ( $valid ) {
		pp_mt_rl_reset();
		// Relay protection: limita le chiamate autenticate per impedire uso come open relay.
		if ( pp_mt_relay_is_blocked() ) {
			return false;
		}
		pp_mt_relay_record();
	} else {
		pp_mt_rl_record_failure();
	}
	return $valid;
}

function pp_mt_plus_token( string $email, string $token ): string {
	$at = strrpos( $email, '@' );
	if ( $at === false ) {
		return $email;
	}
	$local  = substr( $email, 0, $at );
	$domain = substr( $email, $at );
	// Rimuove un eventuale tag + già presente
	$plus = strpos( $local, '+' );
	if ( $plus !== false ) {
		$local = substr( $local, 0, $plus );
	}
	return $local . '+' . $token . $domain;
}

function pp_mt_handle_check( WP_REST_Request $request ) {
	$raw        = $request->get_param( 'test_email' ) ?? '';
	$recipients = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $raw ) ) ) );
	$recipients = array_slice( array_unique( $recipients ), 0, PP_MT_MAX_RECIPIENTS );
	if ( empty( $recipients ) ) {
		$recipients = (array) get_option( 'admin_email' );
	}

	// Token univoco per questa call — sopravvive ai plugin di template mail
	$token = $request->get_param( 'pp_check_token' ) ?? '';
	if ( $token ) {
		// Plus addressing: check+{token}@dominio.it
		$recipients = array_map( fn( $e ) => pp_mt_plus_token( $e, $token ), $recipients );
	}

	$headers = array();
	if ( $token ) {
		// Header custom: immune a qualsiasi plugin di template
		$headers[] = 'X-PP-Check-Token: ' . $token;
	}

	$subject = sprintf( __( '[PaperPlane Mail Test child site] %s — %s', 'paperplane-mail-test-child' ), get_bloginfo( 'name' ), date_i18n( 'd/m/Y H:i' ) );
	$body    = sprintf( __( 'Automatic mail function test from %s.', 'paperplane-mail-test-child' ), home_url() );
	$result  = wp_mail( $recipients, $subject, $body, $headers );

	return new WP_REST_Response( array(
		'success' => $result,
		'message' => $result ? __( 'Mail sent successfully.', 'paperplane-mail-test-child' ) : __( 'wp_mail() returned false.', 'paperplane-mail-test-child' ),
		'site'    => home_url(),
		'time'    => current_time( 'mysql' ),
	), 200 );
}

// ─── Pagina opzioni ───────────────────────────────────────────────────────────

// ─── Admin notice: chiave non ancora copiata ──────────────────────────────────

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$wpconfig_active = defined( 'PP_MAIL_TEST_SECRET' ) && PP_MAIL_TEST_SECRET;
	$pending         = get_transient( 'pp_mt_suggested_secret' );
	$stored_enc      = get_option( PP_MT_OPTION_SECRET, '' );
	$key_copied      = get_option( PP_MT_OPTION_COPIED, 0 );

	if ( ! $wpconfig_active && ! $pending && $stored_enc && ! $key_copied ) {
		$url = admin_url( 'tools.php?page=pp-mail-test' );
		echo '<div class="notice notice-error"><p>';
		printf(
			'<strong>%s</strong> %s <a href="%s">%s</a>',
			esc_html__( 'PaperPlane Mail Test:', 'paperplane-mail-test-child' ),
			esc_html__( 'the secret key has not been copied yet.', 'paperplane-mail-test-child' ),
			esc_url( $url ),
			esc_html__( 'Copy it now →', 'paperplane-mail-test-child' )
		);
		echo '</p></div>';
	}
} );

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
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'paperplane-mail-test-child' ) );
	}

	// Rigenera chiave wp-config (flusso legacy)
	if ( isset( $_GET['pp_mt_regen'] ) && check_admin_referer( 'pp_mt_regen' ) ) {
		delete_transient( 'pp_mt_suggested_secret' );
		// Se la costante è ancora in wp-config, genera una nuova chiave suggerita
		// così l'utente entra nel flusso wpconfig_setup con il nuovo valore.
		if ( defined( 'PP_MAIL_TEST_SECRET' ) && PP_MAIL_TEST_SECRET ) {
			pp_mt_get_suggested_secret();
		}
	}

	$result  = sanitize_key( $_GET['pp_mt_result'] ?? '' );
	$pending = get_transient( 'pp_mt_suggested_secret' );

	// Determina la sorgente della chiave attiva.
	$wpconfig_active = defined( 'PP_MAIL_TEST_SECRET' ) && PP_MAIL_TEST_SECRET;
	$stored_enc      = get_option( PP_MT_OPTION_SECRET, '' );

	if ( $pending ) {
		// Flusso legacy in corso: l'utente sta configurando la chiave in wp-config.php
		$source         = 'wpconfig_setup';
		$display_secret = $pending;
	} elseif ( $wpconfig_active ) {
		// Chiave attiva tramite costante wp-config.php (retrocompatibilità)
		$source         = 'wpconfig_active';
		$display_secret = '';
	} elseif ( $stored_enc ) {
		// Chiave attiva tramite wp_options (nuovo comportamento)
		$source         = 'dboption';
		$display_secret = pp_mt_decrypt_secret( $stored_enc );
	} else {
		// Prima installazione o nessuna chiave — genera automaticamente
		$display_secret = pp_mt_generate_and_save_secret();
		$source         = 'dboption';
		$result         = 'new_key';
	}

	$key_copied = (bool) get_option( PP_MT_OPTION_COPIED, 0 );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'PaperPlane Mail Test', 'paperplane-mail-test-child' ); ?></h1>
		<p><?php esc_html_e( 'This plugin exposes a REST endpoint that the PaperPlane assistance site can call to verify the mail function works correctly.', 'paperplane-mail-test-child' ); ?></p>

		<?php if ( $result === 'ok' ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Key verified and active. The value will no longer be shown.', 'paperplane-mail-test-child' ); ?></p></div>
		<?php elseif ( $result === 'not_defined' ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php printf( __( '%s is not defined in %s. Add the line shown below and try again.', 'paperplane-mail-test-child' ), '<code>PP_MAIL_TEST_SECRET</code>', '<code>wp-config.php</code>' ); ?></p></div>
		<?php elseif ( $result === 'mismatch' ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php printf( __( 'The key does not match. Make sure you copied the value shown below exactly into %s.', 'paperplane-mail-test-child' ), '<code>wp-config.php</code>' ); ?></p></div>
		<?php elseif ( $result === 'regen_ok' ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'New key generated. Copy it now — it will be hidden after you click "Copy key".', 'paperplane-mail-test-child' ); ?></p></div>
		<?php elseif ( $result === 'new_key' ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'A secret key has been generated automatically. Copy it now — it will be hidden after you click "Copy key".', 'paperplane-mail-test-child' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( '1. Secret key', 'paperplane-mail-test-child' ); ?></h2>

		<?php if ( $source === 'wpconfig_setup' ) : ?>
			<div class="notice notice-warning inline" style="border-left-color:#d63638">
				<p><strong><?php esc_html_e( 'Copy this key now — it will no longer be shown after verification.', 'paperplane-mail-test-child' ); ?></strong></p>
			</div>
			<p style="margin-top:12px">
				<code id="pp-mt-key-pending" style="font-size:1.1em"><?php echo esc_html( $display_secret ); ?></code>
				<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-pending', this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
			</p>
			<p><?php printf( __( 'Add this line to %s before %s:', 'paperplane-mail-test-child' ), '<code>wp-config.php</code>', '<code>/* That\'s all, stop editing! */</code>' ); ?></p>
			<pre style="background:#f6f7f7;padding:12px;display:inline-block">define( 'PP_MAIL_TEST_SECRET', '<?php echo esc_html( $display_secret ); ?>' );</pre>
			<p style="margin-top:16px"><?php esc_html_e( 'Once you have added the line, click the button below to verify the configuration.', 'paperplane-mail-test-child' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'pp_mt_verify' ); ?>
				<input type="hidden" name="pp_mt_action" value="pp_mt_verify">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Verify configuration', 'paperplane-mail-test-child' ); ?></button>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'pp_mt_regen', '1' ), 'pp_mt_regen' ) ); ?>"
					class="button button-secondary" style="margin-left:8px"
					onclick="return confirm('<?php echo esc_js( __( 'Generate a new key? The current key shown will be replaced.', 'paperplane-mail-test-child' ) ); ?>')"
				><?php esc_html_e( 'Generate a different key', 'paperplane-mail-test-child' ); ?></a>
			</form>

		<?php elseif ( $source === 'wpconfig_active' ) : ?>
			<div class="notice notice-success inline">
				<p>&#10003; <?php printf( __( 'Key active — %s is defined in %s.', 'paperplane-mail-test-child' ), '<code>PP_MAIL_TEST_SECRET</code>', '<code>wp-config.php</code>' ); ?></p>
			</div>
			<p style="margin-top:12px">
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'pp_mt_regen', '1' ), 'pp_mt_regen' ) ); ?>"
					class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Regenerate the secret key? You will need to update wp-config.php with the new value.', 'paperplane-mail-test-child' ) ); ?>')"
				><?php esc_html_e( 'Regenerate key', 'paperplane-mail-test-child' ); ?></a>
			</p>

		<?php elseif ( ! $key_copied ) : ?>
			<?php // ── dboption Fase 1: chiave non ancora copiata ── ?>
			<div class="notice notice-error inline">
				<p><strong><?php esc_html_e( 'Copy the key now.', 'paperplane-mail-test-child' ); ?></strong>
				<?php esc_html_e( 'Clicking "Copy key" will permanently hide it. Make sure to paste it into the main plugin before proceeding — it cannot be recovered, only regenerated.', 'paperplane-mail-test-child' ); ?></p>
			</div>
			<p style="margin-top:12px">
				<code id="pp-mt-key-val" style="font-size:1.1em;user-select:all"><?php echo esc_html( $display_secret ); ?></code>
			</p>
			<form method="post" id="pp-mt-copy-form" style="margin-top:8px">
				<?php wp_nonce_field( 'pp_mt_mark_copied' ); ?>
				<input type="hidden" name="pp_mt_action" value="mark_key_copied">
				<button type="button" class="button button-primary" id="pp-mt-copy-btn">
					<?php esc_html_e( 'Copy key', 'paperplane-mail-test-child' ); ?>
				</button>
			</form>
			<p id="pp-mt-copy-error" style="display:none;color:#d63638;margin-top:8px">
				<?php esc_html_e( 'Could not copy automatically. Copy the key manually from the field above, then click the button below.', 'paperplane-mail-test-child' ); ?>
				<br><button type="button" class="button button-secondary" style="margin-top:6px" onclick="document.getElementById('pp-mt-copy-form').submit()">
					<?php esc_html_e( 'I have copied the key', 'paperplane-mail-test-child' ); ?>
				</button>
			</p>

		<?php else : ?>
			<?php // ── dboption Fase 2: chiave copiata, nascosta definitivamente ── ?>
			<div class="notice notice-success inline">
				<p>&#10003; <?php esc_html_e( 'Key active — stored securely in the database.', 'paperplane-mail-test-child' ); ?></p>
			</div>
			<p style="margin-top:12px">
				<form method="post" style="display:inline">
					<?php wp_nonce_field( 'pp_mt_regen_db' ); ?>
					<input type="hidden" name="pp_mt_action" value="regen_db_secret">
					<button type="submit" class="button button-secondary"
						onclick="return confirm('<?php echo esc_js( __( 'Regenerate the secret key? The current key will stop working immediately — you will need to update the main plugin with the new key.', 'paperplane-mail-test-child' ) ); ?>')"
					><?php esc_html_e( 'Generate new key', 'paperplane-mail-test-child' ); ?></button>
				</form>
			</p>
			<p class="description" style="margin-top:4px">
				<?php esc_html_e( 'Generating a new key will immediately invalidate the current one — update the main plugin configuration afterwards.', 'paperplane-mail-test-child' ); ?>
			</p>

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
			<?php if ( $source === 'wpconfig_setup' ) : ?>
			<tr>
				<th><?php esc_html_e( 'Secret key', 'paperplane-mail-test-child' ); ?></th>
				<td>
					<code id="pp-mt-key-use"><?php echo esc_html( $display_secret ); ?></code>
					<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-use', this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
					<span style="color:#d63638;margin-left:8px">&#9888; <?php esc_html_e( 'Add the key to wp-config.php first', 'paperplane-mail-test-child' ); ?></span>
				</td>
			</tr>
			<?php elseif ( $source === 'dboption' && ! $key_copied ) : ?>
			<tr>
				<th><?php esc_html_e( 'Secret key', 'paperplane-mail-test-child' ); ?></th>
				<td>
					<code><?php echo esc_html( $display_secret ); ?></code>
					<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy_text(<?php echo wp_json_encode( $display_secret ); ?>, this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
				</td>
			</tr>
			<?php elseif ( $source === 'dboption' && $key_copied ) : ?>
			<tr>
				<th><?php esc_html_e( 'Secret key', 'paperplane-mail-test-child' ); ?></th>
				<td><span style="color:#999"><?php esc_html_e( 'Hidden — generate a new key if you need to reconfigure.', 'paperplane-mail-test-child' ); ?></span></td>
			</tr>
			<?php endif; ?>
		</table>

	</div>

	<script>
	function pp_mt_copy(id, btn) {
		var text = document.getElementById(id).textContent;
		navigator.clipboard.writeText(text).then(function() {
			var orig = btn.textContent;
			btn.textContent = '<?php echo esc_js( __( 'Copied!', 'paperplane-mail-test-child' ) ); ?>';
			setTimeout(function() { btn.textContent = orig; }, 2000);
		});
	}
	function pp_mt_copy_text(text, btn) {
		navigator.clipboard.writeText(text).then(function() {
			var orig = btn.textContent;
			btn.textContent = '<?php echo esc_js( __( 'Copied!', 'paperplane-mail-test-child' ) ); ?>';
			setTimeout(function() { btn.textContent = orig; }, 2000);
		});
	}
	<?php if ( $source === 'dboption' && ! $key_copied ) : ?>
	(function() {
		var btn = document.getElementById('pp-mt-copy-btn');
		if ( ! btn ) { return; }
		btn.addEventListener('click', function() {
			var key = document.getElementById('pp-mt-key-val').textContent.trim();
			navigator.clipboard.writeText(key).then(function() {
				document.getElementById('pp-mt-copy-form').submit();
			}).catch(function() {
				document.getElementById('pp-mt-copy-error').style.display = 'block';
			});
		});
	})();
	<?php endif; ?>
	</script>
	<?php
}
