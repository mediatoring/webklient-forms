<?php
/**
 * Plugin Name:       Webklient Forms
 * Description:       Univerzální náhrada WPForms Pro pro weby Webklient.cz. Čtyři vestavěné formuláře – kontakt, poptávka služeb, kariéra a obecná poptávka – vkládané shortcodem [wk_form type="..."]. Záznam odeslání, e-mailové notifikace, HTML automatická odpověď s WYSIWYG editorem, Cloudflare Turnstile, kontrolní otázka, honeypot, přesměrování na děkovací stránku a nastavitelný styl tlačítka.
 * Version:           2.4.2
 * Plugin URI:        https://github.com/mediatoring/webklient-forms
 * Author:            Webklient.cz
 * Author URI:        https://www.webklient.cz
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Update URI:        false
 * Text Domain:       webklient-forms
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Pojistka proti dvojí kopii pluginu. Archiv z GitHubu („Download ZIP“) má
 * složku webklient-forms-main, která se nainstaluje jako samostatný plugin
 * vedle původní složky webklient-forms. Druhé načtení téže třídy PHP shodí
 * („Cannot redeclare class“) a WordPress ohlásí jen závažnou chybu. Druhá
 * kopie se proto raději ohlásí hláškou a skončí. Rozlišuje se podle konstanty,
 * ne podle třídy – třídu si PHP při kompilaci souboru zaregistruje ještě před
 * vykonáním těchto řádků, takže class_exists() by hlásil i první kopii.
 */
if ( defined( 'WKF_VERSION' ) ) {
	if ( ! function_exists( 'wkf_duplicate_copy_notice' ) ) {
		function wkf_duplicate_copy_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>Webklient Forms:</strong> na webu jsou dvě kopie pluginu ve dvou složkách – typicky složka rozbalená z GitHubu (<code>webklient-forms-main</code>) vedle původní instalace. Běží jen jedna, druhá nic nedělá. V přehledu Pluginy tu navíc deaktivujte a smažte – správná složka se jmenuje <code>webklient-forms</code>.</p></div>';
		}
	}
	add_action( 'admin_notices', 'wkf_duplicate_copy_notice' );
	return;
}

define( 'WKF_VERSION', '2.4.2' );
define( 'WKF_PLUGIN_FILE', __FILE__ );
define( 'WKF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WKF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Zdroj automatických aktualizací – veřejný repozitář pluginu, není nastavitelný.
define( 'WKF_UPDATE_REPO', 'mediatoring/webklient-forms' );

final class Webklient_Forms {

	const OPTION_KEY   = 'wkf_settings';
	const CPT_ENTRY    = 'wkf_entry';
	const CPT_FORM     = 'wkf_form';
	const NONCE_ACTION = 'wkf_submit_form';

	/** Příznak, že právě odesílá e-mail tento plugin (pro režim SMTP „jen formuláře"). */
	private $sending_form_mail = false;

	/** Důvod posledního selhání wp_mail (z hooku wp_mail_failed). */
	private $last_mail_error = '';

	/** S čím PHPMailer skutečně odesílal – pro diagnostiku testovacího e-mailu. */
	private $last_mail_debug = '';

	/** Soubory nahrané v právě zpracovávaném odeslání (pro přílohy notifikace). */
	private $current_uploads = array();

	/** Cesta návštěvníka v právě zpracovávaném odeslání (notifikace, webhook). */
	private $current_journey = array();

	/** Povolené přípony a MIME typy nahrávaných souborů (životopisy). */
	const ALLOWED_MIMES = array(
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	const MAX_FILE_SIZE = 10485760; // 10 MB

	private static $instance = null;

	/** Plain-text alternativa pro právě odesílaný HTML e-mail. */
	private $alt_body = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_entry_cpt' ) );
		add_action( 'init', array( $this, 'register_custom_form_cpt' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'register_legacy_shortcode' ), 99 );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_journey' ), 5 );

		add_action( 'wp_ajax_wkf_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_wkf_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_wkf_suggest', array( $this, 'handle_suggest' ) );
		add_action( 'wp_ajax_nopriv_wkf_suggest', array( $this, 'handle_suggest' ) );

		// Administrace.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'manage_' . self::CPT_ENTRY . '_posts_columns', array( $this, 'entry_columns' ) );
		add_action( 'manage_' . self::CPT_ENTRY . '_posts_custom_column', array( $this, 'entry_column_content' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'entry_meta_box' ) );
		add_action( 'add_meta_boxes', array( $this, 'custom_form_meta_boxes' ) );
		add_action( 'save_post_' . self::CPT_FORM, array( $this, 'save_custom_form' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'seed_buttons_notice' ) );
		add_action( 'admin_post_wkf_seed', array( $this, 'handle_seed' ) );
		add_filter( 'manage_' . self::CPT_FORM . '_posts_columns', array( $this, 'form_columns' ) );
		add_action( 'manage_' . self::CPT_FORM . '_posts_custom_column', array( $this, 'form_column_content' ), 10, 2 );
		add_action( 'wp_ajax_wkf_preview_form', array( $this, 'preview_custom_form' ) );
		add_action( 'wp_ajax_wkf_cond_options', array( $this, 'cond_options_ajax' ) );
		add_action( 'phpmailer_init', array( $this, 'setup_smtp' ), 20 );
		// Záměrně úplně poslední v řadě: zaznamená i to, co po nás přepsal jiný plugin.
		add_action( 'phpmailer_init', array( $this, 'capture_mail_debug' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );
		add_action( 'wkf_webhook_retry', array( $this, 'webhook_dispatch' ) );
		add_action( 'wkf_daily_retention', array( $this, 'run_retention' ) );
		add_action( 'init', array( $this, 'schedule_retention' ) );
		add_action( 'admin_post_wkf_webhook_resend', array( $this, 'webhook_resend' ) );
		add_action( 'admin_post_wkf_smtp_test', array( $this, 'smtp_test' ) );
		add_action( 'admin_post_wkf_export_form', array( $this, 'export_form_json' ) );
		add_action( 'admin_post_wkf_export_entries', array( $this, 'export_entries' ) );
		add_action( 'restrict_manage_posts', array( $this, 'entries_filter_dropdown' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'save_post', array( $this, 'flush_usage_cache' ), 5 );
		add_action( 'pre_get_posts', array( $this, 'entries_filter_query' ) );
		add_action( 'admin_post_wkf_import_form', array( $this, 'import_form_json' ) );
		add_action( 'admin_post_wkf_import_wpforms', array( $this, 'import_wpforms' ) );
		add_action( 'wp_ajax_wkf_migrate_batch', array( $this, 'migrate_entries_batch' ) );
		// Automatické aktualizace z GitHubu.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'update_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_update_folder' ), 10, 4 );
		add_action( 'admin_post_wkf_check_update', array( $this, 'force_update_check' ) );
		add_action( 'admin_notices', array( $this, 'research_notice' ) );
		add_action( 'wp_ajax_wkf_dismiss_research', array( $this, 'dismiss_research_notice' ) );
		add_filter( 'post_row_actions', array( $this, 'form_row_actions' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'restrict_manage_posts', array( $this, 'entry_filter_dropdown' ) );
		add_filter( 'pre_get_posts', array( $this, 'entry_filter_query' ) );
	}

	/* =========================================================
	 * Definice formulářů (presety)
	 * =======================================================
	 *
	 * Typy polí: text | email | tel | textarea | select_dynamic |
	 *            checkbox_dynamic | checkbox_static | file
	 *
	 * Klíč pole slouží zároveň jako zástupná značka {klic}
	 * v automatické odpovědi.
	 */

	public function form_schemas() {
		$schemas = $this->build_base_schemas();

		// Aplikace matice viditelnosti a povinnosti z nastavení.
		foreach ( $schemas as $type => $schema ) {
			$fields = array();
			foreach ( $schema['fields'] as $field ) {
				$state = $this->field_state( $type, $field );
				if ( ! $state['enabled'] ) {
					continue;
				}
				$field['required'] = $state['required'];
				$fields[]          = $field;
			}
			$schemas[ $type ]['fields'] = $fields;
		}

		// Vlastní formuláře z builderu (matice presetů se na ně nevztahuje).
		$schemas = array_merge( $schemas, $this->custom_form_schemas() );

		/**
		 * Umožňuje webu upravit či doplnit definice formulářů
		 * (např. v site-specific pluginu nebo child theme).
		 */
		return apply_filters( 'wkf_form_schemas', $schemas );
	}

	/**
	 * Výsledný stav pole (zobrazit/povinné): přepis z matice v nastavení,
	 * jinak výchozí hodnota presetu. E-mail je vždy zobrazený a povinný,
	 * protože na něm stojí notifikace, Reply-To i automatická odpověď.
	 */
	private function field_state( $type, $field ) {
		$s   = $this->get_settings();
		$key = $field['key'];

		if ( 'email' === $key ) {
			return array( 'enabled' => true, 'required' => true );
		}

		$default_enabled  = true;
		$default_required = ! empty( $field['required'] );
		if ( 'sluzby' === $type && 'adresa' === $key ) {
			$default_enabled  = (bool) $s['sluzby_adresa_enabled'];
			$default_required = (bool) $s['sluzby_adresa_required'];
		}

		if ( isset( $s['field_overrides'][ $type ][ $key ] ) ) {
			$override = $s['field_overrides'][ $type ][ $key ];
			return array(
				'enabled'  => ! empty( $override['enabled'] ),
				'required' => ! empty( $override['required'] ),
			);
		}

		return array( 'enabled' => $default_enabled, 'required' => $default_required );
	}

	/** Základní presety (před aplikací matice viditelnosti/povinnosti). */
	private function build_base_schemas() {
		$s = $this->get_settings();

		$schemas = array(
			'kontakt'  => array(
				'name'   => 'Kontaktní formulář',
				'button' => 'Odeslat',
				'fields' => array(
					array( 'key' => 'jmeno',   'type' => 'text',     'label' => 'Vaše jméno',   'required' => true ),
					array( 'key' => 'email',   'type' => 'email',    'label' => 'E-mail',       'required' => true, 'half' => true ),
					array( 'key' => 'telefon', 'type' => 'tel',      'label' => 'Telefon',      'required' => false, 'half' => true ),
					array( 'key' => 'sluzba',  'type' => 'select_dynamic', 'label' => 'Typ služby', 'required' => false,
						'placeholder' => '– typ služby –',
						'source' => 'taxonomy', 'source_slug' => $s['kontakt_taxonomy'] ),
					array( 'key' => 'zprava',  'type' => 'textarea', 'label' => 'Vaše zpráva',  'required' => true, 'rows' => 6 ),
				),
			),
			'sluzby'   => array(
				'name'   => 'Poptávka služeb',
				'button' => 'Odeslat nezávaznou poptávku',
				'fields' => array(
					array( 'key' => 'jmeno',   'type' => 'text',  'label' => 'Vaše jméno / firma', 'required' => true ),
					array( 'key' => 'email',   'type' => 'email', 'label' => 'E-mail',  'required' => true, 'half' => true ),
					array( 'key' => 'telefon', 'type' => 'tel',   'label' => 'Telefon', 'required' => true, 'half' => true ),
					array( 'key' => 'sluzba',  'type' => 'select_dynamic', 'label' => 'Mám zájem o službu', 'required' => true,
						'placeholder' => '– vyberte si, o jakou službu máte zájem –',
						'source' => $s['sluzby_source'], 'source_slug' => $s['sluzby_slug'] ),
					array( 'key' => 'adresa',  'type' => 'textarea', 'label' => 'Přesná adresa realizace', 'required' => (bool) $s['sluzby_adresa_required'], 'rows' => 3, 'suggest' => 'address' ),
					array( 'key' => 'zprava',  'type' => 'textarea', 'label' => 'Podrobnosti poptávky (rozměry, termín, představa o rozpočtu atd.)', 'required' => true, 'rows' => 6 ),
				),
			),
			'kariera'  => array(
				'name'   => 'Kariéra',
				'button' => 'Odeslat',
				'fields' => array(
					array( 'key' => 'jmeno',   'type' => 'text',  'label' => 'Vaše jméno', 'required' => true ),
					array( 'key' => 'email',   'type' => 'email', 'label' => 'Emailová adresa', 'required' => true, 'half' => true ),
					array( 'key' => 'telefon', 'type' => 'tel',   'label' => 'Telefon', 'required' => false, 'half' => true ),
					array( 'key' => 'pozice',  'type' => 'checkbox_dynamic', 'label' => 'Pozice, o kterou máte zájem', 'required' => true,
						'source' => 'post_type', 'source_slug' => $s['kariera_post_type'],
						'empty_text' => 'Aktuálně nejsou vypsány žádné volné pozice.' ),
					array( 'key' => 'soubor',  'type' => 'file', 'label' => 'Životopis, motivační dopis…', 'required' => true,
						'hint' => 'Nahrajte Váš životopis, max. 10 MB (podporováno: .doc, .docx, .odt, .pdf, .jpg, .png a .jpeg)' ),
					array( 'key' => 'zprava',  'type' => 'textarea', 'label' => 'Proč u nás chcete pracovat?', 'required' => false, 'rows' => 6 ),
				),
			),
			'poptavka' => array(
				'name'   => 'Poptávka',
				'button' => 'Odeslat',
				'fields' => array(
					array( 'key' => 'jmeno',   'type' => 'text',  'label' => 'Vaše jméno/firma/organizace', 'required' => false ),
					array( 'key' => 'email',   'type' => 'email', 'label' => 'E-mail',  'required' => true, 'half' => true ),
					array( 'key' => 'telefon', 'type' => 'tel',   'label' => 'Telefon', 'required' => false, 'half' => true ),
					array( 'key' => 'zprava',  'type' => 'textarea', 'label' => 'Váš vzkaz, dotaz', 'required' => false, 'rows' => 6 ),
					array( 'key' => 'zajem',   'type' => 'checkbox_static', 'label' => 'Zajímám se o', 'required' => false, 'inline' => true,
						'options' => $this->interests_options() ),
				),
			),
		);

		// Volitelné pole IČO s našeptáváním z ARES – vkládá se za telefon do všech formulářů.
		if ( $s['ico_enabled'] ) {
			$ico_field = array(
				'key'      => 'ico',
				'type'     => 'text',
				'label'    => 'IČO',
				'required' => false,
				'suggest'  => 'ares',
				'hint'     => 'Začněte psát IČO nebo název firmy – údaje doplníme z registru ARES.',
			);
			foreach ( $schemas as $type => $schema ) {
				$position = count( $schema['fields'] );
				foreach ( $schema['fields'] as $index => $field ) {
					if ( 'telefon' === $field['key'] ) {
						$position = $index + 1;
						break;
					}
				}
				array_splice( $schemas[ $type ]['fields'], $position, 0, array( $ico_field ) );
			}
		}

		return $schemas;
	}

	/** Volby „Zajímám se o" ze settings (jedna volba na řádek). */
	private function interests_options() {
		$s     = $this->get_settings();
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) $s['poptavka_interests'] ) ) );
		return array_values( $lines );
	}

	/* =========================================================
	 * Nastavení
	 * ======================================================= */

	public function get_settings() {
		$defaults = array(
			// Ochrana proti spamu.
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'antispam_enabled'     => 0,
			'antispam_question'    => 'Prosíme napište první tři písmena abecedy malými písmeny. Děkujeme.',
			'antispam_answer'      => 'abc',
			// Zdroje dat presetů.
			'kontakt_taxonomy'     => '',
			'sluzby_source'        => 'taxonomy',
			'sluzby_slug'          => 'category',
			'kariera_post_type'    => 'job',
			'poptavka_interests'   => "Bezplatné předvedení\nNový stroj\nRepasovaný (bazar)\nPronájem\nServis nebo náhradní díly\nZápůjčka",
			'exclude_default_cat'  => 1,
			// Adresa realizace v poptávce služeb.
			'sluzby_adresa_enabled'  => 1,
			'sluzby_adresa_required' => 0,
			// Per-pole přepisy viditelnosti a povinnosti (matice v nastavení).
			'field_overrides'      => array(),
			// Volitelné pole IČO s našeptáváním z ARES.
			'ico_enabled'          => 0,
			// Našeptávání adres (Mapy.cz Suggest API, klíč z developer.mapy.com).
			'mapy_api_key'         => '',
			// Nenápadný podpis pod formulářem (odkaz na tvůrce webu).
			'credit'            => 0,
			// Sledování cesty návštěvníka k poptávce (vstupní stránka, zdroj, kroky).
			'journey'           => 0,
			'journey_steps'     => 30,
			'journey_consent'   => '',
			// Limit celkové velikosti příloh notifikace (MB); nad ním jdou jen odkazy.
			'attach_limit_mb' => 15,
			// Lead API / webhook: každé odeslání se pošle jako JSON POST.
			'webhook_url'    => '',
			'webhook_header' => 'X-Lead-Key',
			'webhook_key'    => '',
			// Vlastní SMTP odesílání ('' = vypnuto, 'forms' = jen e-maily formulářů, 'all' = celý web).
			'smtp_mode'            => '',
			'smtp_host'            => '',
			'smtp_port'            => 587,
			'smtp_secure'          => 'tls',
			'smtp_user'            => '',
			'smtp_pass'            => '',
			// Ověřování odeslaných adres v RÚIAN (ruian.fnx.io, klíč zdarma e-mailem).
			'fnx_api_key'          => '',
			// Odesílatel.
			'from_name'            => get_bloginfo( 'name' ),
			'from_email'           => get_option( 'admin_email' ),
			// Tlačítko.
			'btn_bg'               => '#960000',
			'btn_bg_hover'         => '#650303',
			'btn_color'            => '#ffffff',
			// Kompatibilita se shortcody WPForms ([wpforms id="..."]).
			'legacy_map'           => '',
		);

		// Per-form volby.
		foreach ( array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ) as $type ) {
			$defaults[ 'recipient_' . $type ] = get_option( 'admin_email' );
			$defaults[ 'thankyou_' . $type ]  = 0;
			$defaults[ 'autoreply_' . $type . '_enabled' ] = 0;
			$defaults[ 'autoreply_' . $type . '_subject' ] = 'Potvrzení – vaše zpráva byla odeslána | ' . get_bloginfo( 'name' );
			$defaults[ 'autoreply_' . $type . '_body' ]    = "Dobrý den,\nvaše zpráva byla úspěšně odeslána. Co nejdříve se vám ozveme.\nS pozdravem\n" . get_bloginfo( 'name' );
		}

		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public function register_settings() {
		register_setting(
			'wkf_settings_group',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	public function sanitize_settings( $input ) {
		$out = $this->get_settings();
		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['turnstile_site_key']   = isset( $input['turnstile_site_key'] ) ? sanitize_text_field( $input['turnstile_site_key'] ) : '';
		$out['turnstile_secret_key'] = isset( $input['turnstile_secret_key'] ) ? sanitize_text_field( $input['turnstile_secret_key'] ) : '';
		$out['antispam_enabled']     = empty( $input['antispam_enabled'] ) ? 0 : 1;
		$out['antispam_question']    = isset( $input['antispam_question'] ) ? sanitize_text_field( $input['antispam_question'] ) : '';
		$out['antispam_answer']      = isset( $input['antispam_answer'] ) ? sanitize_text_field( $input['antispam_answer'] ) : '';

		$out['kontakt_taxonomy']     = isset( $input['kontakt_taxonomy'] ) ? sanitize_key( $input['kontakt_taxonomy'] ) : '';
		$out['sluzby_source']        = ( isset( $input['sluzby_source'] ) && 'post_type' === $input['sluzby_source'] ) ? 'post_type' : 'taxonomy';
		$out['sluzby_slug']          = isset( $input['sluzby_slug'] ) ? sanitize_key( $input['sluzby_slug'] ) : 'category';
		$out['kariera_post_type']    = isset( $input['kariera_post_type'] ) ? sanitize_key( $input['kariera_post_type'] ) : 'job';
		$out['poptavka_interests']   = isset( $input['poptavka_interests'] ) ? sanitize_textarea_field( $input['poptavka_interests'] ) : '';
		$out['exclude_default_cat']  = empty( $input['exclude_default_cat'] ) ? 0 : 1;
		$out['sluzby_adresa_enabled']  = empty( $input['sluzby_adresa_enabled'] ) ? 0 : 1;
		$out['sluzby_adresa_required'] = empty( $input['sluzby_adresa_required'] ) ? 0 : 1;

		// Matice polí: viditelnost a povinnost per formulář a pole.
		$overrides = array();
		if ( isset( $input['field_overrides'] ) && is_array( $input['field_overrides'] ) ) {
			foreach ( $input['field_overrides'] as $type => $fields ) {
				$type = sanitize_key( $type );
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $key => $flags ) {
					$key = sanitize_key( $key );
					$overrides[ $type ][ $key ] = array(
						'enabled'  => empty( $flags['enabled'] ) ? 0 : 1,
						'required' => empty( $flags['required'] ) ? 0 : 1,
					);
				}
			}
		}
		$out['field_overrides'] = $overrides;
		$out['ico_enabled']          = empty( $input['ico_enabled'] ) ? 0 : 1;
		$out['mapy_api_key']         = isset( $input['mapy_api_key'] ) ? sanitize_text_field( $input['mapy_api_key'] ) : '';
		$out['fnx_api_key']          = isset( $input['fnx_api_key'] ) ? sanitize_text_field( $input['fnx_api_key'] ) : '';
		$out['credit']          = empty( $input['credit'] ) ? 0 : 1;
		$out['journey']         = empty( $input['journey'] ) ? 0 : 1;
		$out['journey_steps']   = isset( $input['journey_steps'] ) ? max( 5, min( 100, absint( $input['journey_steps'] ) ) ) : 30;
		$out['journey_consent'] = isset( $input['journey_consent'] ) ? sanitize_text_field( $input['journey_consent'] ) : '';
		$out['attach_limit_mb'] = isset( $input['attach_limit_mb'] ) ? max( 1, min( 50, absint( $input['attach_limit_mb'] ) ) ) : 15;
		$out['webhook_url']    = isset( $input['webhook_url'] ) ? esc_url_raw( trim( (string) $input['webhook_url'] ) ) : '';
		$out['webhook_header'] = isset( $input['webhook_header'] ) && preg_match( '/^[A-Za-z0-9\-]{1,64}$/', (string) $input['webhook_header'] ) ? $input['webhook_header'] : 'X-Lead-Key';
		if ( isset( $input['webhook_key'] ) && '' !== $input['webhook_key'] ) {
			$out['webhook_key'] = $this->encrypt_secret( sanitize_text_field( $input['webhook_key'] ) );
		} else {
			$existing_wh        = get_option( self::OPTION_KEY, array() );
			$out['webhook_key'] = isset( $existing_wh['webhook_key'] ) ? $existing_wh['webhook_key'] : '';
		}
		$out['smtp_mode']   = isset( $input['smtp_mode'] ) && in_array( $input['smtp_mode'], array( 'forms', 'all' ), true ) ? $input['smtp_mode'] : '';
		$out['smtp_host']   = isset( $input['smtp_host'] ) ? sanitize_text_field( $input['smtp_host'] ) : '';
		$out['smtp_port']   = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : 587;
		$out['smtp_secure'] = isset( $input['smtp_secure'] ) && in_array( $input['smtp_secure'], array( '', 'tls', 'ssl' ), true ) ? $input['smtp_secure'] : 'tls';
		$out['smtp_user']   = isset( $input['smtp_user'] ) ? sanitize_text_field( $input['smtp_user'] ) : '';
		// Heslo: prázdné pole = ponechat uložené; vyplněné se šifruje.
		$existing = get_option( self::OPTION_KEY, array() );
		if ( isset( $input['smtp_pass'] ) && '' !== $input['smtp_pass'] ) {
			$out['smtp_pass'] = $this->encrypt_secret( $input['smtp_pass'] );
		} else {
			$out['smtp_pass'] = isset( $existing['smtp_pass'] ) ? $existing['smtp_pass'] : '';
		}

		$out['from_name']            = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
		$out['from_email']           = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '';

		$out['btn_bg']               = isset( $input['btn_bg'] ) ? $this->sanitize_hex( $input['btn_bg'], '#960000' ) : '#960000';
		$out['btn_bg_hover']         = isset( $input['btn_bg_hover'] ) ? $this->sanitize_hex( $input['btn_bg_hover'], '#650303' ) : '#650303';
		$out['btn_color']            = isset( $input['btn_color'] ) ? $this->sanitize_hex( $input['btn_color'], '#ffffff' ) : '#ffffff';
		$out['legacy_map']           = isset( $input['legacy_map'] ) ? sanitize_textarea_field( $input['legacy_map'] ) : '';

		foreach ( array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ) as $type ) {
			$out[ 'recipient_' . $type ] = isset( $input[ 'recipient_' . $type ] ) ? $this->sanitize_email_list( $input[ 'recipient_' . $type ] ) : '';
			$out[ 'thankyou_' . $type ]  = isset( $input[ 'thankyou_' . $type ] ) ? absint( $input[ 'thankyou_' . $type ] ) : 0;
			$out[ 'autoreply_' . $type . '_enabled' ] = empty( $input[ 'autoreply_' . $type . '_enabled' ] ) ? 0 : 1;
			$out[ 'autoreply_' . $type . '_subject' ] = isset( $input[ 'autoreply_' . $type . '_subject' ] ) ? sanitize_text_field( $input[ 'autoreply_' . $type . '_subject' ] ) : '';
			$out[ 'autoreply_' . $type . '_body' ]    = isset( $input[ 'autoreply_' . $type . '_body' ] ) ? wp_kses_post( $input[ 'autoreply_' . $type . '_body' ] ) : '';
		}

		return $out;
	}

	/** Validace hex barvy s výchozí hodnotou. */
	private function sanitize_hex( $value, $default ) {
		$value = trim( (string) $value );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : $default;
	}

	/** Umožní více příjemců oddělených čárkou. */
	private function sanitize_email_list( $value ) {
		// Oddělovačem může být čárka, středník i nový řádek (lidé píšou různě).
		$parts  = preg_split( '/[,;\r\n]+/', (string) $value );
		$emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', (array) $parts ) ) );
		return implode( ', ', array_unique( $emails ) );
	}

	/** Seznam adres jako pole – wp_mail je tak předá všem příjemcům. */
	private function email_list_to_array( $value ) {
		$parts = preg_split( '/[,;\r\n]+/', (string) $value );
		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'is_email' ) );
	}

	/* =========================================================
	 * CPT pro záznamy odeslání
	 * ======================================================= */

	/* =========================================================
	 * Vlastní formuláře (builder)
	 * ======================================================= */

	public function register_custom_form_cpt() {
		register_post_type(
			self::CPT_FORM,
			array(
				'labels'          => array(
					'name'          => 'Formuláře',
					'singular_name' => 'Formulář',
					'add_new'       => 'Přidat formulář',
					'add_new_item'  => 'Nový formulář',
					'edit_item'     => 'Upravit formulář',
					'search_items'  => 'Hledat formuláře',
					'not_found'     => 'Žádné formuláře.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/** Typy polí dostupné v builderu. */
	private function builder_field_types() {
		return array(
			'heading'  => 'Nadpis sekce',
			'text'     => 'Text',
			'email'    => 'E-mail',
			'tel'      => 'Telefon',
			'textarea' => 'Víceřádkový text',
			'adresa'   => 'Adresa',
			'ico'      => 'IČO',
			'select'   => 'Rozbalovací nabídka',
			'checkbox' => 'Zaškrtávací pole',
			'number'   => 'Číslo',
			'date'     => 'Datum',
			'url'      => 'Webová adresa (URL)',
			'hidden'   => 'Skryté pole',
			'content'  => 'Obsahový blok (HTML, obrázek)',
			'pagebreak' => 'Zlom kroku (vícekrokový formulář)',
			'file'     => 'Nahrání souboru',
			'vop'      => 'Souhlas s podmínkami (VOP)',
		);
	}

	/** Přípony povolené pro pole souboru (jpg zahrnuje i jpeg). */
	private function builder_file_types() {
		return array( 'doc', 'docx', 'odt', 'pdf', 'jpg', 'png' );
	}

	/**
	 * Volby s cenou: řádek „Text | 1500" se rozdělí na popisek a cenu.
	 * Vrací options (čisté popisky) a prices (mapa popisek => cena).
	 */
	private function parse_priced_options( $options ) {
		$out = array( 'options' => array(), 'prices' => array() );
		foreach ( $options as $line ) {
			if ( false !== strpos( $line, '|' ) ) {
				list( $label, $price ) = array_map( 'trim', explode( '|', $line, 2 ) );
				$price = str_replace( array( ' ', "\xc2\xa0" ), '', $price );
				$price = (float) str_replace( ',', '.', $price );
				if ( '' !== $label ) {
					$out['options'][]          = $label;
					if ( $price > 0 ) {
						$out['prices'][ $label ] = $price;
					}
				}
			} elseif ( '' !== $line ) {
				$out['options'][] = $line;
			}
		}
		return $out;
	}

	/**
	 * Ceny z meta pole příspěvků daného post typu (mapa titulek => cena).
	 * Umožňuje „košík" nad vlastními post typy – např. knihy s meta polem cena.
	 */
	private function dynamic_prices( $post_type, $meta_key ) {
		$prices = array();
		$posts  = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$raw = get_post_meta( $post->ID, $meta_key, true );
			if ( '' === $raw || null === $raw ) {
				continue;
			}
			$price = (float) str_replace( ',', '.', preg_replace( '/[^\d,.]/', '', (string) $raw ) );
			if ( $price > 0 ) {
				$prices[ $post->post_title ] = $price;
			}
		}
		return $prices;
	}

	/** Formát ceny: 12 500 Kč (celé), 1 250,50 Kč (desetinné). */
	private function format_price( $amount ) {
		$decimals = fmod( $amount, 1 ) > 0.004 ? 2 : 0;
		return number_format( $amount, $decimals, ',', ' ' ) . ' Kč';
	}

	/** Rozparsuje dynamický zdroj z voleb („taxonomy:category" / „post_type:job"). */
	private function parse_dynamic_source( $options_text ) {
		$first = trim( strtok( (string) $options_text, "\n" ) );
		if ( preg_match( '/^(taxonomy|post_type)\s*:\s*([a-z0-9_-]+)$/i', $first, $m ) ) {
			return array( 'source' => strtolower( $m[1] ), 'source_slug' => sanitize_key( $m[2] ) );
		}
		return array( 'source' => 'taxonomy', 'source_slug' => '' );
	}

	/** Šablony pro tlačítka „Nový …" – předvyplní vlastní formulář poli presetu. */
	private function seed_definitions() {
		return array(
			'kontaktni' => array(
				'title'  => 'Kontaktní formulář',
				'slug'   => 'kontakt',
				'button' => 'Odeslat',
				'rows'   => array(
					array( 'type' => 'text', 'label' => 'Vaše jméno', 'required' => 1 ),
					array( 'type' => 'email', 'label' => 'E-mail', 'required' => 1, 'half' => 1 ),
					array( 'type' => 'tel', 'label' => 'Telefon', 'half' => 1 ),
					array( 'type' => 'textarea', 'label' => 'Vaše zpráva', 'required' => 1 ),
				),
			),
			'poptavka-sluzeb' => array(
				'title'  => 'Poptávka služeb',
				'slug'   => 'sluzby',
				'button' => 'Odeslat nezávaznou poptávku',
				'rows'   => array(
					array( 'type' => 'text', 'label' => 'Vaše jméno / firma', 'required' => 1 ),
					array( 'type' => 'email', 'label' => 'E-mail', 'required' => 1, 'half' => 1 ),
					array( 'type' => 'tel', 'label' => 'Telefon', 'required' => 1, 'half' => 1 ),
					array( 'type' => 'ico', 'label' => 'IČO' ),
					array( 'type' => 'select', 'label' => 'Mám zájem o službu', 'required' => 1, 'mode' => 'taxonomy', 'slug' => 'category' ),
					array( 'type' => 'adresa', 'label' => 'Přesná adresa realizace' ),
					array( 'type' => 'textarea', 'label' => 'Podrobnosti poptávky (rozměry, termín, představa o rozpočtu atd.)', 'required' => 1 ),
				),
			),
			'karierni' => array(
				'title'  => 'Kariéra',
				'slug'   => 'kariera',
				'button' => 'Odeslat',
				'rows'   => array(
					array( 'type' => 'text', 'label' => 'Vaše jméno', 'required' => 1 ),
					array( 'type' => 'email', 'label' => 'Emailová adresa', 'required' => 1, 'half' => 1 ),
					array( 'type' => 'tel', 'label' => 'Telefon', 'half' => 1 ),
					array( 'type' => 'checkbox', 'label' => 'Pozice, o kterou máte zájem', 'required' => 1, 'mode' => 'post_type', 'slug' => 'job' ),
					array( 'type' => 'file', 'label' => 'Životopis, motivační dopis…', 'required' => 1 ),
					array( 'type' => 'textarea', 'label' => 'Proč u nás chcete pracovat?' ),
				),
			),
			'poptavka-obecna' => array(
				'title'  => 'Poptávka',
				'slug'   => 'poptavka',
				'button' => 'Odeslat',
				'rows'   => array(
					array( 'type' => 'text', 'label' => 'Vaše jméno/firma/organizace' ),
					array( 'type' => 'email', 'label' => 'E-mail', 'required' => 1, 'half' => 1 ),
					array( 'type' => 'tel', 'label' => 'Telefon', 'half' => 1 ),
					array( 'type' => 'textarea', 'label' => 'Váš vzkaz, dotaz' ),
					array( 'type' => 'checkbox', 'label' => 'Zajímám se o', 'options' => "Bezplatné předvedení\nNový stroj\nRepasovaný (bazar)\nPronájem\nServis nebo náhradní díly\nZápůjčka" ),
				),
			),
		);
	}

	/** Tlačítka „Nový kontaktní / poptávkový / …" nad výpisem vlastních formulářů. */
	public function seed_buttons_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Výpis záznamů: tlačítka exportu (přenášejí aktuální filtr výpisu).
		if ( 'edit-' . self::CPT_ENTRY === $screen->id ) {
			$filter_args = array();
			foreach ( array( 'wkf_form_filter', 'm', 's' ) as $carry ) {
				if ( ! empty( $_GET[ $carry ] ) ) {
					$filter_args[ $carry ] = sanitize_text_field( wp_unslash( $_GET[ $carry ] ) );
				}
			}
			$base     = add_query_arg( $filter_args, admin_url( 'admin-post.php?action=wkf_export_entries' ) );
			$csv      = wp_nonce_url( add_query_arg( 'format', 'csv', $base ), 'wkf_export_entries' );
			$xlsx     = wp_nonce_url( add_query_arg( 'format', 'xlsx', $base ), 'wkf_export_entries' );
			$filtered = ! empty( $filter_args );
			echo '<div class="notice" style="padding:12px;border-left-color:#960000;"><strong>Export záznamů:</strong> ';
			echo '<a href="' . esc_url( $csv ) . '" class="button" style="margin-right:6px;">Stáhnout CSV</a>';
			if ( class_exists( 'ZipArchive' ) ) {
				echo '<a href="' . esc_url( $xlsx ) . '" class="button">Stáhnout XLSX</a>';
			}
			echo '<span class="description" style="margin-left:8px;">' . ( $filtered ? 'Exportují se záznamy podle aktuálního filtru (formulář, měsíc, hledání).' : 'Bez nastaveného filtru se exportují všechny záznamy.' ) . '</span>';
			echo '</div>';
			if ( current_user_can( 'manage_options' ) && $this->wpforms_entries_table_exists() ) {
				$this->render_migration_box();
			}
			return;
		}

		if ( 'edit-' . self::CPT_FORM !== $screen->id ) {
			return;
		}
		// Preset nasazený na webu se automaticky převede na editovatelný
		// formulář se stejným slugem – v adminu tak neexistuje nic, co by
		// nešlo upravit. Nenasazené presety zůstávají jen neviditelným
		// fallbackem a předlohami níže.
		$schemas_over = $this->form_schemas();
		$converted    = array();
		foreach ( array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ) as $preset_slug ) {
			if ( isset( $schemas_over[ $preset_slug ]['_custom'] ) ) {
				continue;
			}
			if ( $this->find_form_usage( $preset_slug ) ) {
				$new_id = $this->materialize_preset( $preset_slug );
				if ( $new_id ) {
					$converted[] = '<a href="' . esc_url( get_edit_post_link( $new_id, 'raw' ) ) . '">' . esc_html( get_the_title( $new_id ) ) . '</a>';
				}
			}
		}
		if ( $converted ) {
			echo '<div class="notice notice-success" style="padding:12px;"><strong>Formuláře běžící na webu byly převedeny na editovatelné:</strong> ' . implode( ', ', $converted ) . '. Pole i nastavení odpovídají dosavadní podobě – nic se na webu nezměnilo, jen to teď můžete upravovat.</div>';
		}

		// Report z importu WPForms (po přesměrování).
		$report = get_transient( 'wkf_wpforms_report_' . get_current_user_id() );
		if ( $report ) {
			delete_transient( 'wkf_wpforms_report_' . get_current_user_id() );
			echo '<div class="notice notice-info" style="padding:12px;"><strong>Import z WPForms</strong>';
			foreach ( $report as $item ) {
				echo '<p style="margin:8px 0 2px;"><strong>' . esc_html( $item['title'] ) . '</strong> – ' . (int) $item['fields'] . ' polí převedeno' . ( $item['post_id'] ? ', <a href="' . esc_url( get_edit_post_link( $item['post_id'], 'raw' ) ) . '">otevřít koncept →</a>' : '' ) . '</p>';
				if ( empty( $item['notes'] ) ) {
					echo '<p class="description" style="margin:0 0 4px;">Bez rozdílů – převedeno beze zbytku.</p>';
				} else {
					echo '<ul style="margin:0 0 4px 18px;list-style:disc;">';
					foreach ( $item['notes'] as $note ) {
						echo '<li>' . esc_html( $note ) . '</li>';
					}
					echo '</ul>';
				}
			}
			echo '</div>';
		}

		// Import z WPForms: z databáze webu (i při deaktivovaném WPForms) nebo ze souboru exportu.
		$wpf_posts = get_posts( array( 'post_type' => 'wpforms', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<div class="notice" style="padding:12px;border-left-color:#960000;"><strong>Import z WPForms:</strong> ';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="wkf_import_wpforms">';
		wp_nonce_field( 'wkf_import_wpforms' );
		if ( $wpf_posts ) {
			echo '<span style="margin-right:8px;">formuláře nalezené v databázi webu:</span>';
			foreach ( $wpf_posts as $wp_form ) {
				echo '<label style="margin-right:10px;"><input type="checkbox" name="wpf_ids[]" value="' . (int) $wp_form->ID . '"> ' . esc_html( $wp_form->post_title ) . ' <span class="description">(ID ' . (int) $wp_form->ID . ')</span></label>';
			}
		} else {
			echo '<span class="description" style="margin-right:8px;">v databázi nejsou žádné formuláře WPForms;</span>';
		}
		echo ' &nbsp;nebo soubor exportu: <input type="file" name="wpf_file" accept=".json,application/json"> ';
		echo '<button type="submit" class="button">Převést vybrané / soubor</button>';
		echo '<span class="description" style="margin-left:8px;">Vytvoří koncepty s mapováním původního ID (shortcode <code>[wpforms id]</code> začne vykreslovat nový formulář po deaktivaci WPForms). Data WPForms se nemění.</span>';
		echo '</form></div>';

		echo '<div class="notice" style="padding:12px;border-left-color:#960000;"><strong>Založit z předlohy:</strong> ';
		foreach ( $this->seed_definitions() as $seed_key => $seed ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wkf_seed&preset=' . $seed_key ),
				'wkf_seed_' . $seed_key
			);
			echo '<a href="' . esc_url( $url ) . '" class="button" style="margin-right:6px;">Nový – ' . esc_html( $seed['title'] ) . '</a>';
		}
		echo '<span class="description" style="margin-left:6px;">Předloha vytvoří koncept s předvyplněnými poli, který si upravíte a publikujete. Shortcode formuláře najdete po publikování ve výpisu níže.</span>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-left:18px;border-left:1px solid #dcdcde;padding-left:18px;">';
		echo '<input type="hidden" name="action" value="wkf_import_form">';
		wp_nonce_field( 'wkf_import_form' );
		echo '<input type="file" name="wkf_import" accept=".json,application/json" required> ';
		echo '<button type="submit" class="button">Importovat JSON</button>';
		echo '</form></div>';
	}

	/** Vytvoření konceptu vlastního formuláře z předlohy. */
	public function handle_seed() {
		$seed_key = isset( $_GET['preset'] ) ? sanitize_key( $_GET['preset'] ) : '';
		$seeds    = $this->seed_definitions();
		if ( ! current_user_can( 'edit_posts' ) || ! isset( $seeds[ $seed_key ] ) ) {
			wp_die( 'Neplatná předloha.' );
		}
		check_admin_referer( 'wkf_seed_' . $seed_key );

		$seed = $seeds[ $seed_key ];
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT_FORM,
				'post_status' => 'draft',
				'post_title'  => $seed['title'],
				'post_name'   => isset( $seed['slug'] ) ? $seed['slug'] : '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			wp_die( 'Formulář se nepodařilo vytvořit.' );
		}

		// Řádky předlohy doplněné o vygenerované klíče (stejná logika jako při uložení).
		$rows      = array();
		$used_keys = array();
		$auto_keys = array( 'email' => 'email', 'tel' => 'telefon', 'text' => 'jmeno', 'adresa' => 'adresa', 'ico' => 'ico' );
		foreach ( $seed['rows'] as $row ) {
			$type = $row['type'];
			if ( isset( $auto_keys[ $type ] ) && ! isset( $used_keys[ $auto_keys[ $type ] ] ) ) {
				$key = $auto_keys[ $type ];
			} else {
				$key  = str_replace( '-', '_', sanitize_key( remove_accents( $row['label'] ) ) );
				$key  = $key ? $key : 'pole';
				$base = $key;
				$n    = 2;
				while ( isset( $used_keys[ $key ] ) ) {
					$key = $base . '_' . $n;
					$n++;
				}
			}
			$used_keys[ $key ] = true;

			$rows[] = array(
				'type'      => $type,
				'key'       => $key,
				'label'     => $row['label'],
				'options'   => isset( $row['options'] ) ? $row['options'] : '',
				'required'  => empty( $row['required'] ) ? 0 : 1,
				'half'      => empty( $row['half'] ) ? 0 : 1,
				'mode'      => isset( $row['mode'] ) ? $row['mode'] : 'manual',
				'slug'      => isset( $row['slug'] ) ? $row['slug'] : '',
				'filetypes' => implode( ',', $this->builder_file_types() ),
				'maxmb'     => 10,
				'opt1'      => 1,
				'opt2'      => 1,
			);
		}

		update_post_meta( $post_id, '_wkf_fields_def', $rows );
		update_post_meta( $post_id, '_wkf_button', $seed['button'] );
		update_post_meta( $post_id, '_wkf_recipient', get_option( 'admin_email' ) );

		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	public function custom_form_meta_boxes() {
		add_meta_box( 'wkf_form_fields', 'Pole formuláře', array( $this, 'render_fields_meta_box' ), self::CPT_FORM, 'normal', 'high' );
		add_meta_box( 'wkf_form_preview', 'Náhled formuláře', array( $this, 'render_preview_meta_box' ), self::CPT_FORM, 'normal', 'default' );
		add_meta_box( 'wkf_form_config', 'Nastavení formuláře', array( $this, 'render_config_meta_box' ), self::CPT_FORM, 'normal', 'default' );
	}

	public function render_fields_meta_box( $post ) {
		wp_nonce_field( 'wkf_save_form', 'wkf_form_nonce' );
		$rows      = get_post_meta( $post->ID, '_wkf_fields_def', true );
		$rows      = is_array( $rows ) ? $rows : array();
		$types     = $this->builder_field_types();
		$ftypes    = $this->builder_file_types();
		$wp_max_mb = max( 1, (int) floor( wp_max_upload_size() / MB_IN_BYTES ) );
		$settings_url = admin_url( 'edit.php?post_type=' . self::CPT_ENTRY . '&page=wkf-settings' );

		if ( 'publish' === $post->post_status ) {
			echo '<p>Shortcode formuláře: <code>[wk_form type="' . esc_html( $post->post_name ) . '"]</code>';
			if ( in_array( $post->post_name, array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ), true ) ) {
				echo ' <em>– tento formulář nahrazuje stejnojmenný vestavěný preset.</em>';
			}
			echo '</p>';
		}

		// Prázdný formulář dostane jeden výchozí řádek – slouží i jako šablona pro klonování.
		if ( empty( $rows ) ) {
			$rows = array( array( 'type' => 'text', 'label' => '', 'options' => '', 'required' => 0, 'half' => 0 ) );
		}
		?>
		<style>
			#wkf-builder .wkf-def-row { border:1px solid #dcdcde; border-radius:6px; background:#fff; padding:10px 12px; margin-bottom:10px; }
			#wkf-builder .wkf-def-head { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:8px; }
			#wkf-builder .wkf-def-head select { min-width:220px; }
			#wkf-builder .wkf-def-actions { margin-left:auto; display:flex; gap:4px; }
			#wkf-builder .wkf-def-extra { margin-top:8px; padding:8px 10px; background:#f6f7f7; border-radius:4px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
			#wkf-builder .wkf-def-extra[hidden], #wkf-builder [data-x][hidden] { display:none; }
			#wkf-builder .wkf-def-options { margin-top:8px; }
			#wkf-builder .wkf-def-options[hidden] { display:none; }
			#wkf-builder .wkf-def-slug { width:180px; }
			#wkf-builder .wkf-def-maxmb { width:70px; }
			#wkf-builder .wkf-def-is-break { border-left:5px solid #960000; background:#fff8f8; margin-top:18px; }
			#wkf-builder .wkf-def-is-break::before { content:attr(data-step-label); display:block; font-weight:700; color:#960000; margin-bottom:6px; }
			#wkf-builder .wkf-def-pagebreak-opts { margin-top:8px; color:#50575e; }
			#wkf-builder .wkf-def-pagebreak-opts[hidden] { display:none; }
			#wkf-builder .wkf-def-req-wrap[hidden], #wkf-builder .wkf-def-half-wrap[hidden] { display:none; }
			#wkf-builder .wkf-def-props { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:8px; color:#50575e; }
			#wkf-builder .wkf-def-props [hidden] { display:none; }
			#wkf-builder .wkf-def-key { width:150px; font-family:monospace; }
			#wkf-builder .wkf-def-hint, #wkf-builder .wkf-def-placeholder, #wkf-builder .wkf-def-default { width:220px; }
			#wkf-builder .wkf-def-num { width:70px; }
			#wkf-builder .wkf-def-content { margin-top:8px; }
			#wkf-builder .wkf-def-content[hidden] { display:none; }
			#wkf-builder .wkf-def-content textarea { font-family:monospace; font-size:12px; }
			#wkf-builder .wkf-cond-groups { width:100%; margin-top:6px; }
			#wkf-builder .wkf-cond-groups[hidden] { display:none; }
			#wkf-builder .wkf-cond-group { border-left:3px solid #c3c4c7; padding:4px 10px; margin-bottom:6px; }
			#wkf-builder .wkf-cond-or { font-weight:600; color:#960000; margin:2px 0 4px; }
			#wkf-builder .wkf-cond-rule { display:flex; gap:6px; align-items:center; margin-bottom:4px; flex-wrap:wrap; }
			#wkf-builder .wkf-cond-and { min-width:34px; color:#787c82; }
			#wkf-builder .wkf-rule-value { width:170px; }
			#wkf-builder .wkf-rule-value[hidden] { display:none; }
			#wkf-builder .wkf-rule-del { color:#d63638; text-decoration:none; }
			#wkf-builder .wkf-def-cond { margin-top:8px; color:#50575e; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
			#wkf-builder .wkf-def-cond [hidden] { display:none; }
			#wkf-builder .wkf-def-cond-value { width:150px; }
			#wkf-builder .wkf-def-optlist { margin-top:8px; }
			#wkf-builder .wkf-def-optlist[hidden] { display:none; }
			#wkf-builder .wkf-opt { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
			#wkf-builder .wkf-opt-label { flex:1; }
			#wkf-builder .wkf-opt-price { width:110px; }
			#wkf-builder .wkf-opt-price-wrap[hidden] { display:none; }
			#wkf-builder .wkf-def-qty-wrap[hidden] { display:none; }
			#wkf-builder .wkf-def-pricemeta { width:210px; }
			#wkf-builder .wkf-def-pricemeta[hidden] { display:none; }
		</style>
		<div id="wkf-builder"
			data-wpmax="<?php echo esc_attr( $wp_max_mb ); ?>"
			data-settings-url="<?php echo esc_url( $settings_url ); ?>">
			<div id="wkf-builder-rows">
			<?php foreach ( $rows as $row ) :
				$row = wp_parse_args( $row, array( 'mode' => 'manual', 'slug' => '', 'filetypes' => implode( ',', $ftypes ), 'maxmb' => 10, 'opt1' => 1, 'opt2' => 1 ) );
				// Starší dynamické typy převedeme na sjednocené s režimem.
				if ( 'select_zdroj' === $row['type'] || 'checkbox_zdroj' === $row['type'] ) {
					$parsed        = $this->parse_dynamic_source( $row['options'] );
					$row['type']   = 'select_zdroj' === $row['type'] ? 'select' : 'checkbox';
					$row['mode']   = $parsed['source'];
					$row['slug']   = $parsed['source_slug'];
					$row['options'] = '';
				}
				$row_ftypes = array_map( 'trim', explode( ',', $row['filetypes'] ) );
			?>
				<div class="wkf-def-row">
					<div class="wkf-def-head">
					<select name="wkf_def_type[]" class="wkf-def-type">
							<?php foreach ( $types as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label class="wkf-def-req-wrap"><input type="checkbox" name="wkf_def_required[]" value="0" class="wkf-req-cb" <?php checked( ! empty( $row['required'] ) ); ?>> Povinné</label>
						<label class="wkf-def-half-wrap"><input type="checkbox" name="wkf_def_half[]" value="0" class="wkf-half-cb" <?php checked( ! empty( $row['half'] ) ); ?>> Poloviční šířka</label>
						<span class="wkf-def-actions">
							<button type="button" class="button wkf-up" title="Posunout nahoru">↑</button>
							<button type="button" class="button wkf-down" title="Posunout dolů">↓</button>
							<button type="button" class="button wkf-remove" title="Odebrat">✕</button>
						</span>
					</div>
					<input type="text" class="widefat wkf-def-label" name="wkf_def_label[]" placeholder="Popisek pole" value="<?php echo esc_attr( $row['label'] ); ?>">

					<div class="wkf-def-props">
						<span data-x="key">Klíč: <input type="text" class="wkf-def-key" name="wkf_def_key[]" value="<?php echo esc_attr( isset( $row['key'] ) ? $row['key'] : '' ); ?>" placeholder="(vygeneruje se)" pattern="[a-z0-9_]*" title="malá písmena, číslice a podtržítko"> <span class="description">značka <code class="wkf-def-key-tag">{<?php echo esc_html( isset( $row['key'] ) ? $row['key'] : '…' ); ?>}</code></span></span>
						<span data-x="role">Role:
							<select name="wkf_def_role[]" class="wkf-def-role">
								<?php foreach ( $this->field_roles() as $role_key => $role_label ) : ?>
									<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( isset( $row['role'] ) ? $row['role'] : '', $role_key ); ?>><?php echo esc_html( $role_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
						<span data-x="hint"><input type="text" class="wkf-def-hint" name="wkf_def_hint[]" placeholder="nápověda pod polem" value="<?php echo esc_attr( isset( $row['hint'] ) ? $row['hint'] : '' ); ?>"></span>
						<span data-x="placeholder"><input type="text" class="wkf-def-placeholder" name="wkf_def_placeholder[]" placeholder="placeholder" value="<?php echo esc_attr( isset( $row['placeholder'] ) ? $row['placeholder'] : '' ); ?>"></span>
						<span data-x="default"><input type="text" class="wkf-def-default" name="wkf_def_default[]" placeholder="výchozí hodnota / předvybraná volba" value="<?php echo esc_attr( isset( $row['default'] ) ? $row['default'] : '' ); ?>"></span>
						<span data-x="number">Min <input type="text" class="wkf-def-num" name="wkf_def_min[]" value="<?php echo esc_attr( isset( $row['min'] ) ? $row['min'] : '' ); ?>"> Max <input type="text" class="wkf-def-num" name="wkf_def_max[]" value="<?php echo esc_attr( isset( $row['max'] ) ? $row['max'] : '' ); ?>"> Krok <input type="text" class="wkf-def-num" name="wkf_def_step[]" value="<?php echo esc_attr( isset( $row['step'] ) ? $row['step'] : '' ); ?>"></span>
					</div>

					<div data-x="pagebreak" class="wkf-def-pagebreak-opts">
						Tlačítko vpřed (do tohoto kroku): <input type="text" name="wkf_def_btn_next[]" value="<?php echo esc_attr( isset( $row['btn_next'] ) ? $row['btn_next'] : '' ); ?>" placeholder="Pokračovat">
						&nbsp;Tlačítko zpět: <input type="text" name="wkf_def_btn_prev[]" value="<?php echo esc_attr( isset( $row['btn_prev'] ) ? $row['btn_prev'] : '' ); ?>" placeholder="Zpět">
						<label><input type="checkbox" name="wkf_def_hide_prev[]" value="0" class="wkf-hideprev-cb" <?php checked( ! empty( $row['hide_prev'] ) ); ?>> skrýt tlačítko zpět</label>
						<input type="hidden" name="wkf_def_wpf_id[]" value="<?php echo esc_attr( isset( $row['wpf_id'] ) ? $row['wpf_id'] : '' ); ?>">
					</div>
					<div data-x="content" class="wkf-def-content">
						<textarea class="widefat wkf-def-content-html" name="wkf_def_content[]" rows="4" placeholder="HTML obsah bloku – text, obrázek s kótami, odkaz…"><?php echo esc_textarea( isset( $row['content'] ) ? $row['content'] : '' ); ?></textarea>
						<button type="button" class="button wkf-content-media">Vložit obrázek z knihovny médií</button>
					</div>

					<div class="wkf-def-extra">
						<span data-x="source">
							Volby:
							<select name="wkf_def_mode[]" class="wkf-def-mode">
								<option value="manual" <?php selected( $row['mode'], 'manual' ); ?>>ručně zadané</option>
								<option value="taxonomy" <?php selected( $row['mode'], 'taxonomy' ); ?>>dynamicky z taxonomie</option>
								<option value="post_type" <?php selected( $row['mode'], 'post_type' ); ?>>dynamicky z typu příspěvku</option>
							</select>
							<input type="text" class="wkf-def-slug" name="wkf_def_slug[]" placeholder="slug (např. category)" value="<?php echo esc_attr( $row['slug'] ); ?>">
							<?php
							if ( in_array( $row['type'], array( 'select', 'checkbox' ), true ) && 'manual' !== $row['mode'] ) {
								if ( '' === $row['slug'] ) {
									echo '<span style="color:#d63638;font-weight:600;">Zdroj nemá slug – pole se na webu nevykreslí.</span>';
								} else {
									$src_count = count( $this->dynamic_options( array( 'source' => $row['mode'], 'source_slug' => $row['slug'] ) ) );
									echo $src_count
										? '<span style="color:#008a20;">zdroj vrací ' . (int) $src_count . ' položek</span>'
										: '<span style="color:#d63638;font-weight:600;">zdroj „' . esc_html( $row['slug'] ) . '" nevrací žádné položky – pole se na webu nevykreslí.</span>';
								}
							}
							?>
							<label class="wkf-def-priced-wrap"><input type="checkbox" class="wkf-priced-cb" <?php checked( false !== strpos( (string) $row['options'], '|' ) || ! empty( $row['pricemeta'] ) ); ?>> S cenou</label>
							<input type="text" class="wkf-def-pricemeta" name="wkf_def_pricemeta[]" placeholder="meta klíč ceny (např. cena, _price)" value="<?php echo esc_attr( isset( $row['pricemeta'] ) ? $row['pricemeta'] : '' ); ?>" hidden>
							<label class="wkf-def-qty-wrap" <?php echo false === strpos( (string) $row['options'], '|' ) ? 'hidden' : ''; ?>><input type="checkbox" name="wkf_def_qty[]" value="0" class="wkf-qty-cb" <?php checked( ! empty( $row['qty'] ) ); ?>> S počtem kusů</label>
						</span>
						<span data-x="adresa">
							<label><input type="checkbox" name="wkf_def_opt1[]" value="0" class="wkf-opt1-cb" <?php checked( ! empty( $row['opt1'] ) ); ?>> Našeptávat adresy (Mapy.cz)</label>
							<label><input type="checkbox" name="wkf_def_opt2[]" value="0" class="wkf-opt2-cb" <?php checked( ! empty( $row['opt2'] ) ); ?>> Ověřovat v RÚIAN</label>
							<a href="<?php echo esc_url( $settings_url ); ?>" target="_blank">Nastavení API klíčů →</a>
						</span>
						<span data-x="ico">
							<label><input type="checkbox" name="wkf_def_opt1[]" value="0" class="wkf-opt1-cb" <?php checked( ! empty( $row['opt1'] ) ); ?>> Našeptávat a doplňovat z ARES</label>
						</span>
						<span data-x="prefill">
							Předvyplnit:
							<select name="wkf_def_prefill[]" class="wkf-def-prefill">
								<option value="" <?php selected( empty( $row['prefill'] ) ); ?>>– nepředvyplňovat –</option>
								<option value="query" <?php selected( isset( $row['prefill'] ) ? $row['prefill'] : '', 'query' ); ?>>z query parametru URL</option>
								<option value="title" <?php selected( isset( $row['prefill'] ) ? $row['prefill'] : '', 'title' ); ?>>titulkem stránky</option>
							</select>
							<input type="text" class="wkf-def-prefill-param" name="wkf_def_prefill_param[]" placeholder="název parametru (např. from)" value="<?php echo esc_attr( isset( $row['prefill_param'] ) ? $row['prefill_param'] : '' ); ?>" <?php echo isset( $row['prefill'] ) && 'query' === $row['prefill'] ? '' : 'hidden'; ?>>
						</span>
						<span data-x="choice">
							Výběr:
							<select name="wkf_def_choice[]" class="wkf-def-choice">
								<option value="multi" <?php selected( isset( $row['choice'] ) ? $row['choice'] : 'multi', 'multi' ); ?>>více možností (zaškrtávací)</option>
								<option value="single" <?php selected( isset( $row['choice'] ) ? $row['choice'] : '', 'single' ); ?>>jedna možnost (přepínač)</option>
							</select>
							Zobrazení:
							<select name="wkf_def_layout[]" class="wkf-def-layout">
								<option value="vertical" <?php selected( isset( $row['layout'] ) ? $row['layout'] : 'vertical', 'vertical' ); ?>>svisle</option>
								<option value="horizontal" <?php selected( isset( $row['layout'] ) ? $row['layout'] : '', 'horizontal' ); ?>>vodorovně</option>
							</select>
						</span>
						<span data-x="vop">
							Stránka s podmínkami:
							<select name="wkf_def_voppage[]" class="wkf-def-voppage">
								<option value="0">– vyberte stránku –</option>
								<?php foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $vop_page ) : ?>
									<option value="<?php echo (int) $vop_page->ID; ?>" <?php selected( isset( $row['voppage'] ) ? (int) $row['voppage'] : 0, $vop_page->ID ); ?>><?php echo esc_html( $vop_page->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
						<span data-x="file">
							Povolené typy:
							<?php foreach ( $ftypes as $ext ) : ?>
								<label><input type="checkbox" class="wkf-ftype-cb" value="<?php echo esc_attr( $ext ); ?>" <?php checked( in_array( $ext, $row_ftypes, true ) ); ?>> .<?php echo esc_html( $ext ); ?></label>
							<?php endforeach; ?>
							<input type="hidden" name="wkf_def_filetypes[]" class="wkf-def-filetypes" value="<?php echo esc_attr( $row['filetypes'] ); ?>">
							&nbsp;Max. <input type="number" class="wkf-def-maxmb" name="wkf_def_maxmb[]" min="1" max="<?php echo esc_attr( $wp_max_mb ); ?>" value="<?php echo esc_attr( min( (int) $row['maxmb'], $wp_max_mb ) ); ?>"> MB
							<span class="description">(limit serveru <?php echo esc_html( $wp_max_mb ); ?> MB)</span>
							&nbsp;Počet souborů: <input type="number" class="wkf-def-maxmb" name="wkf_def_maxfiles[]" min="1" max="10" value="<?php echo esc_attr( isset( $row['maxfiles'] ) ? max( 1, (int) $row['maxfiles'] ) : 1 ); ?>">
						</span>
					</div>

					<textarea class="widefat wkf-def-options" rows="2" name="wkf_def_options[]" placeholder="Volby – jedna na řádek"><?php echo esc_textarea( isset( $row['options'] ) ? $row['options'] : '' ); ?></textarea>

					<div class="wkf-def-optlist" hidden>
						<div class="wkf-optlist-rows"></div>
						<button type="button" class="button wkf-opt-add">+ přidat volbu</button>
					</div>

					<?php
					$row_rules = isset( $row['cond_rules'] ) && is_array( $row['cond_rules'] ) ? $row['cond_rules'] : array();
					if ( ! $row_rules && ! empty( $row['cond_field'] ) ) {
						$row_rules = array( array( array( 'field' => $row['cond_field'], 'op' => isset( $row['cond_op'] ) && 'not_empty' === $row['cond_op'] ? 'not_empty' : 'eq', 'value' => isset( $row['cond_value'] ) ? $row['cond_value'] : '' ) ) );
					}
					$row_mode = $row_rules ? ( isset( $row['cond_mode'] ) && 'hide' === $row['cond_mode'] ? 'hide' : 'show' ) : '';
					?>
					<div class="wkf-def-cond">
						<select name="wkf_def_cond_mode[]" class="wkf-def-cond-mode">
							<option value="" <?php selected( $row_mode, '' ); ?>>Zobrazit vždy</option>
							<option value="show" <?php selected( $row_mode, 'show' ); ?>>Zobrazit, když…</option>
							<option value="hide" <?php selected( $row_mode, 'hide' ); ?>>Skrýt, když…</option>
						</select>
						<textarea class="wkf-def-cond-rules" name="wkf_def_cond_rules[]" hidden><?php echo esc_textarea( wp_json_encode( $row_rules, JSON_UNESCAPED_UNICODE ) ); ?></textarea>
						<div class="wkf-cond-groups" <?php echo $row_mode ? '' : 'hidden'; ?>></div>
						<datalist class="wkf-cond-datalist"></datalist>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
			<p><button type="button" class="button button-secondary" id="wkf-add-row">+ Přidat pole</button></p>
			<p class="description">První pole typu E-mail, Telefon, Text, Adresa a IČO dostane automatický klíč (email, telefon, jmeno, adresa, ico) pro notifikace, doplňování a zástupné značky v automatické odpovědi. U voleb lze zaškrtnout „S cenou" – ručně zadané volby pak mají popisek a cenu, u dynamických z typu příspěvku se cena načítá z meta pole (např. <code>cena</code> nebo <code>_price</code> u WooCommerce). Formulář ceny zobrazí, s volbou „S počtem kusů" nabídne množství, sečte orientační celkovou cenu a uvede ji v notifikaci i záznamu.</p>
		</div>

		<script>
		(function () {
			var builder = document.getElementById('wkf-builder');
			var container = document.getElementById('wkf-builder-rows');
			var rowHtml = null;

			function optRowHtml(label, price) {
				return '<div class="wkf-opt">'
					+ '<input type="text" class="wkf-opt-label" placeholder="Popisek volby" value="' + (label || '').replace(/"/g, '&quot;') + '">'
					+ '<span class="wkf-opt-price-wrap"><input type="number" class="wkf-opt-price" placeholder="Cena" min="0" step="0.01" value="' + (price || '') + '"> Kč</span> '
					+ '<button type="button" class="button wkf-opt-del">✕</button>'
					+ '</div>';
			}

			// Ceníkové řádky <-> interní zápis „Popisek | cena" v textarei.
			function buildOptList(row) {
				var textarea = row.querySelector('.wkf-def-options');
				var holder = row.querySelector('.wkf-optlist-rows');
				holder.innerHTML = '';
				textarea.value.split('\n').forEach(function (line) {
					line = line.trim();
					if (!line) { return; }
					var parts = line.split('|');
					holder.insertAdjacentHTML('beforeend', optRowHtml(parts[0].trim(), (parts[1] || '').trim().replace(',', '.').replace(/\s/g, '')));
				});
				if (!holder.children.length) {
					holder.insertAdjacentHTML('beforeend', optRowHtml('', ''));
				}
			}

			function syncOptions(row) {
				var priced = row.querySelector('.wkf-priced-cb').checked;
				var lines = [];
				row.querySelectorAll('.wkf-opt').forEach(function (opt) {
					var label = opt.querySelector('.wkf-opt-label').value.trim();
					var price = opt.querySelector('.wkf-opt-price').value.trim();
					if (label) {
						lines.push(priced && price ? label + ' | ' + price : label);
					}
				});
				row.querySelector('.wkf-def-options').value = lines.join('\n');
			}

			function refreshRow(row) {
				var type = row.querySelector('.wkf-def-type').value;
				var extra = row.querySelector('.wkf-def-extra');
				var options = row.querySelector('.wkf-def-options');
				var mode = row.querySelector('.wkf-def-mode').value;
				var pricedCb = row.querySelector('.wkf-priced-cb');
				var optlist = row.querySelector('.wkf-def-optlist');

				var inputTypes = ['text', 'email', 'tel', 'textarea', 'adresa', 'ico', 'number', 'date', 'url', 'select', 'checkbox', 'file', 'hidden', 'vop'];
				var textLike = ['text', 'email', 'tel', 'textarea', 'adresa', 'ico', 'number', 'url'];
				row.querySelectorAll('[data-x]').forEach(function (block) {
					var x = block.getAttribute('data-x');
					block.hidden = !(
						(x === 'source' && (type === 'select' || type === 'checkbox')) ||
						(x === 'choice' && type === 'checkbox') ||
						(x === 'prefill' && type === 'text') ||
						(x === 'adresa' && type === 'adresa') ||
						(x === 'ico' && type === 'ico') ||
						(x === 'vop' && type === 'vop') ||
						(x === 'file' && type === 'file') ||
						(x === 'key' && inputTypes.indexOf(type) !== -1) ||
						(x === 'role' && ['text', 'email', 'tel', 'textarea', 'adresa', 'ico'].indexOf(type) !== -1) ||
						(x === 'hint' && inputTypes.indexOf(type) !== -1 && type !== 'hidden') ||
						(x === 'placeholder' && textLike.indexOf(type) !== -1) ||
						(x === 'default' && (textLike.indexOf(type) !== -1 || ['select', 'checkbox', 'hidden', 'date'].indexOf(type) !== -1)) ||
						(x === 'number' && type === 'number') ||
						(x === 'content' && type === 'content') ||
						(x === 'pagebreak' && type === 'pagebreak')
					);
				});
				row.classList.toggle('wkf-def-is-break', type === 'pagebreak');
				// Zlom kroku nemá povinnost ani šířku – volby se skryjí a odškrtnou.
				var isBreak = type === 'pagebreak';
				['.wkf-def-req-wrap', '.wkf-def-half-wrap'].forEach(function (sel) {
					var wrap = row.querySelector(sel);
					if (wrap) {
						wrap.hidden = isBreak;
						if (isBreak) {
							wrap.querySelector('input').checked = false;
						}
					}
				});
				extra.hidden = !row.querySelector('.wkf-def-extra [data-x]:not([hidden])');
				row.querySelector('.wkf-def-label').placeholder = type === 'content' ? 'Interní název bloku (nezobrazuje se)' : (type === 'pagebreak' ? 'Nadpis nového kroku (nepovinný)' : 'Popisek pole');
				row.querySelector('.wkf-def-default').placeholder = (type === 'select' || type === 'checkbox') ? 'předvybraná volba (více oddělte čárkou)' : 'výchozí hodnota';

				var choiceField = type === 'select' || type === 'checkbox';
				var manualChoices = choiceField && mode === 'manual';
				var dynamicPosts = choiceField && mode === 'post_type';
				row.querySelector('.wkf-def-slug').hidden = !(choiceField && mode !== 'manual');
				row.querySelector('.wkf-def-priced-wrap').hidden = !(manualChoices || dynamicPosts);

				// Volby se zadávají vždy po řádcích; textarea je jen interní úložiště.
				options.hidden = true;
				optlist.hidden = !manualChoices;
				if (manualChoices && !row.querySelector('.wkf-opt')) {
					buildOptList(row);
				}
				var priced = (manualChoices || dynamicPosts) && pricedCb.checked;
				row.querySelectorAll('.wkf-opt-price-wrap').forEach(function (wrap) {
					wrap.hidden = !(manualChoices && priced);
				});
				row.querySelector('.wkf-def-pricemeta').hidden = !(dynamicPosts && priced);
				row.querySelector('.wkf-def-qty-wrap').hidden = !priced;

				// Synchronizace zaškrtnutých typů souborů do skrytého pole.
				var hiddenTypes = row.querySelector('.wkf-def-filetypes');
				var checked = [];
				row.querySelectorAll('.wkf-ftype-cb:checked').forEach(function (cb) { checked.push(cb.value); });
				hiddenTypes.value = checked.join(',');
			}

			// Checkboxy nesou index svého řádku, aby se po odeslání správně spárovaly.
			function reindex() {
				Array.prototype.forEach.call(container.querySelectorAll('.wkf-def-row'), function (row, i) {
					['wkf-req-cb', 'wkf-half-cb', 'wkf-opt1-cb', 'wkf-opt2-cb', 'wkf-qty-cb', 'wkf-hideprev-cb'].forEach(function (cls) {
						row.querySelectorAll('.' + cls).forEach(function (cb) { cb.value = i; });
					});
					refreshRow(row);
				});
				// Číslování kroků u zlomů, ať je v dlouhém formuláři vidět struktura.
				var stepNo = 1;
				container.querySelectorAll('.wkf-def-row').forEach(function (row) {
					if (row.classList.contains('wkf-def-is-break')) {
						stepNo++;
						row.setAttribute('data-step-label', 'Krok ' + stepNo + ' začíná zde');
					}
				});
				if (window.wkfSchedulePreview) {
					window.wkfSchedulePreview();
				}
			}

			document.getElementById('wkf-add-row').addEventListener('click', function () {
				if (rowHtml === null) {
					return;
				}
				var wrapper = document.createElement('div');
				wrapper.innerHTML = rowHtml;
				var row = wrapper.firstElementChild;
				row.querySelectorAll('input[type="text"], textarea').forEach(function (el) { el.value = ''; });
				row.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = cb.classList.contains('wkf-opt1-cb') || cb.classList.contains('wkf-opt2-cb') || cb.classList.contains('wkf-ftype-cb'); });
				row.querySelector('.wkf-optlist-rows').innerHTML = '';
				row.querySelector('.wkf-def-key').value = '';
				row.querySelector('.wkf-def-key-tag').textContent = '{…}';
				row.querySelector('.wkf-def-content-html').value = '';
				row.querySelector('.wkf-def-cond-rules').value = '[]';
				row.querySelector('.wkf-def-cond-mode').value = '';
				row.querySelector('.wkf-cond-groups').innerHTML = '';
				row.querySelector('.wkf-cond-groups').hidden = true;
				row.querySelector('.wkf-def-type').value = 'text';
				row.querySelector('.wkf-def-mode').value = 'manual';
				row.querySelector('.wkf-def-maxmb').value = '10';
				container.appendChild(row);
				reindex();
			});

			builder.addEventListener('click', function (e) {
				var row = e.target.closest('.wkf-def-row');
				if (!row) { return; }
				if (e.target.classList.contains('wkf-opt-add')) {
					row.querySelector('.wkf-optlist-rows').insertAdjacentHTML('beforeend', optRowHtml('', ''));
					return;
				}
				if (e.target.classList.contains('wkf-opt-del')) {
					e.target.closest('.wkf-opt').remove();
					syncOptions(row);
					reindex();
					return;
				}
				if (e.target.classList.contains('wkf-remove')) {
					row.remove();
				} else if (e.target.classList.contains('wkf-up') && row.previousElementSibling) {
					container.insertBefore(row, row.previousElementSibling);
				} else if (e.target.classList.contains('wkf-down') && row.nextElementSibling) {
					container.insertBefore(row.nextElementSibling, row);
				} else {
					return;
				}
				reindex();
			});

			builder.addEventListener('change', function (e) {
				var row = e.target.closest('.wkf-def-row');
				if (row && e.target.classList.contains('wkf-priced-cb')) {
					syncOptions(row); // ceny se do úložiště zapisují jen v režimu S cenou
				}
				if (row) {
					var condMode = row.querySelector('.wkf-def-cond-mode');
					if (condMode && e.target === condMode) {
						var groupsBox = row.querySelector('.wkf-cond-groups');
						groupsBox.hidden = condMode.value === '';
						if (condMode.value === '') {
							row.querySelector('.wkf-def-cond-rules').value = '[]';
							groupsBox.innerHTML = '';
						} else if (!groupsBox.querySelector('.wkf-cond-rule')) {
							setRules(row, [[{ field: '', op: 'eq', value: '' }]]);
						}
					}
					if (e.target.closest('.wkf-cond-groups')) {
						serializeRules(row);
						if (e.target.classList.contains('wkf-rule-field') || e.target.classList.contains('wkf-rule-op')) {
							renderRules(row, getRules(row));
						}
					}
					var prefillSel = row.querySelector('.wkf-def-prefill');
					if (prefillSel) {
						row.querySelector('.wkf-def-prefill-param').hidden = prefillSel.value !== 'query';
					}
				}
				reindex();
			});
			builder.addEventListener('input', function (e) {
				var opt = e.target.closest('.wkf-opt');
				if (opt) {
					syncOptions(e.target.closest('.wkf-def-row'));
				}
				if (window.wkfSchedulePreview) {
					window.wkfSchedulePreview();
				}
			});

			// Našeptávání hodnot podmínky: volby řídicího pole do datalistu.
			var condNonce = '<?php echo esc_js( wp_create_nonce( 'wkf_preview' ) ); ?>';

			var condOps = <?php echo wp_json_encode( $this->cond_operators(), JSON_UNESCAPED_UNICODE ); ?>;
			var noValueOps = ['empty', 'not_empty'];

			function findRowByKey(key) {
				var found = null;
				container.querySelectorAll('.wkf-def-row').forEach(function (row) {
					if (!found && row.querySelector('.wkf-def-key').value.trim() === key) {
						found = row;
					}
				});
				return found;
			}

			// Seznam polí, na která lze podmínku navázat (vše kromě aktuálního, nadpisů, obsahu a souborů).
			function controllerOptions(currentRow) {
				var list = [];
				container.querySelectorAll('.wkf-def-row').forEach(function (row) {
					var key = row.querySelector('.wkf-def-key').value.trim();
					var type = row.querySelector('.wkf-def-type').value;
					if (row === currentRow || !key || ['heading', 'content', 'file', 'vop'].indexOf(type) !== -1) {
						return;
					}
					list.push({ key: key, label: row.querySelector('.wkf-def-label').value.trim() || key });
				});
				return list;
			}

			function getRules(row) {
				try {
					var parsed = JSON.parse(row.querySelector('.wkf-def-cond-rules').value || '[]');
					return Array.isArray(parsed) ? parsed : [];
				} catch (err) {
					return [];
				}
			}

			function setRules(row, groups) {
				row.querySelector('.wkf-def-cond-rules').value = JSON.stringify(groups);
				renderRules(row, groups);
			}

			function serializeRules(row) {
				var groups = [];
				row.querySelectorAll('.wkf-cond-group').forEach(function (g) {
					var rules = [];
					g.querySelectorAll('.wkf-cond-rule').forEach(function (r) {
						rules.push({
							field: r.querySelector('.wkf-rule-field').value,
							op: r.querySelector('.wkf-rule-op').value,
							value: r.querySelector('.wkf-rule-value').value
						});
					});
					if (rules.length) {
						groups.push(rules);
					}
				});
				row.querySelector('.wkf-def-cond-rules').value = JSON.stringify(groups);
			}

			function renderRules(row, groups) {
				var box = row.querySelector('.wkf-cond-groups');
				var controllers = controllerOptions(row);
				var html = '';
				groups.forEach(function (group, gi) {
					html += '<div class="wkf-cond-group">' + (gi > 0 ? '<div class="wkf-cond-or">NEBO</div>' : '');
					group.forEach(function (rule, ri) {
						html += '<div class="wkf-cond-rule">' + (ri > 0 ? '<span class="wkf-cond-and">a</span>' : '<span class="wkf-cond-and">když</span>');
						html += '<select class="wkf-rule-field"><option value="">– pole –</option>';
						controllers.forEach(function (c) {
							html += '<option value="' + c.key + '"' + (c.key === rule.field ? ' selected' : '') + '>' + c.label.replace(/</g, '&lt;') + '</option>';
						});
						html += '</select><select class="wkf-rule-op">';
						Object.keys(condOps).forEach(function (op) {
							html += '<option value="' + op + '"' + (op === rule.op ? ' selected' : '') + '>' + condOps[op] + '</option>';
						});
						html += '</select>';
						html += '<input type="text" class="wkf-rule-value" placeholder="hodnota" value="' + String(rule.value || '').replace(/"/g, '&quot;') + '"' + (noValueOps.indexOf(rule.op) !== -1 ? ' hidden' : '') + '>';
						html += '<button type="button" class="button-link wkf-rule-del" title="Odebrat pravidlo">✕</button>';
						html += '</div>';
					});
					html += '<button type="button" class="button button-small wkf-rule-add">+ a další pravidlo</button></div>';
				});
				html += '<button type="button" class="button button-small wkf-group-add">+ NEBO skupina</button>';
				box.innerHTML = html;
			}

			// Našeptávání hodnot podle voleb řídicího pole (ruční z builderu, dynamické ze serveru).
			var condNonce = '<?php echo esc_js( wp_create_nonce( 'wkf_preview' ) ); ?>';
			function updateRuleDatalist(row, ruleEl) {
				var fieldKey = ruleEl.querySelector('.wkf-rule-field').value;
				var valueInput = ruleEl.querySelector('.wkf-rule-value');
				var datalist = row.querySelector('.wkf-cond-datalist');
				var listId = 'wkf-cond-dl-' + Array.prototype.indexOf.call(container.children, row);
				datalist.id = listId;
				valueInput.setAttribute('list', listId);
				datalist.innerHTML = '';
				var controller = findRowByKey(fieldKey);
				if (!controller) { return; }
				var type = controller.querySelector('.wkf-def-type').value;
				var mode = controller.querySelector('.wkf-def-mode').value;
				if (type !== 'select' && type !== 'checkbox') { return; }
				if (mode === 'manual') {
					var html = '';
					controller.querySelector('.wkf-def-options').value.split('\n').forEach(function (line) {
						var label = line.split('|')[0].trim();
						if (label) { html += '<option value="' + label.replace(/"/g, '&quot;') + '"></option>'; }
					});
					datalist.innerHTML = html;
					return;
				}
				var data = new FormData();
				data.append('action', 'wkf_cond_options');
				data.append('nonce', condNonce);
				data.append('source', mode);
				data.append('slug', controller.querySelector('.wkf-def-slug').value);
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (json && json.success && json.data) {
							datalist.innerHTML = json.data.items.map(function (item) {
								return '<option value="' + item.replace(/"/g, '&quot;') + '"></option>';
							}).join('');
						}
					})
					.catch(function () {});
			}

			builder.addEventListener('click', function (e) {
				var row = e.target.closest('.wkf-def-row');
				if (!row) { return; }
				if (e.target.classList.contains('wkf-rule-add') || e.target.classList.contains('wkf-group-add') || e.target.classList.contains('wkf-rule-del')) {
					serializeRules(row);
					var groups = getRules(row);
					if (e.target.classList.contains('wkf-group-add')) {
						groups.push([{ field: '', op: 'eq', value: '' }]);
					} else if (e.target.classList.contains('wkf-rule-add')) {
						var gi = Array.prototype.indexOf.call(row.querySelectorAll('.wkf-cond-group'), e.target.closest('.wkf-cond-group'));
						groups[gi].push({ field: '', op: 'eq', value: '' });
					} else {
						var ruleEl = e.target.closest('.wkf-cond-rule');
						var gIdx = Array.prototype.indexOf.call(row.querySelectorAll('.wkf-cond-group'), ruleEl.closest('.wkf-cond-group'));
						var rIdx = Array.prototype.indexOf.call(ruleEl.closest('.wkf-cond-group').querySelectorAll('.wkf-cond-rule'), ruleEl);
						groups[gIdx].splice(rIdx, 1);
						groups = groups.filter(function (g) { return g.length; });
					}
					setRules(row, groups);
					if (window.wkfSchedulePreview) { window.wkfSchedulePreview(); }
					return;
				}
				if (e.target.classList.contains('wkf-content-media') && window.wp && wp.media) {
					var frame = wp.media({ title: 'Vybrat obrázek', multiple: false, library: { type: 'image' } });
					frame.on('select', function () {
						var att = frame.state().get('selection').first().toJSON();
						var ta = row.querySelector('.wkf-def-content-html');
						ta.value += (ta.value ? '\n' : '') + '<img src="' + att.url + '" alt="' + (att.alt || '').replace(/"/g, '&quot;') + '" style="max-width:100%;height:auto;">';
						if (window.wkfSchedulePreview) { window.wkfSchedulePreview(); }
					});
					frame.open();
				}
			});
			builder.addEventListener('focusin', function (e) {
				if (e.target.classList.contains('wkf-rule-value')) {
					updateRuleDatalist(e.target.closest('.wkf-def-row'), e.target.closest('.wkf-cond-rule'));
				}
			});
			// Kontrola kolize klíčů při ruční úpravě.
			builder.addEventListener('input', function (e) {
				if (!e.target.classList.contains('wkf-def-key')) { return; }
				var val = e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
				e.target.value = val;
				var dup = 0;
				container.querySelectorAll('.wkf-def-key').forEach(function (k) { if (k.value === val && val) { dup++; } });
				e.target.style.borderColor = dup > 1 ? '#d63638' : '';
				e.target.title = dup > 1 ? 'Tento klíč už jiné pole používá' : '';
				var tag = e.target.closest('.wkf-def-row').querySelector('.wkf-def-key-tag');
				if (tag) { tag.textContent = '{' + (val || '…') + '}'; }
			});

			// Šablona nového řádku = kopie prvního řádku (server ho vykreslí vždy).
			var firstRow = container.querySelector('.wkf-def-row');
			if (firstRow) {
				rowHtml = firstRow.outerHTML;
			}
			container.querySelectorAll('.wkf-def-row').forEach(function (row) {
				var type = row.querySelector('.wkf-def-type').value;
				var mode = row.querySelector('.wkf-def-mode').value;
				if ((type === 'select' || type === 'checkbox') && mode === 'manual') {
					buildOptList(row);
				}
				var rules = getRules(row);
				if (rules.length) {
					renderRules(row, rules);
				}
			});
			reindex();
		})();
		</script>
		<?php
	}

	/** Živý náhled formuláře (AJAX). */
	public function render_preview_meta_box( $post ) {
		?>
		<div id="wkf-preview-box"><p class="description">Náhled se načte po úpravě polí…</p></div>
		<script>
		(function () {
			var timer = null;

			window.wkfSchedulePreview = function () {
				if (timer) { clearTimeout(timer); }
				timer = setTimeout(loadPreview, 500);
			};

			function loadPreview() {
				var box = document.getElementById('wkf-preview-box');
				var data = new FormData();
				data.append('action', 'wkf_preview_form');
				data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'wkf_preview' ) ); ?>');

				var builder = document.getElementById('wkf-builder');
				if (!builder) { return; }
				var columns = <?php echo wp_json_encode( $this->builder_row_columns() ); ?>;
				builder.querySelectorAll('.wkf-def-row').forEach(function (row) {
					columns.forEach(function (col) {
						var el = row.querySelector('[name="wkf_def_' + col + '[]"]');
						data.append('wkf_def_' + col + '[]', el ? el.value : '');
					});
					['required', 'half', 'qty', 'opt1', 'opt2'].forEach(function (flag) {
						row.querySelectorAll('[name="wkf_def_' + flag + '[]"]:checked').forEach(function (cb) {
							data.append('wkf_def_' + flag + '[]', cb.value);
						});
					});
				});
				var buttonField = document.querySelector('[name="wkf_f_button"]');
				data.append('wkf_button', buttonField ? buttonField.value : '');

				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (json && json.success && json.data) {
							box.innerHTML = json.data.html;
						}
					})
					.catch(function () {});
			}

			window.wkfSchedulePreview();
		})();
		</script>
		<style>
			#wkf-preview-box { background:#f6f7f7; border:1px dashed #c3c4c7; border-radius:6px; padding:20px; }
			#wkf-preview-box .wkf-form { pointer-events:none; max-width:680px; }
			#wkf-preview-box .wkf-form input[type="text"],
			#wkf-preview-box .wkf-form input[type="email"],
			#wkf-preview-box .wkf-form input[type="tel"],
			#wkf-preview-box .wkf-form select,
			#wkf-preview-box .wkf-form textarea { width:100% !important; max-width:100% !important; box-sizing:border-box; }
			#wkf-preview-box .wkf-form .wkf-field { margin-bottom:14px; }
		</style>
		<?php
	}

	public function render_config_meta_box( $post ) {
		$pages     = get_pages( array( 'post_status' => 'publish' ) );
		$recipient = get_post_meta( $post->ID, '_wkf_recipient', true );
		$thankyou  = (int) get_post_meta( $post->ID, '_wkf_thankyou', true );
		$button    = get_post_meta( $post->ID, '_wkf_button', true );
		$ar_on     = (int) get_post_meta( $post->ID, '_wkf_ar_enabled', true );
		$ar_subj   = get_post_meta( $post->ID, '_wkf_ar_subject', true );
		$ar_body   = get_post_meta( $post->ID, '_wkf_ar_body', true );
		$legacy_wpf = get_post_meta( $post->ID, '_wkf_legacy_wpforms', true );
		$legacy_cf7 = get_post_meta( $post->ID, '_wkf_legacy_cf7', true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wkf_f_legacy_wpf">Převzít shortcody WPForms</label></th>
				<td><input type="text" id="wkf_f_legacy_wpf" class="regular-text" name="wkf_f_legacy_wpf" value="<?php echo esc_attr( $legacy_wpf ); ?>" placeholder="např. 20469, 21608">
				<p class="description">ID starých WPForms formulářů oddělená čárkou. Shortcody <code>[wpforms id="..."]</code> s těmito ID pak vykreslí tento formulář – obsah stránek není třeba upravovat. Aktivuje se po deaktivaci pluginu WPForms.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_legacy_cf7">Převzít shortcody Contact Form 7</label></th>
				<td><input type="text" id="wkf_f_legacy_cf7" class="regular-text" name="wkf_f_legacy_cf7" value="<?php echo esc_attr( $legacy_cf7 ); ?>" placeholder="např. 123 nebo a4b8c2f">
				<p class="description">ID formulářů CF7 oddělená čárkou (novější CF7 používá i písmenné hashe – zkopírujte hodnotu <code>id</code> ze shortcodu). Aktivuje se po deaktivaci pluginu Contact Form 7.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_recipient">Příjemce notifikace</label></th>
				<td><input type="text" id="wkf_f_recipient" class="regular-text" name="wkf_f_recipient" value="<?php echo esc_attr( $recipient ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				<p class="description">Více adres oddělte čárkou. Prázdné = e-mail správce webu.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_button">Text tlačítka</label></th>
				<td><input type="text" id="wkf_f_button" class="regular-text" name="wkf_f_button" value="<?php echo esc_attr( $button ); ?>" placeholder="Odeslat"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_subject">Předmět notifikace</label></th>
				<td><input type="text" id="wkf_f_subject" class="large-text" name="wkf_f_subject" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wkf_subject', true ) ); ?>" placeholder="<?php echo esc_attr( $post->post_title . ' - formulář | {page_title}' ); ?>">
				<p class="description">Značky: <code>{form_name}</code>, <code>{page_title}</code>, <code>{page_url}</code> a klíče polí, např. <code>{jmeno}</code>. Prázdné = výchozí tvar.</p></td>
			</tr>
			<tr>
				<th scope="row">Odesílatel notifikace</th>
				<td><input type="text" name="wkf_f_from_name" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wkf_from_name', true ) ); ?>" placeholder="jméno (prázdné = globální)">
				&nbsp;<input type="email" name="wkf_f_from_email" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wkf_from_email', true ) ); ?>" placeholder="e-mail (prázdné = globální)">
				&nbsp;Reply-To z pole: <select name="wkf_f_replyto">
					<option value="">– role E-mail odesílatele –</option>
					<?php
					$def_rows = get_post_meta( $post->ID, '_wkf_fields_def', true );
					$replyto  = get_post_meta( $post->ID, '_wkf_replyto', true );
					foreach ( is_array( $def_rows ) ? $def_rows : array() as $r ) {
						if ( ! empty( $r['key'] ) && in_array( $r['type'], array( 'email', 'text' ), true ) ) {
							echo '<option value="' . esc_attr( $r['key'] ) . '" ' . selected( $replyto, $r['key'], false ) . '>' . esc_html( $r['label'] ) . '</option>';
						}
					}
					?>
				</select></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_confirm">Potvrzovací hláška</label></th>
				<td><textarea id="wkf_f_confirm" class="large-text" rows="3" name="wkf_f_confirm" placeholder="Děkujeme, formulář byl úspěšně odeslán."><?php echo esc_textarea( get_post_meta( $post->ID, '_wkf_confirm', true ) ); ?></textarea>
				<p class="description">Zobrazí se po odeslání (pokud není nastavena děkovací stránka). Jednoduché HTML a značky polí jsou povoleny.</p></td>
			</tr>
			<tr>
				<th scope="row">Ukazatel postupu</th>
				<td><select name="wkf_f_progress">
					<?php $prog = get_post_meta( $post->ID, '_wkf_progress', true ); ?>
					<option value="none" <?php selected( $prog, 'none' ); ?>>žádný</option>
					<option value="text" <?php selected( $prog, 'text' ); ?>>textový („Krok 2 z 4")</option>
					<option value="bar" <?php selected( $prog, 'bar' ); ?>>vizuální kroky</option>
				</select>
				&nbsp;<input type="text" name="wkf_f_progress_text" class="regular-text" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wkf_progress_text', true ) ); ?>" placeholder="Krok {n} z {total}">
				<p class="description">Platí jen pro vícekrokové formuláře (pole „Zlom kroku"). Barva ukazatele se řídí barvou tlačítka v Nastavení.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_retention">Uchování záznamů</label></th>
				<td><input type="number" id="wkf_f_retention" style="width:90px;" min="0" name="wkf_f_retention" value="<?php echo esc_attr( (int) get_post_meta( $post->ID, '_wkf_retention', true ) ); ?>"> dní
				<p class="description">Starší záznamy včetně nahraných souborů se automaticky smažou (denní úloha). 0 = uchovávat bez omezení.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_thankyou">Děkovací stránka</label></th>
				<td><select id="wkf_f_thankyou" name="wkf_f_thankyou">
					<option value="0">— nepřesměrovávat, zobrazit hlášku —</option>
					<?php foreach ( $pages as $p ) : ?>
						<option value="<?php echo (int) $p->ID; ?>" <?php selected( $thankyou, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
					<?php endforeach; ?>
				</select></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_q_mode">Kontrolní otázka</label></th>
				<td>
					<?php $q_mode = get_post_meta( $post->ID, '_wkf_q_mode', true ); ?>
					<select id="wkf_f_q_mode" name="wkf_f_q_mode">
						<option value="" <?php selected( $q_mode, '' ); ?>>podle globálního nastavení</option>
						<option value="custom" <?php selected( $q_mode, 'custom' ); ?>>vlastní otázka</option>
						<option value="off" <?php selected( $q_mode, 'off' ); ?>>vypnout u tohoto formuláře</option>
					</select>
					<span id="wkf-q-custom" <?php echo 'custom' === $q_mode ? '' : 'hidden'; ?>>
						<input type="text" class="regular-text" name="wkf_f_q_question" placeholder="Otázka" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wkf_q_question', true ) ); ?>">
						<input type="text" name="wkf_f_q_answer" placeholder="Správná odpověď" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wkf_q_answer', true ) ); ?>">
					</span>
					<script>
					document.getElementById('wkf_f_q_mode').addEventListener('change', function () {
						document.getElementById('wkf-q-custom').hidden = this.value !== 'custom';
					});
					</script>
				</td>
			</tr>
			<tr>
				<th scope="row">Lead API / webhook</th>
				<td><label><input type="checkbox" name="wkf_f_webhook" value="1" <?php checked( '0' !== get_post_meta( $post->ID, '_wkf_webhook', true ) ); ?>> Posílat záznamy na webhook (nastavený globálně v Nastavení)</label></td>
			</tr>
			<tr>
				<th scope="row">Automatická odpověď</th>
				<td><label><input type="checkbox" name="wkf_f_ar_enabled" value="1" <?php checked( $ar_on ); ?>> Posílat potvrzení na zadaný e-mail</label>
				<p class="description">Zástupné značky podle popisků polí nejsou k dispozici; použít lze <code>{email}</code> a značky odpovídající klíčům polí (zobrazí se po uložení v tabulce výše).</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wkf_f_ar_subject">Předmět potvrzení</label></th>
				<td><input type="text" id="wkf_f_ar_subject" class="large-text" name="wkf_f_ar_subject" value="<?php echo esc_attr( $ar_subj ); ?>"></td>
			</tr>
			<tr>
				<th scope="row">Text potvrzení</th>
				<td><?php
				wp_editor(
					$ar_body,
					'wkf_f_ar_body',
					array(
						'textarea_name' => 'wkf_f_ar_body',
						'textarea_rows' => 6,
						'media_buttons' => false,
						'teeny'         => true,
						'quicktags'     => true,
					)
				);
				?></td>
			</tr>
		</table>
		<?php
	}

	public function save_custom_form( $post_id, $post ) {
		if ( ! isset( $_POST['wkf_form_nonce'] ) || ! wp_verify_nonce( $_POST['wkf_form_nonce'], 'wkf_save_form' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_wkf_fields_def', $this->parse_builder_rows_from_post() );

		update_post_meta( $post_id, '_wkf_recipient', isset( $_POST['wkf_f_recipient'] ) ? $this->sanitize_email_list( wp_unslash( $_POST['wkf_f_recipient'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_thankyou', isset( $_POST['wkf_f_thankyou'] ) ? absint( $_POST['wkf_f_thankyou'] ) : 0 );
		update_post_meta( $post_id, '_wkf_button', isset( $_POST['wkf_f_button'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_f_button'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_ar_enabled', empty( $_POST['wkf_f_ar_enabled'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_wkf_webhook', empty( $_POST['wkf_f_webhook'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_wkf_subject', isset( $_POST['wkf_f_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_f_subject'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_from_name', isset( $_POST['wkf_f_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_f_from_name'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_from_email', isset( $_POST['wkf_f_from_email'] ) ? sanitize_email( wp_unslash( $_POST['wkf_f_from_email'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_replyto', isset( $_POST['wkf_f_replyto'] ) ? sanitize_key( $_POST['wkf_f_replyto'] ) : '' );
		update_post_meta( $post_id, '_wkf_confirm', isset( $_POST['wkf_f_confirm'] ) ? wp_kses_post( wp_unslash( $_POST['wkf_f_confirm'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_retention', isset( $_POST['wkf_f_retention'] ) ? absint( $_POST['wkf_f_retention'] ) : 0 );
		update_post_meta( $post_id, '_wkf_progress', isset( $_POST['wkf_f_progress'] ) && in_array( $_POST['wkf_f_progress'], array( 'text', 'bar' ), true ) ? $_POST['wkf_f_progress'] : 'none' );
		update_post_meta( $post_id, '_wkf_progress_text', isset( $_POST['wkf_f_progress_text'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_f_progress_text'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_ar_subject', isset( $_POST['wkf_f_ar_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_f_ar_subject'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_ar_body', isset( $_POST['wkf_f_ar_body'] ) ? wp_kses_post( wp_unslash( $_POST['wkf_f_ar_body'] ) ) : '' );

		$q_mode = isset( $_POST['wkf_f_q_mode'] ) && in_array( $_POST['wkf_f_q_mode'], array( 'custom', 'off' ), true ) ? $_POST['wkf_f_q_mode'] : '';
		update_post_meta( $post_id, '_wkf_q_mode', $q_mode );
		update_post_meta( $post_id, '_wkf_q_question', isset( $_POST['wkf_f_q_question'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_f_q_question'] ) ) : '' );
		update_post_meta( $post_id, '_wkf_q_answer', isset( $_POST['wkf_f_q_answer'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_f_q_answer'] ) ) : '' );

		// Mapování starých shortcodů: povolené jen alfanumerické ID oddělené čárkou.
		foreach ( array( 'wkf_f_legacy_wpf' => '_wkf_legacy_wpforms', 'wkf_f_legacy_cf7' => '_wkf_legacy_cf7' ) as $post_key => $meta_key ) {
			$raw = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : '';
			$ids = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			$ids = array_filter( $ids, static function ( $id ) {
				return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $id );
			} );
			update_post_meta( $post_id, $meta_key, implode( ', ', $ids ) );
		}
	}

	/** Sestaví definici polí z odeslaného builderu (sdíleno uložením a náhledem). */
	/** Seznam builder sloupců (POST pole wkf_def_<název>[]), které tvoří jeden řádek. */
	private function builder_row_columns() {
		return array( 'key', 'type', 'label', 'options', 'mode', 'slug', 'filetypes', 'maxmb', 'maxfiles', 'pricemeta', 'voppage', 'choice', 'layout', 'prefill', 'prefill_param', 'role', 'hint', 'placeholder', 'default', 'min', 'max', 'step', 'content', 'btn_next', 'btn_prev', 'wpf_id', 'cond_mode', 'cond_rules', 'cond_field', 'cond_op', 'cond_value' );
	}

	/** Role pole – určuje význam pro notifikace, Reply-To, našeptávání a značky. */
	private function field_roles() {
		return array(
			''        => '– bez role –',
			'jmeno'   => 'Jméno odesílatele',
			'email'   => 'E-mail odesílatele (Reply-To, autoreply)',
			'telefon' => 'Telefon odesílatele',
			'firma'   => 'Název firmy',
			'ico'     => 'IČO (ARES)',
			'adresa'  => 'Adresa realizace (RÚIAN)',
			'zprava'  => 'Hlavní zpráva',
		);
	}

	/** Operátory podmíněné logiky. */
	private function cond_operators() {
		return array(
			'eq'           => 'je rovno',
			'neq'          => 'není rovno',
			'empty'        => 'je prázdné',
			'not_empty'    => 'není prázdné',
			'contains'     => 'obsahuje',
			'not_contains' => 'neobsahuje',
			'starts'       => 'začíná na',
			'ends'         => 'končí na',
			'gt'           => 'větší než',
			'lt'           => 'menší než',
		);
	}

	/**
	 * Sanitizace jednoho builder řádku z libovolného zdroje (POST, JSON import,
	 * WPForms importér). Klíč pole se zde jen čistí – přiděluje ho volající.
	 */
	private function normalize_row( $raw ) {
		$wp_max_mb = max( 1, (int) floor( wp_max_upload_size() / MB_IN_BYTES ) );
		$ftypes    = isset( $raw['filetypes'] ) ? array_values( array_intersect( array_map( 'trim', explode( ',', (string) $raw['filetypes'] ) ), $this->builder_file_types() ) ) : array();
		$maxmb     = isset( $raw['maxmb'] ) ? absint( $raw['maxmb'] ) : 10;

		// Podmínky: nový formát (skupiny pravidel) má přednost, starý se převede.
		$rules = array();
		if ( ! empty( $raw['cond_rules'] ) ) {
			$decoded = is_array( $raw['cond_rules'] ) ? $raw['cond_rules'] : json_decode( (string) $raw['cond_rules'], true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $group ) {
					$clean_group = array();
					foreach ( (array) $group as $rule ) {
						$field = isset( $rule['field'] ) ? str_replace( '-', '_', sanitize_key( $rule['field'] ) ) : '';
						$op    = isset( $rule['op'] ) && isset( $this->cond_operators()[ $rule['op'] ] ) ? $rule['op'] : 'eq';
						if ( '' !== $field ) {
							$clean_group[] = array( 'field' => $field, 'op' => $op, 'value' => isset( $rule['value'] ) ? sanitize_text_field( $rule['value'] ) : '' );
						}
					}
					if ( $clean_group ) {
						$rules[] = $clean_group;
					}
				}
			}
		} elseif ( ! empty( $raw['cond_field'] ) ) {
			$legacy_op = isset( $raw['cond_op'] ) && 'not_empty' === $raw['cond_op'] ? 'not_empty' : 'eq';
			$rules[]   = array( array( 'field' => str_replace( '-', '_', sanitize_key( $raw['cond_field'] ) ), 'op' => $legacy_op, 'value' => isset( $raw['cond_value'] ) ? sanitize_text_field( $raw['cond_value'] ) : '' ) );
		}

		$type = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : 'text';
		$content = isset( $raw['content'] ) ? wp_kses_post( (string) $raw['content'] ) : '';

		return array(
			'type'          => $type,
			'key'           => isset( $raw['key'] ) ? str_replace( '-', '_', sanitize_key( $raw['key'] ) ) : '',
			'label'         => isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : '',
			'options'       => isset( $raw['options'] ) ? sanitize_textarea_field( $raw['options'] ) : '',
			'required'      => empty( $raw['required'] ) ? 0 : 1,
			'half'          => empty( $raw['half'] ) ? 0 : 1,
			'mode'          => isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'manual', 'taxonomy', 'post_type' ), true ) ? $raw['mode'] : 'manual',
			'slug'          => isset( $raw['slug'] ) ? sanitize_key( $raw['slug'] ) : '',
			'filetypes'     => $ftypes ? implode( ',', $ftypes ) : implode( ',', $this->builder_file_types() ),
			'maxmb'         => min( max( 1, $maxmb ? $maxmb : 10 ), $wp_max_mb ),
			'maxfiles'      => isset( $raw['maxfiles'] ) ? min( max( 1, absint( $raw['maxfiles'] ) ), 10 ) : 1,
			'qty'           => empty( $raw['qty'] ) ? 0 : 1,
			'pricemeta'     => isset( $raw['pricemeta'] ) ? sanitize_key( $raw['pricemeta'] ) : '',
			'voppage'       => isset( $raw['voppage'] ) ? absint( $raw['voppage'] ) : 0,
			'choice'        => isset( $raw['choice'] ) && 'single' === $raw['choice'] ? 'single' : 'multi',
			'layout'        => isset( $raw['layout'] ) && 'horizontal' === $raw['layout'] ? 'horizontal' : 'vertical',
			'prefill'       => isset( $raw['prefill'] ) && in_array( $raw['prefill'], array( 'query', 'title' ), true ) ? $raw['prefill'] : '',
			'prefill_param' => isset( $raw['prefill_param'] ) ? sanitize_key( $raw['prefill_param'] ) : '',
			'opt1'          => isset( $raw['opt1'] ) ? ( empty( $raw['opt1'] ) ? 0 : 1 ) : 1,
			'opt2'          => isset( $raw['opt2'] ) ? ( empty( $raw['opt2'] ) ? 0 : 1 ) : 1,
			'role'          => isset( $raw['role'] ) && isset( $this->field_roles()[ $raw['role'] ] ) ? $raw['role'] : '',
			'hint'          => isset( $raw['hint'] ) ? sanitize_text_field( $raw['hint'] ) : '',
			'placeholder'   => isset( $raw['placeholder'] ) ? sanitize_text_field( $raw['placeholder'] ) : '',
			'default'       => isset( $raw['default'] ) ? sanitize_textarea_field( $raw['default'] ) : '',
			'min'           => isset( $raw['min'] ) && '' !== $raw['min'] ? (string) (float) str_replace( ',', '.', $raw['min'] ) : '',
			'max'           => isset( $raw['max'] ) && '' !== $raw['max'] ? (string) (float) str_replace( ',', '.', $raw['max'] ) : '',
			'step'          => isset( $raw['step'] ) && '' !== $raw['step'] ? (string) abs( (float) str_replace( ',', '.', $raw['step'] ) ) : '',
			'content'       => $content,
			'btn_next'      => isset( $raw['btn_next'] ) ? sanitize_text_field( $raw['btn_next'] ) : '',
			'btn_prev'      => isset( $raw['btn_prev'] ) ? sanitize_text_field( $raw['btn_prev'] ) : '',
			'hide_prev'     => empty( $raw['hide_prev'] ) ? 0 : 1,
			'wpf_id'        => isset( $raw['wpf_id'] ) ? sanitize_text_field( $raw['wpf_id'] ) : '',
			'cond_mode'     => isset( $raw['cond_mode'] ) && 'hide' === $raw['cond_mode'] ? 'hide' : 'show',
			'cond_rules'    => $rules,
		);
	}

	/**
	 * Načte řádky builderu z POST. Klíč pole je stabilní: uložený nebo ručně
	 * zadaný klíč se zachová; nový se odvodí z role, jinak z popisku.
	 */
	private function parse_builder_rows_from_post( $for_preview = false ) {
		$columns = $this->builder_row_columns();
		$post    = array();
		foreach ( $columns as $col ) {
			$post[ $col ] = isset( $_POST[ 'wkf_def_' . $col ] ) ? (array) wp_unslash( $_POST[ 'wkf_def_' . $col ] ) : array();
		}
		$flags = array();
		foreach ( array( 'required', 'half', 'qty', 'opt1', 'opt2', 'hide_prev' ) as $flag ) {
			$flags[ $flag ] = isset( $_POST[ 'wkf_def_' . $flag ] ) ? array_map( 'intval', (array) $_POST[ 'wkf_def_' . $flag ] ) : array();
		}

		$allowed_types = array_keys( $this->builder_field_types() );
		$reserved_keys = array( 'page_title', 'page_url', 'query_string', 'website_hp', 'antispam', 'form_type', 'nonce', 'action', 'key' );
		$used_keys     = array();
		$rows          = array();

		foreach ( $post['type'] as $index => $type ) {
			$raw = array();
			foreach ( $columns as $col ) {
				$raw[ $col ] = isset( $post[ $col ][ $index ] ) ? $post[ $col ][ $index ] : '';
			}
			foreach ( $flags as $flag => $set ) {
				$raw[ $flag ] = in_array( $index, $set, true ) ? 1 : 0;
			}
			$row = $this->normalize_row( $raw );
			if ( ! in_array( $row['type'], $allowed_types, true ) ) {
				continue;
			}
			if ( '' === $row['label'] && ! in_array( $row['type'], array( 'content', 'pagebreak' ), true ) ) {
				if ( ! $for_preview ) {
					continue;
				}
				$row['label'] = '(bez popisku)';
			}

			// Role: výslovná, nebo nabídnutá podle typu/popisku u nového pole;
			// u starších polí s klíčem rovným roli (jmeno, email…) se role odvodí z klíče.
			if ( '' === $row['role'] && '' === $row['key'] ) {
				$row['role'] = $this->suggest_role( $row['type'], $row['label'] );
			} elseif ( '' === $row['role'] && isset( $this->field_roles()[ $row['key'] ] ) ) {
				$row['role'] = $row['key'];
			}

			// Klíč: existující → role (je-li volná) → z popisku, vždy unikátní.
			$key = $row['key'];
			if ( '' === $key || isset( $used_keys[ $key ] ) || in_array( $key, $reserved_keys, true ) ) {
				if ( '' !== $row['role'] && ! isset( $used_keys[ $row['role'] ] ) ) {
					$key = $row['role'];
				} else {
					$key = str_replace( '-', '_', sanitize_key( remove_accents( $row['label'] ) ) );
					$key = $key ? $key : ( 'content' === $row['type'] ? 'obsah' : ( 'pagebreak' === $row['type'] ? 'krok' : 'pole' ) );
					if ( in_array( $key, $reserved_keys, true ) ) {
						$key = 'f_' . $key;
					}
					$base = $key;
					$n    = 2;
					while ( isset( $used_keys[ $key ] ) ) {
						$key = $base . '_' . $n;
						$n++;
					}
				}
			}
			$used_keys[ $key ] = true;
			$row['key']        = $key;
			$rows[]            = $row;
		}

		return $rows;
	}

	/** Nabídne roli podle typu a popisku nového pole. */
	private function suggest_role( $type, $label ) {
		$l = mb_strtolower( remove_accents( $label ) );
		if ( 'email' === $type ) {
			return 'email';
		}
		if ( 'tel' === $type ) {
			return 'telefon';
		}
		if ( 'adresa' === $type ) {
			return 'adresa';
		}
		if ( 'ico' === $type ) {
			return 'ico';
		}
		if ( 'text' === $type && ( false !== strpos( $l, 'jmeno' ) || false !== strpos( $l, 'jméno' ) ) ) {
			return 'jmeno';
		}
		if ( 'text' === $type && false !== strpos( $l, 'firma' ) ) {
			return 'firma';
		}
		if ( 'textarea' === $type && ( false !== strpos( $l, 'zprav' ) || false !== strpos( $l, 'popis' ) || false !== strpos( $l, 'poznam' ) || false !== strpos( $l, 'vzkaz' ) ) ) {
			return 'zprava';
		}
		return '';
	}

	/** Převod uložené builder definice na schéma enginu. */
	private function custom_form_schemas() {
		$posts   = get_posts( array( 'post_type' => self::CPT_FORM, 'post_status' => 'publish', 'posts_per_page' => -1 ) );
		$schemas = array();

		foreach ( $posts as $post ) {
			$slug = $post->post_name;
			if ( ! $slug ) {
				continue;
			}
			// Slug shodný s vestavěným presetem (kontakt, sluzby, kariera,
			// poptavka) preset vědomě nahrazuje – array_merge ve form_schemas
			// dá vlastnímu formuláři přednost.
			$rows   = get_post_meta( $post->ID, '_wkf_fields_def', true );
			$fields = $this->rows_to_fields( is_array( $rows ) ? $rows : array() );
			if ( $fields ) {
				$button           = get_post_meta( $post->ID, '_wkf_button', true );
				$progress         = get_post_meta( $post->ID, '_wkf_progress', true );
				$schemas[ $slug ] = array(
					'name'          => $post->post_title,
					'button'        => $button ? $button : 'Odeslat',
					'fields'        => $fields,
					'_custom'       => $post->ID,
					'progress'      => in_array( $progress, array( 'text', 'bar' ), true ) ? $progress : 'none',
					'progress_text' => (string) get_post_meta( $post->ID, '_wkf_progress_text', true ),
				);
			}
		}
		return $schemas;
	}

	/** Převod řádků builderu na pole enginu (sdíleno schématy a náhledem). */
	private function rows_to_fields( $rows ) {
		$fields = array();
		foreach ( $rows as $row ) {
			$options = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $row['options'] ) ) ) );
			$mode    = isset( $row['mode'] ) ? $row['mode'] : 'manual';
			$slug    = isset( $row['slug'] ) ? $row['slug'] : '';
			$field   = array(
				'key'         => $row['key'],
				'label'       => $row['label'],
				'required'    => ! empty( $row['required'] ),
				'half'        => ! empty( $row['half'] ),
				'role'        => isset( $row['role'] ) ? $row['role'] : '',
				'hint'        => isset( $row['hint'] ) ? $row['hint'] : '',
				'placeholder' => isset( $row['placeholder'] ) ? $row['placeholder'] : '',
				'default'     => isset( $row['default'] ) ? $row['default'] : '',
			);
			// Podmínky: nový formát (skupiny pravidel) nebo převod staré jednoduché podmínky.
			$groups = array();
			if ( ! empty( $row['cond_rules'] ) && is_array( $row['cond_rules'] ) ) {
				$groups = $row['cond_rules'];
			} elseif ( ! empty( $row['cond_field'] ) ) {
				$groups = array( array( array( 'field' => $row['cond_field'], 'op' => isset( $row['cond_op'] ) && 'not_empty' === $row['cond_op'] ? 'not_empty' : 'eq', 'value' => isset( $row['cond_value'] ) ? $row['cond_value'] : '' ) ) );
			}
			if ( $groups ) {
				$field['cond'] = array(
					'mode'   => isset( $row['cond_mode'] ) && 'hide' === $row['cond_mode'] ? 'hide' : 'show',
					'groups' => $groups,
				);
			}
			switch ( $row['type'] ) {
				case 'heading':
					$field['type'] = 'heading';
					break;
				case 'content':
					$field['type']    = 'content';
					$field['content'] = isset( $row['content'] ) ? $row['content'] : '';
					break;
				case 'pagebreak':
					$field['type']      = 'pagebreak';
					$field['btn_next']  = isset( $row['btn_next'] ) ? $row['btn_next'] : '';
					$field['btn_prev']  = isset( $row['btn_prev'] ) ? $row['btn_prev'] : '';
					$field['hide_prev'] = ! empty( $row['hide_prev'] );
					break;
				case 'number':
					$field['type'] = 'number';
					$field['min']  = isset( $row['min'] ) ? $row['min'] : '';
					$field['max']  = isset( $row['max'] ) ? $row['max'] : '';
					$field['step'] = isset( $row['step'] ) ? $row['step'] : '';
					break;
				case 'date':
					$field['type'] = 'date';
					break;
				case 'url':
					$field['type'] = 'url';
					break;
				case 'hidden':
					$field['type'] = 'hidden';
					break;
				case 'textarea':
					$field['type'] = 'textarea';
					$field['rows'] = 5;
					break;
				case 'adresa':
					$field['type'] = 'textarea';
					$field['rows'] = 3;
					// Výchozí zapnuto i pro starší uložené řádky bez voleb.
					if ( ! isset( $row['opt1'] ) || ! empty( $row['opt1'] ) ) {
						$field['suggest'] = 'address';
					}
					if ( ! isset( $row['opt2'] ) || ! empty( $row['opt2'] ) ) {
						$field['ruian'] = true;
					}
					break;
				case 'ico':
					$field['type'] = 'text';
					if ( ! isset( $row['opt1'] ) || ! empty( $row['opt1'] ) ) {
						$field['suggest'] = 'ares';
						$field['hint']    = 'Začněte psát IČO nebo název firmy – údaje doplníme z registru ARES.';
					}
					break;
				case 'select':
					if ( 'manual' === $mode ) {
						$parsed               = $this->parse_priced_options( $options );
						$field['type']        = 'select_static';
						$field['options']     = $parsed['options'];
						$field['prices']      = $parsed['prices'];
						$field['qty_enabled'] = ! empty( $row['qty'] ) && $parsed['prices'];
						$field['placeholder'] = '– vyberte –';
					} else {
						$field['type']        = 'select_dynamic';
						$field['source']      = $mode;
						$field['source_slug'] = $slug;
						$field['placeholder'] = '– vyberte –';
						if ( 'post_type' === $mode && ! empty( $row['pricemeta'] ) ) {
							$field['prices']      = $this->dynamic_prices( $slug, $row['pricemeta'] );
							$field['qty_enabled'] = ! empty( $row['qty'] ) && $field['prices'];
						}
					}
					break;
				case 'select_zdroj': // starší uložené řádky
					$field                = array_merge( $field, $this->parse_dynamic_source( $row['options'] ) );
					$field['type']        = 'select_dynamic';
					$field['placeholder'] = '– vyberte –';
					break;
				case 'checkbox':
					$field['single'] = isset( $row['choice'] ) && 'single' === $row['choice'];
					$field['inline'] = isset( $row['layout'] ) && 'horizontal' === $row['layout'];
					if ( 'manual' === $mode ) {
						$parsed               = $this->parse_priced_options( $options );
						$field['type']        = 'checkbox_static';
						$field['options']     = $parsed['options'];
						$field['prices']      = $parsed['prices'];
						$field['qty_enabled'] = ! empty( $row['qty'] ) && $parsed['prices'];
					} else {
						$field['type']        = 'checkbox_dynamic';
						$field['source']      = $mode;
						$field['source_slug'] = $slug;
						if ( 'post_type' === $mode && ! empty( $row['pricemeta'] ) ) {
							$field['prices']      = $this->dynamic_prices( $slug, $row['pricemeta'] );
							$field['qty_enabled'] = ! empty( $row['qty'] ) && $field['prices'];
						}
					}
					break;
				case 'checkbox_zdroj': // starší uložené řádky
					$field         = array_merge( $field, $this->parse_dynamic_source( $row['options'] ) );
					$field['type'] = 'checkbox_dynamic';
					break;
				case 'vop':
					$field['type']     = 'vop';
					$field['required'] = true;
					$field['page_id']  = isset( $row['voppage'] ) ? (int) $row['voppage'] : 0;
					break;
				case 'file':
					$field['type']      = 'file';
					$field['max_files'] = isset( $row['maxfiles'] ) ? max( 1, (int) $row['maxfiles'] ) : 1;
					$ext           = isset( $row['filetypes'] ) && $row['filetypes']
						? array_values( array_intersect( explode( ',', $row['filetypes'] ), $this->builder_file_types() ) )
						: $this->builder_file_types();
					$maxmb         = isset( $row['maxmb'] ) ? max( 1, (int) $row['maxmb'] ) : 10;
					$field['allowed_ext'] = $ext;
					$field['max_mb']      = $maxmb;
					$field['hint']        = sprintf( 'Max. %d MB (podporováno: .%s)', $maxmb, implode( ', .', $ext ) );
					break;
				default: // text, email, tel
					$field['type'] = $row['type'];
					if ( 'text' === $row['type'] && ! empty( $row['prefill'] ) ) {
						$field['prefill']       = $row['prefill'];
						$field['prefill_param'] = isset( $row['prefill_param'] ) ? $row['prefill_param'] : '';
					}
					break;
			}
			$fields[] = $field;
		}

		// Vícekrokové formuláře: zlom jako první řádek netvoří prázdný krok,
		// zlom jako poslední se ignoruje; podmínka zlomu se dědí na pole kroku
		// (server tak pole přeskočeného kroku nesbírá – stejně jako prohlížeč).
		$cleaned   = array();
		$step_cond = null;
		foreach ( $fields as $idx => $field ) {
			if ( 'pagebreak' === $field['type'] ) {
				if ( empty( $cleaned ) || $idx === count( $fields ) - 1 ) {
					continue;
				}
				$step_cond = ! empty( $field['cond'] ) ? $field['cond'] : null;
				$cleaned[] = $field;
				continue;
			}
			if ( $step_cond ) {
				$field['step_cond'] = $step_cond;
			}
			$cleaned[] = $field;
		}
		return $cleaned;
	}

	/** AJAX náhled formuláře v editoru (jen pro přihlášené editory). */
	public function preview_custom_form() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'wkf_preview', 'nonce' );

		$rows   = $this->parse_builder_rows_from_post( true );
		$fields = $this->rows_to_fields( $rows );
		if ( ! $fields ) {
			wp_send_json_success( array( 'html' => '<p class="description">Přidejte pole – náhled se zobrazí zde.</p>' ) );
		}

		$button = isset( $_POST['wkf_button'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_button'] ) ) : '';
		$schema = array( 'name' => 'Náhled', 'button' => $button ? $button : 'Odeslat', 'fields' => $fields );

		// Styly jsou součástí odpovědi, aby náhled nezávisel na admin stylech ani cache.
		$s   = $this->get_settings();
		$css = '<style>'
			. '#wkf-preview-box .wkf-form{max-width:680px;pointer-events:none;}'
			. '#wkf-preview-box .wkf-field{margin:0 0 1.1rem;}'
			. '#wkf-preview-box .wkf-label{display:block;font-weight:600;margin-bottom:.35rem;}'
			. '#wkf-preview-box .wkf-req{color:#d63638;}'
			. '#wkf-preview-box .wkf-form input[type="text"],#wkf-preview-box .wkf-form input[type="email"],#wkf-preview-box .wkf-form input[type="tel"],#wkf-preview-box .wkf-form select,#wkf-preview-box .wkf-form textarea{width:100% !important;max-width:100% !important;box-sizing:border-box;padding:.5rem .7rem;border:1px solid #c8c8c8;border-radius:4px;background:#fff;font:inherit;}'
			. '#wkf-preview-box .wkf-form textarea{min-height:90px;}'
			. '#wkf-preview-box .wkf-row{display:flex;gap:1.1rem;}'
			. '#wkf-preview-box .wkf-row .wkf-col{flex:1 1 0;min-width:0;}'
			. '#wkf-preview-box .wkf-section-title{margin:1.4rem 0 .7rem;font-size:1.1em;}'
			. '#wkf-preview-box .wkf-checkboxes{border:0;padding:0;margin:0;}'
			. '#wkf-preview-box .wkf-checkboxes label{display:block;margin:0 0 .35rem;font-weight:400;}'
			. '#wkf-preview-box .wkf-dropzone{border:2px dashed #c3c4c7;border-radius:6px;padding:14px;background:#fff;}'
			. '#wkf-preview-box .wkf-hint{color:#666;font-size:.9em;margin:.3rem 0 0;}'
			. '#wkf-preview-box .wkf-total{margin:.8rem 0;padding:.6rem .8rem;background:#fff;border:1px solid #dcdcde;border-radius:6px;}'
			. '#wkf-preview-box .wkf-cond{display:block !important;border-left:3px solid #c3c4c7;padding-left:10px;}'
			. '#wkf-preview-box .wkf-cond[hidden]{display:block !important;}'
			. '#wkf-preview-box .wkf-price{color:#666;font-size:.92em;}'
			. '#wkf-preview-box .wkf-qty{width:64px;}'
			. '#wkf-preview-box .wkf-qty-wrap[hidden]{display:none;}'
			. sprintf(
				'#wkf-preview-box .wkf-submit{padding:10px 20px;border-radius:8px;background:%1$s;color:%3$s;border:none;font-size:18px;font-weight:800;cursor:default;}#wkf-preview-box .wkf-submit:hover{background:%2$s;}',
				esc_attr( $s['btn_bg'] ),
				esc_attr( $s['btn_bg_hover'] ),
				esc_attr( $s['btn_color'] )
			)
			. '</style>';

		$html = $this->render_schema( 'nahled', $schema, $schema['button'], true );

		// Náhled žije uvnitř admin formuláře editace příspěvku: atributy
		// required by blokovaly uložení (HTML5 validace celého formuláře)
		// a atributy name by se odesílaly s uložením. Obojí se odstraní –
		// náhled je jen obrázek, vizuálně se nemění.
		$html = preg_replace( '/\srequired(?=[\s>])/', '', $html );
		$html = preg_replace( '/\sname="wkf_[^"]*"/', '', $html );

		wp_send_json_success( array( 'html' => $css . $html ) );
	}

	/** Hodnoty dynamického zdroje pro našeptávání podmínek v builderu. */
	public function cond_options_ajax() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'wkf_preview', 'nonce' );

		$source = isset( $_POST['source'] ) && 'post_type' === $_POST['source'] ? 'post_type' : 'taxonomy';
		$slug   = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$items  = $slug ? $this->dynamic_options( array( 'source' => $source, 'source_slug' => $slug ) ) : array();

		wp_send_json_success( array( 'items' => array_slice( $items, 0, 100 ) ) );
	}

	/** CSS formulářů na editační obrazovce vlastního formuláře (pro náhled). */
	public function admin_assets( $hook ) {
		$screen_now = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen_now && self::CPT_FORM === $screen_now->post_type ) {
			wp_enqueue_media();
		}
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || self::CPT_FORM !== $screen->post_type ) {
			return;
		}
		$s = $this->get_settings();
		wp_enqueue_style( 'wkf-forms', WKF_PLUGIN_URL . 'assets/forms.css', array(), WKF_VERSION );
		wp_add_inline_style(
			'wkf-forms',
			sprintf(
				'.wkf-form{--wkf-brand:%1$s;}.wkf-submit{padding:10px 20px;border-radius:8px;background:%1$s;color:%3$s;border:none;font-size:18px;font-weight:800;}.wkf-submit:hover{background:%2$s;color:%3$s;}',
				esc_attr( $s['btn_bg'] ),
				esc_attr( $s['btn_bg_hover'] ),
				esc_attr( $s['btn_color'] )
			)
		);
	}

	/**
	 * Provozní konfigurace formuláře (příjemce, děkovačka, autoreply):
	 * presety čtou z nastavení pluginu, vlastní formuláře z post meta.
	 */
	private function form_config( $type ) {
		$s = $this->get_settings();

		// Vlastní formulář má přednost – i když slugem nahrazuje preset.
		$schemas = $this->form_schemas();
		$post_id = isset( $schemas[ $type ]['_custom'] ) ? (int) $schemas[ $type ]['_custom'] : 0;

		if ( ! $post_id && in_array( $type, array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ), true ) ) {
			return array(
				'recipient'  => $s[ 'recipient_' . $type ],
				'thankyou'   => (int) $s[ 'thankyou_' . $type ],
				'ar_enabled' => ! empty( $s[ 'autoreply_' . $type . '_enabled' ] ),
				'ar_subject' => $s[ 'autoreply_' . $type . '_subject' ],
				'ar_body'    => $s[ 'autoreply_' . $type . '_body' ],
				'subject'    => '',
				'from_name'  => '',
				'from_email' => '',
				'replyto'    => '',
				'confirm'    => '',
				'retention'  => 0,
			);
		}
		$recipient = $post_id ? get_post_meta( $post_id, '_wkf_recipient', true ) : '';

		return array(
			'recipient'  => $recipient ? $recipient : get_option( 'admin_email' ),
			'thankyou'   => $post_id ? (int) get_post_meta( $post_id, '_wkf_thankyou', true ) : 0,
			'ar_enabled' => $post_id ? (bool) get_post_meta( $post_id, '_wkf_ar_enabled', true ) : false,
			'ar_subject' => $post_id ? (string) get_post_meta( $post_id, '_wkf_ar_subject', true ) : '',
			'ar_body'    => $post_id ? (string) get_post_meta( $post_id, '_wkf_ar_body', true ) : '',
			'subject'    => $post_id ? (string) get_post_meta( $post_id, '_wkf_subject', true ) : '',
			'from_name'  => $post_id ? (string) get_post_meta( $post_id, '_wkf_from_name', true ) : '',
			'from_email' => $post_id ? (string) get_post_meta( $post_id, '_wkf_from_email', true ) : '',
			'replyto'    => $post_id ? (string) get_post_meta( $post_id, '_wkf_replyto', true ) : '',
			'confirm'    => $post_id ? (string) get_post_meta( $post_id, '_wkf_confirm', true ) : '',
			'retention'  => $post_id ? (int) get_post_meta( $post_id, '_wkf_retention', true ) : 0,
		);
	}

	/** Nahradí zástupné značky {klíč}, {form_name}, {page_title}, {page_url} v textu. */
	private function fill_placeholders( $text, $values, $schema, $page_title, $page_url ) {
		$map = array(
			'{form_name}'  => isset( $schema['name'] ) ? $schema['name'] : '',
			'{page_title}' => $page_title,
			'{page_url}'   => $page_url,
			'{admin_email}' => get_option( 'admin_email' ),
		);
		foreach ( $values as $key => $value ) {
			$map[ '{' . $key . '}' ] = str_replace( "\n", ', ', (string) $value );
		}
		return trim( strtr( (string) $text, $map ) );
	}

	/** Sloupec se shortcodem ve výpisu vlastních formulářů. */
	public function form_columns( $columns ) {
		$columns['wkf_usage'] = 'Umístění na webu';
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['wkf_shortcode'] = 'Shortcode';
			}
		}
		return $new;
	}

	public function form_column_content( $column, $post_id ) {
		if ( 'wkf_usage' === $column ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status && $post->post_name ) {
				echo $this->usage_links_html( $this->find_form_usage( $post->post_name ) );
			} else {
				echo '<span class="description">–</span>';
			}
			return;
		}
		if ( 'wkf_shortcode' !== $column ) {
			return;
		}
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && $post->post_name ) {
			echo '<code style="user-select:all;cursor:copy;">[wk_form type="' . esc_html( $post->post_name ) . '"]</code>';
			if ( in_array( $post->post_name, array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ), true ) ) {
				echo '<br><span class="description" style="color:#996800;">nahrazuje vestavěný preset „' . esc_html( $post->post_name ) . '"</span>';
			}
		} else {
			echo '<span class="description">po publikování</span>';
		}
	}

	/**
	 * Převede runtime schéma presetu (po aplikaci matice a zdrojů z nastavení)
	 * na builder řádky, aby vznikl identický editovatelný formulář.
	 */
	private function schema_to_rows( $schema ) {
		$rows = array();
		foreach ( $schema['fields'] as $field ) {
			if ( ! empty( $field['enabled_check'] ) && empty( $field['enabled'] ) ) {
				continue;
			}
			$row = array(
				'key'        => isset( $field['key'] ) ? $field['key'] : '',
				'label'      => isset( $field['label'] ) ? $field['label'] : '',
				'required'   => empty( $field['required'] ) ? 0 : 1,
				'half'       => empty( $field['half'] ) ? 0 : 1,
				'mode'       => 'manual',
				'slug'       => '',
				'options'    => '',
				'filetypes'  => implode( ',', $this->builder_file_types() ),
				'maxmb'      => 10,
				'opt1'       => 1,
				'opt2'       => 1,
				'qty'        => 0,
				'pricemeta'  => '',
				'voppage'    => 0,
				'choice'     => 'multi',
				'layout'     => empty( $field['inline'] ) ? 'vertical' : 'horizontal',
				'role'       => in_array( $field['key'], array( 'jmeno', 'email', 'telefon', 'adresa', 'ico', 'zprava' ), true ) ? $field['key'] : '',
				'hint'       => isset( $field['hint'] ) ? $field['hint'] : '',
				'placeholder' => '',
				'default'    => '',
				'min'        => '',
				'max'        => '',
				'step'       => '',
				'content'    => '',
				'maxfiles'   => 1,
				'cond_mode'  => 'show',
				'cond_rules' => array(),
			);

			switch ( $field['type'] ) {
				case 'heading':
					$row['type'] = 'heading';
					break;
				case 'email':
					$row['type'] = 'email';
					break;
				case 'tel':
					$row['type'] = 'tel';
					break;
				case 'textarea':
					$row['type'] = 'adresa' === $field['key'] ? 'adresa' : 'textarea';
					break;
				case 'select_static':
					$row['type']    = 'select';
					$row['options'] = implode( "\n", isset( $field['options'] ) ? $field['options'] : array() );
					break;
				case 'select_dynamic':
					$row['type'] = 'select';
					$row['mode'] = isset( $field['source'] ) ? $field['source'] : 'taxonomy';
					$row['slug'] = isset( $field['source_slug'] ) ? $field['source_slug'] : '';
					break;
				case 'checkbox_static':
					$row['type']    = 'checkbox';
					$row['options'] = implode( "\n", isset( $field['options'] ) ? $field['options'] : array() );
					break;
				case 'checkbox_dynamic':
					$row['type'] = 'checkbox';
					$row['mode'] = isset( $field['source'] ) ? $field['source'] : 'post_type';
					$row['slug'] = isset( $field['source_slug'] ) ? $field['source_slug'] : '';
					break;
				case 'file':
					$row['type'] = 'file';
					if ( ! empty( $field['allowed_ext'] ) ) {
						$row['filetypes'] = implode( ',', array_intersect( (array) $field['allowed_ext'], $this->builder_file_types() ) );
					}
					if ( ! empty( $field['max_mb'] ) ) {
						$row['maxmb'] = min( max( 1, (int) $field['max_mb'] ), 50 );
					}
					break;
				default: // text a ostatní
					$row['type'] = 'text';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Vytvoří z běžícího presetu publikovaný editovatelný formulář
	 * se stejným slugem (preset tím okamžitě nahradí) a konfigurací.
	 */
	private function materialize_preset( $slug ) {
		$schemas = $this->form_schemas();
		if ( ! isset( $schemas[ $slug ] ) || isset( $schemas[ $slug ]['_custom'] ) ) {
			return 0;
		}
		$schema = $schemas[ $slug ];
		$config = $this->form_config( $slug );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT_FORM,
				'post_status' => 'publish',
				'post_title'  => $schema['name'],
				'post_name'   => $slug,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, '_wkf_fields_def', $this->schema_to_rows( $schema ) );
		update_post_meta( $post_id, '_wkf_button', $schema['button'] );
		update_post_meta( $post_id, '_wkf_recipient', $config['recipient'] ? $config['recipient'] : get_option( 'admin_email' ) );
		update_post_meta( $post_id, '_wkf_thankyou', (int) $config['thankyou'] );
		update_post_meta( $post_id, '_wkf_ar_enabled', empty( $config['ar_enabled'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_wkf_ar_subject', (string) $config['ar_subject'] );
		update_post_meta( $post_id, '_wkf_ar_body', (string) $config['ar_body'] );

		$this->flush_usage_cache( $post_id );
		return (int) $post_id;
	}

	/** Smaže cache umístění formulářů po každém uložení obsahu. */
	public function flush_usage_cache( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		foreach ( array_keys( $this->form_schemas() ) as $slug ) {
			delete_transient( 'wkf_usage_' . $slug );
		}
	}

	/**
	 * Najde publikované stránky/příspěvky, kde formulář běží. Hledá shortcode
	 * [wk_form type="slug"] v post_content i v post metech (Bricks a Elementor
	 * ukládají obsah do meta, Elementor s JSON-escapovanými uvozovkami)
	 * a mapované staré shortcody ([wpforms id], [contact-form-7 id]).
	 * Výsledek se cachuje na 5 minut.
	 */
	private function find_form_usage( $slug ) {
		$cache_key = 'wkf_usage_' . $slug;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$needles = array(
			'[wk_form type="' . $slug . '"',
			'[wk_form type=\\"' . $slug . '\\"', // Elementor JSON
		);
		// Šablona bez atributu = kontakt.
		if ( 'kontakt' === $slug ) {
			$needles[] = '[wk_form]';
		}
		// Mapovaná stará ID mířící na tento slug.
		foreach ( array( 'wpforms', 'cf7' ) as $system ) {
			foreach ( $this->legacy_map( $system ) as $old_id => $mapped ) {
				if ( $mapped !== $slug ) {
					continue;
				}
				$tag       = 'wpforms' === $system ? 'wpforms' : 'contact-form-7';
				$needles[] = '[' . $tag . ' id="' . $old_id . '"';
				$needles[] = '[' . $tag . ' id=\\"' . $old_id . '\\"';
			}
		}

		$where_content = array();
		$where_meta    = array();
		$params_c      = array();
		$params_m      = array();
		foreach ( $needles as $needle ) {
			$like            = '%' . $wpdb->esc_like( $needle ) . '%';
			$where_content[] = 'post_content LIKE %s';
			$params_c[]      = $like;
			$where_meta[]    = 'meta_value LIKE %s';
			$params_m[]      = $like;
		}

		$ids_content = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment','wkf_form','wkf_entry') AND (" . implode( ' OR ', $where_content ) . ') LIMIT 20',
			$params_c
		) );
		$ids_meta = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision','attachment','wkf_form','wkf_entry') AND (" . implode( ' OR ', $where_meta ) . ') LIMIT 20',
			$params_m
		) );

		$pages = array();
		foreach ( array_unique( array_merge( $ids_content, $ids_meta ) ) as $page_id ) {
			$pages[ (int) $page_id ] = get_the_title( $page_id );
		}
		set_transient( $cache_key, $pages, 5 * MINUTE_IN_SECONDS );
		return $pages;
	}

	/** Vypíše odkazy na stránky, kde formulář běží (max 3 + počet). */
	private function usage_links_html( $pages ) {
		if ( empty( $pages ) ) {
			return '<span class="description">nenalezeno v obsahu</span>';
		}
		$links = array();
		$i     = 0;
		foreach ( $pages as $page_id => $title ) {
			if ( $i++ >= 3 ) {
				$links[] = '… (+' . ( count( $pages ) - 3 ) . ')';
				break;
			}
			$links[] = '<a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" rel="noopener">' . esc_html( $title ? $title : ( '#' . $page_id ) ) . '</a>';
		}
		return implode( ', ', $links );
	}

	/** Řádkové akce: export formulářů, opakované odeslání záznamů do Lead API. */
	public function form_row_actions( $actions, $post ) {
		if ( self::CPT_ENTRY === $post->post_type && current_user_can( 'manage_options' )
			&& get_post_meta( $post->ID, '_wkf_webhook_payload', true ) ) {
			$resend_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wkf_webhook_resend&entry=' . $post->ID ),
				'wkf_webhook_resend_' . $post->ID
			);
			$actions['wkf_resend'] = '<a href="' . esc_url( $resend_url ) . '">Lead API – odeslat znovu</a>';
		}
		if ( self::CPT_FORM === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wkf_export_form&form=' . $post->ID ),
				'wkf_export_' . $post->ID
			);
			$actions['wkf_export'] = '<a href="' . esc_url( $url ) . '">Exportovat (JSON)</a>';
		}
		return $actions;
	}

	/** Export definice vlastního formuláře do přenositelného JSON. */
	public function export_form_json() {
		$post_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( 'wkf_export_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || self::CPT_FORM !== $post->post_type ) {
			wp_die( 'Formulář nenalezen.' );
		}

		// Vynechávají se hodnoty vázané na konkrétní web (děkovací stránka,
		// stránka VOP, mapování starých shortcodů) – doplní se po importu.
		$rows = get_post_meta( $post_id, '_wkf_fields_def', true );
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as $i => $row ) {
			unset( $rows[ $i ]['voppage'] );
		}

		$export = array(
			'wkf_form_export' => 1,
			'plugin_version'  => WKF_VERSION,
			'exported'        => gmdate( 'c' ),
			'title'           => $post->post_title,
			'fields'          => array_values( $rows ),
			'config'          => array(
				'button'     => (string) get_post_meta( $post_id, '_wkf_button', true ),
				'recipient'  => (string) get_post_meta( $post_id, '_wkf_recipient', true ),
				'ar_enabled' => (int) get_post_meta( $post_id, '_wkf_ar_enabled', true ),
				'ar_subject' => (string) get_post_meta( $post_id, '_wkf_ar_subject', true ),
				'ar_body'    => (string) get_post_meta( $post_id, '_wkf_ar_body', true ),
				'q_mode'     => (string) get_post_meta( $post_id, '_wkf_q_mode', true ),
				'q_question' => (string) get_post_meta( $post_id, '_wkf_q_question', true ),
				'q_answer'   => (string) get_post_meta( $post_id, '_wkf_q_answer', true ),
				'subject'    => (string) get_post_meta( $post_id, '_wkf_subject', true ),
				'from_name'  => (string) get_post_meta( $post_id, '_wkf_from_name', true ),
				'from_email' => (string) get_post_meta( $post_id, '_wkf_from_email', true ),
				'replyto'    => (string) get_post_meta( $post_id, '_wkf_replyto', true ),
				'confirm'    => (string) get_post_meta( $post_id, '_wkf_confirm', true ),
				'retention'  => (int) get_post_meta( $post_id, '_wkf_retention', true ),
				'progress'   => (string) get_post_meta( $post_id, '_wkf_progress', true ),
				'progress_text' => (string) get_post_meta( $post_id, '_wkf_progress_text', true ),
			),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wkf-form-' . sanitize_file_name( $post->post_name ? $post->post_name : $post_id ) . '.json"' );
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/* =========================================================
	 * Import z WPForms
	 * ======================================================= */

	public function import_wpforms() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( 'wkf_import_wpforms' );

		$sources = array();
		if ( ! empty( $_POST['wpf_ids'] ) ) {
			foreach ( array_map( 'absint', (array) $_POST['wpf_ids'] ) as $wp_id ) {
				$post = get_post( $wp_id );
				if ( $post && 'wpforms' === $post->post_type ) {
					$decoded = json_decode( $post->post_content, true );
					if ( is_array( $decoded ) ) {
						$decoded['id'] = $wp_id;
						if ( empty( $decoded['settings']['form_title'] ) ) {
							$decoded['settings']['form_title'] = $post->post_title;
						}
						$sources[] = $decoded;
					}
				}
			}
		}
		if ( ! empty( $_FILES['wpf_file']['tmp_name'] ) ) {
			$decoded = json_decode( (string) file_get_contents( $_FILES['wpf_file']['tmp_name'] ), true );
			if ( is_array( $decoded ) ) {
				// Export WPForms je pole formulářů; jeden formulář má klíč "fields".
				$list = isset( $decoded['fields'] ) ? array( $decoded ) : $decoded;
				foreach ( $list as $one ) {
					if ( is_array( $one ) && isset( $one['fields'] ) ) {
						$sources[] = $one;
					}
				}
			}
		}
		if ( ! $sources ) {
			wp_die( 'Nebyl vybrán žádný formulář ani platný soubor exportu WPForms.' );
		}

		$report = array();
		foreach ( $sources as $form_data ) {
			$result  = $this->wpforms_convert( $form_data );
			$post_id = wp_insert_post(
				array(
					'post_type'   => self::CPT_FORM,
					'post_status' => 'draft',
					'post_title'  => $result['title'],
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				$result['notes'][] = 'Koncept se nepodařilo vytvořit.';
				$post_id           = 0;
			} else {
				update_post_meta( $post_id, '_wkf_fields_def', $result['rows'] );
				update_post_meta( $post_id, '_wkf_button', $result['config']['button'] );
				update_post_meta( $post_id, '_wkf_recipient', $result['config']['recipient'] );
				update_post_meta( $post_id, '_wkf_thankyou', (int) $result['config']['thankyou'] );
				update_post_meta( $post_id, '_wkf_legacy_wpforms', $result['legacy_id'] ? (string) $result['legacy_id'] : '' );
				update_post_meta( $post_id, '_wkf_webhook', '1' );
				$this->save_form_config_meta( $post_id, $result['config'] );
			}
			$report[] = array( 'title' => $result['title'], 'fields' => count( $result['rows'] ), 'post_id' => $post_id, 'notes' => $result['notes'] );
		}
		set_transient( 'wkf_wpforms_report_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::CPT_FORM ) );
		exit;
	}

	/** Převod jedné definice WPForms na řádky builderu, konfiguraci a report rozdílů. */
	private function wpforms_convert( $data ) {
		$notes    = array();
		$fields   = isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();
		$settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
		$title    = ! empty( $settings['form_title'] ) ? sanitize_text_field( $settings['form_title'] ) : 'Importovaný formulář';

		// Pořadí: tak, jak je WPForms ukládá (nikdy nepřerovnávat podle číselných klíčů).
		// Pole uvnitř rozložení se zařadí na místo rozložení, v pořadí sloupců.
		$in_layout = array();
		foreach ( $fields as $f ) {
			if ( isset( $f['type'] ) && 'layout' === $f['type'] && ! empty( $f['columns'] ) ) {
				foreach ( (array) $f['columns'] as $col ) {
					foreach ( isset( $col['fields'] ) ? (array) $col['fields'] : array() as $fid ) {
						$in_layout[ (string) $fid ] = true;
					}
				}
			}
		}
		$by_id = array();
		foreach ( $fields as $f ) {
			if ( isset( $f['id'] ) ) {
				$by_id[ (string) $f['id'] ] = $f;
			}
		}
		$ordered = array(); // seznam [field, half]
		foreach ( $fields as $f ) {
			if ( ! isset( $f['type'] ) ) {
				continue;
			}
			if ( 'layout' === $f['type'] ) {
				$cols = ! empty( $f['columns'] ) ? array_values( (array) $f['columns'] ) : array();
				$two  = 2 === count( $cols );
				foreach ( $cols as $col ) {
					$half = $two || ( isset( $col['width'] ) && (int) $col['width'] <= 50 );
					foreach ( isset( $col['fields'] ) ? (array) $col['fields'] : array() as $fid ) {
						if ( isset( $by_id[ (string) $fid ] ) ) {
							$child = $by_id[ (string) $fid ];
							// Podmínka na rozložení se dědí na pole uvnitř (pokud vlastní nemají).
							if ( ! empty( $f['conditional_logic'] ) && ! empty( $f['conditionals'] ) ) {
								if ( empty( $child['conditional_logic'] ) ) {
									$child['conditional_logic'] = '1';
									$child['conditional_type'] = isset( $f['conditional_type'] ) ? $f['conditional_type'] : 'show';
									$child['conditionals']     = $f['conditionals'];
								} else {
									$notes[] = 'Pole „' . ( isset( $child['label'] ) ? $child['label'] : $fid ) . '" má vlastní podmínku i podmínku na rozložení; použita vlastní.';
								}
							}
							$ordered[] = array( $child, $half );
						}
					}
				}
				continue;
			}
			if ( isset( $in_layout[ (string) $f['id'] ] ) ) {
				continue;
			}
			$css  = isset( $f['css'] ) ? (string) $f['css'] : '';
			$half = (bool) preg_match( '/wpforms-one-(half|third|fourth)/', $css );
			$ordered[] = array( $f, $half );
		}

		// 1. průchod: řádky + mapa ID -> klíč.
		$progress_setting = 'none';
		$rows      = array();
		$id_to_key = array();
		$used_keys = array();
		$choice_labels = array(); // id => [choiceKey => label]
		$ops_map   = array( '==' => 'eq', '!=' => 'neq', 'e' => 'empty', '!e' => 'not_empty', 'c' => 'contains', '!c' => 'not_contains', '^' => 'starts', '~' => 'ends', '>' => 'gt', '<' => 'lt' );

		foreach ( $ordered as $pair ) {
			list( $f, $half ) = $pair;
			$type  = $f['type'];
			$label = isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : '';
			$raw   = array(
				'label'       => $label,
				'required'    => ! empty( $f['required'] ) ? 1 : 0,
				'half'        => $half ? 1 : 0,
				'hint'        => isset( $f['description'] ) ? wp_strip_all_tags( $f['description'] ) : '',
				'placeholder' => isset( $f['placeholder'] ) ? $f['placeholder'] : '',
				'default'     => isset( $f['default_value'] ) ? $f['default_value'] : '',
			);
			$choices = isset( $f['choices'] ) && is_array( $f['choices'] ) ? $f['choices'] : array();
			$labels_for_id = array();
			$defaults = array();
			$opt_lines = array();
			foreach ( $choices as $ckey => $choice ) {
				$cl = isset( $choice['label'] ) ? sanitize_text_field( $choice['label'] ) : '';
				if ( '' === $cl ) {
					continue;
				}
				$labels_for_id[ (string) $ckey ] = $cl;
				$opt_lines[] = $cl;
				if ( ! empty( $choice['default'] ) ) {
					$defaults[] = $cl;
				}
			}
			$inline = isset( $f['input_columns'] ) && '' !== (string) $f['input_columns'] && 'inline' !== $f['input_columns'] ? (int) $f['input_columns'] > 1 : ( isset( $f['input_columns'] ) && 'inline' === $f['input_columns'] );

			switch ( $type ) {
				case 'text':
				case 'textarea':
				case 'email':
				case 'url':
				case 'hidden':
					$raw['type'] = $type;
					break;
				case 'phone':
					$raw['type'] = 'tel';
					break;
				case 'name':
					$raw['type'] = 'text';
					if ( isset( $f['format'] ) && 'simple' !== $f['format'] ) {
						$notes[] = 'Pole „' . $label . '" (jméno ve formátu ' . $f['format'] . ') převedeno na jedno textové pole.';
					}
					break;
				case 'number':
				case 'number-slider':
					$raw['type'] = 'number';
					$raw['min']  = isset( $f['min'] ) ? $f['min'] : '';
					$raw['max']  = isset( $f['max'] ) ? $f['max'] : '';
					$raw['step'] = isset( $f['step'] ) ? $f['step'] : '';
					break;
				case 'select':
					$raw['type']    = 'select';
					$raw['options'] = implode( "\n", $opt_lines );
					$raw['default'] = implode( ', ', $defaults );
					if ( ! empty( $f['multiple'] ) ) {
						$raw['type']   = 'checkbox';
						$raw['choice'] = 'multi';
						$notes[]       = 'Pole „' . $label . '" (vícenásobný výběr) převedeno na zaškrtávací pole.';
					}
					break;
				case 'radio':
					$raw['type']    = 'checkbox';
					$raw['choice']  = 'single';
					$raw['options'] = implode( "\n", $opt_lines );
					$raw['default'] = implode( ', ', $defaults );
					$raw['layout']  = $inline ? 'horizontal' : 'vertical';
					break;
				case 'checkbox':
					$raw['type']    = 'checkbox';
					$raw['choice']  = 'multi';
					$raw['options'] = implode( "\n", $opt_lines );
					$raw['default'] = implode( ', ', $defaults );
					$raw['layout']  = $inline ? 'horizontal' : 'vertical';
					break;
				case 'file-upload':
					$raw['type']      = 'file';
					$exts             = isset( $f['extensions'] ) ? array_map( 'trim', explode( ',', strtolower( $f['extensions'] ) ) ) : array();
					$raw['filetypes'] = $exts ? implode( ',', array_intersect( $exts, $this->builder_file_types() ) ) : '';
					$dropped          = array_diff( $exts, $this->builder_file_types() );
					if ( $dropped ) {
						$notes[] = 'Pole „' . $label . '": přípony ' . implode( ', ', $dropped ) . ' nejsou v pluginu povolené a byly vynechány.';
					}
					$raw['maxmb']    = ! empty( $f['max_size'] ) ? (int) $f['max_size'] : 10;
					$raw['maxfiles'] = ! empty( $f['max_file_number'] ) ? (int) $f['max_file_number'] : 1;
					break;
				case 'html':
				case 'content':
					$raw['type']    = 'content';
					$raw['content'] = isset( $f['code'] ) ? $f['code'] : ( isset( $f['content'] ) ? $f['content'] : '' );
					$raw['label']   = $label ? $label : 'Obsahový blok';
					break;
				case 'divider':
					$raw['type'] = 'heading';
					break;
				case 'date-time':
					$raw['type'] = 'date';
					if ( isset( $f['format'] ) && 'date' !== $f['format'] ) {
						$notes[] = 'Pole „' . $label . '" (formát ' . $f['format'] . ') převedeno na pouhé datum bez času.';
					}
					break;
				case 'gdpr-checkbox':
					$raw['type']     = 'checkbox';
					$raw['choice']   = 'multi';
					$raw['required'] = 1;
					$raw['options']  = isset( $f['choices'][1]['label'] ) ? sanitize_text_field( $f['choices'][1]['label'] ) : 'Souhlasím se zpracováním osobních údajů';
					break;
				case 'pagebreak':
					$position = isset( $f['position'] ) ? $f['position'] : '';
					if ( 'top' === $position ) {
						$indicator = isset( $f['indicator'] ) ? $f['indicator'] : 'none';
						$progress_setting = 'none' === $indicator ? 'none' : ( 'progress' === $indicator ? 'text' : 'bar' );
						continue 2; // horní zlom jen nese nastavení ukazatele
					}
					if ( 'bottom' === $position ) {
						continue 2;
					}
					$raw['type']     = 'pagebreak';
					$raw['label']    = isset( $f['title'] ) ? sanitize_text_field( $f['title'] ) : '';
					$raw['btn_next'] = isset( $f['next'] ) ? sanitize_text_field( $f['next'] ) : '';
					$raw['btn_prev'] = isset( $f['prev'] ) ? sanitize_text_field( $f['prev'] ) : '';
					$raw['hide_prev'] = empty( $f['prev_toggle'] ) ? 1 : 0;
					break;
				default:
					$notes[] = 'Pole „' . $label . '" typu ' . $type . ' není podporováno a bylo vynecháno.';
					continue 2;
			}

			$raw['wpf_id'] = (string) $f['id']; // původní ID pole (migrace záznamů)
			$row = $this->normalize_row( $raw );
			if ( '' === $row['role'] ) {
				$row['role'] = $this->suggest_role( $row['type'], $row['label'] );
			}
			$key = $row['role'] && ! isset( $used_keys[ $row['role'] ] ) ? $row['role'] : str_replace( '-', '_', sanitize_key( remove_accents( $row['label'] ) ) );
			$key = $key ? $key : ( 'pagebreak' === $row['type'] ? 'krok' : 'pole' );
			$base = $key;
			$n    = 2;
			while ( isset( $used_keys[ $key ] ) ) {
				$key = $base . '_' . $n;
				$n++;
			}
			$used_keys[ $key ] = true;
			$row['key']        = $key;
			$id_to_key[ (string) $f['id'] ] = $key;
			$choice_labels[ (string) $f['id'] ] = $labels_for_id;
			$row['_wpf'] = $f; // dočasně pro 2. průchod
			$rows[]      = $row;
		}

		// 2. průchod: podmínky (skupiny a pravidla, režim zobrazit/skrýt).
		foreach ( $rows as $i => $row ) {
			$f = $row['_wpf'];
			unset( $rows[ $i ]['_wpf'] );
			if ( empty( $f['conditional_logic'] ) || empty( $f['conditionals'] ) ) {
				continue;
			}
			$groups = array();
			foreach ( (array) $f['conditionals'] as $group ) {
				$clean = array();
				foreach ( (array) $group as $rule ) {
					$rid = isset( $rule['field'] ) ? (string) $rule['field'] : '';
					$op  = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';
					if ( '' === $rid || ! isset( $id_to_key[ $rid ] ) ) {
						$notes[] = 'Podmínka u pole „' . $row['label'] . '" odkazuje na neexistující nebo nepřevedené pole a byla vynechána.';
						continue;
					}
					if ( ! isset( $ops_map[ $op ] ) ) {
						$notes[] = 'Podmínka u pole „' . $row['label'] . '": operátor ' . $op . ' není podporován, pravidlo vynecháno.';
						continue;
					}
					$value = isset( $rule['value'] ) ? (string) $rule['value'] : '';
					if ( isset( $choice_labels[ $rid ][ $value ] ) ) {
						$value = $choice_labels[ $rid ][ $value ]; // klíč volby -> popisek
					}
					$clean[] = array( 'field' => $id_to_key[ $rid ], 'op' => $ops_map[ $op ], 'value' => $value );
				}
				if ( $clean ) {
					$groups[] = $clean;
				}
			}
			$rows[ $i ]['cond_mode']  = isset( $f['conditional_type'] ) && 'hide' === $f['conditional_type'] ? 'hide' : 'show';
			$rows[ $i ]['cond_rules'] = $groups;
		}
		$rows = array_values( $rows );

		// Nastavení formuláře z první aktivní notifikace a potvrzení.
		$replace_tags = function ( $text ) use ( $id_to_key, &$notes ) {
			$text = (string) $text;
			$text = preg_replace_callback( '/\{field_id="(\d+)"\}/', function ( $m ) use ( $id_to_key ) {
				return isset( $id_to_key[ $m[1] ] ) ? '{' . $id_to_key[ $m[1] ] . '}' : '';
			}, $text );
			$text = str_replace( array( '{admin_email}', '{site_name}', '{all_fields}', '{entry_id}' ), array( get_option( 'admin_email' ), get_bloginfo( 'name' ), '', '' ), $text );
			if ( preg_match_all( '/\{[a-z_]+(?:="[^"]*")?\}/', $text, $m ) ) {
				foreach ( array_unique( $m[0] ) as $tag ) {
					if ( ! in_array( $tag, array( '{page_title}', '{page_url}', '{form_name}' ), true ) && ! preg_match( '/^\{[a-z0-9_]+\}$/', $tag ) ) {
						$notes[] = 'Zástupná značka ' . $tag . ' nemá ekvivalent a byla ponechána beze změny.';
					}
				}
			}
			return trim( $text );
		};

		$config = array( 'button' => ! empty( $settings['submit_text'] ) ? sanitize_text_field( $settings['submit_text'] ) : 'Odeslat', 'recipient' => '', 'thankyou' => 0, 'subject' => '', 'from_name' => '', 'from_email' => '', 'replyto' => '', 'confirm' => '', 'retention' => 0, 'progress' => $progress_setting, 'progress_text' => '' );
		$notifs = isset( $settings['notifications'] ) && is_array( $settings['notifications'] ) ? $settings['notifications'] : array();
		$first  = null;
		foreach ( $notifs as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			if ( null === $first ) {
				$first = $n;
			} else {
				$notes[] = 'Další notifikace „' . ( isset( $n['notification_name'] ) ? $n['notification_name'] : '' ) . '" nebyla převedena – plugin má jednu notifikaci na formulář.';
			}
		}
		if ( $first ) {
			$config['recipient']  = $this->sanitize_email_list( $replace_tags( isset( $first['email'] ) ? $first['email'] : '' ) );
			$config['subject']    = sanitize_text_field( $replace_tags( isset( $first['subject'] ) ? $first['subject'] : '' ) );
			$config['from_name']  = sanitize_text_field( $replace_tags( isset( $first['sender_name'] ) ? $first['sender_name'] : '' ) );
			$config['from_email'] = sanitize_email( $replace_tags( isset( $first['sender_address'] ) ? $first['sender_address'] : '' ) );
			if ( ! empty( $first['replyto'] ) && preg_match( '/\{field_id="(\d+)"\}/', $first['replyto'], $rm ) && isset( $id_to_key[ $rm[1] ] ) ) {
				$config['replyto'] = $id_to_key[ $rm[1] ];
			}
			if ( $config['from_email'] === get_option( 'admin_email' ) ) {
				$config['from_email'] = ''; // shodné s globálním = použít globální
			}
		}
		$confs = isset( $settings['confirmations'] ) && is_array( $settings['confirmations'] ) ? array_values( $settings['confirmations'] ) : array();
		if ( count( $confs ) > 1 ) {
			$notes[] = 'Formulář má více potvrzení; převedeno jen první.';
		}
		if ( $confs && is_array( $confs[0] ) ) {
			$c = $confs[0];
			$ctype = isset( $c['type'] ) ? $c['type'] : 'message';
			if ( 'page' === $ctype && ! empty( $c['page'] ) ) {
				$config['thankyou'] = (int) $c['page'];
			} elseif ( 'redirect' === $ctype ) {
				$notes[] = 'Potvrzení přesměrovává na externí URL (' . ( isset( $c['redirect'] ) ? $c['redirect'] : '' ) . ') – plugin podporuje jen stránky webu; nastavte děkovací stránku ručně.';
			} else {
				$config['confirm'] = wp_kses_post( $replace_tags( isset( $c['message'] ) ? $c['message'] : '' ) );
			}
		}
		if ( ! empty( $settings['disable_entries'] ) ) {
			$notes[] = 'Ve WPForms bylo ukládání záznamů vypnuté; plugin záznamy ukládá – případně nastavte dobu uchování.';
		}

		return array(
			'title'     => $title,
			'rows'      => $rows,
			'config'    => $config,
			'legacy_id' => isset( $data['id'] ) ? (int) $data['id'] : 0,
			'notes'     => array_values( array_unique( $notes ) ),
		);
	}

	/* =========================================================
	 * Pozvánka do akademického výzkumu (jen informace, žádný sběr)
	 * ======================================================= */

	/**
	 * Běží už na webu nástroj GEO Research? Plugin do výzkumu sám nic
	 * neodesílá – pozvánku jen skryje, aby se nenabízelo něco, co web má.
	 */
	private function research_tool_present() {
		if ( defined( 'GEO_RESEARCH_VERSION' ) || class_exists( 'GEO_Research' ) || class_exists( 'Geo_Research' ) || function_exists( 'geo_research_boot' ) ) {
			return true;
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( false !== strpos( $plugin, 'geo-research' ) ) {
				return true;
			}
		}
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $plugin ) {
				if ( false !== strpos( $plugin, 'geo-research' ) ) {
					return true;
				}
			}
		}
		// Varianta v jednom souboru pro weby mimo WordPress i vedle něj.
		return file_exists( ABSPATH . 'geo-research-lite.php' );
	}

	/** Pozvánka na obrazovkách pluginu; skryje se po zavření i při zapojeném webu. */
	public function research_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ours = in_array( $screen->post_type, array( self::CPT_FORM, self::CPT_ENTRY ), true ) || false !== strpos( (string) $screen->id, 'wkf-settings' );
		if ( ! $ours || get_user_meta( get_current_user_id(), '_wkf_research_dismissed', true ) || $this->research_tool_present() ) {
			return;
		}
		$nonce = wp_create_nonce( 'wkf_dismiss_research' );
		echo '<div class="notice notice-info is-dismissible" id="wkf-research-notice"><p><strong>Webklient Forms je zdarma a pod licencí MIT.</strong> Když chcete tvůrcům oplatit, zapojte web do akademického výzkumu Slezské univerzity o tom, jak generativní AI čte české weby – dozvíte se, kteří roboti AI k vám chodí a kolik lidí přijde z odpovědí AI systémů. Je to <em>samostatný</em> plugin se souhlasem a vlastním poučením; Webklient Forms sám nic neodesílá. <a href="https://geo.kubicek.ai/spoluprace/" target="_blank" rel="noopener">Podrobnosti a zapojení →</a></p>';
		echo '<script>(function(){var n=document.getElementById("wkf-research-notice");n&&n.addEventListener("click",function(e){if(!e.target.classList.contains("notice-dismiss"))return;var d=new FormData();d.append("action","wkf_dismiss_research");d.append("nonce","' . $nonce . '");fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:d});});})();</script></div>';
	}

	public function dismiss_research_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'wkf_dismiss_research', 'nonce' );
		update_user_meta( get_current_user_id(), '_wkf_research_dismissed', 1 );
		wp_send_json_success();
	}

	/* =========================================================
	 * Automatické aktualizace z GitHubu
	 * ======================================================= */

	/** Základní URL API pevně nastaveného repozitáře pluginu. */
	private function update_api_url( $path = '' ) {
		return 'https://api.github.com/repos/' . WKF_UPDATE_REPO . $path;
	}

	/** Zapamatuje si, proč se vydání nepodařilo načíst (pro hlášku v nastavení). */
	private function remember_update_error( $reason ) {
		set_site_transient( 'wkf_update_error', (string) $reason, 6 * HOUR_IN_SECONDS );
	}

	/** Argumenty requestu na GitHub – veřejné API, bez autorizace. */
	private function update_request_args() {
		return array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'webklient-forms/' . WKF_VERSION,
			),
		);
	}

	/**
	 * Poslední vydání z GitHubu (cache 6 h). Vrací verzi, URL balíčku,
	 * popis změn a datum – nebo prázdné pole, když se nic nenajde.
	 */
	private function latest_release( $force = false ) {
		$cache_key = 'wkf_update_' . md5( WKF_UPDATE_REPO . WKF_VERSION );
		if ( ! $force ) {
			$cached = get_site_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// Jen řádná vydání – pre-release se záměrně nenabízejí.
		$response = wp_remote_get( $this->update_api_url( '/releases/latest' ), $this->update_request_args() );
		if ( is_wp_error( $response ) ) {
			$this->remember_update_error( 'Web se nespojil s api.github.com: ' . $response->get_error_message() );
			set_site_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			if ( 404 === $code ) {
				// Typický stav nového repozitáře: existuje, ale zatím bez řádného vydání.
				$this->remember_update_error( 'Repozitář ' . WKF_UPDATE_REPO . ' zatím nemá žádné řádné vydání (Releases). Aktualizace se nabídne, až vydání vznikne.' );
			} elseif ( 403 === $code || 429 === $code ) {
				$this->remember_update_error( 'GitHub dočasně odmítl dotaz (limit počtu dotazů, kód ' . $code . '). Zkuste to později.' );
			} else {
				$this->remember_update_error( 'GitHub odpověděl kódem ' . $code . '.' );
			}
			set_site_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			$this->remember_update_error( 'Odpověď GitHubu neobsahuje označení vydání (tag).' );
			set_site_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		// Balíček: přiložené ZIP assety mají přednost před zdrojovým archivem.
		$package = isset( $body['zipball_url'] ) ? $body['zipball_url'] : '';
		foreach ( isset( $body['assets'] ) ? (array) $body['assets'] : array() as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['browser_download_url'], -4 ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}

		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'   => $package,
			'changelog' => isset( $body['body'] ) ? (string) $body['body'] : '',
			'date'      => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
		);
		delete_site_transient( 'wkf_update_error' );
		set_site_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** Ohlásí WordPressu dostupnou aktualizaci. */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->latest_release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}
		$basename = plugin_basename( WKF_PLUGIN_FILE );
		$item     = array(
			'id'          => $basename,
			'slug'        => dirname( $basename ),
			'plugin'      => $basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
		);
		if ( version_compare( $release['version'], WKF_VERSION, '>' ) ) {
			$transient->response[ $basename ] = (object) $item;
		} else {
			$transient->no_update[ $basename ] = (object) $item;
		}
		return $transient;
	}

	/** Detail aktualizace v okně „Zobrazit podrobnosti". */
	public function update_plugin_info( $result, $action, $args ) {
		$basename = plugin_basename( WKF_PLUGIN_FILE );
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( $basename ) !== $args->slug ) {
			return $result;
		}
		$release = $this->latest_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Webklient Forms',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://www.webklient.cz">Webklient.cz</a>',
			'homepage'      => 'https://github.com/' . WKF_UPDATE_REPO,
			'download_link' => $release['package'],
			'last_updated'  => $release['date'],
			'sections'      => array(
				'description' => 'Formuláře pro WordPress – builder, podmíněná logika, vícekrokové formuláře, záznamy, exporty a napojení na CRM.',
				'changelog'   => $release['changelog'] ? wpautop( wp_kses_post( $release['changelog'] ) ) : 'Popis změn najdete na GitHubu.',
			),
		);
	}

	/**
	 * Archivy z GitHubu mají v názvu složky commit hash; WordPress by plugin
	 * nainstaloval jako nový. Složka se proto přejmenuje na slug pluginu.
	 */
	public function fix_update_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		$basename = plugin_basename( WKF_PLUGIN_FILE );
		if ( empty( $extra['plugin'] ) || $extra['plugin'] !== $basename ) {
			return $source;
		}
		$desired = trailingslashit( $remote_source ) . dirname( $basename ) . '/';
		if ( $source === $desired ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}
		return $source;
	}

	/** Ruční „Zkontrolovat aktualizaci" z nastavení pluginu. */
	public function force_update_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( 'wkf_check_update' );

		$release = $this->latest_release( true );
		delete_site_transient( 'update_plugins' );
		$status = empty( $release['version'] )
			? 'fail'
			: ( version_compare( $release['version'], WKF_VERSION, '>' ) ? 'new:' . $release['version'] : 'current' );
		wp_safe_redirect( add_query_arg( 'wkf_update', rawurlencode( $status ), admin_url( 'edit.php?post_type=' . self::CPT_ENTRY . '&page=wkf-settings' ) ) );
		exit;
	}

	/* =========================================================
	 * Migrace záznamů z WPForms
	 * ======================================================= */

	private function wpforms_entries_table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpforms_entries';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** Blok s ovládáním migrace ve výpisu záznamů (dávkově přes AJAX). */
	private function render_migration_box() {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforms_entries" );
		$state = get_option( 'wkf_migration_state', array() );
		$state = is_array( $state ) ? $state : array();
		$nonce = wp_create_nonce( 'wkf_migrate' );
		echo '<div class="notice" style="padding:12px;border-left-color:#960000;" id="wkf-migration">';
		echo '<strong>Migrace záznamů z WPForms:</strong> v tabulkách WPForms je ' . $total . ' záznamů. ';
		echo '<button type="button" class="button" data-wkf-migrate="dry">Spustit nanečisto</button> ';
		echo '<button type="button" class="button button-primary" data-wkf-migrate="live">' . ( ! empty( $state['cursor'] ) && empty( $state['done'] ) ? 'Pokračovat v ostrém běhu' : 'Spustit ostrý běh' ) . '</button> ';
		if ( ! empty( $state['cursor'] ) ) {
			echo '<button type="button" class="button-link" data-wkf-migrate="reset">začít ostrý běh znovu od začátku</button> ';
		}
		echo '<span class="description">Data WPForms se nemění. Zpracovává se po dávkách, běh lze přerušit a navázat.</span>';
		echo '<div id="wkf-migration-out" style="margin-top:10px;font-family:monospace;font-size:12px;white-space:pre-wrap;max-height:320px;overflow:auto;background:#fff;padding:8px;border:1px solid #dcdcde;display:none;"></div>';
		echo '<script>(function(){var box=document.getElementById("wkf-migration"),out=document.getElementById("wkf-migration-out");
box.addEventListener("click",function(e){var b=e.target.closest("[data-wkf-migrate]");if(!b)return;var mode=b.getAttribute("data-wkf-migrate");
if(mode==="live"&&!confirm("Spustit ostrý běh migrace? Záznamy se zapíší do Webklient Forms."))return;
out.style.display="block";out.textContent=(mode==="dry"?"Běh nanečisto…":"Ostrý běh…")+"\n";var cursor=0,stats=null;box.querySelectorAll("button").forEach(function(x){x.disabled=true;});
function step(){var d=new FormData();d.append("action","wkf_migrate_batch");d.append("nonce","' . $nonce . '");d.append("mode",mode);d.append("cursor",cursor);
fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:d}).then(function(r){return r.json();}).then(function(j){if(!j||!j.success){out.textContent+="Chyba: "+(j&&j.data&&j.data.message||"neznámá")+"\n";return;}
var r=j.data;cursor=r.cursor;out.textContent+=r.log.join("\n")+(r.log.length?"\n":"");out.scrollTop=out.scrollHeight;
if(r.done){out.textContent+="\n=== PROTOKOL ===\n"+r.summary+"\n";box.querySelectorAll("button").forEach(function(x){x.disabled=false;});if(mode==="live"){setTimeout(function(){location.reload();},1500);}}else{step();}}).catch(function(err){out.textContent+="Chyba spojení: "+err+"\n";});}
step();});})();</script></div>';
	}

	/** Jedna dávka migrace (dry = nic nezapisuje). Kurzor = poslední zpracované entry_id. */
	public function migrate_entries_batch() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Nedostatečná oprávnění.' ) );
		}
		check_ajax_referer( 'wkf_migrate', 'nonce' );
		if ( ! $this->wpforms_entries_table_exists() ) {
			wp_send_json_error( array( 'message' => 'Tabulka wpforms_entries neexistuje.' ) );
		}

		$mode   = isset( $_POST['mode'] ) && 'live' === $_POST['mode'] ? 'live' : ( isset( $_POST['mode'] ) && 'reset' === $_POST['mode'] ? 'reset' : 'dry' );
		$cursor = isset( $_POST['cursor'] ) ? (int) $_POST['cursor'] : 0;
		$log    = array();

		$state = get_option( 'wkf_migration_state', array() );
		$state = is_array( $state ) ? $state : array();
		if ( 'reset' === $mode ) {
			delete_option( 'wkf_migration_state' );
			wp_send_json_success( array( 'done' => true, 'cursor' => 0, 'log' => array( 'Stav ostrého běhu vymazán; příští ostrý běh začne od začátku.' ), 'summary' => '' ) );
		}
		// Ostrý běh navazuje na uložený kurzor (přerušení uprostřed dávky = pokračuje se od poslední dokončené).
		if ( 'live' === $mode && 0 === $cursor && ! empty( $state['cursor'] ) && empty( $state['done'] ) ) {
			$cursor = (int) $state['cursor'];
			$log[]  = 'Navazuji na předchozí běh od záznamu #' . $cursor . '.';
		}
		$stats = 'live' === $mode && $cursor > 0 && ! empty( $state['stats'] ) ? $state['stats'] : array( 'forms' => array(), 'migrated' => 0, 'skipped' => array(), 'files_missing' => 0, 'unmapped_fields' => array() );

		// Mapování WPForms form_id -> cílový formulář (z importéru: _wkf_legacy_wpforms).
		$map = array();
		foreach ( get_posts( array( 'post_type' => self::CPT_FORM, 'post_status' => 'publish', 'posts_per_page' => -1 ) ) as $form_post ) {
			$ids = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $form_post->ID, '_wkf_legacy_wpforms', true ) ) ) );
			foreach ( $ids as $legacy_id ) {
				$map[ (int) $legacy_id ] = $form_post;
			}
		}

		$batch = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpforms_entries WHERE entry_id > %d ORDER BY entry_id ASC LIMIT 40", $cursor ), ARRAY_A );
		if ( ! $batch ) {
			if ( 'live' === $mode ) {
				$state['done'] = true;
				update_option( 'wkf_migration_state', array_merge( $state, array( 'cursor' => $cursor, 'stats' => $stats ) ), false );
			}
			wp_send_json_success( array( 'done' => true, 'cursor' => $cursor, 'log' => $log, 'summary' => $this->migration_summary( $stats, $mode ) ) );
		}

		// Existující migrované záznamy (idempotence).
		$existing = array();
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wkf_source'" ) as $src ) {
			$existing[ $src ] = true;
		}
		$upload = wp_upload_dir();

		foreach ( $batch as $entry ) {
			$cursor  = (int) $entry['entry_id'];
			$form_id = (int) $entry['form_id'];
			$source  = 'wpforms:' . $cursor;
			$fkey    = 'WPForms #' . $form_id;
			if ( ! isset( $stats['forms'][ $fkey ] ) ) {
				$stats['forms'][ $fkey ] = array( 'migrated' => 0, 'skipped' => 0 );
			}

			if ( ! isset( $map[ $form_id ] ) ) {
				$wp_form = get_post( $form_id );
				$reason  = $wp_form && 'trash' === $wp_form->post_status ? 'formulář je v koši a nebyl převeden importérem' : 'formulář nebyl převeden importérem';
				$this->migration_skip( $stats, $fkey, $reason );
				continue;
			}
			if ( isset( $existing[ $source ] ) ) {
				$this->migration_skip( $stats, $fkey, 'už migrováno dříve' );
				continue;
			}
			$target    = $map[ $form_id ];
			$retention = (int) get_post_meta( $target->ID, '_wkf_retention', true );
			if ( $retention > 0 && strtotime( $entry['date'] ) < time() - $retention * DAY_IN_SECONDS ) {
				$this->migration_skip( $stats, $fkey, 'starší než doba uchování (' . $retention . ' dní) u cílového formuláře' );
				continue;
			}

			// Mapování polí: původní ID -> klíč (wpf_id z importéru), záložně podle popisku.
			$rows     = get_post_meta( $target->ID, '_wkf_fields_def', true );
			$rows     = is_array( $rows ) ? $rows : array();
			$by_wpfid = array();
			$by_label = array();
			foreach ( $rows as $row ) {
				if ( empty( $row['key'] ) ) {
					continue;
				}
				if ( ! empty( $row['wpf_id'] ) ) {
					$by_wpfid[ (string) $row['wpf_id'] ] = $row;
				}
				$by_label[ mb_strtolower( trim( (string) $row['label'] ) ) ] = $row;
			}

			$fields_json = json_decode( (string) $entry['fields'], true );
			$labels      = array();
			$values      = array();
			$files       = array();
			foreach ( is_array( $fields_json ) ? $fields_json : array() as $fid => $fd ) {
				if ( ! is_array( $fd ) ) {
					continue;
				}
				$name  = isset( $fd['name'] ) ? sanitize_text_field( $fd['name'] ) : 'Pole ' . $fid;
				$ftype = isset( $fd['type'] ) ? $fd['type'] : '';
				$value = isset( $fd['value'] ) ? (string) $fd['value'] : '';

				if ( 'file-upload' === $ftype ) {
					$items = ! empty( $fd['value_raw'] ) && is_array( $fd['value_raw'] ) ? $fd['value_raw'] : ( $value ? array( array( 'value' => $value, 'file' => isset( $fd['file'] ) ? $fd['file'] : basename( $value ), 'file_user_name' => isset( $fd['file_user_name'] ) ? $fd['file_user_name'] : '' ) ) : array() );
					$names = array();
					foreach ( $items as $item ) {
						$url  = isset( $item['value'] ) ? $item['value'] : ( isset( $item['url'] ) ? $item['url'] : '' );
						if ( ! $url ) {
							continue;
						}
						$local = str_replace( $upload['baseurl'], $upload['basedir'], $url );
						$orig  = ! empty( $item['file_user_name'] ) ? $item['file_user_name'] : basename( $url );
						if ( ! file_exists( $local ) ) {
							$stats['files_missing']++;
							$log[] = '#' . $cursor . ': soubor nenalezen – ' . $url;
							$names[] = $orig . ' (soubor nenalezen)';
							continue;
						}
						if ( 'live' === $mode ) {
							$dest_dir = trailingslashit( $upload['basedir'] ) . 'webklient-forms/migrated/' . $cursor;
							wp_mkdir_p( $dest_dir );
							$dest = $dest_dir . '/' . wp_unique_filename( $dest_dir, sanitize_file_name( $orig ) );
							if ( copy( $local, $dest ) ) {
								$files[] = array( 'field' => $name, 'file' => $dest, 'url' => str_replace( $upload['basedir'], $upload['baseurl'], $dest ), 'name' => basename( $dest ) );
							} else {
								$log[] = '#' . $cursor . ': soubor se nepodařilo zkopírovat – ' . $local;
							}
						}
						$names[] = $orig;
					}
					$value = implode( "\n", $names );
				}
				if ( 'name' === $ftype && '' === $value ) {
					$value = trim( ( isset( $fd['first'] ) ? $fd['first'] : '' ) . ' ' . ( isset( $fd['last'] ) ? $fd['last'] : '' ) );
				}
				$value = sanitize_textarea_field( $value );
				if ( 'divider' === $ftype || 'html' === $ftype || 'content' === $ftype || 'pagebreak' === $ftype ) {
					continue;
				}

				$row = isset( $by_wpfid[ (string) $fid ] ) ? $by_wpfid[ (string) $fid ] : ( isset( $by_label[ mb_strtolower( $name ) ] ) ? $by_label[ mb_strtolower( $name ) ] : null );
				if ( $row ) {
					$labels[ $row['label'] ] = $value;
					$values[ $row['key'] ]   = $value;
					if ( ! empty( $row['role'] ) ) {
						$values[ $row['role'] ] = $value;
					}
				} else {
					$labels[ 'Původní pole: ' . $name ] = $value; // bez protějšku – zůstane čitelné v detailu i exportu
					$stats['unmapped_fields'][ $fkey . ' / ' . $name ] = true;
				}
			}

			if ( 'dry' === $mode ) {
				$stats['migrated']++;
				$stats['forms'][ $fkey ]['migrated']++;
				continue;
			}

			$display  = ! empty( $values['jmeno'] ) ? $values['jmeno'] : ( ! empty( $values['email'] ) ? $values['email'] : '' );
			$date     = $entry['date'] ? $entry['date'] : current_time( 'mysql' );
			$entry_id = wp_insert_post(
				array(
					'post_type'     => self::CPT_ENTRY,
					'post_status'   => 'publish',
					'post_title'    => sprintf( '%s – %s – %s', $target->post_title, $display, wp_date( 'j. n. Y H:i', strtotime( $date ) ) ),
					'post_date'     => $date,
					'post_date_gmt' => get_gmt_from_date( $date ),
				),
				true
			);
			if ( is_wp_error( $entry_id ) ) {
				$this->migration_skip( $stats, $fkey, 'záznam se nepodařilo založit' );
				continue;
			}
			update_post_meta( $entry_id, '_wkf_form_type', $target->post_name );
			update_post_meta( $entry_id, '_wkf_fields', $labels );
			update_post_meta( $entry_id, '_wkf_email', isset( $values['email'] ) ? $values['email'] : '' );
			update_post_meta( $entry_id, '_wkf_phone', isset( $values['telefon'] ) ? $values['telefon'] : '' );
			update_post_meta( $entry_id, '_wkf_ip', isset( $entry['ip_address'] ) ? sanitize_text_field( $entry['ip_address'] ) : '' );
			update_post_meta( $entry_id, '_wkf_mail_sent', 1 );
			update_post_meta( $entry_id, '_wkf_source', $source );
			update_post_meta( $entry_id, '_wkf_migrated_at', current_time( 'mysql' ) );
			if ( $files ) {
				update_post_meta( $entry_id, '_wkf_files', $files );
				update_post_meta( $entry_id, '_wkf_file_url', $files[0]['url'] );
				update_post_meta( $entry_id, '_wkf_file_path', $files[0]['file'] );
			}
			$existing[ $source ] = true;
			$stats['migrated']++;
			$stats['forms'][ $fkey ]['migrated']++;
		}

		if ( 'live' === $mode ) {
			update_option( 'wkf_migration_state', array( 'cursor' => $cursor, 'stats' => $stats, 'done' => false ), false );
		}
		$log[] = sprintf( 'Zpracováno po záznam #%d – převedeno %d, přeskočeno %d.', $cursor, $stats['migrated'], array_sum( $stats['skipped'] ) );
		wp_send_json_success( array( 'done' => false, 'cursor' => $cursor, 'log' => $log, 'summary' => '' ) );
	}

	private function migration_skip( &$stats, $fkey, $reason ) {
		$stats['skipped'][ $reason ] = isset( $stats['skipped'][ $reason ] ) ? $stats['skipped'][ $reason ] + 1 : 1;
		$stats['forms'][ $fkey ]['skipped']++;
	}

	private function migration_summary( $stats, $mode ) {
		$lines   = array( 'dry' === $mode ? 'Běh nanečisto – nic nebylo zapsáno.' : 'Ostrý běh dokončen.' );
		$lines[] = 'Převedeno: ' . (int) $stats['migrated'] . ', přeskočeno: ' . array_sum( $stats['skipped'] ) . ', chybějících souborů: ' . (int) $stats['files_missing'] . '.';
		foreach ( $stats['forms'] as $fkey => $c ) {
			$lines[] = '  ' . $fkey . ': převedeno ' . $c['migrated'] . ', přeskočeno ' . $c['skipped'];
		}
		foreach ( $stats['skipped'] as $reason => $n ) {
			$lines[] = '  přeskočeno – ' . $reason . ': ' . $n;
		}
		if ( ! empty( $stats['unmapped_fields'] ) ) {
			$lines[] = 'Pole bez protějšku (uložena jako „Původní pole: …"): ' . implode( '; ', array_keys( $stats['unmapped_fields'] ) );
		}
		return implode( "\n", $lines );
	}

	/** Uloží nová nastavení formuláře (předmět, odesílatel, hláška, retence) z pole config. */
	private function save_form_config_meta( $post_id, $config ) {
		update_post_meta( $post_id, '_wkf_subject', isset( $config['subject'] ) ? sanitize_text_field( $config['subject'] ) : '' );
		update_post_meta( $post_id, '_wkf_from_name', isset( $config['from_name'] ) ? sanitize_text_field( $config['from_name'] ) : '' );
		update_post_meta( $post_id, '_wkf_from_email', isset( $config['from_email'] ) ? sanitize_email( $config['from_email'] ) : '' );
		update_post_meta( $post_id, '_wkf_replyto', isset( $config['replyto'] ) ? sanitize_key( $config['replyto'] ) : '' );
		update_post_meta( $post_id, '_wkf_confirm', isset( $config['confirm'] ) ? wp_kses_post( $config['confirm'] ) : '' );
		update_post_meta( $post_id, '_wkf_retention', isset( $config['retention'] ) ? absint( $config['retention'] ) : 0 );
		update_post_meta( $post_id, '_wkf_progress', isset( $config['progress'] ) && in_array( $config['progress'], array( 'text', 'bar' ), true ) ? $config['progress'] : 'none' );
		update_post_meta( $post_id, '_wkf_progress_text', isset( $config['progress_text'] ) ? sanitize_text_field( $config['progress_text'] ) : '' );
	}

	/** Import definice formuláře z JSON – vytvoří koncept. */
	public function import_form_json() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( 'wkf_import_form' );

		if ( empty( $_FILES['wkf_import']['tmp_name'] ) ) {
			wp_die( 'Nebyl vybrán soubor.' );
		}
		$json = file_get_contents( $_FILES['wkf_import']['tmp_name'] );
		$data = json_decode( (string) $json, true );
		if ( empty( $data['wkf_form_export'] ) || empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			wp_die( 'Soubor není platný export formuláře Webklient Forms.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT_FORM,
				'post_status' => 'draft',
				'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : 'Importovaný formulář',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			wp_die( 'Formulář se nepodařilo vytvořit.' );
		}

		// Řádky projdou stejnou sanitizací jako při ručním uložení; klíče se zachovají.
		$allowed_types = array_merge( array_keys( $this->builder_field_types() ), array( 'select_zdroj', 'checkbox_zdroj' ) );
		$rows          = array();
		$used          = array();
		foreach ( $data['fields'] as $raw_row ) {
			if ( empty( $raw_row['type'] ) || ! in_array( $raw_row['type'], $allowed_types, true ) ) {
				continue;
			}
			$row = $this->normalize_row( $raw_row );
			$row['voppage'] = 0; // ID stránky je vázané na zdrojový web
			if ( '' === $row['key'] || isset( $used[ $row['key'] ] ) ) {
				$row['key'] = ( $row['key'] ? $row['key'] : 'pole' ) . '_' . ( count( $rows ) + 1 );
			}
			$used[ $row['key'] ] = true;
			$rows[]              = $row;
		}
		update_post_meta( $post_id, '_wkf_fields_def', $rows );

		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();
		update_post_meta( $post_id, '_wkf_button', isset( $config['button'] ) ? sanitize_text_field( $config['button'] ) : '' );
		update_post_meta( $post_id, '_wkf_recipient', isset( $config['recipient'] ) ? $this->sanitize_email_list( $config['recipient'] ) : '' );
		update_post_meta( $post_id, '_wkf_ar_enabled', empty( $config['ar_enabled'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_wkf_ar_subject', isset( $config['ar_subject'] ) ? sanitize_text_field( $config['ar_subject'] ) : '' );
		update_post_meta( $post_id, '_wkf_ar_body', isset( $config['ar_body'] ) ? wp_kses_post( $config['ar_body'] ) : '' );
		update_post_meta( $post_id, '_wkf_q_mode', isset( $config['q_mode'] ) && in_array( $config['q_mode'], array( 'custom', 'off' ), true ) ? $config['q_mode'] : '' );
		update_post_meta( $post_id, '_wkf_q_question', isset( $config['q_question'] ) ? sanitize_text_field( $config['q_question'] ) : '' );
		update_post_meta( $post_id, '_wkf_q_answer', isset( $config['q_answer'] ) ? sanitize_text_field( $config['q_answer'] ) : '' );
		$this->save_form_config_meta( $post_id, $config );

		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/* =========================================================
	 * Dashboard widget
	 * ======================================================= */

	public function register_dashboard_widget() {
		if ( current_user_can( 'edit_posts' ) ) {
			wp_add_dashboard_widget( 'wkf_dashboard', 'Formuláře – poptávky', array( $this, 'render_dashboard_widget' ) );
		}
	}

	public function render_dashboard_widget() {
		$days = isset( $_GET['wkf_dash'] ) && 30 === (int) $_GET['wkf_dash'] ? 30 : 7;

		$entries = get_posts(
			array(
				'post_type'      => self::CPT_ENTRY,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'date_query'     => array( array( 'after' => ( $days - 1 ) . ' days ago', 'inclusive' => true ) ),
			)
		);

		// Počty po dnech a po formulářích.
		$per_day  = array();
		$per_form = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$per_day[ wp_date( 'Y-m-d', strtotime( '-' . $i . ' days' ) ) ] = 0;
		}
		foreach ( $entries as $entry ) {
			$day = wp_date( 'Y-m-d', strtotime( $entry->post_date ) );
			if ( isset( $per_day[ $day ] ) ) {
				$per_day[ $day ]++;
			}
			$form_type              = (string) get_post_meta( $entry->ID, '_wkf_form_type', true );
			$per_form[ $form_type ] = isset( $per_form[ $form_type ] ) ? $per_form[ $form_type ] + 1 : 1;
		}

		// SVG graf (bez závislostí): plocha + linka + body v barvě tlačítka.
		$s      = $this->get_settings();
		$color  = $s['btn_bg'] ? $s['btn_bg'] : '#960000';
		$width  = 300;
		$height = 70;
		$max    = max( 1, max( $per_day ) );
		$count  = count( $per_day );
		$step   = $count > 1 ? $width / ( $count - 1 ) : $width;

		$points = array();
		$x      = 0;
		foreach ( $per_day as $value ) {
			$points[] = round( $x, 1 ) . ',' . round( $height - ( $value / $max ) * ( $height - 8 ) - 2, 1 );
			$x       += $step;
		}
		$line = implode( ' ', $points );
		$area = '0,' . $height . ' ' . $line . ' ' . $width . ',' . $height;

		echo '<style>#wkf_dashboard .wkf-dash-forms{margin:8px 0 0;}#wkf_dashboard .wkf-dash-forms li{display:flex;justify-content:space-between;border-top:1px solid #f0f0f1;padding:6px 2px;margin:0;}#wkf_dashboard .wkf-dash-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}</style>';

		echo '<div class="wkf-dash-head"><strong>Celkem za období: ' . count( $entries ) . '</strong><span>';
		echo 7 === $days ? '<strong>7 dní</strong>' : '<a href="' . esc_url( admin_url( 'index.php?wkf_dash=7' ) ) . '">7 dní</a>';
		echo ' | ';
		echo 30 === $days ? '<strong>30 dní</strong>' : '<a href="' . esc_url( admin_url( 'index.php?wkf_dash=30' ) ) . '">30 dní</a>';
		echo '</span></div>';

		echo '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="80" preserveAspectRatio="none" role="img" aria-label="Počet odeslaných formulářů po dnech">';
		echo '<polygon points="' . esc_attr( $area ) . '" fill="' . esc_attr( $color ) . '" fill-opacity="0.12"/>';
		echo '<polyline points="' . esc_attr( $line ) . '" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2"/>';
		if ( $count <= 14 ) {
			foreach ( explode( ' ', $line ) as $point ) {
				list( $px, $py ) = explode( ',', $point );
				echo '<circle cx="' . esc_attr( $px ) . '" cy="' . esc_attr( $py ) . '" r="3" fill="#fff" stroke="' . esc_attr( $color ) . '" stroke-width="2"/>';
			}
		}
		echo '</svg>';

		$first_day = array_keys( $per_day )[0];
		echo '<div style="display:flex;justify-content:space-between;color:#787c82;font-size:11px;"><span>' . esc_html( wp_date( 'j. n.', strtotime( $first_day ) ) ) . '</span><span>dnes</span></div>';

		// Rozpad po formulářích s proklikem do filtrovaného výpisu.
		$schemas = $this->form_schemas();
		echo '<ul class="wkf-dash-forms">';
		foreach ( $schemas as $type => $schema ) {
			$form_count = isset( $per_form[ $type ] ) ? $per_form[ $type ] : 0;
			$url        = admin_url( 'edit.php?post_type=' . self::CPT_ENTRY . '&wkf_form_filter=' . $type );
			echo '<li><span>' . esc_html( $schema['name'] ) . '</span><a href="' . esc_url( $url ) . '"><strong>' . $form_count . '</strong></a></li>';
			unset( $per_form[ $type ] );
		}
		// Záznamy formulářů, které už neexistují.
		foreach ( $per_form as $type => $form_count ) {
			$url = admin_url( 'edit.php?post_type=' . self::CPT_ENTRY . '&wkf_form_filter=' . $type );
			echo '<li><span>' . esc_html( $type ) . ' <em>(odstraněný)</em></span><a href="' . esc_url( $url ) . '"><strong>' . $form_count . '</strong></a></li>';
		}
		echo '</ul>';

		echo '<p style="margin:10px 0 0;"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_ENTRY ) ) . '">Všechny záznamy →</a></p>';
	}

	/** Filtr výpisu záznamů podle formuláře. */
	public function entries_filter_dropdown( $post_type ) {
		if ( self::CPT_ENTRY !== $post_type ) {
			return;
		}
		$current = isset( $_GET['wkf_form_filter'] ) ? sanitize_key( $_GET['wkf_form_filter'] ) : '';
		$schemas = $this->form_schemas();
		echo '<select name="wkf_form_filter"><option value="">Všechny formuláře</option>';
		foreach ( $schemas as $type => $schema ) {
			echo '<option value="' . esc_attr( $type ) . '" ' . selected( $current, $type, false ) . '>' . esc_html( $schema['name'] ) . '</option>';
		}
		echo '</select>';
	}

	public function entries_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::CPT_ENTRY !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( ! empty( $_GET['wkf_form_filter'] ) ) {
			$query->set(
				'meta_query',
				array( array( 'key' => '_wkf_form_type', 'value' => sanitize_key( $_GET['wkf_form_filter'] ) ) )
			);
		}
	}

	/** Export záznamů do CSV nebo XLSX – respektuje aktuální filtr výpisu. */
	public function export_entries() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( 'wkf_export_entries' );

		$format = isset( $_GET['format'] ) && 'xlsx' === $_GET['format'] && class_exists( 'ZipArchive' ) ? 'xlsx' : 'csv';

		$args = array(
			'post_type'      => self::CPT_ENTRY,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		// Přenesené filtry z výpisu záznamů.
		if ( ! empty( $_GET['wkf_form_filter'] ) ) {
			$args['meta_query'] = array( array( 'key' => '_wkf_form_type', 'value' => sanitize_key( $_GET['wkf_form_filter'] ) ) );
		}
		if ( ! empty( $_GET['m'] ) && preg_match( '/^\d{6}$/', $_GET['m'] ) ) {
			$args['m'] = sanitize_text_field( $_GET['m'] );
		}
		if ( ! empty( $_GET['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		$entries = get_posts( $args );

		// Sloupce: pevný kontext + sjednocení všech popisků polí napříč záznamy
		// (v pořadí prvního výskytu).
		$field_columns = array();
		$rows          = array();
		foreach ( $entries as $entry ) {
			$fields = get_post_meta( $entry->ID, '_wkf_fields', true );
			$fields = is_array( $fields ) ? $fields : array();
			foreach ( $fields as $label => $value ) {
				if ( ! in_array( $label, $field_columns, true ) ) {
					$field_columns[] = $label;
				}
			}
			$rows[] = array(
				'date'    => get_the_date( 'j. n. Y H:i', $entry ),
				'form'    => (string) get_post_meta( $entry->ID, '_wkf_form_type', true ),
				'fields'  => $fields,
				'page'    => (string) get_post_meta( $entry->ID, '_wkf_page_title', true ),
				'url'     => (string) get_post_meta( $entry->ID, '_wkf_page_url', true ),
				'address' => (string) get_post_meta( $entry->ID, '_wkf_address_check', true ),
				'journey' => $this->journey_lines( get_post_meta( $entry->ID, '_wkf_journey', true ) ),
				'file'    => implode( ' | ', array_filter( array_merge( array( (string) get_post_meta( $entry->ID, '_wkf_file_url', true ) ), array_map( function ( $f ) { return isset( $f['url'] ) ? $f['url'] : ''; }, array_slice( (array) get_post_meta( $entry->ID, '_wkf_files', true ), 1 ) ) ) ) ),
				'ip'      => (string) get_post_meta( $entry->ID, '_wkf_ip', true ),
			);
		}

		$journey_columns = array( 'Zdroj návštěvy', 'Hledaný výraz', 'Vstupní stránka', 'Odkud přišel', 'Kampaň (UTM)', 'Prohlédnuté stránky', 'Cesta po webu' );
		$has_journey     = false;
		foreach ( $rows as $row ) {
			if ( ! empty( $row['journey'] ) ) {
				$has_journey = true;
				break;
			}
		}
		$header = array_merge(
			array( 'Datum', 'Formulář' ),
			$field_columns,
			array( 'Odesláno ze stránky', 'URL stránky', 'Ověření adresy (RÚIAN)', 'Soubor', 'IP' ),
			$has_journey ? $journey_columns : array()
		);

		$data = array( $header );
		foreach ( $rows as $row ) {
			$line = array( $row['date'], $row['form'] );
			foreach ( $field_columns as $column ) {
				$line[] = isset( $row['fields'][ $column ] ) ? (string) $row['fields'][ $column ] : '';
			}
			$line[] = $row['page'];
			$line[] = $row['url'];
			$line[] = $row['address'];
			$line[] = $row['file'];
			$line[] = $row['ip'];
			if ( $has_journey ) {
				foreach ( $journey_columns as $column ) {
					$line[] = isset( $row['journey'][ $column ] ) ? $row['journey'][ $column ] : '';
				}
			}
			$data[] = $line;
		}

		$filename = 'zaznamy-formularu-' . gmdate( 'Y-m-d' );
		nocache_headers();

		if ( 'xlsx' === $format ) {
			$this->stream_xlsx( $data, $filename . '.xlsx' );
		}
		$this->stream_csv( $data, $filename . '.csv' );
	}

	/**
	 * CSV se středníkem a BOM (kvůli českému Excelu). Buňky začínající
	 * znaky =, +, -, @ se prefixují apostrofem – obrana proti CSV/formula
	 * injection (Excel by jinak hodnotu z formuláře vyhodnotil jako vzorec).
	 */
	private function stream_csv( $data, $filename ) {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF";
		$out = fopen( 'php://output', 'w' );
		foreach ( $data as $line ) {
			$safe = array_map(
				static function ( $cell ) {
					$cell = (string) $cell;
					return preg_match( '/^[=+\-@\t\r]/', $cell ) ? "'" . $cell : $cell;
				},
				$line
			);
			fputcsv( $out, $safe, ';' );
		}
		fclose( $out );
		exit;
	}

	/** Minimální XLSX writer (inline strings, bez závislostí, vyžaduje ZipArchive). */
	private function stream_xlsx( $data, $filename ) {
		$tmp = wp_tempnam( 'wkf-xlsx' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			$this->stream_csv( $data, str_replace( '.xlsx', '.csv', $filename ) );
		}

		$sheet_rows = '';
		foreach ( $data as $r => $line ) {
			$sheet_rows .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( $line as $c => $cell ) {
				$ref = $this->xlsx_col( $c ) . ( $r + 1 );
				$sheet_rows .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
					. htmlspecialchars( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $cell ), ENT_XML1 | ENT_QUOTES, 'UTF-8' )
					. '</t></is></c>';
			}
			$sheet_rows .= '</row>';
		}

		$zip->addFromString(
			'[Content_Types].xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>'
		);
		$zip->addFromString(
			'_rels/.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'
		);
		$zip->addFromString(
			'xl/workbook.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Záznamy" sheetId="1" r:id="rId1"/></sheets></workbook>'
		);
		$zip->addFromString(
			'xl/_rels/workbook.xml.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>'
		);
		$zip->addFromString(
			'xl/worksheets/sheet1.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet_rows . '</sheetData></worksheet>'
		);
		$zip->close();

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp );
		unlink( $tmp );
		exit;
	}

	/** Excel označení sloupce (0 => A, 26 => AA…). */
	private function xlsx_col( $index ) {
		$col = '';
		$index++;
		while ( $index > 0 ) {
			$mod   = ( $index - 1 ) % 26;
			$col   = chr( 65 + $mod ) . $col;
			$index = (int) ( ( $index - $mod ) / 26 );
		}
		return $col;
	}

	public function register_entry_cpt() {
		register_post_type(
			self::CPT_ENTRY,
			array(
				'labels'          => array(
					'name'          => 'Záznamy formulářů',
					'singular_name' => 'Záznam formuláře',
					'edit_item'     => 'Detail záznamu',
					'search_items'  => 'Hledat záznamy',
					'not_found'     => 'Žádné záznamy nenalezeny.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	public function entry_columns( $columns ) {
		return array(
			'cb'        => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'     => 'Záznam',
			'wkf_form'  => 'Formulář',
			'wkf_email' => 'E-mail',
			'wkf_phone' => 'Telefon',
			'wkf_mail'  => 'Notifikace',
			'wkf_webhook' => 'Lead API',
			'date'      => 'Datum',
		);
	}

	public function entry_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'wkf_form':
				$type    = get_post_meta( $post_id, '_wkf_form_type', true );
				$schemas = $this->form_schemas();
				echo isset( $schemas[ $type ] ) ? esc_html( $schemas[ $type ]['name'] ) : esc_html( $type );
				break;
			case 'wkf_email':
				echo esc_html( get_post_meta( $post_id, '_wkf_email', true ) );
				break;
			case 'wkf_phone':
				echo esc_html( get_post_meta( $post_id, '_wkf_phone', true ) );
				break;
			case 'wkf_webhook':
				$wh = (string) get_post_meta( $post_id, '_wkf_webhook_result', true );
				if ( '' === $wh ) {
					echo '<span class="description">—</span>';
				} elseif ( 0 === strpos( $wh, 'předáno' ) ) {
					echo '<span style="color:#008a20;">' . esc_html( $wh ) . '</span>';
				} else {
					echo '<span style="color:#d63638;" title="' . esc_attr( $wh ) . '">' . esc_html( mb_strimwidth( $wh, 0, 60, '…' ) ) . '</span>';
				}
				break;
			case 'wkf_mail':
				if ( get_post_meta( $post_id, '_wkf_mail_sent', true ) ) {
					echo '<span style="color:#008a20;">odeslána</span>';
				} else {
					$mail_error = (string) get_post_meta( $post_id, '_wkf_mail_error', true );
					echo '<span style="color:#d63638;">selhala</span>';
					if ( $mail_error ) {
						echo '<br><span class="description" title="' . esc_attr( $mail_error ) . '">' . esc_html( mb_strimwidth( $mail_error, 0, 90, '…' ) ) . '</span>';
					}
				}
				break;
		}
	}

	/** Filtr záznamů podle typu formuláře nad výpisem. */
	public function entry_filter_dropdown( $post_type ) {
		if ( self::CPT_ENTRY !== $post_type ) {
			return;
		}
		$current = isset( $_GET['wkf_form_type'] ) ? sanitize_key( $_GET['wkf_form_type'] ) : '';
		echo '<select name="wkf_form_type"><option value="">Všechny formuláře</option>';
		foreach ( $this->form_schemas() as $type => $schema ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $type ), selected( $current, $type, false ), esc_html( $schema['name'] ) );
		}
		echo '</select>';
	}

	public function entry_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $query;
		}
		if ( self::CPT_ENTRY !== $query->get( 'post_type' ) ) {
			return $query;
		}
		if ( ! empty( $_GET['wkf_form_type'] ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => '_wkf_form_type',
						'value' => sanitize_key( $_GET['wkf_form_type'] ),
					),
				)
			);
		}
		return $query;
	}

	public function entry_meta_box() {
		add_meta_box( 'wkf_entry_data', 'Data formuláře', array( $this, 'render_entry_meta_box' ), self::CPT_ENTRY, 'normal', 'high' );
	}

	public function render_entry_meta_box( $post ) {
		$type    = get_post_meta( $post->ID, '_wkf_form_type', true );
		$schemas = $this->form_schemas();
		$fields  = get_post_meta( $post->ID, '_wkf_fields', true );

		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th style="width:260px;text-align:left;">Formulář</th><td>' . esc_html( isset( $schemas[ $type ] ) ? $schemas[ $type ]['name'] : $type ) . '</td></tr>';

		if ( is_array( $fields ) ) {
			foreach ( $fields as $label => $value ) {
				if ( '' === $value || null === $value ) {
					continue;
				}
				echo '<tr><th style="text-align:left;">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
			}
		}

		$file_url = get_post_meta( $post->ID, '_wkf_file_url', true );
		if ( $file_url ) {
			echo '<tr><th style="text-align:left;">Nahraný soubor</th><td><a href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener">' . esc_html( basename( $file_url ) ) . '</a></td></tr>';
		}

		$context = array(
			'Ověření adresy (RÚIAN)' => get_post_meta( $post->ID, '_wkf_address_check', true ),
			'Odesláno ze stránky' => get_post_meta( $post->ID, '_wkf_page_title', true ),
			'URL stránky'         => get_post_meta( $post->ID, '_wkf_page_url', true ),
			'Query parametry'     => get_post_meta( $post->ID, '_wkf_query_string', true ),
			'Odkazující stránka'  => get_post_meta( $post->ID, '_wkf_referer', true ),
			'Kontext návštěvy'    => $this->journey_html( get_post_meta( $post->ID, '_wkf_journey', true ) ),
			'IP adresa'           => get_post_meta( $post->ID, '_wkf_ip', true ),
			'Webhook / Lead API'  => get_post_meta( $post->ID, '_wkf_webhook_result', true ),
			'Zdroj (migrace)'     => get_post_meta( $post->ID, '_wkf_source', true ) ? get_post_meta( $post->ID, '_wkf_source', true ) . ' (migrováno ' . get_post_meta( $post->ID, '_wkf_migrated_at', true ) . ')' : '',
			'Nahrané soubory'     => implode( "\n", array_map( function ( $f ) { return ( isset( $f['field'] ) ? $f['field'] . ': ' : '' ) . $f['url']; }, (array) get_post_meta( $post->ID, '_wkf_files', true ) ) ),
			'Notifikace odeslána' => get_post_meta( $post->ID, '_wkf_mail_sent', true )
				? 'ano'
				: 'ne' . ( get_post_meta( $post->ID, '_wkf_mail_error', true ) ? ' – ' . get_post_meta( $post->ID, '_wkf_mail_error', true ) : '' ),
			'Potvrzení klientovi' => get_post_meta( $post->ID, '_wkf_autoreply_sent', true ) ? 'ano' : 'ne',
		);
		foreach ( $context as $label => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			// Kontext návštěvy je předpřipravené HTML (odkazy na navštívené stránky).
			$cell = 'Kontext návštěvy' === $label ? wp_kses_post( $value ) : esc_html( $value );
			echo '<tr><th style="text-align:left;">' . esc_html( $label ) . '</th><td>' . $cell . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* =========================================================
	 * Administrační menu a stránka nastavení
	 * ======================================================= */

	public function admin_menu() {
		$entries_slug = 'edit.php?post_type=' . self::CPT_ENTRY;
		add_menu_page( 'Formuláře', 'Formuláře', 'manage_options', $entries_slug, '', 'dashicons-feedback', 26 );
		add_submenu_page( $entries_slug, 'Záznamy', 'Záznamy', 'manage_options', $entries_slug );
		add_submenu_page( $entries_slug, 'Formuláře', 'Formuláře', 'manage_options', 'edit.php?post_type=' . self::CPT_FORM );
		add_submenu_page( $entries_slug, 'Nastavení', 'Nastavení', 'manage_options', 'wkf-settings', array( $this, 'render_settings_page' ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = $this->get_settings();
		$pages   = get_pages( array( 'post_status' => 'publish' ) );
		$schemas = $this->build_base_schemas();
		?>
		<div class="wrap">
			<h1>Webklient Forms – nastavení</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wkf_settings_group' ); ?>

				<style>
					.wkf-settings-nav { display:flex; gap:6px; flex-wrap:wrap; margin:12px 0 20px; position:sticky; top:32px; background:#f0f0f1; padding:8px 0; z-index:5; }
					.wkf-settings-nav a { display:inline-block; padding:6px 12px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; text-decoration:none; color:#1d2327; }
					.wkf-settings-nav a:hover { border-color:#960000; color:#960000; }
					.wkf-settings-section { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:4px 20px 12px; margin-bottom:20px; scroll-margin-top:90px; }
					.wkf-settings-section h3 { font-size:1em; margin:18px 0 4px; padding-top:14px; border-top:1px solid #f0f0f1; }
					.wkf-settings-section h3:first-of-type { border-top:0; padding-top:0; }
					.wkf-settings-intro { margin-top:-4px; }
				</style>
				<p class="description" style="font-size:14px;">Tady je jen to, co platí pro celý web. Pole, příjemce, děkovací stránku, automatickou odpověď i webhook si nastavíte u každého formuláře zvlášť v přehledu <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::CPT_FORM ) ); ?>">Formuláře</a>.</p>
				<nav class="wkf-settings-nav">
					<a href="#wkf-sec-email">E-maily</a>
					<a href="#wkf-sec-spam">Ochrana proti spamu</a>
					<a href="#wkf-sec-integrace">Napojení na služby</a>
					<a href="#wkf-sec-vzhled">Vzhled</a>
					<a href="#wkf-sec-aktualizace">Aktualizace</a>
				</nav>

				<div class="wkf-settings-section" id="wkf-sec-email">
				<h2 class="wkf-settings-title">E-maily z formulářů</h2>
				<p class="description wkf-settings-intro">Odkud notifikace odcházejí a přes jaký server. Bez SMTP se použije standardní odesílání WordPressu, které mnohé hostingy a schránky zahazují – pokud notifikace nechodí, začněte zde.</p>
				<h3>Odesílatel e-mailů</h3>
				<p class="description">Limit celkové velikosti příloh notifikace: <input type="number" style="width:80px;" min="1" max="50" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[attach_limit_mb]" value="<?php echo esc_attr( $s['attach_limit_mb'] ); ?>"> MB – nad ním notifikace obsahuje jen odkazy ke stažení.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_from_name">Jméno odesílatele</label></th>
						<td><input type="text" id="wkf_from_name" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_from_email">E-mail odesílatele</label></th>
						<td><input type="email" id="wkf_from_email" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>"></td>
					</tr>
				</table>

				<h3>SMTP odesílání</h3>
				<?php if ( class_exists( 'Webklient_SMTP_Logger_Pro' ) ) : ?>
					<p class="description"><strong>Na webu běží Webklient SMTP Logger Pro – odesílání e-mailů řídí on a nastavení níže se nepoužije.</strong></p>
				<?php endif; ?>
				<?php if ( isset( $_GET['wkf_smtp_test'] ) ) : ?>
					<div class="notice notice-<?php echo 'ok' === $_GET['wkf_smtp_test'] ? 'success' : 'error'; ?> inline"><p>
						<?php
						$test_notice_to = isset( $_GET['wkf_smtp_to'] ) ? sanitize_email( wp_unslash( $_GET['wkf_smtp_to'] ) ) : '';
						if ( 'ok' === $_GET['wkf_smtp_test'] ) {
							echo 'Testovací e-mail byl odeslán' . ( $test_notice_to ? ' na adresu <strong>' . esc_html( $test_notice_to ) . '</strong>' : '' ) . ' – zkontrolujte schránku (i spam).';
						} else {
							echo 'Testovací e-mail' . ( $test_notice_to ? ' na adresu <strong>' . esc_html( $test_notice_to ) . '</strong>' : '' ) . ' se nepodařilo odeslat – zkontrolujte údaje SMTP serveru.';
						}
						if ( isset( $_GET['wkf_smtp_used'] ) && '' !== $_GET['wkf_smtp_used'] ) {
							echo '<br><strong>Odesláno jako:</strong> ' . esc_html( sanitize_text_field( wp_unslash( $_GET['wkf_smtp_used'] ) ) );
						}
						if ( isset( $_GET['wkf_smtp_reason'] ) && '' !== $_GET['wkf_smtp_reason'] ) {
							echo '<br><strong>Důvod:</strong> ' . esc_html( sanitize_text_field( wp_unslash( $_GET['wkf_smtp_reason'] ) ) );
						}
						?>
					</p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_smtp_mode">Režim</label></th>
						<td><select id="wkf_smtp_mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_mode]">
							<option value="" <?php selected( $s['smtp_mode'], '' ); ?>>vypnuto (výchozí odesílání WordPressu)</option>
							<option value="forms" <?php selected( $s['smtp_mode'], 'forms' ); ?>>jen e-maily formulářů</option>
							<option value="all" <?php selected( $s['smtp_mode'], 'all' ); ?>>všechny e-maily webu</option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_smtp_host">SMTP server</label></th>
						<td><input type="text" id="wkf_smtp_host" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_host]" value="<?php echo esc_attr( $s['smtp_host'] ); ?>" placeholder="smtp.seznam.cz">
						&nbsp;<label for="wkf_smtp_secure">Zabezpečení:</label> <select id="wkf_smtp_secure" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_secure]">
							<option value="tls" data-wkf-port="587" <?php selected( $s['smtp_secure'], 'tls' ); ?>>TLS (STARTTLS, port 587)</option>
							<option value="ssl" data-wkf-port="465" <?php selected( $s['smtp_secure'], 'ssl' ); ?>>SSL (port 465)</option>
							<option value="" data-wkf-port="25" <?php selected( $s['smtp_secure'], '' ); ?>>žádné (port 25)</option>
						</select>
						&nbsp;<label for="wkf_smtp_port">Port:</label> <input type="number" id="wkf_smtp_port" style="width:90px;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_port]" value="<?php echo esc_attr( $s['smtp_port'] ); ?>">
						<p class="description">Port se při změně zabezpečení doplní sám (TLS 587, SSL 465, bez šifrování 25). Nestandardní port, který si zadáte ručně, zůstane zachován.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_smtp_user">Přihlášení</label></th>
						<td><input type="text" id="wkf_smtp_user" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_user]" value="<?php echo esc_attr( $s['smtp_user'] ); ?>" placeholder="uživatel (e-mail)" autocomplete="off">
						&nbsp;<input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[smtp_pass]" value="" placeholder="<?php echo $s['smtp_pass'] ? 'heslo uloženo – vyplňte jen pro změnu' : 'heslo'; ?>" autocomplete="new-password">
						<p class="description">Heslo se ukládá šifrovaně (AES-256, klíč odvozený ze security saltů webu). Odesílatel a jméno se přebírají ze sekce Odesílatel e-mailů výše.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_smtp_test_to">Testovací e-mail</label></th>
						<td>
						<?php if ( $s['smtp_mode'] && $s['smtp_host'] ) : ?>
							<input type="email" id="wkf_smtp_test_to" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" placeholder="adresa, kam test odejít" autocomplete="off">
							&nbsp;<a id="wkf_smtp_test_btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wkf_smtp_test' ), 'wkf_smtp_test' ) ); ?>" class="button">Odeslat testovací e-mail</a>
							<p class="description">Test se odesílá podle <strong>uloženého</strong> nastavení – pokud jste údaje právě měnili, nejdřív nastavení uložte. Prázdné pole = test půjde na adresu přihlášeného uživatele. Když server přihlášení přijme, ale odeslat odmítne, bývá na vině <strong>e-mail odesílatele</strong> v sekci výše – musí patřit k tomu SMTP účtu nebo být jeho povolený alias.</p>
						<?php else : ?>
							<p class="description">Test bude k dispozici po uložení režimu a adresy SMTP serveru.</p>
						<?php endif; ?>
						</td>
					</tr>
				</table>
				<script>
				(function(){
					var sec  = document.getElementById('wkf_smtp_secure'),
						port = document.getElementById('wkf_smtp_port');
					if ( sec && port ) {
						// Standardní porty se přepnou samy, ručně zadaný nestandardní port zůstává.
						var standard = [ '', '25', '465', '587' ];
						sec.addEventListener( 'change', function () {
							var opt = sec.options[ sec.selectedIndex ],
								def = opt ? opt.getAttribute( 'data-wkf-port' ) : '';
							if ( ! def ) {
								return;
							}
							if ( standard.indexOf( String( port.value ).trim() ) === -1 ) {
								return;
							}
							port.value = def;
						} );
					}
					var to  = document.getElementById('wkf_smtp_test_to'),
						btn = document.getElementById('wkf_smtp_test_btn');
					if ( ! to || ! btn ) {
						return;
					}
					var base = btn.getAttribute('href');
					function sync() {
						var val = to.value.trim();
						btn.setAttribute( 'href', val ? base + '&wkf_test_to=' + encodeURIComponent( val ) : base );
					}
					to.addEventListener( 'input', sync );
					to.addEventListener( 'keydown', function ( e ) {
						// Enter v poli spustí test, ne uložení celého nastavení.
						if ( 'Enter' === e.key ) {
							e.preventDefault();
							sync();
							btn.click();
						}
					} );
					sync();
				})();
				</script>

				</div>

				<div class="wkf-settings-section" id="wkf-sec-spam">
				<h2 class="wkf-settings-title">Ochrana proti spamu</h2>
				<p class="description wkf-settings-intro">Cloudflare Turnstile chrání všechny formuláře neviditelně; kontrolní otázka je záložní ochrana pro weby bez Turnstile. Formuláře mají navíc vždy honeypot a limit počtu odeslání z jedné adresy.</p>
				<h3>Ochrana proti spamu</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_ts_site">Turnstile Site Key</label></th>
						<td><input type="text" id="wkf_ts_site" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[turnstile_site_key]" value="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_ts_secret">Turnstile Secret Key</label></th>
						<td><input type="text" id="wkf_ts_secret" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[turnstile_secret_key]" value="<?php echo esc_attr( $s['turnstile_secret_key'] ); ?>">
						<p class="description">Bez vyplněných klíčů se ověření Turnstile přeskočí.</p></td>
					</tr>
					<tr>
						<th scope="row">Kontrolní otázka</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[antispam_enabled]" value="1" <?php checked( $s['antispam_enabled'] ); ?>> Zobrazovat ve formulářích kontrolní otázku</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_as_q">Text otázky</label></th>
						<td><input type="text" id="wkf_as_q" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[antispam_question]" value="<?php echo esc_attr( $s['antispam_question'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_as_a">Správná odpověď</label></th>
						<td><input type="text" id="wkf_as_a" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[antispam_answer]" value="<?php echo esc_attr( $s['antispam_answer'] ); ?>">
						<p class="description">Porovnává se bez ohledu na velikost písmen a mezery na okrajích.</p></td>
					</tr>
				</table>

				</div>

				<div class="wkf-settings-section" id="wkf-sec-integrace">
				<h2 class="wkf-settings-title">Napojení na služby</h2>
				<p class="description wkf-settings-intro">Klíče pro našeptávání adres (Mapy.cz), ověřování adres v RÚIAN a předávání poptávek do CRM nebo automatizací (Make, n8n) přes webhook.</p>
				<h3>API klíče pro našeptávání a ověřování</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_mapy_key">Mapy.cz API klíč</label></th>
						<td><input type="text" id="wkf_mapy_key" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mapy_api_key]" value="<?php echo esc_attr( $s['mapy_api_key'] ); ?>">
						<p class="description">Našeptávání adres funguje i bez klíče (veřejné Photon API nad OSM daty pocházejícími z RÚIAN, omezené na ČR). Vyplněním klíče z developer.mapy.com se přepne na Mapy.cz Suggest API s garantovanou kvalitou a kvótou. Volání jdou přes server webu a klíč se nikdy neposílá do prohlížeče.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_fnx_key">RUIAN API klíč (ruian.fnx.io)</label></th>
						<td><input type="text" id="wkf_fnx_key" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fnx_api_key]" value="<?php echo esc_attr( $s['fnx_api_key'] ); ?>">
						<p class="description">Klíč zdarma na <a href="https://ruian.fnx.io" target="_blank" rel="noopener">ruian.fnx.io</a>. Každá odeslaná adresa realizace se ověří a do notifikace i záznamu se doplní výsledek (shoda, normalizovaná podoba adresy). Ověření nikdy neblokuje odeslání formuláře; prázdné pole = vypnuto.</p></td>
					</tr>
				</table>

				<h3>Kontext návštěvy u poptávek</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Sledovat cestu návštěvníka</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[journey]" value="1" <?php checked( ! empty( $s['journey'] ) ); ?>> U každé poptávky ukládat vstupní stránku, zdroj návštěvy a prohlédnuté stránky</label>
						&nbsp;maximálně <input type="number" style="width:70px;" min="5" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[journey_steps]" value="<?php echo esc_attr( $s['journey_steps'] ); ?>"> kroků
						<p class="description">Data se drží jen v prohlížeči návštěvníka (sessionStorage, žádné cookies) a odešlou se teprve s formulářem. Vyhledávače dnes hledaný výraz většinou nepředávají – naplní se hlavně u placených kampaní s <code>utm_term</code>.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_journey_consent">Vázat na souhlas</label></th>
						<td><input type="text" id="wkf_journey_consent" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[journey_consent]" value="<?php echo esc_attr( $s['journey_consent'] ); ?>" placeholder="název_cookie:hodnota (např. wk_consent:analytics)">
						<p class="description">Nepovinné. Když vyplníte, sledování se spustí jen tehdy, obsahuje-li uvedená cookie danou hodnotu – tak lze navázat na souhlas z cookie lišty. Prázdné = sledovat vždy.</p></td>
					</tr>
				</table>

				<h3>Lead API / webhook</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_webhook_url">URL endpointu</label></th>
						<td><input type="url" id="wkf_webhook_url" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[webhook_url]" value="<?php echo esc_attr( $s['webhook_url'] ); ?>" placeholder="https://crm.example.cz/api/lead/prijem">
						<p class="description">Každé odeslání formuláře se pošle jako JSON POST (id, jmeno, email, telefon, zprava, formular, url a mapa všech polí v <code>pole</code>). Funguje s Lead API, Make, n8n i vlastními systémy. Prázdné = vypnuto.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_webhook_key">Autorizace</label></th>
						<td>Hlavička: <input type="text" id="wkf_webhook_header" style="width:160px;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[webhook_header]" value="<?php echo esc_attr( $s['webhook_header'] ); ?>">
						&nbsp;Klíč: <input type="password" id="wkf_webhook_key" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[webhook_key]" value="" placeholder="<?php echo $s['webhook_key'] ? 'klíč uložen – vyplňte jen pro změnu' : 'klíč'; ?>" autocomplete="new-password">
						<p class="description">Klíč se ukládá šifrovaně a posílá se v uvedené hlavičce. Výsledek předání vidíte u každého záznamu.</p></td>
					</tr>
				</table>

				</div>

				<div class="wkf-settings-section" id="wkf-sec-aktualizace">
				<h2 class="wkf-settings-title">Aktualizace pluginu</h2>
				<p class="description wkf-settings-intro">Plugin se aktualizuje přímo z GitHubu – nové vydání se nabídne v přehledu pluginů jako každá jiná aktualizace. Zdroj je pevně dán repozitářem pluginu a nenastavuje se.</p>
				<?php if ( isset( $_GET['wkf_update'] ) ) :
					$upd = sanitize_text_field( wp_unslash( $_GET['wkf_update'] ) );
					?>
					<div class="notice notice-<?php echo 0 === strpos( $upd, 'new:' ) ? 'warning' : ( 'current' === $upd ? 'success' : 'error' ); ?> inline"><p>
						<?php
						if ( 0 === strpos( $upd, 'new:' ) ) {
							echo 'K dispozici je verze ' . esc_html( substr( $upd, 4 ) ) . ' – nainstalujete ji v přehledu Pluginy.';
						} elseif ( 'current' === $upd ) {
							echo 'Máte nejnovější vydanou verzi.';
						} else {
							echo 'Vydání se nepodařilo načíst.';
							$upd_reason = get_site_transient( 'wkf_update_error' );
							if ( $upd_reason ) {
								echo '<br><strong>Důvod:</strong> ' . esc_html( $upd_reason );
							}
						}
						?>
					</p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Verze</th>
						<td><p class="description">Nainstalováno: <strong><?php echo esc_html( WKF_VERSION ); ?></strong>, zdroj vydání: <a href="https://github.com/<?php echo esc_attr( WKF_UPDATE_REPO ); ?>/releases" target="_blank" rel="noopener"><code><?php echo esc_html( WKF_UPDATE_REPO ); ?></code></a>. Nabízejí se jen řádná vydání, předběžná (pre-release) nikoliv.
						&nbsp;<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wkf_check_update' ), 'wkf_check_update' ) ); ?>" class="button">Zkontrolovat aktualizaci</a></p></td>
					</tr>
				</table>
				</div>

				<div class="wkf-settings-section" id="wkf-sec-vzhled">
				<h2 class="wkf-settings-title">Vzhled</h2>
				<p class="description wkf-settings-intro">Barvy tlačítka Odeslat na všech formulářích. Ostatní vzhled přebírá formulář ze šablony webu.</p>
				<p class="description">Podpis pod formulářem: <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[credit]" value="1" <?php checked( ! empty( $s['credit'] ) ); ?>> zobrazovat nenápadný odkaz „Formulář od Webklient.cz"</label></p>

				<h3>Tlačítko Odeslat</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_btn_bg">Barva pozadí</label></th>
						<td><input type="color" id="wkf_btn_bg" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_bg]" value="<?php echo esc_attr( $s['btn_bg'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_btn_bg_hover">Barva pozadí – hover</label></th>
						<td><input type="color" id="wkf_btn_bg_hover" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_bg_hover]" value="<?php echo esc_attr( $s['btn_bg_hover'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_btn_color">Barva textu</label></th>
						<td><input type="color" id="wkf_btn_color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_color]" value="<?php echo esc_attr( $s['btn_color'] ); ?>"></td>
					</tr>
				</table>

				</div>

				<?php
				// Blok presetů se zobrazuje jen na webech, které je reálně používají.
				// Nepočítá se uložená matice polí (zapisuje se při každém uložení
				// nastavení) ani příjemce rovný výchozí hodnotě (admin e-mail webu).
				$uses_presets = '' !== trim( (string) $s['legacy_map'] );
				$admin_email  = (string) get_option( 'admin_email' );
				foreach ( array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ) as $preset_check ) {
					$preset_recipient = (string) $s[ 'recipient_' . $preset_check ];
					if ( ( '' !== $preset_recipient && $preset_recipient !== $admin_email )
						|| (int) $s[ 'thankyou_' . $preset_check ]
						|| ! empty( $s[ 'autoreply_' . $preset_check . '_enabled' ] ) ) {
						$uses_presets = true;
					}
				}
				if ( $uses_presets ) :
				?>
				<details style="margin:24px 0;border:1px solid #ccd0d4;border-radius:4px;padding:8px 16px;background:#fff;">
				<summary style="cursor:pointer;font-size:1.1em;font-weight:600;padding:6px 0;">Vestavěné presety (kompatibilita) – kontakt, sluzby, kariera, poptavka</summary>
				<p class="description">Historické presety pro shortcody <code>[wk_form type="kontakt|sluzby|kariera|poptavka"]</code> a mapování WPForms. Pro nové formuláře používejte <strong>Formuláře</strong> s tlačítky předloh – tam se vše nastavuje na jednom místě.</p>
				<h2>Zdroje dat</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_kontakt_tax">Kontakt – taxonomie „Typ služby"</label></th>
						<td><input type="text" id="wkf_kontakt_tax" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[kontakt_taxonomy]" value="<?php echo esc_attr( $s['kontakt_taxonomy'] ); ?>">
						<p class="description">Ponechte prázdné, pokud kontaktní formulář nemá mít výběr služby (např. <code>category</code>).</p></td>
					</tr>
					<tr>
						<th scope="row">Poptávka služeb – zdroj nabídky</th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sluzby_source]" value="taxonomy" <?php checked( $s['sluzby_source'], 'taxonomy' ); ?>> Taxonomie</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sluzby_source]" value="post_type" <?php checked( $s['sluzby_source'], 'post_type' ); ?>> Post type</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_sluzby_slug">Poptávka služeb – slug zdroje</label></th>
						<td><input type="text" id="wkf_sluzby_slug" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sluzby_slug]" value="<?php echo esc_attr( $s['sluzby_slug'] ); ?>">
						<p class="description">Slug taxonomie (např. <code>category</code>) nebo post typu (např. <code>sluzba</code>).</p></td>
					</tr>
					<tr>
						<th scope="row">Výchozí rubrika</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[exclude_default_cat]" value="1" <?php checked( $s['exclude_default_cat'] ); ?>> Skrýt výchozí rubriku (Nezařazené), pokud je zdrojem <code>category</code></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_kariera_pt">Kariéra – post type pozic</label></th>
						<td><input type="text" id="wkf_kariera_pt" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[kariera_post_type]" value="<?php echo esc_attr( $s['kariera_post_type'] ); ?>">
						<p class="description">Kariérní formulář nabízí publikované příspěvky tohoto post typu (výchozí <code>job</code>).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wkf_interests">Poptávka – volby „Zajímám se o"</label></th>
						<td><textarea id="wkf_interests" class="large-text" rows="6" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[poptavka_interests]"><?php echo esc_textarea( $s['poptavka_interests'] ); ?></textarea>
						<p class="description">Jedna volba na řádek. Ponechte prázdné, pokud se checkboxy nemají zobrazovat.</p></td>
					</tr>
				</table>

				<h2>Kompatibilita s WPForms</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wkf_legacy_map">Mapování starých shortcodů</label></th>
						<td><textarea id="wkf_legacy_map" class="large-text code" rows="6" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[legacy_map]" placeholder="20469 = kontakt&#10;20512 = poptavka"><?php echo esc_textarea( $s['legacy_map'] ); ?></textarea>
						<p class="description">Staré shortcody <code>[wpforms id="..."]</code> v obsahu a šablonách zůstanou funkční – zde přiřaďte každému WPForms ID typ nového formuláře, jedna dvojice <code>ID = typ</code> na řádek. Typy: <code>kontakt</code> (jméno, e-mail, telefon, zpráva), <code>sluzby</code> (poptávka služeb – výběr služby, adresa realizace, podrobnosti), <code>kariera</code> (pozice + životopis), <code>poptavka</code> (obecná poptávka s checkboxy zájmů, bez adresy). Převzetí shortcodu se aktivuje až po deaktivaci pluginu WPForms; dokud je WPForms aktivní, formuláře vykresluje on. Atributy <code>title</code> a <code>description</code> se ignorují.</p></td>
					</tr>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Pole IČO</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ico_enabled]" value="1" <?php checked( $s['ico_enabled'] ); ?>> Zobrazovat ve všech formulářích volitelné pole IČO s našeptáváním z ARES</label>
						<p class="description">Návštěvník začne psát IČO nebo název firmy a z registru ARES se mu nabídne firma – výběrem se doplní IČO, název do pole jména a případně sídlo do adresy.</p></td>
					</tr>
				</table>

				<?php foreach ( $schemas as $type => $schema ) : ?>
					<h2><?php echo esc_html( $schema['name'] ); ?> – <code>[wk_form type="<?php echo esc_attr( $type ); ?>"]</code></h2>
					<table class="widefat striped" style="max-width:640px;margin-bottom:12px;">
						<thead><tr><th>Pole</th><th style="width:90px;">Zobrazit</th><th style="width:90px;">Povinné</th></tr></thead>
						<tbody>
						<?php foreach ( $schema['fields'] as $field ) :
							$state    = $this->field_state( $type, $field );
							$is_email = 'email' === $field['key'];
							$base     = self::OPTION_KEY . '[field_overrides][' . esc_attr( $type ) . '][' . esc_attr( $field['key'] ) . ']';
						?>
							<tr>
								<td><?php echo esc_html( $field['label'] ); ?> <code style="opacity:.6;"><?php echo esc_html( $field['key'] ); ?></code></td>
								<td>
									<?php if ( $is_email ) : ?>
										<input type="checkbox" checked disabled><input type="hidden" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1">
									<?php else : ?>
										<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" <?php checked( $state['enabled'] ); ?>>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_email ) : ?>
										<input type="checkbox" checked disabled><input type="hidden" name="<?php echo esc_attr( $base ); ?>[required]" value="1">
									<?php else : ?>
										<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[required]" value="1" <?php checked( $state['required'] ); ?>>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wkf_rec_<?php echo esc_attr( $type ); ?>">Příjemce notifikace</label></th>
							<td><input type="text" id="wkf_rec_<?php echo esc_attr( $type ); ?>" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recipient_<?php echo esc_attr( $type ); ?>]" value="<?php echo esc_attr( $s[ 'recipient_' . $type ] ); ?>">
							<p class="description">Více adres oddělte čárkou.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wkf_ty_<?php echo esc_attr( $type ); ?>">Děkovací stránka</label></th>
							<td>
								<select id="wkf_ty_<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[thankyou_<?php echo esc_attr( $type ); ?>]">
									<option value="0">— nepřesměrovávat, zobrazit hlášku —</option>
									<?php foreach ( $pages as $p ) : ?>
										<option value="<?php echo (int) $p->ID; ?>" <?php selected( $s[ 'thankyou_' . $type ], $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">Automatická odpověď klientovi</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[autoreply_<?php echo esc_attr( $type ); ?>_enabled]" value="1" <?php checked( $s[ 'autoreply_' . $type . '_enabled' ] ); ?>> Posílat potvrzení klientovi</label>
							<p class="description">Zástupné značky: <code>{jmeno}</code>, <code>{email}</code>, <code>{telefon}</code>, <code>{sluzba}</code>, <code>{pozice}</code>, <code>{zprava}</code> a další podle klíčů polí. E-mail se odesílá jako HTML v e-mailově bezpečné šabloně.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wkf_ar_<?php echo esc_attr( $type ); ?>_subject">Předmět potvrzení</label></th>
							<td><input type="text" id="wkf_ar_<?php echo esc_attr( $type ); ?>_subject" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[autoreply_<?php echo esc_attr( $type ); ?>_subject]" value="<?php echo esc_attr( $s[ 'autoreply_' . $type . '_subject' ] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row">Text potvrzení</th>
							<td>
							<?php
							wp_editor(
								$s[ 'autoreply_' . $type . '_body' ],
								'wkf_ar_' . $type . '_body',
								array(
									'textarea_name' => self::OPTION_KEY . '[autoreply_' . $type . '_body]',
									'textarea_rows' => 6,
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => true,
								)
							);
							?>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>
				</details>
				<?php endif; ?>

				<?php submit_button( 'Uložit nastavení' ); ?>
			</form>
		</div>
		<?php
	}

	/* =========================================================
	 * Assety
	 * ======================================================= */

	/**
	 * Sledovací skript běží na celém webu (jinak by cesta začínala až na
	 * stránce s formulářem). Data zůstávají v sessionStorage prohlížeče.
	 */
	public function enqueue_journey() {
		$s = $this->get_settings();
		if ( empty( $s['journey'] ) || is_admin() ) {
			return;
		}
		// Volitelná vazba na souhlas: skript se načte, jen když cookie nese danou hodnotu.
		if ( $s['journey_consent'] ) {
			list( $cookie_name, $needle ) = array_pad( explode( ':', $s['journey_consent'], 2 ), 2, '' );
			$cookie_name = trim( $cookie_name );
			$needle      = trim( $needle );
			if ( ! isset( $_COOKIE[ $cookie_name ] ) || ( '' !== $needle && false === strpos( wp_unslash( $_COOKIE[ $cookie_name ] ), $needle ) ) ) {
				return;
			}
		}
		wp_enqueue_script( 'wkf-journey', WKF_PLUGIN_URL . 'assets/journey.js', array(), WKF_VERSION, false );
		wp_localize_script( 'wkf-journey', 'wkfJourneyConfig', array( 'maxSteps' => (int) $s['journey_steps'] ) );
	}

	public function register_assets() {
		wp_register_style( 'wkf-forms', WKF_PLUGIN_URL . 'assets/forms.css', array(), WKF_VERSION );
		wp_register_script( 'wkf-forms', WKF_PLUGIN_URL . 'assets/forms.js', array(), WKF_VERSION, true );
		wp_register_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
	}

	private function enqueue_assets() {
		$s = $this->get_settings();
		wp_enqueue_style( 'wkf-forms' );
		wp_add_inline_style(
			'wkf-forms',
			sprintf(
				'.wkf-form{--wkf-brand:%1$s;}.wkf-submit{padding:10px 20px;border-radius:8px;background:%1$s;color:%3$s;border:none;font-size:18px;font-weight:800;}.wkf-submit:hover{background:%2$s;color:%3$s;}',
				esc_attr( $s['btn_bg'] ),
				esc_attr( $s['btn_bg_hover'] ),
				esc_attr( $s['btn_color'] )
			)
		);
		wp_enqueue_script( 'wkf-forms' );
		if ( $s['turnstile_site_key'] ) {
			wp_enqueue_script( 'cf-turnstile' );
		}
		wp_localize_script(
			'wkf-forms',
			'wkfForms',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/* =========================================================
	 * Shortcode a generický renderer
	 * ======================================================= */

	public function register_shortcode() {
		add_shortcode( 'wk_form', array( $this, 'render_form_shortcode' ) );
	}

	/**
	 * Kompatibilita se starými shortcody [wpforms id="..."].
	 * Registruje se jen v případě, že plugin WPForms není aktivní,
	 * aby nedošlo ke konfliktu během postupné migrace.
	 */
	public function register_legacy_shortcode() {
		if ( ! shortcode_exists( 'wpforms' ) ) {
			add_shortcode( 'wpforms', array( $this, 'render_legacy_shortcode' ) );
		}
		if ( ! shortcode_exists( 'contact-form-7' ) && ! class_exists( 'WPCF7' ) ) {
			add_shortcode( 'contact-form-7', array( $this, 'render_legacy_cf7_shortcode' ) );
		}
	}

	/**
	 * Mapa starých shortcodů na typy formulářů. Skládá se z globálního
	 * nastavení („ID = typ", jen WPForms) a z mapování u jednotlivých
	 * vlastních formulářů (WPForms i Contact Form 7); to má přednost.
	 */
	private function legacy_map( $system = 'wpforms' ) {
		$map = array();

		// Globální mapování z nastavení (historické, pouze WPForms).
		if ( 'wpforms' === $system ) {
			$s = $this->get_settings();
			foreach ( explode( "\n", (string) $s['legacy_map'] ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = preg_split( '/[=\s]+/', $line, 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}
				$id   = trim( $parts[0] );
				$type = sanitize_key( trim( $parts[1] ) );
				if ( $id && $type ) {
					$map[ $id ] = $type;
				}
			}
		}

		// Mapování z jednotlivých vlastních formulářů (má přednost).
		$meta_key = 'wpforms' === $system ? '_wkf_legacy_wpforms' : '_wkf_legacy_cf7';
		$posts    = get_posts( array( 'post_type' => self::CPT_FORM, 'post_status' => 'publish', 'posts_per_page' => -1 ) );
		foreach ( $posts as $post ) {
			$ids = get_post_meta( $post->ID, $meta_key, true );
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $ids ) ) ) as $id ) {
				$map[ $id ] = $post->post_name;
			}
		}

		return $map;
	}

	public function render_legacy_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'          => 0,
				'title'       => 'false',
				'description' => 'false',
			),
			$atts,
			'wpforms'
		);

		$id  = trim( (string) $atts['id'] );
		$map = $this->legacy_map( 'wpforms' );

		if ( '' === $id || empty( $map[ $id ] ) ) {
			return current_user_can( 'manage_options' )
				? '<p><strong>Webklient Forms:</strong> shortcode <code>[wpforms id="' . esc_html( $id ) . '"]</code> nemá přiřazený formulář. Doplňte mapování v nastavení vlastního formuláře, nebo v Nastavení → Kompatibilita s WPForms.</p>'
				: '';
		}

		return $this->render_form_shortcode( array( 'type' => $map[ $id ] ) );
	}

	/** Kompatibilita se shortcody Contact Form 7 ([contact-form-7 id="..."]). */
	public function render_legacy_cf7_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => '', 'title' => '', 'html_id' => '', 'html_class' => '' ), $atts, 'contact-form-7' );

		$id  = trim( (string) $atts['id'] );
		$map = $this->legacy_map( 'cf7' );

		if ( '' === $id || empty( $map[ $id ] ) ) {
			return current_user_can( 'manage_options' )
				? '<p><strong>Webklient Forms:</strong> shortcode <code>[contact-form-7 id="' . esc_html( $id ) . '"]</code> nemá přiřazený formulář. Doplňte ID v nastavení vlastního formuláře (Převzetí starých shortcodů).</p>'
				: '';
		}

		return $this->render_form_shortcode( array( 'type' => $map[ $id ] ) );
	}

	public function render_form_shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'type' => 'kontakt', 'button' => '', 'sluzba' => '' ), $atts, 'wk_form' );
		$type    = sanitize_key( $atts['type'] );
		$schemas = $this->form_schemas();

		if ( ! isset( $schemas[ $type ] ) ) {
			return current_user_can( 'manage_options' )
				? '<p><strong>Webklient Forms:</strong> neznámý typ formuláře „' . esc_html( $type ) . '". Dostupné typy: ' . esc_html( implode( ', ', array_keys( $schemas ) ) ) . '.</p>'
				: '';
		}

		$this->enqueue_assets();

		$schema = $schemas[ $type ];

		// Atribut sluzba="..." napevno předvyplní pole služby a skryje dropdown
		// (typicky na stránce konkrétní služby). Hodnota musí odpovídat existující položce.
		$fixed_service = sanitize_text_field( $atts['sluzba'] );
		if ( $fixed_service ) {
			foreach ( $schema['fields'] as $index => $field ) {
				if ( 'sluzba' === $field['key'] && 'select_dynamic' === $field['type'] ) {
					if ( in_array( $fixed_service, $this->dynamic_options( $field ), true ) ) {
						$schema['fields'][ $index ]['fixed_value'] = $fixed_service;
					} elseif ( current_user_can( 'manage_options' ) ) {
						return '<p><strong>Webklient Forms:</strong> služba „' . esc_html( $fixed_service ) . '" neexistuje ve zdroji nabídky. Zkontrolujte atribut <code>sluzba</code> shortcodu.</p>';
					}
					break;
				}
			}
		}

		$button = $atts['button'] ? $atts['button'] : $schema['button'];
		$html   = $this->render_schema( $type, $schema, $button );

		// Formulář běží na vestavěném presetu (žádný vlastní formulář ho
		// nenahradil): přihlášeným redaktorům se nad ním ukáže, kam chodí
		// notifikace a jak formulář převzít. Návštěvníci lištu nevidí.
		$is_preset = in_array( $type, array( 'kontakt', 'sluzby', 'kariera', 'poptavka' ), true ) && empty( $schema['_custom'] );
		if ( $is_preset && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			$config    = $this->form_config( $type );
			$recipient = $config['recipient'] ? $config['recipient'] : get_option( 'admin_email' );
			$forms_url = admin_url( 'edit.php?post_type=' . self::CPT_FORM );
			$note      = '<div class="wkf-admin-note" style="margin:0 0 12px;padding:10px 14px;background:#fcf9e8;border:1px solid #dba617;border-radius:4px;font-size:14px;line-height:1.5;">'
				. '<strong>Poznámka pro redakci</strong> (návštěvníci ji nevidí): formulář běží na vestavěném presetu „' . esc_html( $type ) . '" a notifikace chodí na <strong>' . esc_html( $recipient ) . '</strong>. '
				. 'Odeslané zprávy najdete v <a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_ENTRY ) ) . '">Záznamech</a>. '
				. 'Po otevření přehledu <a href="' . esc_url( $forms_url ) . '">Formuláře</a> se automaticky převede na editovatelný formulář se stejnými poli i nastavením.'
				. '</div>';
			$html = $note . $html;
		}

		return $html;
	}

	/** Vykreslí posloupnost polí; dvojice polovičních polí vedle sebe. */
	private function render_field_sequence( $type, $fields ) {
		$count = count( $fields );
		$i     = 0;
		while ( $i < $count ) {
			$field = $fields[ $i ];
			if ( ! empty( $field['half'] ) && isset( $fields[ $i + 1 ] ) && ! empty( $fields[ $i + 1 ]['half'] ) ) {
				echo '<div class="wkf-row"><div class="wkf-col">';
				$this->render_field_wrapped( $type, $field );
				echo '</div><div class="wkf-col">';
				$this->render_field_wrapped( $type, $fields[ $i + 1 ] );
				echo '</div></div>';
				$i += 2;
				continue;
			}
			$this->render_field_wrapped( $type, $field );
			$i++;
		}
	}

	/** Vykreslí pole; podmíněná pole obalí kontejnerem s data atributy pro JS. */
	private function render_field_wrapped( $form_type, $field ) {
		ob_start();
		$this->render_field( $form_type, $field );
		$html = ob_get_clean();

		// Nápověda pod polem (soubor si ji vypisuje sám).
		if ( ! empty( $field['hint'] ) && ! in_array( $field['type'], array( 'file', 'heading', 'content', 'hidden' ), true ) ) {
			$pos = strrpos( $html, '</div>' );
			$tag = '</div>';
			if ( substr( rtrim( $html ), -11 ) === '</fieldset>' ) {
				$pos = strrpos( $html, '</fieldset>' );
				$tag = '</fieldset>';
			}
			if ( false !== $pos ) {
				$html = substr( $html, 0, $pos ) . '<p class="wkf-hint">' . esc_html( $field['hint'] ) . '</p>' . substr( $html, $pos );
			}
		}

		if ( empty( $field['cond'] ) ) {
			echo $html; // phpcs:ignore -- složeno z escapovaných částí
			return;
		}
		echo '<div class="wkf-cond" data-wkf-cond="' . esc_attr( wp_json_encode( $field['cond'], JSON_UNESCAPED_UNICODE ) ) . '" data-wkf-cond-key="' . esc_attr( $field['key'] ) . '" hidden>';
		echo $html; // phpcs:ignore
		echo '</div>';
	}

	/**
	 * Je pole viditelné? Vyhodnotí režim zobrazit/skrýt a skupiny pravidel
	 * (skupiny = NEBO, pravidla ve skupině = A). Řídicí pole, které je samo
	 * skryté, se chová jako prázdné – přes libovolný počet úrovní.
	 */
	private function cond_met( $field, $all_fields = array(), $depth = 0 ) {
		// Pole ve skrytém kroku je skryté bez ohledu na vlastní podmínku.
		if ( ! empty( $field['step_cond'] ) && $depth < 10 ) {
			if ( ! $this->cond_met( array( 'key' => '', 'cond' => $field['step_cond'] ), $all_fields, $depth + 1 ) ) {
				return false;
			}
		}
		if ( empty( $field['cond'] ) || empty( $field['cond']['groups'] ) ) {
			return true;
		}
		$by_key = array();
		foreach ( $all_fields as $f ) {
			if ( isset( $f['key'] ) ) {
				$by_key[ $f['key'] ] = $f;
			}
		}
		$any_group = false;
		foreach ( $field['cond']['groups'] as $group ) {
			$all_rules = true;
			foreach ( $group as $rule ) {
				$values = array();
				$ctrl   = isset( $by_key[ $rule['field'] ] ) ? $by_key[ $rule['field'] ] : null;
				$hidden = $ctrl && $depth < 10 && ! $this->cond_met( $ctrl, $all_fields, $depth + 1 );
				if ( ! $hidden ) {
					$posted = 'wkf_' . $rule['field'];
					if ( isset( $_POST[ $posted ] ) ) {
						$values = is_array( $_POST[ $posted ] )
							? array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST[ $posted ] ) )
							: array( sanitize_text_field( wp_unslash( $_POST[ $posted ] ) ) );
					}
					$values = array_values( array_filter( $values, 'strlen' ) );
				}
				if ( ! $this->rule_matches( $rule['op'], $values, isset( $rule['value'] ) ? $rule['value'] : '' ) ) {
					$all_rules = false;
					break;
				}
			}
			if ( $all_rules ) {
				$any_group = true;
				break;
			}
		}
		return 'hide' === $field['cond']['mode'] ? ! $any_group : $any_group;
	}

	/** Vyhodnocení jednoho pravidla nad hodnotami řídicího pole (stejná logika jako v JS). */
	private function rule_matches( $op, $values, $expected ) {
		$joined = implode( "\n", $values );
		$lower  = mb_strtolower( $joined );
		$exp_l  = mb_strtolower( (string) $expected );
		switch ( $op ) {
			case 'empty':
				return empty( $values );
			case 'not_empty':
				return ! empty( $values );
			case 'eq':
				return in_array( (string) $expected, $values, true );
			case 'neq':
				return ! in_array( (string) $expected, $values, true );
			case 'contains':
				return '' !== $exp_l && false !== mb_strpos( $lower, $exp_l );
			case 'not_contains':
				return '' === $exp_l || false === mb_strpos( $lower, $exp_l );
			case 'starts':
				return '' !== $exp_l && 0 === mb_strpos( $lower, $exp_l );
			case 'ends':
				return '' !== $exp_l && mb_substr( $lower, -mb_strlen( $exp_l ) ) === $exp_l;
			case 'gt':
				return $values && is_numeric( str_replace( ',', '.', $values[0] ) ) && (float) str_replace( ',', '.', $values[0] ) > (float) str_replace( ',', '.', $expected );
			case 'lt':
				return $values && is_numeric( str_replace( ',', '.', $values[0] ) ) && (float) str_replace( ',', '.', $values[0] ) < (float) str_replace( ',', '.', $expected );
		}
		return false;
	}

	/** Vykreslení formuláře podle schématu; v režimu náhledu bez antispamu a kontextu. */
	private function render_schema( $type, $schema, $button, $preview = false ) {
		$has_file = false;
		foreach ( $schema['fields'] as $field ) {
			if ( 'file' === $field['type'] ) {
				$has_file = true;
			}
		}

		ob_start();
		if ( $preview ) {
			// Náhled žije uvnitř admin formuláře editace příspěvku – vnořený
			// <form> by HTML parser při innerHTML zahodil, proto obal jako <div>.
			echo '<div class="wkf-form wkf-form-' . esc_attr( $type ) . '">';
		} else {
			echo '<form class="wkf-form wkf-form-' . esc_attr( $type ) . '" data-wkf-form="' . esc_attr( $type ) . '" method="post" novalidate';
			echo $has_file ? ' enctype="multipart/form-data">' : '>';
		}

		// Rozdělení do kroků podle zlomů (formulář bez zlomu = jeden krok).
		$steps = array( array( 'fields' => array(), 'title' => '', 'btn_next' => '', 'btn_prev' => '', 'hide_prev' => false, 'cond' => null ) );
		foreach ( $schema['fields'] as $field ) {
			if ( 'pagebreak' === $field['type'] ) {
				$steps[] = array(
					'fields'    => array(),
					'title'     => $field['label'],
					'btn_next'  => $field['btn_next'],
					'btn_prev'  => $field['btn_prev'],
					'hide_prev' => ! empty( $field['hide_prev'] ),
					'cond'      => ! empty( $field['cond'] ) ? $field['cond'] : null,
				);
				continue;
			}
			$steps[ count( $steps ) - 1 ]['fields'][] = $field;
		}
		$multi = count( $steps ) > 1;

		if ( $multi ) {
			$progress      = isset( $schema['progress'] ) ? $schema['progress'] : 'none';
			$progress_text = ! empty( $schema['progress_text'] ) ? $schema['progress_text'] : 'Krok {n} z {total}';
			echo '<div class="wkf-steps-head" hidden>';
			if ( 'none' !== $progress ) {
				echo '<div class="wkf-progress wkf-progress-' . esc_attr( $progress ) . '" data-wkf-progress="' . esc_attr( $progress ) . '" data-wkf-progress-text="' . esc_attr( $progress_text ) . '"></div>';
			}
			echo '<div class="wkf-step-live wkf-visually-hidden" aria-live="polite" aria-atomic="true"></div>';
			echo '<div class="wkf-step-errors" role="alert" tabindex="-1" hidden></div>';
			echo '</div>';
		}

		foreach ( $steps as $index => $step ) {
			if ( $multi ) {
				$cond_attr = $step['cond'] ? ' data-wkf-step-cond="' . esc_attr( wp_json_encode( $step['cond'], JSON_UNESCAPED_UNICODE ) ) . '"' : '';
				$title     = $step['title'] ? $step['title'] : sprintf( 'Krok %d', $index + 1 );
				echo '<section class="wkf-step" data-wkf-step="' . $index . '" data-wkf-step-title="' . esc_attr( $title ) . '"' . $cond_attr . '>';
				echo '<h3 class="wkf-step-title" tabindex="-1">' . esc_html( $title ) . '</h3>';
			}
			$this->render_field_sequence( $type, $step['fields'] );
			if ( $multi ) {
				$next = isset( $steps[ $index + 1 ] ) ? $steps[ $index + 1 ] : null;
				echo '<div class="wkf-step-nav" hidden>';
				if ( $index > 0 && ! $step['hide_prev'] ) {
					echo '<button type="button" class="wkf-step-prev" aria-label="' . esc_attr( 'Zpět na předchozí krok' ) . '">' . esc_html( $step['btn_prev'] ? $step['btn_prev'] : 'Zpět' ) . '</button>';
				}
				if ( $next ) {
					echo '<button type="button" class="wkf-step-next" aria-label="' . esc_attr( 'Pokračovat na další krok' ) . '">' . esc_html( $next['btn_next'] ? $next['btn_next'] : 'Pokračovat' ) . '</button>';
				}
				echo '</div>';
				echo '</section>';
			}
		}

		if ( ! $preview ) {
			echo $this->antispam_field( $type );
			echo $this->hidden_context_fields();
			echo '<input type="hidden" name="wkf_form_type" value="' . esc_attr( $type ) . '">';
			echo $this->turnstile_widget();
		}
		$s_credit = $this->get_settings();
		if ( ! empty( $s_credit['credit'] ) && ! $preview ) {
			echo '<p class="wkf-credit"><a href="https://www.webklient.cz/" target="_blank" rel="noopener">Formulář od Webklient.cz</a></p>';
		}

		$has_prices = false;
		foreach ( $schema['fields'] as $field ) {
			if ( ! empty( $field['prices'] ) ) {
				$has_prices = true;
				break;
			}
		}
		if ( $has_prices ) {
			echo '<div class="wkf-total" data-wkf-total hidden>Orientační cena celkem: <strong><span data-wkf-total-amount>0 Kč</span></strong></div>';
		}

		echo '<div class="wkf-message" data-wkf-message role="alert"></div>';
		if ( $preview ) {
			echo '<button type="button" class="wkf-submit">' . esc_html( $button ) . '</button>';
			echo '</div>';
		} else {
			echo '<button type="submit" class="wkf-submit">' . esc_html( $button ) . '</button>';
			echo '</form>';
		}

		return ob_get_clean();
	}

	/** Vykreslení jednoho pole podle definice. */
	private function render_field( $form_type, $field ) {
		$id       = 'wkf-' . $form_type . '-' . $field['key'];
		$name     = 'wkf_' . $field['key'];
		$required = ! empty( $field['required'] );
		$req_mark = $required ? ' <span class="wkf-req">*</span>' : '';
		$default  = isset( $field['default'] ) ? (string) $field['default'] : '';
		$defaults = array_values( array_filter( array_map( 'trim', preg_split( '/[\n,]/', $default ) ) ) );
		$ph_attr  = ! empty( $field['placeholder'] ) ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
		$val_attr = '' !== $default ? ' value="' . esc_attr( $default ) . '"' : '';

		switch ( $field['type'] ) {
			case 'heading':
				echo '<h3 class="wkf-section-title">' . esc_html( $field['label'] ) . '</h3>';
				break;

			case 'content':
				echo '<div class="wkf-field wkf-content">' . wp_kses_post( isset( $field['content'] ) ? $field['content'] : '' ) . '</div>';
				break;

			case 'pagebreak':
				break;

			case 'hidden':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '"' . $val_attr . '>';
				break;

			case 'number':
			case 'date':
			case 'url':
				$range = '';
				if ( 'number' === $field['type'] ) {
					$range .= '' !== (string) $field['min'] ? ' min="' . esc_attr( $field['min'] ) . '"' : '';
					$range .= '' !== (string) $field['max'] ? ' max="' . esc_attr( $field['max'] ) . '"' : '';
					$range .= '' !== (string) $field['step'] ? ' step="' . esc_attr( $field['step'] ) . '"' : ' step="any"';
					$range .= ' inputmode="decimal"';
				}
				echo '<div class="wkf-field">';
				echo '<label class="wkf-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $req_mark . '</label>';
				echo '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $range . $ph_attr . $val_attr . ( $required ? ' required' : '' ) . '>';
				echo '</div>';
				break;

			case 'select_static':
				$options = isset( $field['options'] ) ? $field['options'] : array();
				$prices  = isset( $field['prices'] ) ? $field['prices'] : array();
				if ( empty( $options ) ) {
					break;
				}
				echo '<div class="wkf-field">';
				echo '<label class="wkf-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $req_mark . '</label>';
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $prices ? ' data-wkf-priced="1"' : '' ) . ( $required ? ' required' : '' ) . '>';
				echo '<option value="">' . esc_html( isset( $field['placeholder'] ) ? $field['placeholder'] : '– vyberte –' ) . '</option>';
				foreach ( $options as $option ) {
					$price_attr = isset( $prices[ $option ] ) ? ' data-wkf-price="' . esc_attr( $prices[ $option ] ) . '"' : '';
					$text       = isset( $prices[ $option ] ) ? $option . ' – ' . $this->format_price( $prices[ $option ] ) : $option;
					echo '<option value="' . esc_attr( $option ) . '"' . $price_attr . ( in_array( $option, $defaults, true ) ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>';
				}
				echo '</select>';
				if ( ! empty( $field['qty_enabled'] ) ) {
					echo '<span class="wkf-qty-wrap wkf-qty-select" hidden>× <input type="number" class="wkf-qty" name="' . esc_attr( $name ) . '_qty" value="1" min="1" max="999"> ks</span>';
				}
				echo '</div>';
				break;

			case 'text':
			case 'email':
			case 'tel':
				$autocomplete = 'tel' === $field['type'] ? ' autocomplete="tel"' : '';
				$suggest      = ! empty( $field['suggest'] ) ? $field['suggest'] : '';
				echo '<div class="wkf-field">';
				echo '<label class="wkf-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $req_mark . '</label>';
				if ( $suggest ) {
					echo '<div class="wkf-suggest-wrap">';
				}
				$prefill_attr = '';
				if ( ! empty( $field['prefill'] ) ) {
					$prefill_attr = ' data-wkf-prefill="' . esc_attr( $field['prefill'] ) . '"';
					if ( 'query' === $field['prefill'] && ! empty( $field['prefill_param'] ) ) {
						$prefill_attr .= ' data-wkf-prefill-param="' . esc_attr( $field['prefill_param'] ) . '"';
					}
				}
				echo '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $autocomplete . $prefill_attr . $ph_attr . $val_attr . ( $required ? ' required' : '' ) . ( $suggest ? ' data-wkf-suggest="' . esc_attr( $suggest ) . '" autocomplete="off" role="combobox" aria-expanded="false"' : '' ) . '>';
				if ( $suggest ) {
					echo '<div class="wkf-suggest" role="listbox" hidden></div></div>';
				}
				if ( ! empty( $field['hint'] ) ) {
					echo '<p class="wkf-hint">' . esc_html( $field['hint'] ) . '</p>';
				}
				echo '</div>';
				break;

			case 'textarea':
				$rows    = isset( $field['rows'] ) ? (int) $field['rows'] : 6;
				$suggest = ! empty( $field['suggest'] ) ? $field['suggest'] : '';
				echo '<div class="wkf-field">';
				echo '<label class="wkf-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $req_mark . '</label>';
				if ( $suggest ) {
					echo '<div class="wkf-suggest-wrap">';
				}
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . $rows . '"' . $ph_attr . ( $required ? ' required' : '' ) . ( $suggest ? ' data-wkf-suggest="' . esc_attr( $suggest ) . '"' : '' ) . '>' . esc_textarea( $default ) . '</textarea>';
				if ( $suggest ) {
					echo '<div class="wkf-suggest" role="listbox" hidden></div></div>';
				}
				if ( ! empty( $field['hint'] ) ) {
					echo '<p class="wkf-hint">' . esc_html( $field['hint'] ) . '</p>';
				}
				echo '</div>';
				break;

			case 'select_dynamic':
				if ( empty( $field['source_slug'] ) ) {
					// Návštěvníkům se pole tiše nevykreslí; redakce dostane vysvětlení.
					if ( current_user_can( 'edit_posts' ) ) {
						echo '<div class="wkf-field"><p style="margin:0;padding:8px 12px;background:#fcf9e8;border:1px dashed #dba617;border-radius:4px;font-size:13px;">Pole „' . esc_html( $field['label'] ) . '" se návštěvníkům nezobrazuje – dynamický zdroj nabídky nemá vyplněný slug. Doplňte ho v editaci formuláře, nebo přepněte volby na „ručně zadané".</p></div>';
					}
					break;
				}
				// Napevno daná služba (atribut shortcodu) – skryté pole místo dropdownu.
				if ( ! empty( $field['fixed_value'] ) ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $field['fixed_value'] ) . '">';
					break;
				}
				$options = $this->dynamic_options( $field );
				if ( empty( $options ) ) {
					if ( current_user_can( 'edit_posts' ) ) {
						echo '<div class="wkf-field"><p style="margin:0;padding:8px 12px;background:#fcf9e8;border:1px dashed #dba617;border-radius:4px;font-size:13px;">Pole „' . esc_html( $field['label'] ) . '" se návštěvníkům nezobrazuje – zdroj „' . esc_html( $field['source'] . ': ' . $field['source_slug'] ) . '" nevrací žádné publikované položky.</p></div>';
					}
					break;
				}
				$prices  = isset( $field['prices'] ) ? $field['prices'] : array();
				echo '<div class="wkf-field">';
				echo '<label class="wkf-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $req_mark . '</label>';
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $prices ? ' data-wkf-priced="1"' : '' ) . ( $required ? ' required' : '' ) . '>';
				echo '<option value="">' . esc_html( isset( $field['placeholder'] ) ? $field['placeholder'] : '– vyberte –' ) . '</option>';
				foreach ( $options as $option ) {
					$price_attr = isset( $prices[ $option ] ) ? ' data-wkf-price="' . esc_attr( $prices[ $option ] ) . '"' : '';
					$text       = isset( $prices[ $option ] ) ? $option . ' – ' . $this->format_price( $prices[ $option ] ) : $option;
					echo '<option value="' . esc_attr( $option ) . '"' . $price_attr . ( in_array( $option, $defaults, true ) ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>';
				}
				echo '</select>';
				if ( ! empty( $field['qty_enabled'] ) ) {
					echo '<span class="wkf-qty-wrap wkf-qty-select" hidden>× <input type="number" class="wkf-qty" name="' . esc_attr( $name ) . '_qty" value="1" min="1" max="999"> ks</span>';
				}
				echo '</div>';
				break;

			case 'checkbox_dynamic':
			case 'checkbox_static':
				if ( 'checkbox_dynamic' === $field['type'] && empty( $field['source_slug'] ) ) {
					if ( current_user_can( 'edit_posts' ) ) {
						echo '<div class="wkf-field"><p style="margin:0;padding:8px 12px;background:#fcf9e8;border:1px dashed #dba617;border-radius:4px;font-size:13px;">Pole „' . esc_html( $field['label'] ) . '" se návštěvníkům nezobrazuje – dynamický zdroj voleb nemá vyplněný slug.</p></div>';
					}
					break;
				}
				$options = 'checkbox_dynamic' === $field['type'] ? $this->dynamic_options( $field ) : ( isset( $field['options'] ) ? $field['options'] : array() );
				if ( 'checkbox_static' === $field['type'] && empty( $options ) ) {
					break; // Prázdný seznam voleb – pole se nevykreslí.
				}
				$inline = ! empty( $field['inline'] ) ? ' wkf-checkboxes-inline' : '';
				$single = ! empty( $field['single'] );
				echo '<fieldset class="wkf-field wkf-checkboxes' . $inline . '"' . ( $required ? ' data-wkf-required-group="1"' : '' ) . '>';
				echo '<legend class="wkf-label">' . esc_html( $field['label'] ) . $req_mark . '</legend>';
				if ( $options ) {
					$prices     = isset( $field['prices'] ) ? $field['prices'] : array();
					$use_qty    = ! empty( $field['qty_enabled'] );
					$input_type = $single ? 'radio' : 'checkbox';
					$input_name = $single ? $name : $name . '[]';
					foreach ( $options as $opt_index => $option ) {
						$price_attr = isset( $prices[ $option ] ) ? ' data-wkf-price="' . esc_attr( $prices[ $option ] ) . '"' : '';
						$suffix     = isset( $prices[ $option ] ) ? ' <span class="wkf-price">' . esc_html( $this->format_price( $prices[ $option ] ) ) . '</span>' : '';
						$qty_html   = '';
						if ( $use_qty && isset( $prices[ $option ] ) ) {
							$qty_html = ' <span class="wkf-qty-wrap" hidden>× <input type="number" class="wkf-qty" name="' . esc_attr( $name ) . '_qty[' . $opt_index . ']" value="1" min="1" max="999"> ks</span>';
						}
						echo '<label class="wkf-check"><input type="' . $input_type . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $option ) . '"' . $price_attr . ( in_array( $option, $defaults, true ) ? ' checked' : '' ) . ( $single && $required ? ' required' : '' ) . '> ' . esc_html( $option ) . $suffix . $qty_html . '</label>';
					}
				} else {
					echo '<p class="wkf-empty">' . esc_html( isset( $field['empty_text'] ) ? $field['empty_text'] : 'Žádné položky k výběru.' ) . '</p>';
				}
				echo '</fieldset>';
				break;

			case 'vop':
				$page_id   = ! empty( $field['page_id'] ) ? (int) $field['page_id'] : 0;
				$page_link = '';
				if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
					$page_link = ' <a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $page_id ) ) . '</a>';
				}
				echo '<div class="wkf-field wkf-vop">';
				echo '<label class="wkf-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="ano" required> ' . esc_html( $field['label'] ) . $page_link . ' <span class="wkf-req">*</span></label>';
				echo '</div>';
				break;

			case 'file':
				$allowed_ext = ! empty( $field['allowed_ext'] ) ? (array) $field['allowed_ext'] : array( 'doc', 'docx', 'odt', 'pdf', 'jpg', 'png' );
				if ( in_array( 'jpg', $allowed_ext, true ) && ! in_array( 'jpeg', $allowed_ext, true ) ) {
					$allowed_ext[] = 'jpeg';
				}
				$max_mb = ! empty( $field['max_mb'] ) ? (int) $field['max_mb'] : 10;
				$accept = '.' . implode( ',.', $allowed_ext );
				echo '<div class="wkf-field">';
				echo '<label class="wkf-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $req_mark . '</label>';
				echo '<div class="wkf-dropzone" data-wkf-dropzone>';
				$max_files = ! empty( $field['max_files'] ) ? (int) $field['max_files'] : 1;
				echo '<input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . ( $max_files > 1 ? '[]" multiple' : '"' ) . ' accept="' . esc_attr( $accept ) . '" data-wkf-ext="' . esc_attr( implode( ',', $allowed_ext ) ) . '" data-wkf-max="' . esc_attr( $max_mb * MB_IN_BYTES ) . '" data-wkf-maxfiles="' . $max_files . '"' . ( $required ? ' required' : '' ) . '>';
				echo '<span class="wkf-dropzone-text">' . ( $max_files > 1 ? 'Přetáhněte až ' . $max_files . ' souborů sem nebo <u>klikněte pro výběr</u>' : 'Přetáhněte soubor sem nebo <u>klikněte pro výběr</u>' ) . '</span>';
				echo '<span class="wkf-dropzone-file" data-wkf-filename></span>';
				echo '</div>';
				if ( ! empty( $field['hint'] ) ) {
					echo '<p class="wkf-hint">' . esc_html( $field['hint'] ) . '</p>';
				}
				echo '</div>';
				break;
		}
	}

	/** Volby dynamického pole z taxonomie nebo post typu. */
	private function dynamic_options( $field ) {
		if ( empty( $field['source_slug'] ) ) {
			return array();
		}
		$s = $this->get_settings();

		if ( 'post_type' === $field['source'] ) {
			$posts = get_posts(
				array(
					'post_type'      => $field['source_slug'],
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			return array_map( 'get_the_title', $posts );
		}

		$args = array(
			'taxonomy'   => $field['source_slug'],
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( $s['exclude_default_cat'] && 'category' === $field['source_slug'] ) {
			$args['exclude'] = array( (int) get_option( 'default_category' ) );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return wp_list_pluck( $terms, 'name' );
	}

	private function turnstile_widget() {
		$s = $this->get_settings();
		if ( ! $s['turnstile_site_key'] ) {
			return '';
		}
		return '<div class="wkf-field wkf-turnstile"><div class="cf-turnstile" data-sitekey="' . esc_attr( $s['turnstile_site_key'] ) . '" data-language="cs"></div></div>';
	}

	/**
	 * Kontrolní otázka pro daný formulář. Vlastní formuláře mohou globální
	 * nastavení přepsat (vlastní otázka/odpověď) nebo otázku vypnout.
	 */
	private function antispam_config( $form_type ) {
		$s      = $this->get_settings();
		$config = array(
			'enabled'  => (bool) $s['antispam_enabled'],
			'question' => $s['antispam_question'],
			'answer'   => $s['antispam_answer'],
		);

		$schemas = $this->form_schemas();
		$post_id = isset( $schemas[ $form_type ]['_custom'] ) ? (int) $schemas[ $form_type ]['_custom'] : 0;
		if ( ! $post_id ) {
			return $config;
		}

		$mode = get_post_meta( $post_id, '_wkf_q_mode', true );
		if ( 'off' === $mode ) {
			$config['enabled'] = false;
		} elseif ( 'custom' === $mode ) {
			$question = get_post_meta( $post_id, '_wkf_q_question', true );
			$answer   = get_post_meta( $post_id, '_wkf_q_answer', true );
			if ( $question && $answer ) {
				$config['enabled']  = true;
				$config['question'] = $question;
				$config['answer']   = $answer;
			}
		}
		return $config;
	}

	private function antispam_field( $form_type ) {
		$config = $this->antispam_config( $form_type );
		if ( ! $config['enabled'] ) {
			return '';
		}
		$id = 'wkf-' . $form_type . '-antispam';
		return '<div class="wkf-field">'
			. '<label class="wkf-label" for="' . esc_attr( $id ) . '">Ochrana před spamem <span class="wkf-req">*</span></label>'
			. '<p class="wkf-hint wkf-antispam-hint">' . esc_html( $config['question'] ) . '</p>'
			. '<input type="text" id="' . esc_attr( $id ) . '" name="wkf_antispam" autocomplete="off" required>'
			. '</div>';
	}

	private function hidden_context_fields() {
		global $post;
		$page_title   = is_singular() && $post ? get_the_title( $post ) : wp_get_document_title();
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$page_url     = home_url( $request_uri );
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';

		// Hodnoty se na klientu aktualizují JavaScriptem (kvůli page cache).
		$html  = '<input type="hidden" name="wkf_page_title" value="' . esc_attr( $page_title ) . '">';
		$html .= '<input type="hidden" name="wkf_page_url" data-wkf-page-url value="' . esc_url( $page_url ) . '">';
		$html .= '<input type="hidden" name="wkf_query_string" data-wkf-query-string value="' . esc_attr( $query_string ) . '">';
		$s     = $this->get_settings();
		if ( ! empty( $s['journey'] ) ) {
			$html .= '<input type="hidden" name="wkf_journey" data-wkf-journey value="">';
		}
		// Honeypot – skryté pole, které lidé nevyplní.
		$html .= '<div class="wkf-hp" aria-hidden="true"><label>Nevyplňujte<input type="text" name="wkf_website_hp" tabindex="-1" autocomplete="off"></label></div>';
		return $html;
	}

	/**
	 * Sanitizace cesty návštěvníka z prohlížeče. Struktura je pevná,
	 * délka omezená; do záznamu se nedostane nic, co neprošlo tímto sítem.
	 */
	private function parse_journey( $raw ) {
		if ( ! $raw ) {
			return array();
		}
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$s     = $this->get_settings();
		$limit = max( 5, min( 100, (int) $s['journey_steps'] ) );

		$safe_url = static function ( $url ) {
			$url = esc_url_raw( (string) $url );
			return preg_match( '#^https?://#i', $url ) ? $url : '';
		};
		$out = array(
			'landing'       => isset( $data['landing'] ) ? $safe_url( $data['landing'] ) : '',
			'landing_title' => isset( $data['landingTitle'] ) ? sanitize_text_field( $data['landingTitle'] ) : '',
			'referrer'      => isset( $data['referrer'] ) ? $safe_url( $data['referrer'] ) : '',
			'source'        => isset( $data['source'] ) ? sanitize_text_field( $data['source'] ) : '',
			'keyword'       => isset( $data['keyword'] ) ? sanitize_text_field( $data['keyword'] ) : '',
			'minutes'       => isset( $data['minutes'] ) ? max( 0, (int) $data['minutes'] ) : 0,
			'pages'         => isset( $data['pages'] ) ? max( 0, (int) $data['pages'] ) : 0,
			'utm'           => array(),
			'steps'         => array(),
		);
		foreach ( isset( $data['utm'] ) && is_array( $data['utm'] ) ? $data['utm'] : array() as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid' ), true ) ) {
				$out['utm'][ $key ] = sanitize_text_field( $value );
			}
		}
		foreach ( array_slice( isset( $data['steps'] ) && is_array( $data['steps'] ) ? $data['steps'] : array(), 0, $limit ) as $step ) {
			if ( empty( $step['url'] ) ) {
				continue;
			}
			$url = $safe_url( $step['url'] );
			if ( ! $url ) {
				continue;
			}
			$out['steps'][] = array(
				'url'   => $url,
				'title' => isset( $step['title'] ) ? sanitize_text_field( $step['title'] ) : '',
				't'     => isset( $step['t'] ) ? max( 0, (int) $step['t'] ) : 0,
			);
		}
		return ( $out['steps'] || $out['landing'] ) ? $out : array();
	}

	/** Shrnutí cesty do řádků pro notifikaci, webhook a export. */
	private function journey_lines( $journey ) {
		if ( empty( $journey ) || ! is_array( $journey ) ) {
			return array();
		}
		$lines = array();
		if ( ! empty( $journey['source'] ) ) {
			$lines['Zdroj návštěvy'] = $journey['source'];
		}
		if ( ! empty( $journey['keyword'] ) ) {
			$lines['Hledaný výraz'] = $journey['keyword'];
		}
		if ( ! empty( $journey['landing'] ) ) {
			$lines['Vstupní stránka'] = ( $journey['landing_title'] ? $journey['landing_title'] . ' – ' : '' ) . $journey['landing'];
		}
		if ( ! empty( $journey['referrer'] ) ) {
			$lines['Odkud přišel'] = $journey['referrer'];
		}
		if ( ! empty( $journey['utm'] ) ) {
			$utm = array();
			foreach ( $journey['utm'] as $key => $value ) {
				$utm[] = $key . '=' . $value;
			}
			$lines['Kampaň (UTM)'] = implode( ', ', $utm );
		}
		if ( ! empty( $journey['steps'] ) ) {
			$lines['Prohlédnuté stránky'] = count( $journey['steps'] ) . ' za ' . (int) $journey['minutes'] . ' min';
			$path = array();
			foreach ( $journey['steps'] as $index => $step ) {
				$path[] = ( $index + 1 ) . '. ' . ( $step['title'] ? $step['title'] : $step['url'] ) . ' (' . gmdate( 'i:s', $step['t'] ) . ')';
			}
			$lines['Cesta po webu'] = implode( "\n", $path );
		}
		return $lines;
	}

	/** Přehledný výpis cesty návštěvníka v detailu záznamu (HTML). */
	private function journey_html( $journey ) {
		if ( empty( $journey ) || ! is_array( $journey ) ) {
			return '';
		}
		$html = '<div class="wkf-journey">';
		$meta = array();
		if ( ! empty( $journey['source'] ) ) {
			$meta[] = '<strong>Zdroj:</strong> ' . esc_html( $journey['source'] );
		}
		if ( ! empty( $journey['keyword'] ) ) {
			$meta[] = '<strong>Hledaný výraz:</strong> ' . esc_html( $journey['keyword'] );
		}
		if ( ! empty( $journey['pages'] ) ) {
			$meta[] = '<strong>Na webu:</strong> ' . (int) $journey['pages'] . ' stránek za ' . (int) $journey['minutes'] . ' min';
		}
		if ( $meta ) {
			$html .= '<p style="margin:0 0 6px;">' . implode( ' &nbsp;|&nbsp; ', $meta ) . '</p>';
		}
		if ( ! empty( $journey['utm'] ) ) {
			$utm = array();
			foreach ( $journey['utm'] as $key => $value ) {
				$utm[] = esc_html( $key . '=' . $value );
			}
			$html .= '<p style="margin:0 0 6px;"><strong>Kampaň:</strong> ' . implode( ', ', $utm ) . '</p>';
		}
		if ( ! empty( $journey['referrer'] ) ) {
			$html .= '<p style="margin:0 0 6px;"><strong>Odkud přišel:</strong> <a href="' . esc_url( $journey['referrer'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $journey['referrer'] ) . '</a></p>';
		}
		if ( ! empty( $journey['steps'] ) ) {
			$html .= '<ol style="margin:0 0 0 18px;padding:0;">';
			foreach ( $journey['steps'] as $index => $step ) {
				$html .= '<li style="margin:2px 0;"><a href="' . esc_url( $step['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $step['title'] ? $step['title'] : $step['url'] ) . '</a> <span style="color:#787c82;">' . esc_html( gmdate( 'i:s', $step['t'] ) ) . ( 0 === $index ? ' – vstupní stránka' : '' ) . '</span></li>';
			}
			$html .= '</ol>';
		}
		return $html . '</div>';
	}

	/* =========================================================
	 * Zpracování odeslání (AJAX)
	 * ======================================================= */

	public function handle_submit() {
		// Nonce se záměrně neověřuje – stránky jdou z page cache a vložený nonce
		// po 24 h expiruje, což by odesílání rozbilo (stejný důvod, proč nonce
		// nepoužívá ani WPForms). Ochranu zajišťuje Turnstile, kontrolní otázka,
		// honeypot a rate limit dle IP.
		if ( ! $this->rate_limit_ok( 'submit', 10 ) ) {
			wp_send_json_error( array( 'message' => 'Příliš mnoho pokusů o odeslání. Zkuste to prosím za chvíli.' ) );
		}

		$s       = $this->get_settings();
		$schemas = $this->form_schemas();

		// Honeypot – tiše ukončíme jako úspěch.
		if ( ! empty( $_POST['wkf_website_hp'] ) ) {
			wp_send_json_success( array( 'redirect' => '' ) );
		}

		$form_type = isset( $_POST['wkf_form_type'] ) ? sanitize_key( wp_unslash( $_POST['wkf_form_type'] ) ) : '';
		if ( ! isset( $schemas[ $form_type ] ) ) {
			wp_send_json_error( array( 'message' => 'Neplatný formulář. Obnovte prosím stránku a zkuste to znovu.' ) );
		}
		$schema = $schemas[ $form_type ];

		// Kontrolní otázka (per formulář, s globálním výchozím nastavením).
		$antispam = $this->antispam_config( $form_type );
		if ( $antispam['enabled'] ) {
			$answer = isset( $_POST['wkf_antispam'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_antispam'] ) ) : '';
			if ( '' === $answer || strtolower( trim( $answer ) ) !== strtolower( trim( $antispam['answer'] ) ) ) {
				wp_send_json_error( array( 'message' => 'Odpověď na kontrolní otázku není správná. Zkuste to prosím znovu.' ) );
			}
		}

		// Turnstile.
		if ( $s['turnstile_secret_key'] ) {
			$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
			if ( ! $token || ! $this->verify_turnstile( $token, $s['turnstile_secret_key'] ) ) {
				wp_send_json_error( array( 'message' => 'Ověření proti spamu se nezdařilo. Obnovte prosím stránku a zkuste to znovu.' ) );
			}
		}

		// Sběr a validace polí podle schématu.
		$values    = array(); // key => hodnota (string)
		$labels    = array(); // label => hodnota (pro záznam a e-mail)
		$errors    = array();
		$file_info = null;

		foreach ( $schema['fields'] as $field ) {
			$key      = $field['key'];
			$name     = 'wkf_' . $key;
			$required = ! empty( $field['required'] );

			// Pole s nesplněnou podmínkou se nevaliduje ani nesbírá.
			if ( ! $this->cond_met( $field, $schema['fields'] ) ) {
				continue;
			}

			switch ( $field['type'] ) {
				case 'heading':
					continue 2; // Nadpis nenese hodnotu.

				case 'select_static':
					$value   = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
					$allowed = isset( $field['options'] ) ? $field['options'] : array();
					if ( $required && '' === $value ) {
						$errors[] = sprintf( 'Vyberte prosím „%s".', $field['label'] );
					}
					if ( $value && ! in_array( $value, $allowed, true ) ) {
						$errors[] = 'Vybraná hodnota není platná. Obnovte prosím stránku a zkuste to znovu.';
					}
					break;

				case 'email':
					$value = isset( $_POST[ $name ] ) ? sanitize_email( wp_unslash( $_POST[ $name ] ) ) : '';
					if ( $required && '' === $value ) {
						$errors[] = 'Vyplňte prosím e-mailovou adresu.';
					} elseif ( '' !== $value && ! is_email( $value ) ) {
						$errors[] = 'E-mailová adresa nemá platný tvar.';
					}
					break;

				case 'tel':
					$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
					if ( $required && '' === $value ) {
						$errors[] = sprintf( 'Vyplňte prosím pole „%s".', $field['label'] );
					} elseif ( '' !== $value ) {
						$digits = preg_replace( '/[\s\-().]/', '', $value );
						if ( ! preg_match( '/^\+?\d{9,15}$/', $digits ) ) {
							$errors[] = 'Telefonní číslo nemá platný tvar.';
						} else {
							$value = $digits;
						}
					}
					break;

				case 'textarea':
					$value = isset( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : '';
					if ( $required && '' === $value ) {
						$errors[] = sprintf( 'Vyplňte prosím pole „%s".', $field['label'] );
					}
					break;

				case 'select_dynamic':
					$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
					if ( empty( $field['source_slug'] ) ) {
						$value = '';
						break;
					}
					if ( $required && '' === $value ) {
						$errors[] = sprintf( 'Vyberte prosím „%s".', $field['label'] );
					}
					if ( $value && ! in_array( $value, $this->dynamic_options( $field ), true ) ) {
						$errors[] = 'Vybraná hodnota není platná. Obnovte prosím stránku a zkuste to znovu.';
					}
					break;

				case 'checkbox_dynamic':
				case 'checkbox_static':
					$allowed = 'checkbox_dynamic' === $field['type'] ? $this->dynamic_options( $field ) : ( isset( $field['options'] ) ? $field['options'] : array() );
					if ( ! empty( $field['single'] ) ) {
						// Režim „jedna možnost" (přepínač) – skalární hodnota.
						$value = isset( $_POST[ $name ] ) && ! is_array( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
						if ( $value && ! in_array( $value, $allowed, true ) ) {
							$errors[] = 'Vybraná hodnota není platná. Obnovte prosím stránku a zkuste to znovu.';
							$value    = '';
						}
						if ( $required && '' === $value ) {
							$errors[] = sprintf( 'Vyberte prosím jednu z možností v poli „%s".', $field['label'] );
						}
						break;
					}
					$raw = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST[ $name ] ) ) : array();
					$raw = array_values( array_intersect( $raw, $allowed ) );
					if ( $required && empty( $raw ) ) {
						$errors[] = sprintf( 'Vyberte prosím alespoň jednu položku v poli „%s".', $field['label'] );
					}
					$value = implode( "\n", $raw );
					break;

				case 'content':
				case 'pagebreak':
					continue 2; // Obsahový blok a zlom kroku nic neodesílají.

				case 'hidden':
					$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
					break;

				case 'number':
					$raw_num = isset( $_POST[ $name ] ) ? trim( str_replace( array( ' ', ',' ), array( '', '.' ), sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ) ) : '';
					$value   = '';
					if ( '' !== $raw_num ) {
						if ( ! is_numeric( $raw_num ) ) {
							$errors[] = sprintf( 'Pole „%s" musí obsahovat číslo.', $field['label'] );
						} else {
							$num = (float) $raw_num;
							if ( '' !== (string) $field['min'] && $num < (float) $field['min'] ) {
								$errors[] = sprintf( 'Pole „%s" musí být nejméně %s.', $field['label'], $field['min'] );
							}
							if ( '' !== (string) $field['max'] && $num > (float) $field['max'] ) {
								$errors[] = sprintf( 'Pole „%s" smí být nejvýše %s.', $field['label'], $field['max'] );
							}
							$value = fmod( $num, 1 ) ? rtrim( rtrim( number_format( $num, 4, '.', '' ), '0' ), '.' ) : (string) (int) $num;
						}
					} elseif ( $required ) {
						$errors[] = sprintf( 'Vyplňte prosím pole „%s".', $field['label'] );
					}
					break;

				case 'date':
					$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
					if ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
						$errors[] = sprintf( 'Pole „%s" musí obsahovat platné datum.', $field['label'] );
					} elseif ( $required && '' === $value ) {
						$errors[] = sprintf( 'Vyplňte prosím pole „%s".', $field['label'] );
					}
					break;

				case 'url':
					$value = isset( $_POST[ $name ] ) ? esc_url_raw( trim( wp_unslash( $_POST[ $name ] ) ) ) : '';
					if ( $required && '' === $value ) {
						$errors[] = sprintf( 'Vyplňte prosím pole „%s".', $field['label'] );
					}
					break;

				case 'vop':
					$value = isset( $_POST[ $name ] ) && 'ano' === $_POST[ $name ] ? 'ano' : '';
					if ( '' === $value ) {
						$errors[] = 'Bez potvrzení souhlasu s podmínkami nelze formulář odeslat.';
					}
					break;

				case 'file':
					$value = '';
					$names = isset( $_FILES[ $name ]['name'] ) ? array_filter( (array) $_FILES[ $name ]['name'], 'strlen' ) : array();
					if ( empty( $names ) ) {
						if ( $required ) {
							$errors[] = sprintf( 'Nahrajte prosím soubor v poli „%s".', $field['label'] );
						}
					} elseif ( count( $names ) > max( 1, (int) $field['max_files'] ) ) {
						$errors[] = sprintf( 'V poli „%s" lze nahrát nejvýše %d souborů.', $field['label'], (int) $field['max_files'] );
					}
					break;

				default: // text, tel
					$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
					if ( $required && '' === $value ) {
						$errors[] = sprintf( 'Vyplňte prosím pole „%s".', $field['label'] );
					}
					break;
			}

			$values[ $key ]            = isset( $value ) ? $value : '';
			$labels[ $field['label'] ] = $values[ $key ];
			// Role pole (jméno, e-mail, telefon…) zpřístupní hodnotu pod rolí
			// nezávisle na klíči – notifikace, Reply-To i webhook čtou roli.
			if ( ! empty( $field['role'] ) && $field['role'] !== $key && ( ! isset( $values[ $field['role'] ] ) || '' === $values[ $field['role'] ] ) ) {
				$values[ $field['role'] ] = $values[ $key ];
			}
		}

		// IČO: nepovinné, ale pokud je vyplněné, musí mít 8 číslic.
		if ( isset( $values['ico'] ) && '' !== $values['ico'] ) {
			$ico_digits = preg_replace( '/\s+/', '', $values['ico'] );
			if ( ! preg_match( '/^\d{8}$/', $ico_digits ) ) {
				$errors[] = 'IČO musí mít 8 číslic.';
			} else {
				$values['ico'] = $ico_digits;
				foreach ( $schema['fields'] as $field ) {
					if ( 'ico' === $field['key'] ) {
						$labels[ $field['label'] ] = $ico_digits;
						break;
					}
				}
			}
		}

		if ( $errors ) {
			wp_send_json_error( array( 'message' => implode( ' ', $errors ) ) );
		}

		// Upload souborů (po validaci ostatních polí) – více polí i více souborů v poli.
		$uploaded_files = array();
		foreach ( $schema['fields'] as $field ) {
			if ( 'file' !== $field['type'] || ! $this->cond_met( $field, $schema['fields'] ) ) {
				continue;
			}
			$name = 'wkf_' . $field['key'];
			if ( empty( $_FILES[ $name ] ) ) {
				continue;
			}
			$batch = $_FILES[ $name ];
			$list  = array();
			if ( is_array( $batch['name'] ) ) {
				foreach ( $batch['name'] as $i => $fname ) {
					if ( '' === $fname ) {
						continue;
					}
					$list[] = array( 'name' => $fname, 'type' => $batch['type'][ $i ], 'tmp_name' => $batch['tmp_name'][ $i ], 'error' => $batch['error'][ $i ], 'size' => $batch['size'][ $i ] );
				}
			} elseif ( '' !== $batch['name'] ) {
				$list[] = $batch;
			}
			$names_out = array();
			foreach ( $list as $single_file ) {
				$file_info = $this->handle_upload(
					$single_file,
					! empty( $field['allowed_ext'] ) ? (array) $field['allowed_ext'] : null,
					! empty( $field['max_mb'] ) ? (int) $field['max_mb'] * MB_IN_BYTES : null
				);
				if ( is_wp_error( $file_info ) ) {
					wp_send_json_error( array( 'message' => $file_info->get_error_message() ) );
				}
				$uploaded_files[] = array( 'field' => $field['label'], 'file' => $file_info['file'], 'url' => $file_info['url'], 'name' => basename( $file_info['file'] ) );
				$names_out[]      = basename( $file_info['file'] );
			}
			if ( $names_out ) {
				$values[ $field['key'] ]   = implode( "\n", $names_out );
				$labels[ $field['label'] ] = implode( "\n", $names_out );
			}
		}
		$file_info = $uploaded_files ? array( 'file' => $uploaded_files[0]['file'], 'url' => $uploaded_files[0]['url'] ) : null;

		$journey      = $this->parse_journey( isset( $_POST['wkf_journey'] ) ? wp_unslash( $_POST['wkf_journey'] ) : '' );
		$page_title   = isset( $_POST['wkf_page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_page_title'] ) ) : '';
		$page_url     = isset( $_POST['wkf_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['wkf_page_url'] ) ) : '';
		$query_string = isset( $_POST['wkf_query_string'] ) ? sanitize_text_field( wp_unslash( $_POST['wkf_query_string'] ) ) : '';
		$referer      = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		// Záznam do administrace.
		$display_name = ! empty( $values['jmeno'] ) ? $values['jmeno'] : ( isset( $values['email'] ) ? $values['email'] : '' );
		$entry_id     = wp_insert_post(
			array(
				'post_type'   => self::CPT_ENTRY,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s – %s – %s', $schema['name'], $display_name, wp_date( 'j. n. Y H:i' ) ),
			),
			true
		);

		// Orientační cena: součet cen zvolených položek podle definice formuláře,
		// volitelně násobených počtem kusů (klientský součet se nikdy nepřebírá).
		$total = 0.0;
		foreach ( $schema['fields'] as $field ) {
			if ( empty( $field['prices'] ) || ! isset( $values[ $field['key'] ] ) || '' === $values[ $field['key'] ] ) {
				continue;
			}
			$qty_name  = 'wkf_' . $field['key'] . '_qty';
			$use_qty   = ! empty( $field['qty_enabled'] );
			$display   = array();
			$is_multi  = in_array( $field['type'], array( 'checkbox_static', 'checkbox_dynamic' ), true ) && empty( $field['single'] );
			$opts_list = isset( $field['options'] ) ? $field['options'] : $this->dynamic_options( $field );
			$chosen    = $is_multi ? explode( "\n", $values[ $field['key'] ] ) : array( $values[ $field['key'] ] );

			foreach ( $chosen as $choice ) {
				if ( ! isset( $field['prices'][ $choice ] ) ) {
					$display[] = $choice;
					continue;
				}
				$qty = 1;
				if ( $use_qty ) {
					if ( $is_multi ) {
						$opt_index = array_search( $choice, $opts_list, true );
						if ( false !== $opt_index && isset( $_POST[ $qty_name ][ $opt_index ] ) ) {
							$qty = (int) $_POST[ $qty_name ][ $opt_index ];
						}
					} elseif ( isset( $_POST[ $qty_name ] ) && ! is_array( $_POST[ $qty_name ] ) ) {
						$qty = (int) $_POST[ $qty_name ];
					}
					$qty = min( max( 1, $qty ), 999 );
				}
				$total    += (float) $field['prices'][ $choice ] * $qty;
				$display[] = $qty > 1 ? $choice . ' × ' . $qty . ' ks' : $choice;
			}

			if ( $use_qty ) {
				$labels[ $field['label'] ] = implode( "\n", $display );
			}
		}
		if ( $total > 0 ) {
			$labels['Orientační cena celkem'] = $this->format_price( $total );
		}

		// Ověření adresy realizace v RÚIAN (pole typu adresa, nebo klíč adresa).
		$address_value = '';
		foreach ( $schema['fields'] as $field ) {
			if ( ( ! empty( $field['ruian'] ) || 'adresa' === $field['key'] ) && ! empty( $values[ $field['key'] ] ) ) {
				$address_value = $values[ $field['key'] ];
				break;
			}
		}
		$address_check = $address_value ? $this->validate_address_ruian( $address_value ) : '';

		if ( ! is_wp_error( $entry_id ) ) {
			update_post_meta( $entry_id, '_wkf_form_type', $form_type );
			update_post_meta( $entry_id, '_wkf_fields', $labels );
			update_post_meta( $entry_id, '_wkf_email', isset( $values['email'] ) ? $values['email'] : '' );
			update_post_meta( $entry_id, '_wkf_phone', isset( $values['telefon'] ) ? $values['telefon'] : '' );
			if ( $journey ) {
				update_post_meta( $entry_id, '_wkf_journey', $journey );
			}
			update_post_meta( $entry_id, '_wkf_page_title', $page_title );
			update_post_meta( $entry_id, '_wkf_page_url', $page_url );
			update_post_meta( $entry_id, '_wkf_query_string', $query_string );
			update_post_meta( $entry_id, '_wkf_referer', $referer );
			update_post_meta( $entry_id, '_wkf_ip', $this->client_ip() );
			if ( $address_check ) {
				update_post_meta( $entry_id, '_wkf_address_check', $address_check );
			}
			if ( $file_info ) {
				update_post_meta( $entry_id, '_wkf_file_url', $file_info['url'] );
				update_post_meta( $entry_id, '_wkf_file_path', $file_info['file'] );
			}
			if ( $uploaded_files ) {
				update_post_meta( $entry_id, '_wkf_files', $uploaded_files );
			}
		}

		// Notifikační e-mail.
		$this->current_uploads = $uploaded_files;
		$this->current_journey = $journey;
		$mail_sent = $this->send_notification( $form_type, $schema, $labels, $values, $page_title, $page_url, $query_string, $referer, $file_info, $address_check );
		if ( ! is_wp_error( $entry_id ) ) {
			update_post_meta( $entry_id, '_wkf_mail_sent', $mail_sent ? 1 : 0 );
			if ( ! $mail_sent ) {
				update_post_meta( $entry_id, '_wkf_mail_error', sanitize_text_field( $this->last_mail_error ? $this->last_mail_error : 'Bez podrobností – wp_mail selhal ještě před předáním mail serveru.' ) );
			}
			$this->send_webhook( $entry_id, $form_type, $schema, $labels, $values, $page_url, $referer, $query_string );
		}

		// Automatická odpověď klientovi.
		$autoreply_sent = $this->send_autoreply( $form_type, $values );
		if ( ! is_wp_error( $entry_id ) ) {
			update_post_meta( $entry_id, '_wkf_autoreply_sent', $autoreply_sent ? 1 : 0 );
		}

		// Přesměrování na děkovací stránku.
		$config      = $this->form_config( $form_type );
		$thankyou_id = (int) $config['thankyou'];
		$redirect    = $thankyou_id ? get_permalink( $thankyou_id ) : '';

		// Shrnutí odeslaných údajů – zobrazí se místo formuláře (bez přesměrování).
		$summary = '';
		if ( ! $redirect && $labels ) {
			$summary = '<div class="wkf-summary"><h3 class="wkf-summary-title">Souhrn odeslané zprávy</h3><dl>';
			foreach ( $labels as $summary_label => $summary_value ) {
				if ( '' === trim( (string) $summary_value ) ) {
					continue;
				}
				$summary .= '<dt>' . esc_html( $summary_label ) . '</dt><dd>' . nl2br( esc_html( $summary_value ) ) . '</dd>';
			}
			$summary .= '</dl></div>';
		}

		$confirm = $config['confirm'] ? wp_kses_post( $this->fill_placeholders( $config['confirm'], $values, $schema, $page_title, $page_url ) ) : '';

		wp_send_json_success(
			array(
				'redirect' => $redirect ? $redirect : '',
				'message'  => $confirm ? $confirm : 'Děkujeme, formulář byl úspěšně odeslán.',
				'summary'  => $summary,
			)
		);
	}

	/* =========================================================
	 * Našeptávání (ARES, Mapy.cz) – serverová proxy s cache
	 * ======================================================= */

	public function handle_suggest() {
		// Nonce se záměrně neověřuje: stránky se servírují z cache a vložený
		// nonce expiruje, což by našeptávání rozbilo (HTTP 403). Endpoint nic
		// nemění a vrací jen veřejná data; zneužití brání rate limit dle IP.
		if ( ! $this->rate_limit_ok( 'suggest', 30 ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$kind = isset( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : '';
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$q    = trim( $q );

		if ( ! in_array( $kind, array( 'ares', 'address' ), true ) || mb_strlen( $q ) < 3 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$cache_key = 'wkf_sg_' . $kind . '_' . md5( $q );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( array( 'items' => $cached ) );
		}

		$items = 'ares' === $kind ? $this->suggest_ares( $q ) : $this->suggest_address( $q );

		set_transient( $cache_key, $items, HOUR_IN_SECONDS );
		wp_send_json_success( array( 'items' => $items ) );
	}

	/** ARES: 8 číslic = lookup dle IČO, jinak fulltext dle obchodního jména. */
	private function suggest_ares( $q ) {
		$items  = array();
		$digits = preg_replace( '/\s+/', '', $q );

		if ( preg_match( '/^\d{8}$/', $digits ) ) {
			$response = wp_remote_get(
				'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' . $digits,
				array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) )
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $body['ico'] ) ) {
					$items[] = array(
						'ico'    => sanitize_text_field( $body['ico'] ),
						'nazev'  => sanitize_text_field( isset( $body['obchodniJmeno'] ) ? $body['obchodniJmeno'] : '' ),
						'adresa' => sanitize_text_field( isset( $body['sidlo']['textovaAdresa'] ) ? $body['sidlo']['textovaAdresa'] : '' ),
					);
				}
			}
			return $items;
		}

		$response = wp_remote_post(
			'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode( array( 'obchodniJmeno' => $q, 'pocet' => 5 ) ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['ekonomickeSubjekty'] ) && is_array( $body['ekonomickeSubjekty'] ) ) {
				foreach ( array_slice( $body['ekonomickeSubjekty'], 0, 5 ) as $subject ) {
					$items[] = array(
						'ico'    => sanitize_text_field( isset( $subject['ico'] ) ? $subject['ico'] : '' ),
						'nazev'  => sanitize_text_field( isset( $subject['obchodniJmeno'] ) ? $subject['obchodniJmeno'] : '' ),
						'adresa' => sanitize_text_field( isset( $subject['sidlo']['textovaAdresa'] ) ? $subject['sidlo']['textovaAdresa'] : '' ),
					);
				}
			}
		}
		return $items;
	}

	/* =========================================================
	 * Ověření adresy v RÚIAN (ruian.fnx.io)
	 * ======================================================= */

	/**
	 * Heuristické rozložení volně napsané adresy na komponenty pro validaci.
	 * Vrací pole s klíči street, cp, co, zip, municipalityName.
	 */
	private function parse_address( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', str_replace( array( "\r", "\n" ), ', ', (string) $text ) ) );

		$parts = array( 'street' => '', 'cp' => '', 'co' => '', 'zip' => '', 'municipalityName' => '' );

		// PSČ.
		if ( preg_match( '/\b(\d{3})\s?(\d{2})\b/', $text, $m ) ) {
			$parts['zip'] = $m[1] . $m[2];
			$text         = trim( str_replace( $m[0], '', $text ), " ,\t" );
		}

		$segments = array_values( array_filter( array_map( 'trim', explode( ',', $text ) ) ) );
		if ( ! $segments ) {
			return $parts;
		}

		// První segment: ulice + čísla (385/34 nebo 385).
		$first = $segments[0];
		if ( preg_match( '/(\d+[a-zA-Z]?)\s*\/\s*(\d+[a-zA-Z]?)/', $first, $m ) ) {
			$parts['cp'] = $m[1];
			$parts['co'] = $m[2];
			$first       = trim( str_replace( $m[0], '', $first ) );
		} elseif ( preg_match( '/\b(\d+[a-zA-Z]?)\b(?!.*\d)/', $first, $m ) ) {
			$parts['cp'] = $m[1];
			$first       = trim( str_replace( $m[0], '', $first ) );
		}
		$parts['street'] = trim( $first, " ,\t" );

		// Poslední segment bez číslic = obec; jinak fallback na ulici/část obce.
		$last = end( $segments );
		if ( count( $segments ) > 1 ) {
			$parts['municipalityName'] = trim( preg_replace( '/\d+/', '', $last ), " ,\t\/" );
		}
		if ( '' === $parts['municipalityName'] ) {
			$parts['municipalityName'] = $parts['street'];
		}

		return $parts;
	}

	/**
	 * Ověří adresu v RÚIAN. Vrací čitelný řetězec s výsledkem, nebo prázdný
	 * řetězec, pokud ověření není nakonfigurované či selhalo. Nikdy neblokuje odeslání.
	 */
	private function validate_address_ruian( $address_text ) {
		$s = $this->get_settings();
		if ( ! $s['fnx_api_key'] || '' === trim( (string) $address_text ) ) {
			return '';
		}

		$parts = $this->parse_address( $address_text );
		if ( '' === $parts['municipalityName'] && '' === $parts['zip'] ) {
			return '';
		}

		$query = array( 'apiKey' => $s['fnx_api_key'] );
		foreach ( array( 'municipalityName', 'street', 'cp', 'co', 'zip' ) as $key ) {
			if ( '' !== $parts[ $key ] ) {
				$query[ $key ] = $parts[ $key ];
			}
		}

		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $query ), 'https://ruian.fnx.io/api/v1/ruian/validate' ),
			array( 'timeout' => 6, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['status'] ) ) {
			return '';
		}

		if ( 'NOT_FOUND' === $body['status'] || empty( $body['place'] ) ) {
			return 'nenalezena v RÚIAN – zkontrolujte adresu';
		}
		if ( ! in_array( $body['status'], array( 'MATCH', 'POSSIBLE' ), true ) ) {
			return '';
		}

		$place      = $body['place'];
		$number     = trim( ( isset( $place['cp'] ) ? $place['cp'] : '' ) . ( ! empty( $place['co'] ) ? '/' . $place['co'] : '' ) );
		$normalized = trim(
			( ! empty( $place['streetName'] ) ? $place['streetName'] . ' ' : '' ) . $number
			. ( ! empty( $place['municipalityPartName'] ) ? ', ' . $place['municipalityPartName'] : '' )
			. ( ! empty( $place['zip'] ) ? ', ' . $place['zip'] : '' )
			. ( ! empty( $place['municipalityName'] ) ? ' ' . $place['municipalityName'] : '' ),
			' ,'
		);
		$confidence = isset( $place['confidence'] ) ? round( 100 * (float) $place['confidence'] ) : null;

		return sprintf(
			'%s%s – %s',
			'MATCH' === $body['status'] ? 'ověřena' : 'pravděpodobná shoda',
			null !== $confidence ? ' (' . $confidence . ' %)' : '',
			sanitize_text_field( $normalized )
		);
	}

	/**
	 * Našeptávání adres. S vyplněným klíčem se používá Mapy.cz Suggest API
	 * (RÚIAN data, garantovaná kvalita), bez klíče veřejné Photon API nad
	 * OpenStreetMap (česká adresní data v OSM pocházejí z importu RÚIAN).
	 */
	private function suggest_address( $q ) {
		$s = $this->get_settings();
		return $s['mapy_api_key'] ? $this->suggest_address_mapy( $q, $s['mapy_api_key'] ) : $this->suggest_address_photon( $q );
	}

	/** Mapy.cz Suggest API – klíč zůstává na serveru. */
	private function suggest_address_mapy( $q, $api_key ) {
		$url = add_query_arg(
			array(
				'query'  => rawurlencode( $q ),
				'limit'  => 5,
				'lang'   => 'cs',
				'locale' => 'cs',
				'type'   => 'regional.address',
				'apikey' => rawurlencode( $api_key ),
			),
			'https://api.mapy.cz/v1/suggest'
		);

		$items    = array();
		$response = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['items'] ) && is_array( $body['items'] ) ) {
				foreach ( array_slice( $body['items'], 0, 5 ) as $item ) {
					$name     = isset( $item['name'] ) ? $item['name'] : '';
					$location = isset( $item['location'] ) ? $item['location'] : '';
					$value    = trim( $name . ( $location ? ', ' . $location : '' ) );
					if ( $value ) {
						$items[] = array( 'value' => sanitize_text_field( $value ) );
					}
				}
			}
		}
		return $items;
	}

	/** Photon (OSM) – bez klíče; výsledky omezené na území ČR. */
	private function suggest_address_photon( $q ) {
		$url = 'https://photon.komoot.io/api/?q=' . rawurlencode( $q ) . '&limit=6&bbox=12.09,48.55,18.87,51.06';

		$items    = array();
		$seen     = array();
		$response = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $items;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['features'] ) || ! is_array( $body['features'] ) ) {
			return $items;
		}

		foreach ( $body['features'] as $feature ) {
			$p = isset( $feature['properties'] ) ? $feature['properties'] : array();

			$street = isset( $p['street'] ) ? $p['street'] : '';
			$number = isset( $p['housenumber'] ) ? $p['housenumber'] : '';
			$zip    = isset( $p['postcode'] ) ? str_replace( ' ', '', $p['postcode'] ) : '';
			$city   = isset( $p['city'] ) ? $p['city'] : '';

			// Ulice bez čísla (typ street) nebo obec – použije se name.
			if ( '' === $street && ! empty( $p['name'] ) && in_array( isset( $p['type'] ) ? $p['type'] : '', array( 'street', 'city', 'district', 'locality' ), true ) ) {
				$street = $p['name'];
			}
			if ( '' === $street && '' === $city ) {
				continue;
			}

			$value = trim(
				trim( $street . ' ' . $number )
				. ( $zip || $city ? ', ' . trim( $zip . ' ' . $city ) : '' ),
				' ,'
			);
			$key = mb_strtolower( $value );
			if ( '' === $value || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$items[]      = array( 'value' => sanitize_text_field( $value ) );
			if ( count( $items ) >= 5 ) {
				break;
			}
		}
		return $items;
	}

	/* =========================================================
	 * Vlastní SMTP odesílání
	 * ======================================================= */

	/** Šifrování tajemství klíčem odvozeným ze saltů webu (AES-256-CBC). */
	private function encrypt_secret( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain );
		}
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return 'enc:' . base64_encode( $iv . $cipher );
	}

	private function decrypt_secret( $stored ) {
		if ( 0 === strpos( (string) $stored, 'b64:' ) ) {
			return base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 !== strpos( (string) $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ) );
		if ( strlen( $raw ) <= 16 ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
	}

	/* =========================================================
	 * Retence záznamů (GDPR)
	 * ======================================================= */

	public function schedule_retention() {
		if ( ! wp_next_scheduled( 'wkf_daily_retention' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wkf_daily_retention' );
		}
	}

	/** Smaže záznamy starší než doba uchování nastavená u formuláře, včetně souborů. */
	public function run_retention() {
		$forms = get_posts( array( 'post_type' => self::CPT_FORM, 'post_status' => 'any', 'posts_per_page' => -1 ) );
		foreach ( $forms as $form ) {
			$days = (int) get_post_meta( $form->ID, '_wkf_retention', true );
			if ( $days < 1 || ! $form->post_name ) {
				continue;
			}
			$old = get_posts(
				array(
					'post_type'      => self::CPT_ENTRY,
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'date_query'     => array( array( 'before' => $days . ' days ago' ) ),
					'meta_query'     => array( array( 'key' => '_wkf_form_type', 'value' => $form->post_name ) ),
				)
			);
			foreach ( $old as $entry_id ) {
				$files = get_post_meta( $entry_id, '_wkf_files', true );
				foreach ( is_array( $files ) ? $files : array() as $f ) {
					if ( ! empty( $f['file'] ) && file_exists( $f['file'] ) ) {
						wp_delete_file( $f['file'] );
					}
				}
				$single = get_post_meta( $entry_id, '_wkf_file_path', true );
				if ( $single && file_exists( $single ) ) {
					wp_delete_file( $single );
				}
				wp_delete_post( $entry_id, true );
			}
		}
	}

	/* =========================================================
	 * Lead API / webhook
	 * ======================================================= */

	/**
	 * Sestaví payload dle konvence Lead API (id, jmeno, email, telefon,
	 * firma, ico, zprava, formular, url, pole) a odešle na endpoint.
	 * Neúspěch se opakuje automaticky po 5 a 30 minutách; díky stabilnímu
	 * "id" nevznikají duplicity. Payload se ukládá k záznamu, aby šlo
	 * odeslání kdykoli zopakovat i ručně.
	 */
	private function send_webhook( $entry_id, $form_type, $schema, $labels, $values, $page_url, $referer = '', $query_string = '' ) {
		$s = $this->get_settings();
		if ( ! $s['webhook_url'] ) {
			return;
		}
		// Per formulář lze webhook vypnout (meta _wkf_webhook = '0').
		$post_id = isset( $schema['_custom'] ) ? (int) $schema['_custom'] : 0;
		if ( $post_id && '0' === get_post_meta( $post_id, '_wkf_webhook', true ) ) {
			return;
		}

		// Doplňková pole pro obchodníka (kontext, který nemá systémové pole).
		$pole = $labels;
		if ( $referer ) {
			$pole['Odkazující stránka'] = $referer;
		}
		if ( $query_string ) {
			$pole['Query parametry URL'] = $query_string;
		}
		if ( $this->current_uploads ) {
			$pole['Soubory'] = implode( "\n", array_map( function ( $f ) { return $f['url']; }, $this->current_uploads ) );
		}
		foreach ( $this->journey_lines( $this->current_journey ) as $jlabel => $jvalue ) {
			$pole[ $jlabel ] = $jvalue;
		}

		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$payload = array(
			'id'       => $host . '-wkf-' . $entry_id, // stabilní: opakování nezaloží duplicitu
			'jmeno'    => isset( $values['jmeno'] ) ? $values['jmeno'] : '',
			'email'    => isset( $values['email'] ) ? $values['email'] : '',
			'telefon'  => isset( $values['telefon'] ) ? $values['telefon'] : '',
			'firma'    => isset( $values['firma'] ) ? $values['firma'] : '',
			'ico'      => isset( $values['ico'] ) ? $values['ico'] : '',
			'zprava'   => isset( $values['zprava'] ) ? $values['zprava'] : '',
			// 'zdroj' se záměrně neposílá – určuje ho klíč přiřazený webu.
			'formular' => isset( $schema['name'] ) ? $schema['name'] : $form_type,
			'url'      => $page_url,
			'pole'     => $pole,
			'test'     => false,
		);

		update_post_meta( $entry_id, '_wkf_webhook_payload', wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
		update_post_meta( $entry_id, '_wkf_webhook_attempts', 0 );
		$this->webhook_dispatch( $entry_id );
	}

	/** Odešle uložený payload záznamu; při neúspěchu naplánuje opakování (5 a 30 min). */
	public function webhook_dispatch( $entry_id ) {
		$entry_id = (int) $entry_id;
		$s        = $this->get_settings();
		$payload  = (string) get_post_meta( $entry_id, '_wkf_webhook_payload', true );
		if ( ! $s['webhook_url'] || '' === $payload ) {
			return;
		}

		$attempt = (int) get_post_meta( $entry_id, '_wkf_webhook_attempts', true ) + 1;
		update_post_meta( $entry_id, '_wkf_webhook_attempts', $attempt );

		$headers = array( 'Content-Type' => 'application/json' );
		$key     = $this->decrypt_secret( $s['webhook_key'] );
		if ( $key ) {
			$headers[ $s['webhook_header'] ] = $key;
		}

		$response = wp_remote_post(
			$s['webhook_url'],
			array(
				'timeout' => 8,
				'headers' => $headers,
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result  = 'chyba: ' . $response->get_error_message();
			$success = false;
		} else {
			$code    = (int) wp_remote_retrieve_response_code( $response );
			$success = 200 === $code;
			$result  = $success
				? 'předáno (200)' . ( false !== strpos( (string) wp_remote_retrieve_body( $response ), '"duplicate"' ) ? ' – duplicitní, v CRM už existuje' : '' )
				: 'odmítnuto (HTTP ' . $code . '): ' . mb_strimwidth( (string) wp_remote_retrieve_body( $response ), 0, 200 );
		}

		update_post_meta( $entry_id, '_wkf_webhook_result', $result . ' [pokus ' . $attempt . ', ' . wp_date( 'j. n. H:i' ) . ']' );

		// Automatické opakování: po 5 minutách a po dalších 25 (celkem ~30 min).
		if ( ! $success && $attempt < 3 ) {
			wp_schedule_single_event( time() + ( 1 === $attempt ? 5 * MINUTE_IN_SECONDS : 25 * MINUTE_IN_SECONDS ), 'wkf_webhook_retry', array( $entry_id ) );
		}
	}

	/** Ruční opakované odeslání záznamu do Lead API z administrace. */
	public function webhook_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		$entry_id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		check_admin_referer( 'wkf_webhook_resend_' . $entry_id );

		$this->webhook_dispatch( $entry_id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . self::CPT_ENTRY ) );
		exit;
	}

	/** Uloží důvod selhání e-mailu (WordPress ho jinak zahodí). */
	/** Zaznamená skutečnou konfiguraci PHPMaileru těsně před odesláním. */
	public function capture_mail_debug( $phpmailer ) {
		if ( ! $this->sending_form_mail ) {
			return;
		}
		if ( 'smtp' === strtolower( (string) $phpmailer->Mailer ) ) {
			$transport = 'SMTP ' . $phpmailer->Host . ':' . $phpmailer->Port
				. ( $phpmailer->SMTPSecure ? ' ' . strtoupper( (string) $phpmailer->SMTPSecure ) : ' bez šifrování' )
				. ( $phpmailer->SMTPAuth ? ', přihlášen jako ' . $phpmailer->Username : ', bez přihlášení' );
		} else {
			$transport = 'funkce mail() serveru – SMTP se vůbec nepoužilo';
		}
		$this->last_mail_debug = 'odesílatel ' . $phpmailer->From . ' (' . $transport . ')';
	}

	public function capture_mail_error( $wp_error ) {
		if ( $this->sending_form_mail && is_wp_error( $wp_error ) ) {
			$this->last_mail_error = $wp_error->get_error_message();
		}
	}

	/** Nastaví PHPMailer podle SMTP konfigurace pluginu. */
	public function setup_smtp( $phpmailer ) {
		$s = $this->get_settings();
		if ( ! $s['smtp_mode'] || ! $s['smtp_host'] ) {
			return;
		}
		// Když běží samostatný Webklient SMTP Logger Pro, odesílání řídí on.
		if ( class_exists( 'Webklient_SMTP_Logger_Pro' ) ) {
			return;
		}
		// Režim „jen formuláře": mimo odesílání pluginu se nezasahuje.
		if ( 'forms' === $s['smtp_mode'] && ! $this->sending_form_mail ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host     = $s['smtp_host'];
		$phpmailer->Port     = $s['smtp_port'] ? (int) $s['smtp_port'] : 587;
		$phpmailer->SMTPAuth = '' !== $s['smtp_user'];
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $s['smtp_user'];
			$phpmailer->Password = $this->decrypt_secret( $s['smtp_pass'] );
		}
		if ( $s['smtp_secure'] ) {
			$phpmailer->SMTPSecure = $s['smtp_secure'];
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}
		// Odesílatel ze sekce „Odesílatel e-mailů“ – bez něj by zůstala výchozní adresa
		// WordPressu (wordpress@doména), kterou většina SMTP serverů odmítne.
		if ( ! empty( $s['from_email'] ) && is_email( $s['from_email'] ) ) {
			$phpmailer->setFrom( $s['from_email'], $s['from_name'] ? $s['from_name'] : 'WordPress', false );
			$phpmailer->Sender = $s['from_email'];
		}
	}

	/** Testovací e-mail přes nastavené SMTP. */
	public function smtp_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( 'wkf_smtp_test' );

		$user = wp_get_current_user();
		$to   = isset( $_GET['wkf_test_to'] ) ? sanitize_email( wp_unslash( $_GET['wkf_test_to'] ) ) : '';
		if ( ! $to || ! is_email( $to ) ) {
			$to = $user->user_email;
		}
		$s       = $this->get_settings();
		$headers = array();
		if ( ! empty( $s['from_email'] ) && is_email( $s['from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $s['from_name'], $s['from_email'] );
		}
		$body = "Tento e-mail ověřuje nastavení SMTP odesílání v pluginu Webklient Forms.\n\n"
			. 'Server: ' . $s['smtp_host'] . ':' . $s['smtp_port'] . ' (' . ( $s['smtp_secure'] ? strtoupper( $s['smtp_secure'] ) : 'bez šifrování' ) . ")\n"
			. 'Přihlášení: ' . ( $s['smtp_user'] ? $s['smtp_user'] : 'bez autentizace' ) . "\n"
			. 'Odesílatel: ' . ( ! empty( $s['from_email'] ) ? $s['from_email'] : 'výchozí WordPressu' ) . "\n"
			. 'Odesláno: ' . wp_date( 'j. n. Y H:i:s' );

		$this->last_mail_error   = '';
		$this->last_mail_debug   = '';
		$this->sending_form_mail = true;
		$sent = wp_mail(
			$to,
			'Webklient Forms – test SMTP (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ')',
			$body,
			$headers
		);
		$this->sending_form_mail = false;

		$redirect = add_query_arg( 'wkf_smtp_test', $sent ? 'ok' : 'fail', admin_url( 'edit.php?post_type=' . self::CPT_ENTRY . '&page=wkf-settings' ) );
		$redirect = add_query_arg( 'wkf_smtp_to', rawurlencode( $to ), $redirect );
		if ( $this->last_mail_debug ) {
			$redirect = add_query_arg( 'wkf_smtp_used', rawurlencode( mb_strimwidth( $this->last_mail_debug, 0, 200 ) ), $redirect );
		}
		if ( ! $sent && $this->last_mail_error ) {
			$redirect = add_query_arg( 'wkf_smtp_reason', rawurlencode( mb_strimwidth( $this->last_mail_error, 0, 300 ) ), $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	private function verify_turnstile( $token, $secret ) {
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $this->client_ip(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['success'] );
	}

	private function handle_upload( $file, $allowed_ext = null, $max_bytes = null ) {
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'wkf_upload', 'Soubor se nepodařilo nahrát. Zkuste to prosím znovu.' );
		}
		// Explicitní zákaz dotfiles (.htaccess, .user.ini apod.).
		if ( 0 === strpos( ltrim( basename( (string) $file['name'] ) ), '.' ) ) {
			return new WP_Error( 'wkf_upload', 'Nepodporovaný typ souboru.' );
		}

		// Per-pole limity z builderu; bez nich platí výchozí konstanty.
		$mimes = self::ALLOWED_MIMES;
		if ( is_array( $allowed_ext ) && $allowed_ext ) {
			if ( in_array( 'jpg', $allowed_ext, true ) && ! in_array( 'jpeg', $allowed_ext, true ) ) {
				$allowed_ext[] = 'jpeg';
			}
			$mimes = array_intersect_key( self::ALLOWED_MIMES, array_flip( $allowed_ext ) );
			if ( ! $mimes ) {
				$mimes = self::ALLOWED_MIMES;
			}
		}
		$max_bytes = $max_bytes ? (int) $max_bytes : self::MAX_FILE_SIZE;

		if ( $file['size'] > $max_bytes ) {
			return new WP_Error( 'wkf_upload', sprintf( 'Soubor je příliš velký. Maximální velikost je %d MB.', (int) ceil( $max_bytes / MB_IN_BYTES ) ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! array_key_exists( $check['ext'], $mimes ) ) {
			return new WP_Error( 'wkf_upload', 'Nepodporovaný typ souboru. Povoleno: .' . implode( ', .', array_keys( $mimes ) ) . '.' );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$this->protect_upload_dir();

		add_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );
		$result = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
				'unique_filename_callback' => function ( $dir, $name, $ext ) {
					return sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) ) . '-' . wp_generate_password( 8, false ) . $ext;
				},
			)
		);
		remove_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'wkf_upload', 'Soubor se nepodařilo uložit: ' . $result['error'] );
		}
		return $result;
	}

	public function custom_upload_dir( $dirs ) {
		$dirs['subdir'] = '/webklient-forms' . $dirs['subdir'];
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	/**
	 * Obrana do hloubky pro adresář s nahranými soubory:
	 * zákaz spouštění skriptů a listování adresáře (Apache/LiteSpeed),
	 * prázdný index.html jako pojistka pro servery ignorující .htaccess.
	 */
	private function protect_upload_dir() {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/webklient-forms';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "Options -Indexes\n"
				. "<FilesMatch \"\\.(?i:php|phtml|pht|php[0-9]|pl|py|cgi|sh|asp|aspx)$\">\n"
				. "\t<IfModule mod_authz_core.c>\n"
				. "\t\tRequire all denied\n"
				. "\t</IfModule>\n"
				. "\t<IfModule !mod_authz_core.c>\n"
				. "\t\tOrder deny,allow\n"
				. "\t\tDeny from all\n"
				. "\t</IfModule>\n"
				. "</FilesMatch>\n";
			@file_put_contents( $htaccess, $rules );
		}

		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
	}

	private function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/** Jednoduchý rate limit: max. $limit požadavků za 60 s na IP a akci. */
	private function rate_limit_ok( $action, $limit ) {
		$ip = $this->client_ip();
		if ( '' === $ip ) {
			return true;
		}
		$key   = 'wkf_rl_' . $action . '_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/* =========================================================
	 * E-mailové notifikace (interní, plain text)
	 * ======================================================= */

	private function send_notification( $form_type, $schema, $labels, $values, $page_title, $page_url, $query_string, $referer, $file_info, $address_check = '' ) {
		$s      = $this->get_settings();
		$config = $this->form_config( $form_type );
		$to = $this->email_list_to_array( $config['recipient'] );
		if ( ! $to ) {
			return false;
		}

		$separator = '--------------------------------';
		$subject   = $config['subject']
			? $this->fill_placeholders( $config['subject'], $values, $schema, $page_title, $page_url )
			: $schema['name'] . ' - formulář | ' . $page_title;

		$lines = array();
		foreach ( $labels as $label => $value ) {
			// Nevyplněná pole se zobrazí s pomlčkou, aby příjemce viděl, že pole existuje.
			if ( '' === $value ) {
				$lines[] = $label . ': —';
				continue;
			}
			// Víceřádkové hodnoty oddělíme vizuálně.
			if ( false !== strpos( $value, "\n" ) ) {
				$lines[] = $separator;
				$lines[] = $label . ':';
				$lines[] = '';
				$lines[] = $value;
				$lines[] = $separator;
			} else {
				$lines[] = $label . ': ' . $value;
			}
		}

		$lines[] = '';
		if ( $address_check ) {
			$lines[] = 'Ověření adresy (RÚIAN): ' . $address_check;
		}
		$lines[] = sprintf( 'Odesláno z: %s (%s) dne %s', $page_title, $page_url, wp_date( 'j. n. Y H:i' ) );
		if ( $query_string ) {
			$lines[] = 'Query parametry URL: ' . $query_string;
		}
		$lines[] = 'Odkazující stránka: ' . ( $referer ? $referer : '—' );

		$from_name  = $config['from_name'] ? $config['from_name'] : $s['from_name'];
		$from_email = $config['from_email'] && is_email( $config['from_email'] ) ? $config['from_email'] : $s['from_email'];
		$headers    = array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);
		$reply_to = $config['replyto'] && ! empty( $values[ $config['replyto'] ] ) ? $values[ $config['replyto'] ] : ( ! empty( $values['email'] ) ? $values['email'] : '' );
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		// Kontext návštěvy (vstupní stránka, zdroj, cesta po webu).
		$journey_lines = $this->journey_lines( $this->current_journey );
		if ( $journey_lines ) {
			$lines[] = $separator;
			$lines[] = 'Kontext návštěvy:';
			foreach ( $journey_lines as $jlabel => $jvalue ) {
				$lines[] = $jlabel . ': ' . $jvalue;
			}
		}

		// Přílohy: všechny nahrané soubory; nad limitem velikosti jen odkazy.
		$attachments = array();
		$total_size  = 0;
		foreach ( $this->current_uploads as $up ) {
			if ( ! empty( $up['file'] ) && file_exists( $up['file'] ) ) {
				$attachments[] = $up['file'];
				$total_size   += (int) filesize( $up['file'] );
			}
		}
		$limit_bytes = max( 1, (int) $s['attach_limit_mb'] ) * MB_IN_BYTES;
		if ( $attachments && $total_size > $limit_bytes ) {
			$attachments = array();
			$lines[]     = $separator;
			$lines[]     = 'Přílohy překročily limit ' . (int) $s['attach_limit_mb'] . ' MB, soubory ke stažení:';
			foreach ( $this->current_uploads as $up ) {
				$lines[] = $up['name'] . ': ' . $up['url'];
			}
		}

		$this->last_mail_error   = '';
		$this->sending_form_mail = true;
		$sent = wp_mail( $to, $subject, implode( "\n", $lines ), $headers, $attachments );
		$this->sending_form_mail = false;

		if ( ! $sent ) {
			$reason = $this->last_mail_error ? $this->last_mail_error : 'wp_mail vrátil chybu bez podrobností (zkontrolujte SMTP nastavení nebo mail server).';
			$this->last_mail_error = 'Příjemce: ' . implode( ', ', $to ) . ' – ' . $reason;
		}
		return $sent;
	}

	/* =========================================================
	 * Automatická odpověď klientovi (HTML)
	 * ======================================================= */

	private function send_autoreply( $form_type, $values ) {
		$s      = $this->get_settings();
		$config = $this->form_config( $form_type );

		if ( empty( $config['ar_enabled'] ) ) {
			return false;
		}
		if ( empty( $values['email'] ) || ! is_email( $values['email'] ) ) {
			return false;
		}

		$subject = $config['ar_subject'];
		$body    = $config['ar_body'];

		// Zástupné značky {klic} pro všechna pole formuláře; data escapovaná pro HTML.
		$replacements = array();
		foreach ( $values as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = esc_html( str_replace( "\n", ', ', (string) $value ) );
		}
		if ( isset( $replacements['{sluzba}'] ) && '' === $replacements['{sluzba}'] ) {
			$replacements['{sluzba}'] = 'váš dotaz';
		}

		$subject = strtr( $subject, array_map( 'wp_specialchars_decode', $replacements ) );
		$body    = strtr( $body, $replacements );

		// Obsah z WYSIWYG editoru: doplnění odstavců a inline stylů pro poštovní klienty.
		$content = wpautop( $body );
		$content = preg_replace( '/<p(?![^>]*style)/i', '<p style="margin:0 0 16px;"', $content );
		$content = preg_replace( '/<a(?![^>]*style)/i', '<a style="color:' . esc_attr( $s['btn_bg'] ) . ';"', $content );
		$content = preg_replace( '/<(ul|ol)(?![^>]*style)/i', '<$1 style="margin:0 0 16px;padding-left:24px;"', $content );

		$html = $this->autoreply_html_template( $content );

		// Plain-text alternativa pro klienty bez HTML a lepší doručitelnost.
		$this->alt_body = trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $content ) ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $s['from_name'], $s['from_email'] ),
		);
		// Odpověď klienta míří na příjemce daného formuláře (první adresu).
		$reply_to = $config['recipient'];
		if ( $reply_to ) {
			$first = trim( strtok( $reply_to, ',' ) );
			if ( $first && is_email( $first ) ) {
				$headers[] = 'Reply-To: ' . $first;
			}
		}

		add_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );
		$this->sending_form_mail = true;
		$sent = wp_mail( $values['email'], $subject, $html, $headers );
		$this->sending_form_mail = false;
		remove_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );
		$this->alt_body = '';

		return $sent;
	}

	/** Plain-text alternativa HTML e-mailu (multipart/alternative). */
	public function set_alt_body( $phpmailer ) {
		if ( $this->alt_body ) {
			$phpmailer->AltBody = $this->alt_body;
		}
	}

	/** E-mailově bezpečná HTML šablona – tabulkový layout, inline styly, max. šířka 600 px. */
	private function autoreply_html_template( $content ) {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		return '<!DOCTYPE html>'
			. '<html lang="cs"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '<title>' . $site_name . '</title></head>'
			. '<body style="margin:0;padding:0;background-color:#f4f4f4;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">'
			. '<tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:8px;">'
			. '<tr><td style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#333333;">'
			. $content
			. '</td></tr>'
			. '<tr><td style="padding:16px 32px 24px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;color:#888888;border-top:1px solid #eeeeee;">'
			. 'Tato zpráva byla odeslána automaticky z webu ' . $site_name . '.'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}

Webklient_Forms::instance();
