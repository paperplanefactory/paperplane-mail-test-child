<?php
/**
 * Plugin Name: PaperPlane Mail Test Child
 * Description: Exposes a REST endpoint for mail function testing. Install on each monitored site.
 * Version: 1.4.11
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
	// Usa lo zip allegato alla release (cartella paperplane-mail-test-child/); se manca, ripiega sullo zip sorgente.
	$checker->getVcsApi()->enableReleaseAssets( '/^paperplane-mail-test-child\.zip$/' );
} );

// ─── Chiave segreta ───────────────────────────────────────────────────────────
// Chiave cifrata in wp_options (una per sottosito in multisite).
// La costante PP_MAIL_TEST_SECRET in wp-config.php non è più supportata (dalla 1.4.9).

function pp_mt_get_secret(): string {
	$stored = get_option( PP_MT_OPTION_SECRET, '' );
	if ( $stored ) {
		return pp_mt_decrypt_secret( $stored );
	}
	return '';
}

// Chiave salvata ma non più decifrabile: le chiavi di sicurezza di WordPress (AUTH_KEY) sono
// cambiate dopo la generazione (es. rigenerazione dei salt). Serve una nuova chiave.
function pp_mt_key_unreadable(): bool {
	$stored = get_option( PP_MT_OPTION_SECRET, '' );
	return $stored !== '' && pp_mt_decrypt_secret( $stored ) === '';
}

// ─── Azioni admin ────────────────────────────────────────────────────────────

add_action( 'admin_init', 'pp_mt_handle_actions' );

function pp_mt_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$action   = $_POST['pp_mt_action'] ?? '';
	$page_url = admin_url( 'tools.php?page=pp-mail-test' );

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

// Richieste firmate (main ≥ 1.1.10): finestra di validità del timestamp. I nonce usati restano
// memorizzati per il doppio della finestra, così una richiesta non può essere ripetuta.
define( 'PP_MT_SIG_WINDOW', 5 * MINUTE_IN_SECONDS );

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

/**
 * Testo firmato con HMAC-SHA256: deve coincidere con pp_mm_signature_payload() del main.
 */
function pp_mt_signature_payload( string $ts, string $nonce, string $token, string $test_email ): string {
	return implode( "\n", array( 'pp-mail-test-v1', $ts, $nonce, $token, $test_email ) );
}

/**
 * Verifica una richiesta firmata. Restituisce '' se valida, altrimenti il codice del rifiuto:
 * 'invalid' (firma assente o errata), 'clock' (timestamp fuori finestra), 'replay' (nonce già usato).
 * La firma è verificata per prima: solo chi conosce la chiave riceve 'clock' o 'replay'.
 */
function pp_mt_verify_signed( string $secret, array $p ): string {
	$ts         = $p['pp_ts'] ?? '';
	$nonce      = $p['pp_nonce'] ?? '';
	$sig        = $p['pp_sig'] ?? '';
	$token      = $p['pp_check_token'] ?? '';
	$test_email = $p['test_email'] ?? '';
	foreach ( array( $ts, $nonce, $sig, $token, $test_email ) as $v ) {
		if ( ! is_string( $v ) ) {
			return 'invalid';
		}
	}
	if ( ! ctype_digit( $ts ) || ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $nonce ) ) {
		return 'invalid';
	}
	$expected = hash_hmac( 'sha256', pp_mt_signature_payload( $ts, $nonce, $token, $test_email ), $secret );
	if ( ! hash_equals( $expected, $sig ) ) {
		return 'invalid';
	}
	if ( abs( time() - (int) $ts ) > PP_MT_SIG_WINDOW ) {
		return 'clock';
	}
	// Nonce monouso, registrato solo dopo una firma valida (richieste non autentiche non riempiono la cache).
	$key = 'pp_mt_nonce_' . md5( $nonce );
	if ( get_transient( $key ) ) {
		return 'replay';
	}
	set_transient( $key, 1, 2 * PP_MT_SIG_WINDOW );
	return '';
}

