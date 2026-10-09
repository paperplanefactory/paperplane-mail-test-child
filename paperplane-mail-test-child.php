<?php
/**
 * Plugin Name: PaperPlane Mail Test Child
 * Description: Espone un endpoint REST per il test della funzione mail. Da installare sui siti monitorati.
 * Version: 1.2.0
 * Author: Paper Plane Factory
 * Update URI: https://github.com/paperplanefactory/paperplane-mail-test-child/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PP_MT_REST_NS', 'pp-mail-test/v1' );

// ─── Auto-aggiornamenti da GitHub ─────────────────────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';

add_action( 'init', function () {
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
	$token = '';
	if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
		$token = substr( $auth, 7 );
	}
	return hash_equals( $secret, $token );
}

function pp_mt_handle_check( WP_REST_Request $request ) {
	$test_email = sanitize_email( $request->get_param( 'test_email' ) ?? '' );
	if ( ! is_email( $test_email ) ) {
		$test_email = get_option( 'admin_email' );
	}

	$subject = '[Mail Test] ' . get_bloginfo( 'name' ) . ' — ' . date_i18n( 'd/m/Y H:i' );
	$body    = 'Test automatico della funzione mail da ' . home_url() . '.';
	$result  = wp_mail( $test_email, $subject, $body );

	return new WP_REST_Response( array(
		'success' => $result,
		'message' => $result ? 'Mail inviata correttamente.' : 'wp_mail() ha restituito false.',
		'site'    => home_url(),
		'time'    => current_time( 'mysql' ),
	), 200 );
}

// ─── Pagina opzioni ───────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_management_page(
		'PaperPlane Mail Test',
		'PaperPlane Mail Test',
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
		<h1>Paperplane Mail Test</h1>
		<p>Questo plugin espone un endpoint REST che il sito assistenza Paperplane può chiamare per verificare che la funzione mail funzioni correttamente.</p>

		<h2>1. Chiave segreta</h2>

		<?php if ( $secret_active ) : ?>
			<div class="notice notice-success inline"><p>&#10003; Chiave attiva — <code>PP_MAIL_TEST_SECRET</code> è definita in <code>wp-config.php</code>.</p></div>
			<p style="margin-top:12px">
				<strong>Chiave attiva:</strong>
				<code id="pp-mt-key-active"><?php echo esc_html( $secret_active ); ?></code>
				<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-active', this)">Copia</button>
			</p>
		<?php else : ?>
			<div class="notice notice-warning inline">
				<p>&#9888; <code>PP_MAIL_TEST_SECRET</code> non è ancora definita in <code>wp-config.php</code>. Il plugin non risponderà alle richieste finché non la aggiungi.</p>
			</div>
			<p style="margin-top:12px"><strong>Chiave suggerita</strong> (copiala e incollala in <code>wp-config.php</code>):</p>
			<p>
				<code id="pp-mt-key-suggested"><?php echo esc_html( $secret_suggested ); ?></code>
				<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-suggested', this)">Copia</button>
				<a href="<?php echo esc_url( add_query_arg( 'pp_mt_regen', '1' ) ); ?>" class="button button-secondary" style="margin-left:4px"
					onclick="return confirm('Generare una nuova chiave suggerita?')">Rigenera</a>
			</p>
			<p>Aggiungi questa riga in <code>wp-config.php</code> prima di <code>/* That's all */</code>:</p>
			<pre style="background:#f6f7f7;padding:12px;display:inline-block">define( 'PP_MAIL_TEST_SECRET', '<?php echo esc_html( $secret_suggested ); ?>' );</pre>
		<?php endif; ?>

		<h2 style="margin-top:2em">2. Dati per il sito assistenza</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th>URL sito</th>
				<td>
					<code id="pp-mt-url"><?php echo esc_html( home_url() ); ?></code>
					<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-url', this)">Copia</button>
				</td>
			</tr>
			<tr>
				<th>Chiave da usare</th>
				<td>
					<?php $key_to_use = $secret_active ?: $secret_suggested; ?>
					<code id="pp-mt-key-use"><?php echo esc_html( $key_to_use ); ?></code>
					<button type="button" class="button button-secondary" style="margin-left:8px" onclick="pp_mt_copy('pp-mt-key-use', this)">Copia</button>
					<?php if ( ! $secret_active ) : ?>
						<span style="color:#d63638;margin-left:8px">&#9888; Aggiungi prima la chiave in wp-config.php</span>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:2em">3. Configurazione server (.htaccess)</h2>
		<p>Su hosting con Apache + PHP-FPM (configurazione comune), l'header <code>Authorization</code> viene bloccato da Apache e non raggiunge PHP. In questo caso il monitor riceve <strong>HTTP 401</strong> anche se la chiave è corretta.</p>
		<p>Per risolvere, aggiungi questa riga nel <code>.htaccess</code> del sito, subito dopo <code>RewriteEngine On</code>:</p>
		<pre style="background:#f6f7f7;padding:12px;display:inline-block">SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</pre>
		<p class="description">Su Nginx, aggiungi invece <code>fastcgi_pass_header Authorization;</code> nel blocco server.</p>
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