function pp_mt_auth( WP_REST_Request $request ) {
	$secret = pp_mt_get_secret();
	if ( ! $secret ) {
		// Errore esplicito così il main mostra la causa reale invece di un generico 401.
		// Rivela solo che la configurazione del plugin è da rifare, nessun dato sulla chiave.
		if ( pp_mt_key_unreadable() ) {
			return new WP_Error( 'pp_mt_key_unreadable', 'Secret key cannot be decrypted: generate a new key.', array( 'status' => 500 ) );
		}
		return false;
	}

	// Parametri grezzi (non sanitizzati): la firma è calcolata dal main sui valori inviati.
	$params = $request->get_json_params() ?: $request->get_body_params();

	if ( isset( $params['pp_sig'] ) ) {
		// Richiesta firmata (main ≥ 1.1.10): la chiave non viaggia, la richiesta non è ripetibile.
		$error = pp_mt_verify_signed( $secret, $params );
		if ( $error === 'clock' ) {
			return new WP_Error( 'pp_mt_clock_skew', 'Request timestamp out of range: check the server clocks.', array( 'status' => 401, 'server_time' => time() ) );
		}
		if ( $error === 'replay' ) {
			return new WP_Error( 'pp_mt_replay', 'Request already received.', array( 'status' => 401 ) );
		}
		$valid = $error === '';
	} else {
		// Legacy (main ≤ 1.1.9): chiave in chiaro nel body o nell'header Authorization.
		// Accettata solo per la transizione: da rimuovere quando tutti i main sono ≥ 1.1.10.
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
		$valid = $token !== '' && hash_equals( $secret, $token );
	}

	// Una richiesta valida passa sempre il blocco brute force: il contatore è per IP e chi condivide
	// l'IP del main (stesso server, hosting condiviso, proxy/CDN) potrebbe altrimenti bloccarlo
	// con 20 tentativi errati. La chiave (32 caratteri alfanumerici, ~190 bit) non è indovinabile per tentativi.
	if ( $valid ) {
		pp_mt_rl_reset();
		// Relay protection: limita le chiamate autenticate per impedire uso come open relay.
		if ( pp_mt_relay_is_blocked() ) {
			return false;
		}
		pp_mt_relay_record();
		return true;
	}

	if ( ! pp_mt_rl_is_blocked() ) {
		pp_mt_rl_record_failure();
	}
	return false;
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

	// La costante non è più letta: se è rimasta in wp-config.php va rimossa, e la chiave
	// usata dal main deve essere quella salvata nel database (Strumenti → PaperPlane Mail Test).
	if ( defined( 'PP_MAIL_TEST_SECRET' ) ) {
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: 1: constant name, 2: wp-config.php, 3: link to the plugin page */
			__( '<strong>PaperPlane Mail Test:</strong> %1$s in %2$s is no longer used and can be removed. The secret key is now stored in the database — check it on the %3$s page.', 'paperplane-mail-test-child' ),
			'<code>PP_MAIL_TEST_SECRET</code>',
			'<code>wp-config.php</code>',
			'<a href="' . esc_url( admin_url( 'tools.php?page=pp-mail-test' ) ) . '">' . esc_html__( 'PaperPlane Mail Test', 'paperplane-mail-test-child' ) . '</a>'
		);
		echo '</p></div>';
	}

	if ( pp_mt_key_unreadable() ) {
		echo '<div class="notice notice-error"><p>';
		printf(
			'<strong>%s</strong> %s <a href="%s">%s</a>',
			esc_html__( 'PaperPlane Mail Test:', 'paperplane-mail-test-child' ),
			esc_html__( 'the secret key can no longer be read (the WordPress security keys have changed). Mail checks are failing.', 'paperplane-mail-test-child' ),
			esc_url( admin_url( 'tools.php?page=pp-mail-test' ) ),
			esc_html__( 'Generate a new key →', 'paperplane-mail-test-child' )
		);
		echo '</p></div>';
	} elseif ( get_option( PP_MT_OPTION_SECRET, '' ) && ! get_option( PP_MT_OPTION_COPIED, 0 ) ) {
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

	$result     = sanitize_key( $_GET['pp_mt_result'] ?? '' );
	$stored_enc = get_option( PP_MT_OPTION_SECRET, '' );

	if ( $stored_enc ) {
		$display_secret = pp_mt_decrypt_secret( $stored_enc );
	} else {
		// Prima installazione o nessuna chiave — genera automaticamente
		$display_secret = pp_mt_generate_and_save_secret();
		$result         = 'new_key';
	}

	$key_copied = (bool) get_option( PP_MT_OPTION_COPIED, 0 );
	$unreadable = $stored_enc && $display_secret === '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'PaperPlane Mail Test', 'paperplane-mail-test-child' ); ?></h1>
		<p><?php esc_html_e( 'This plugin exposes a REST endpoint that the PaperPlane assistance site can call to verify the mail function works correctly.', 'paperplane-mail-test-child' ); ?></p>

		<?php if ( $result === 'regen_ok' ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'New key generated. Copy it now — it will be hidden after you click "Copy key".', 'paperplane-mail-test-child' ); ?></p></div>
		<?php elseif ( $result === 'new_key' ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'A secret key has been generated automatically. Copy it now — it will be hidden after you click "Copy key".', 'paperplane-mail-test-child' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Configuration', 'paperplane-mail-test-child' ); ?></h2>
		<?php if ( ! $key_copied ) : ?>
			<p><?php esc_html_e( 'Complete the setup with "Copy key" to secure the key, then use these details on the main site.', 'paperplane-mail-test-child' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'Use these details on the main site.', 'paperplane-mail-test-child' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Site URL', 'paperplane-mail-test-child' ); ?></h3>
		<p>
			<code id="pp-mt-url" style="font-size:1.1em;user-select:all"><?php echo esc_html( home_url() ); ?></code>
		</p>
		<p>
			<button type="button" class="button button-secondary" onclick="pp_mt_copy('pp-mt-url', this)"><?php esc_html_e( 'Copy', 'paperplane-mail-test-child' ); ?></button>
		</p>

		<h3><?php esc_html_e( 'Secret key', 'paperplane-mail-test-child' ); ?></h3>
		<?php if ( $unreadable ) : ?>
			<?php // ── Chiave non più decifrabile (salt WordPress cambiati) ── ?>
			<div class="notice notice-error inline">
				<p><strong><?php esc_html_e( 'The saved key can no longer be read.', 'paperplane-mail-test-child' ); ?></strong>
				<?php esc_html_e( 'The WordPress security keys of this site have changed since the key was generated, so mail checks are failing. Generate a new key, then update it on the main site.', 'paperplane-mail-test-child' ); ?></p>
			</div>
			<form method="post" style="margin-top:12px">
				<?php wp_nonce_field( 'pp_mt_regen_db' ); ?>
				<input type="hidden" name="pp_mt_action" value="regen_db_secret">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate new key', 'paperplane-mail-test-child' ); ?></button>
			</form>

		<?php elseif ( ! $key_copied ) : ?>
			<?php // ── Fase 1: chiave non ancora copiata ── ?>
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
			<?php // ── Fase 2: chiave copiata, nascosta definitivamente ── ?>
			<div class="notice notice-success inline">
				<p>&#10003; <?php esc_html_e( 'Key active — stored securely in the database.', 'paperplane-mail-test-child' ); ?></p>
			</div>
			<form method="post" style="margin-top:12px">
				<?php wp_nonce_field( 'pp_mt_regen_db' ); ?>
				<input type="hidden" name="pp_mt_action" value="regen_db_secret">
				<button type="submit" class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Regenerate the secret key? The current key will stop working immediately — you will need to update the main plugin with the new key.', 'paperplane-mail-test-child' ) ); ?>')"
				><?php esc_html_e( 'Generate new key', 'paperplane-mail-test-child' ); ?></button>
			</form>
			<p class="description" style="margin-top:4px">
				<?php esc_html_e( 'Generating a new key will immediately invalidate the current one — update the main plugin configuration afterwards.', 'paperplane-mail-test-child' ); ?>
			</p>
		<?php endif; ?>

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
	<?php if ( ! $key_copied ) : ?>
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
