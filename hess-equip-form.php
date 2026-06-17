<?php
/**
 * Plugin Name:       Hess Air Equipment Form
 * Description:       Multi-step HVAC equipment quote form (no value package). Pulls product data from a Google Sheet (published CSV) and emails quotes via Mailgun.
 * Version:           3.5.47
 * Author:            Hess Air
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       hess-equip-form
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'HESSQFE_VERSION',    '3.5.47' );
define( 'HESSQFE_SLUG',       'hess-equip-form' );
define( 'HESSQFE_DIR',        plugin_dir_path( __FILE__ ) );
define( 'HESSQFE_URL',        plugin_dir_url( __FILE__ ) );
define( 'HESSQFE_CACHE_KEY',  'hessqf_systems_cache_v8' );
define( 'HESSQFE_LASTGOOD_OPTION', 'hessqf_systems_last_good' );

/* ─────────────────────────────────────────────
   BOOTSTRAP — register hooks
────────────────────────────────────────────── */
add_action( 'init',               'hessqfe_register_quote_cpt' );
add_action( 'admin_menu',         'hessqfe_admin_menu' );
add_action( 'admin_init',         'hessqfe_register_settings' );
add_action( 'admin_post_hessqfe_flush_cache', 'hessqfe_flush_cache_action' );
add_action( 'wp_enqueue_scripts', 'hessqfe_enqueue_assets' );
add_action( 'wp_ajax_hessqfe_submit',        'hessqfe_handle_submission' );
add_action( 'wp_ajax_nopriv_hessqfe_submit', 'hessqfe_handle_submission' );
add_shortcode( 'hess_equip_form', 'hessqfe_shortcode' );

// Quote CPT admin UI hooks
add_filter( 'manage_hessqfe_quote_posts_columns',       'hessqfe_quote_list_columns' );
add_action( 'manage_hessqfe_quote_posts_custom_column', 'hessqfe_quote_list_column_content', 10, 2 );
add_filter( 'manage_edit-hessqfe_quote_sortable_columns', 'hessqfe_quote_sortable_columns' );
add_action( 'add_meta_boxes',                          'hessqfe_quote_add_meta_boxes' );
add_action( 'save_post_hessqfe_quote',                  'hessqfe_quote_save_meta', 10, 2 );
add_filter( 'post_row_actions',                        'hessqfe_quote_row_actions', 10, 2 );
add_action( 'restrict_manage_posts',                   'hessqfe_quote_status_filter' );
add_filter( 'parse_query',                             'hessqfe_quote_filter_query' );

/* ─────────────────────────────────────────────
   DEFAULTS — column visibility + settings
────────────────────────────────────────────── */
function hessqfe_default_table_columns() {
	return [
		'brand'     => [ 'label' => 'Brand',              'visible' => 1 ],
		'system'    => [ 'label' => 'System Type',        'visible' => 1 ],
		'capacity'  => [ 'label' => 'Capacity',           'visible' => 1 ],
		'tier'      => [ 'label' => 'Stars',              'visible' => 1 ],
		'model_id'  => [ 'label' => 'Model ID',           'visible' => 1 ],
		'seer2'     => [ 'label' => 'SEER2',              'visible' => 1 ],
		'year'      => [ 'label' => 'Year',               'visible' => 0 ],
		'stage'     => [ 'label' => 'Cap. Stg.',          'visible' => 1 ],
		'price'     => [ 'label' => 'System Price',       'visible' => 1 ],
		'monthly'   => [ 'label' => 'Monthly Investment', 'visible' => 1 ],
		'daily'     => [ 'label' => 'Daily Investment',   'visible' => 1 ],
	];
}

function hessqfe_default_card_fields() {
	return [
		'model_id'      => [ 'label' => 'Model ID',        'visible' => 1 ],
		'outdoor_model' => [ 'label' => 'Outdoor Unit',    'visible' => 1 ],
		'outdoor_price' => [ 'label' => 'Outdoor Price',   'visible' => 0 ],
		'indoor_model'  => [ 'label' => 'Indoor Unit',     'visible' => 1 ],
		'indoor_price'  => [ 'label' => 'Indoor Price',    'visible' => 0 ],
		'seer2'         => [ 'label' => 'SEER2',           'visible' => 1 ],
		'capacity'      => [ 'label' => 'Capacity',        'visible' => 1 ],
		'stage'         => [ 'label' => 'Cap. Stg.',       'visible' => 1 ],
		'monthly'       => [ 'label' => 'Monthly',         'visible' => 1 ],
		'daily'         => [ 'label' => 'Daily',           'visible' => 1 ],
	];
}

/* ─────────────────────────────────────────────
   DATA LOADER — fetch + parse + cache CSV
────────────────────────────────────────────── */
function hessqfe_get_systems( $bypass_cache = false ) {
	if ( ! $bypass_cache ) {
		$cached = get_transient( HESSQFE_CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
	}

	$url = trim( get_option( 'hessqf_sheet_url', '' ) );
	if ( ! $url ) {
		return [];
	}

	$response = wp_remote_get( $url, [
		'timeout'   => 15,
		'sslverify' => true,
	] );

	$systems = [];
	if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
		$csv = wp_remote_retrieve_body( $response );
		if ( $csv ) {
			$systems = hessqfe_parse_csv( $csv );
		}
	}

	if ( ! empty( $systems ) ) {
		$ttl_min = max( 1, (int) get_option( 'hessqf_cache_ttl', 5 ) );
		set_transient( HESSQFE_CACHE_KEY, $systems, $ttl_min * MINUTE_IN_SECONDS );
		update_option( HESSQFE_LASTGOOD_OPTION, $systems, false );
		return $systems;
	}

	// Fetch failed or returned no rows (e.g. transient network blip right
	// after a deploy) — fall back to the last successfully loaded data
	// instead of showing "no product data" to site visitors.
	$last_good = get_option( HESSQFE_LASTGOOD_OPTION, [] );
	return is_array( $last_good ) ? $last_good : [];
}

function hessqfe_parse_csv( $csv ) {
	$lines = preg_split( "/\r\n|\n|\r/", trim( $csv ) );
	if ( count( $lines ) < 2 ) { return []; }

	$header = str_getcsv( array_shift( $lines ) );
	$header = array_map( 'trim', $header );

	// Expected column indices (by header name, case-insensitive)
	$idx = [];
	foreach ( $header as $i => $h ) {
		$idx[ strtolower( $h ) ] = $i;
	}

	$get = function( $row, $key ) use ( $idx ) {
		$k = strtolower( $key );
		if ( ! isset( $idx[ $k ] ) ) { return ''; }
		return isset( $row[ $idx[ $k ] ] ) ? $row[ $idx[ $k ] ] : '';
	};

	$out = [];
	foreach ( $lines as $line ) {
		if ( trim( $line ) === '' ) { continue; }
		$row = str_getcsv( $line );

		// Parse capacity — skip BTU-only rows (don't fit tier model)
		$cap_raw = $get( $row, 'Cap.' );
		$capacity = null;
		if ( preg_match( '/^([\d.]+)\s*ton/i', $cap_raw, $m ) ) {
			$capacity = floatval( $m[1] );
		} else {
			continue; // BTU rows filtered out
		}

		$tier = hessqfe_parse_stars( $get( $row, 'STARS' ) );
		if ( $tier === null ) { continue; } // 0 (Basic) is valid

		$price = hessqfe_parse_currency( $get( $row, 'System Price' ) );
		if ( ! $price || $price < 1000 ) { continue; } // filter stub rows

		$seer2 = hessqfe_parse_numeric( $get( $row, 'SEER2' ) );
		if ( ! $seer2 ) { continue; }

		$system_raw = $get( $row, 'System' );
		$ahri = trim( $get( $row, 'AHRI#' ) );

		$out[] = [
			'ahri'          => $ahri,
			'year'          => (int) $get( $row, 'Year' ),
			'brand'         => trim( $get( $row, 'Brand' ) ),
			'system'        => hessqfe_normalize_system( $system_raw ),
			'system_raw'    => $system_raw,
			'capacity'      => $capacity,
			'tier'          => $tier,
			'tier_label'    => hessqfe_tier_label( $tier ),
			'tier_stars'    => str_repeat( '★', $tier ),
			'model_id'      => trim( $get( $row, 'ID' ) ),
			'seer2'         => $seer2,
			'stage'         => trim( $get( $row, 'Cap. Stg.' ) ),
			'stage_label'   => hessqfe_stage_label( $get( $row, 'Cap. Stg.' ) ),
			'outdoor_model' => trim( $get( $row, 'Outdoor Unit Model' ) ),
			'outdoor_price' => hessqfe_parse_currency( $get( $row, 'Outdoor Unit $' ) ),
			'indoor_model'  => trim( $get( $row, 'Indoor Unit Model' ) ),
			'indoor_price'  => hessqfe_parse_currency( $get( $row, 'Indoor Unit $' ) ),
			'price'         => $price,
			'monthly'       => hessqfe_parse_currency( $get( $row, '*Est Mon Invest.' ) ),
			'daily'         => hessqfe_parse_currency( $get( $row, '*Daily Invest.' ) ),
		];
	}
	return $out;
}

function hessqfe_parse_currency( $str ) {
	$str = trim( (string) $str );
	if ( $str === '' ) { return null; }
	$clean = preg_replace( '/[^\d.\-]/', '', $str );
	if ( $clean === '' || ! is_numeric( $clean ) ) { return null; }
	return floatval( $clean );
}

function hessqfe_parse_numeric( $str ) {
	$str = trim( (string) $str );
	if ( $str === '' ) { return null; }
	$clean = preg_replace( '/[^\d.\-]/', '', $str );
	if ( $clean === '' || ! is_numeric( $clean ) ) { return null; }
	return floatval( $clean );
}

function hessqfe_parse_stars( $str ) {
	$s = trim( (string) $str );
	if ( $s === '' ) { return null; }
	// "Basic" rows from the PDFs — treated as tier 0 (no stars, just a label)
	if ( strcasecmp( $s, 'Basic' ) === 0 ) { return 0; }
	// Star rows: count consecutive '*' chars — 1 to 6 stars supported
	if ( preg_match( '/^\*+$/', $s ) ) {
		$n = strlen( $s );
		return ( $n >= 1 && $n <= 9 ) ? $n : null;
	}
	return null;
}

function hessqfe_tier_label( $tier ) {
	$labels = [
		0 => 'Basic',
		1 => 'Standard',
		2 => 'Better',
		3 => 'Smart Choice',
		4 => 'Best',
		5 => 'Premium',
		6 => 'Elite',
	];
	return $labels[ $tier ] ?? '';
}

function hessqfe_normalize_system( $str ) {
	$s = trim( (string) $str );
	if ( stripos( $s, 'Heat Pump' ) !== false ) { return 'Heat Pump'; }
	if ( stripos( $s, 'Gas' )       !== false ) { return 'Gas'; }
	if ( stripos( $s, 'Electric' )  !== false ) { return 'Electric'; }
	return $s;
}

function hessqfe_stage_label( $stage_raw ) {
	$s = strtolower( trim( (string) $stage_raw ) );
	if ( $s === '' )                                              { return ''; }
	if ( $s === '1' )                                             { return 'Single Stg.'; }
	if ( $s === '2' || $s === '2 stg' || $s === '2stg' )          { return 'Two Stg.'; }
	if ( $s === 'ms' )                                            { return 'Multi Stg.'; }
	if ( $s === 'v-c' || $s === 'v-s' || $s === 'vs' || $s === 'v' ) { return 'Variable Stg.'; }
	if ( strpos( $s, 'inv' ) !== false )                          { return 'Inverter'; }
	return ucwords( $s );
}

/* ─────────────────────────────────────────────
   ASSETS — enqueue CSS/JS only on pages using the shortcode
────────────────────────────────────────────── */
function hessqfe_enqueue_assets() {
	if ( is_admin() ) { return; }

	wp_enqueue_style(
		'hessqfe-style',
		HESSQFE_URL . 'assets/style.css',
		[],
		HESSQFE_VERSION
	);
	wp_enqueue_script(
		'hessqfe-script',
		HESSQFE_URL . 'assets/script.js',
		[],
		HESSQFE_VERSION,
		true
	);
	wp_localize_script( 'hessqfe-script', 'hessqfeData', [
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'hessqfe_submit' ),
		'assetsUrl' => HESSQFE_URL . 'assets/',
	] );
}

/* ─────────────────────────────────────────────
   SHORTCODE — renders the form
────────────────────────────────────────────── */
function hessqfe_shortcode( $atts = [] ) {
	$systems    = hessqfe_get_systems();

	// One-time migration (v3.1.2): turn on the Cap. Stg. column for installs
	// that were running before it became visible by default.
	if ( ! get_option( 'hessqfe_capstg_migrated' ) ) {
		$saved = get_option( 'hessqf_table_columns', null );
		if ( is_array( $saved ) && isset( $saved['stage'] ) ) {
			$saved['stage']['visible'] = 1;
			$saved['stage']['label']   = 'Cap. Stg.';
			update_option( 'hessqfe_table_columns', $saved );
		}
		update_option( 'hessqfe_capstg_migrated', 1 );
	}

	$table_cols = get_option( 'hessqf_table_columns', hessqfe_default_table_columns() );
	$card_flds  = get_option( 'hessqf_card_fields',   hessqfe_default_card_fields() );
	$tax_default= get_option( 'hessqf_tax_default', '' );
	$year_mode  = get_option( 'hessqf_year_mode', 'all' ); // 'all' | 'latest'

	// Year filtering: 'all' = no filter, 'latest' = newest year, numeric = specific year
	if ( $systems ) {
		if ( $year_mode === 'latest' ) {
			$max_year = max( array_column( $systems, 'year' ) );
			$systems  = array_values( array_filter( $systems, fn( $s ) => $s['year'] === $max_year ) );
		} elseif ( is_numeric( $year_mode ) ) {
			$target  = (int) $year_mode;
			$systems = array_values( array_filter( $systems, fn( $s ) => (int) $s['year'] === $target ) );
		}
	}

	$config = [
		'systems'     => $systems,
		'tableCols'   => $table_cols,
		'cardFields'  => $card_flds,
		'taxDefault'  => $tax_default,
		'yearMode'    => $year_mode,
	];

	ob_start();
	include HESSQFE_DIR . 'templates/form.php';
	return ob_get_clean();
}

/* ─────────────────────────────────────────────
   ADMIN — settings page
────────────────────────────────────────────── */
function hessqfe_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=hessqfe_quote',
		'Hess Quote Form Settings',
		'Settings',
		'manage_options',
		HESSQFE_SLUG,
		'hessqfe_render_settings_page'
	);
}

function hessqfe_register_settings() {
	register_setting( 'hessqfe_settings', 'hessqfe_sheet_url' );
	register_setting( 'hessqfe_settings', 'hessqfe_cache_ttl', [ 'type' => 'integer', 'default' => 5 ] );
	register_setting( 'hessqfe_settings', 'hessqfe_year_mode' );
	register_setting( 'hessqfe_settings', 'hessqfe_tax_default' );
	register_setting( 'hessqfe_settings', 'hessqfe_notify_email' );
	register_setting( 'hessqfe_settings', 'hessqfe_notify_cc' );
	register_setting( 'hessqfe_settings', 'hessqfe_notify_bcc' );
	register_setting( 'hessqfe_settings', 'hessqfe_mailgun_api_key' );
	register_setting( 'hessqfe_settings', 'hessqfe_mailgun_domain' );
	register_setting( 'hessqfe_settings', 'hessqfe_table_columns' );
	register_setting( 'hessqfe_settings', 'hessqfe_card_fields' );
}

function hessqfe_flush_cache_action() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Denied' ); }
	check_admin_referer( 'hessqfe_flush_cache' );
	delete_transient( HESSQFE_CACHE_KEY );
	wp_safe_redirect( add_query_arg( 'cache_flushed', '1', admin_url( 'edit.php?post_type=hessqfe_quote&page=' . HESSQFE_SLUG ) ) );
	exit;
}

function hessqfe_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$sheet_url  = get_option( 'hessqf_sheet_url', '' );
	$cache_ttl  = get_option( 'hessqf_cache_ttl', 5 );
	$year_mode  = get_option( 'hessqf_year_mode', 'all' );
	$tax_def    = get_option( 'hessqf_tax_default', '' );
	$notify     = get_option( 'hessqfe_notify_email', get_option( 'admin_email' ) );
	$notify_cc  = get_option( 'hessqfe_notify_cc',  '' );
	$notify_bcc = get_option( 'hessqfe_notify_bcc', '' );
	$mg_key     = get_option( 'hessqfe_mailgun_api_key', '' );
	$mg_domain  = get_option( 'hessqfe_mailgun_domain', '' );
	$table_cols = get_option( 'hessqf_table_columns', hessqfe_default_table_columns() );
	$card_flds  = get_option( 'hessqf_card_fields',   hessqfe_default_card_fields() );

	// Quick data-source sanity check
	$systems = hessqfe_get_systems();
	$row_count = count( $systems );
	?>
	<div class="wrap">
		<h1>Hess Quote Form Settings</h1>

		<?php if ( isset( $_GET['cache_flushed'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Cache flushed. Next page load will re-fetch from Google Sheets.</p></div>
		<?php endif; ?>

		<div class="notice notice-info"><p>
			<strong>Data source status:</strong>
			<?php if ( ! $sheet_url ) : ?>
				<span style="color:#d63638">No Google Sheet URL configured.</span>
			<?php elseif ( $row_count === 0 ) : ?>
				<span style="color:#d63638">URL configured but 0 rows loaded. Check the CSV URL.</span>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hessqfe_flush_cache' ), 'hessqfe_flush_cache' ) ); ?>" class="button button-small" style="margin-left:10px;">Flush Cache</a>
			<?php else : ?>
				<span style="color:#2a7a3b"><?php echo esc_html( $row_count ); ?> systems loaded.</span>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hessqfe_flush_cache' ), 'hessqfe_flush_cache' ) ); ?>" class="button button-small" style="margin-left:10px;">Flush Cache</a>
			<?php endif; ?>
		</p></div>

		<form method="post" action="options.php">
			<?php settings_fields( 'hessqfe_settings' ); ?>

			<h2 class="title">Google Sheets Data Source</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hessqfe_sheet_url">Published CSV URL</label></th>
					<td>
						<input type="url" id="hessqfe_sheet_url" name="hessqfe_sheet_url"
							value="<?php echo esc_attr( $sheet_url ); ?>" class="large-text" placeholder="https://docs.google.com/spreadsheets/d/e/.../pub?output=csv" />
						<p class="description">In Google Sheets: File → Share → Publish to web → CSV format. Paste the resulting URL here.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hessqfe_cache_ttl">Cache TTL (minutes)</label></th>
					<td>
						<input type="number" id="hessqfe_cache_ttl" name="hessqfe_cache_ttl" min="1" max="1440" value="<?php echo esc_attr( $cache_ttl ); ?>" class="small-text" />
						<p class="description">How long to cache the sheet data before re-fetching. 5–15 min is typical. Use the "Flush Cache" link above to force an immediate refresh.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hessqfe_year_mode">Year display</label></th>
					<td>
						<?php
						// Build year list from loaded sheet (descending). "Latest" is always
						// the dynamic newest year, so even after a new year is added the
						// setting keeps working without editing it.
						$available_years = [];
						if ( $systems ) {
							$available_years = array_values( array_unique( array_filter( array_column( $systems, 'year' ) ) ) );
							rsort( $available_years, SORT_NUMERIC );
						}
						?>
						<select id="hessqfe_year_mode" name="hessqfe_year_mode">
							<option value="all"    <?php selected( $year_mode, 'all' ); ?>>Show all years</option>
							<option value="latest" <?php selected( $year_mode, 'latest' ); ?>>Show only latest year (auto-updates)</option>
							<?php if ( $available_years ) : ?>
								<optgroup label="Specific year">
									<?php foreach ( $available_years as $y ) : ?>
										<option value="<?php echo esc_attr( $y ); ?>" <?php selected( (string) $year_mode, (string) $y ); ?>>Only <?php echo esc_html( $y ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						</select>
						<p class="description">
							<strong>Latest</strong> always follows the newest year present in the sheet.
							<strong>Specific year</strong> locks the form to that year only (new years will appear here automatically once they exist in your data).
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Product Table — Columns</h2>
			<p class="description">Show/hide columns and customize the label shown in the table header. Leave a label blank to reset to the default.</p>
			<table class="form-table" role="presentation">
				<thead>
					<tr>
						<th style="width:180px;">Column (default)</th>
						<th>Custom Header Label</th>
						<th style="width:110px;">Show</th>
					</tr>
				</thead>
				<?php foreach ( hessqfe_default_table_columns() as $key => $def ) :
					$current = $table_cols[ $key ] ?? $def;
					$label   = isset( $current['label'] ) && $current['label'] !== '' ? $current['label'] : $def['label'];
				?>
					<tr>
						<th scope="row"><?php echo esc_html( $def['label'] ); ?></th>
						<td>
							<input type="text" class="regular-text"
								name="hessqfe_table_columns[<?php echo esc_attr( $key ); ?>][label]"
								value="<?php echo esc_attr( $label ); ?>"
								placeholder="<?php echo esc_attr( $def['label'] ); ?>" />
						</td>
						<td>
							<label>
								<input type="checkbox" name="hessqfe_table_columns[<?php echo esc_attr( $key ); ?>][visible]" value="1" <?php checked( ! empty( $current['visible'] ) ); ?> />
								Visible
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2 class="title">Tier Comparison Card — Fields</h2>
			<p class="description">Show/hide fields and customize the label shown on the 4 tier cards after a product is selected.</p>
			<table class="form-table" role="presentation">
				<thead>
					<tr>
						<th style="width:180px;">Field (default)</th>
						<th>Custom Label</th>
						<th style="width:110px;">Show</th>
					</tr>
				</thead>
				<?php foreach ( hessqfe_default_card_fields() as $key => $def ) :
					$current = $card_flds[ $key ] ?? $def;
					$label   = isset( $current['label'] ) && $current['label'] !== '' ? $current['label'] : $def['label'];
				?>
					<tr>
						<th scope="row"><?php echo esc_html( $def['label'] ); ?></th>
						<td>
							<input type="text" class="regular-text"
								name="hessqfe_card_fields[<?php echo esc_attr( $key ); ?>][label]"
								value="<?php echo esc_attr( $label ); ?>"
								placeholder="<?php echo esc_attr( $def['label'] ); ?>" />
						</td>
						<td>
							<label>
								<input type="checkbox" name="hessqfe_card_fields[<?php echo esc_attr( $key ); ?>][visible]" value="1" <?php checked( ! empty( $current['visible'] ) ); ?> />
								Visible
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2 class="title">Form Defaults</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hessqfe_tax_default">Default Tax Rate (%)</label></th>
					<td>
						<input type="number" step="0.01" id="hessqfe_tax_default" name="hessqfe_tax_default" value="<?php echo esc_attr( $tax_def ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hessqfe_notify_email">Notification Email (To)</label></th>
					<td>
						<input type="email" id="hessqfe_notify_email" name="hessqfe_notify_email" value="<?php echo esc_attr( $notify ); ?>" class="regular-text" />
						<p class="description">Primary recipient for new quote notifications. Defaults to the site admin email.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hessqfe_notify_cc">CC Recipients</label></th>
					<td>
						<input type="text" id="hessqfe_notify_cc" name="hessqfe_notify_cc" value="<?php echo esc_attr( $notify_cc ); ?>" class="regular-text" placeholder="manager@example.com, sales@example.com" />
						<p class="description">Optional. Comma-separated list of emails to CC on each quote notification. Visible to all recipients.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hessqfe_notify_bcc">BCC Recipients</label></th>
					<td>
						<input type="text" id="hessqfe_notify_bcc" name="hessqfe_notify_bcc" value="<?php echo esc_attr( $notify_bcc ); ?>" class="regular-text" placeholder="archive@example.com" />
						<p class="description">Optional. Comma-separated list of emails to BCC on each quote notification. Hidden from other recipients.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Mailgun (Email Delivery)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hessqfe_mailgun_api_key">Mailgun API Key</label></th>
					<td>
						<input type="password" id="hessqfe_mailgun_api_key" name="hessqfe_mailgun_api_key"
							value="<?php echo esc_attr( $mg_key ); ?>" class="regular-text" autocomplete="off" />
						<p class="description">Use a <strong>Domain Sending Key</strong> (not your private API key) — Mailgun Dashboard → Your Domain → Domain Settings → Sending API Keys.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hessqfe_mailgun_domain">Mailgun Sending Domain</label></th>
					<td>
						<input type="text" id="hessqfe_mailgun_domain" name="hessqfe_mailgun_domain"
							value="<?php echo esc_attr( $mg_domain ); ?>" class="regular-text" placeholder="mg.yoursite.com" />
						<p class="description">The verified sending domain from Mailgun. Leave empty to fall back to <code>wp_mail()</code>.</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2>Usage</h2>
		<p>Add the shortcode <code>[hess_equip_form]</code> to any page to display the form.</p>
	</div>
	<?php
}

/* ─────────────────────────────────────────────
   AJAX SUBMISSION
────────────────────────────────────────────── */
function hessqfe_handle_submission() {
	check_ajax_referer( 'hessqfe_submit', 'nonce' );

	$quote_num = sanitize_text_field( $_POST['quoteNumber'] ?? '' );
	$associate             = sanitize_text_field( $_POST['associate']          ?? '' );
	$existing_brand        = sanitize_text_field( $_POST['existingBrand']       ?? '' );
	$existing_model        = sanitize_text_field( $_POST['existingModel']       ?? '' );
	$existing_serial       = sanitize_text_field( $_POST['existingSerial']      ?? '' );
	$existing_attic_closet = sanitize_text_field( $_POST['existingAtticCloset'] ?? '' );
	$name      = sanitize_text_field( $_POST['name']        ?? '' );
	$phone     = sanitize_text_field( $_POST['phone']       ?? '' );
	$email     = sanitize_email( $_POST['email']            ?? '' );
	$address   = sanitize_text_field( $_POST['address']     ?? '' );
	$schedule  = sanitize_text_field( $_POST['schedule']    ?? '' );
	$comments  = sanitize_textarea_field( $_POST['comments']?? '' );

	// Signature: only accept a "data:image/png;base64,..." string under a sane size cap
	$signature_raw = isset( $_POST['signature'] ) ? (string) $_POST['signature'] : '';
	$signature     = '';
	if ( $signature_raw !== ''
	     && strlen( $signature_raw ) < 500000
	     && preg_match( '#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $signature_raw ) ) {
		$signature = $signature_raw;
	}

	$unit = [
		'ahri'          => sanitize_text_field( $_POST['ahri']         ?? '' ),
		'model_id'      => sanitize_text_field( $_POST['modelId']      ?? '' ),
		'brand'         => sanitize_text_field( $_POST['brand']        ?? '' ),
		'system'        => sanitize_text_field( $_POST['system']       ?? '' ),
		'capacity'      => sanitize_text_field( $_POST['capacity']     ?? '' ),
		'tier'          => sanitize_text_field( $_POST['tier']         ?? '' ),
		'seer2'         => sanitize_text_field( $_POST['seer2']        ?? '' ),
		'price'         => sanitize_text_field( $_POST['price']        ?? '' ),
		'price_taxed'   => sanitize_text_field( $_POST['priceTaxed']   ?? '' ),
		'monthly'       => sanitize_text_field( $_POST['monthly']      ?? '' ),
		'daily'         => sanitize_text_field( $_POST['daily']        ?? '' ),
		'outdoor'       => sanitize_text_field( $_POST['outdoorModel'] ?? '' ),
		'indoor'        => sanitize_text_field( $_POST['indoorModel']  ?? '' ),
		'stage'         => sanitize_text_field( $_POST['stage']        ?? '' ),
	];

	$pricing = [
		'valuePackage'           => sanitize_text_field( $_POST['valuePackage']           ?? '' ),
		'options'                => sanitize_text_field( $_POST['options']                ?? '' ),
		'optionsBreakdown'       => sanitize_text_field( $_POST['optionsBreakdown']       ?? '' ),
		'installation'           => sanitize_text_field( $_POST['installation']           ?? '' ),
		'installationBreakdown'  => sanitize_text_field( $_POST['installationBreakdown']  ?? '' ),
		'downPayment'            => sanitize_text_field( $_POST['downPayment']            ?? '' ),
		'downNotes'              => sanitize_text_field( $_POST['downNotes']              ?? '' ),
		'tradeIn'                => sanitize_text_field( $_POST['tradeIn']                ?? '' ),
		'tradeInNotes'           => sanitize_text_field( $_POST['tradeInNotes']           ?? '' ),
		'totalInvestment'        => sanitize_text_field( $_POST['totalInvestment']        ?? '' ),
		'amountFinanced'         => sanitize_text_field( $_POST['amountFinanced']         ?? '' ),
		'financing0pct'          => sanitize_text_field( $_POST['financing0pct']          ?? '' ),
	];

	if ( ! $name || ! $email || ! $phone || ! $quote_num ) {
		wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
	}

	// Persist the quote as a hessqfe_quote CPT so it's reviewable in the admin
	$quote_post_id = hessqfe_store_quote( [
		'quoteNumber'         => $quote_num,
		'associate'           => $associate,
		'existingBrand'       => $existing_brand,
		'existingModel'       => $existing_model,
		'existingSerial'      => $existing_serial,
		'existingAtticCloset' => $existing_attic_closet,
		'name'                => $name,
		'phone'               => $phone,
		'email'               => $email,
		'address'             => $address,
		'schedule'            => $schedule,
		'comments'            => $comments,
		'signature'           => $signature,
		'unit'                => $unit,
		'pricing'             => $pricing,
	] );

	$notify_to  = get_option( 'hessqfe_notify_email', get_option( 'admin_email' ) );
	$notify_cc  = hessqfe_parse_email_list( get_option( 'hessqfe_notify_cc',  '' ) );
	$notify_bcc = hessqfe_parse_email_list( get_option( 'hessqfe_notify_bcc', '' ) );

	$existing   = [ 'brand' => $existing_brand, 'model' => $existing_model, 'serial' => $existing_serial, 'atticCloset' => $existing_attic_closet ];
	$admin_html = hessqfe_build_admin_email_html( $quote_num, $associate, $name, $phone, $email, $address, $schedule, $comments, $unit, $pricing, $quote_post_id, $signature, $existing );
	$cust_html  = hessqfe_build_customer_email_html( $quote_num, $name, $unit, $pricing );

	// When the customer has both picked a schedule AND signed the quote, treat
	// it as an accepted sale and adjust the subject lines accordingly.
	$is_accepted_sale = ( $schedule !== '' ) && ( $signature !== '' );
	$admin_subject = $is_accepted_sale
		? "Accepted Sale Request — {$quote_num}"
		: "New Quote Request — {$quote_num}";
	$cust_subject  = $is_accepted_sale
		? "Accepted Sale Request — {$quote_num}"
		: "Quote Confirmed — {$quote_num}";

	$errors = [];
	$admin_result = hessqfe_send_email(
		$notify_to,
		$admin_subject,
		$admin_html,
		$email,
		[ 'cc' => $notify_cc, 'bcc' => $notify_bcc ]
	);
	if ( ! $admin_result['sent'] ) { $errors[] = 'admin: ' . $admin_result['error']; }

	$customer_sent = false;
	$cust_result = hessqfe_send_email( $email, $cust_subject, $cust_html, $notify_to );
	if ( $cust_result['sent'] ) {
		$customer_sent = true;
	} else {
		$errors[] = 'customer: ' . $cust_result['error'];
	}

	wp_send_json_success( [
		'quoteNumber'    => $quote_num,
		'customerEmail'  => $customer_sent,
		'mailErrors'     => $errors,
	] );
}

/* ─────────────────────────────────────────────
   EMAIL — Mailgun HTTP API with wp_mail() fallback
────────────────────────────────────────────── */
/**
 * Parse a comma/semicolon-separated list of emails into a clean array of
 * valid addresses. Invalid entries are silently dropped.
 */
function hessqfe_parse_email_list( $raw ) {
	if ( ! $raw ) { return []; }
	$parts = preg_split( '/[,;\s]+/', (string) $raw );
	$out   = [];
	foreach ( $parts as $p ) {
		$p = trim( $p );
		if ( $p === '' ) { continue; }
		if ( is_email( $p ) ) { $out[] = $p; }
	}
	return array_values( array_unique( $out ) );
}

/**
 * Send an email via Mailgun HTTP API (preferred) or wp_mail() fallback.
 *
 * @param string $to        Primary recipient.
 * @param string $subject
 * @param string $html_body
 * @param string $reply_to  Optional Reply-To address.
 * @param array  $options   [ 'cc' => array, 'bcc' => array ]
 */
function hessqfe_send_email( $to, $subject, $html_body, $reply_to = '', $options = [] ) {
	$api_key   = get_option( 'hessqfe_mailgun_api_key', '' );
	$mg_domain = get_option( 'hessqfe_mailgun_domain', '' );
	$blog_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

	$cc  = isset( $options['cc'] )  && is_array( $options['cc'] )  ? $options['cc']  : [];
	$bcc = isset( $options['bcc'] ) && is_array( $options['bcc'] ) ? $options['bcc'] : [];

	if ( $api_key && $mg_domain ) {
		$from     = $blog_name . ' <noreply@' . $mg_domain . '>';
		$endpoint = 'https://api.mailgun.net/v3/' . rawurlencode( $mg_domain ) . '/messages';
		$body = [
			'from'    => $from,
			'to'      => $to,
			'subject' => $subject,
			'html'    => $html_body,
		];
		if ( $cc )  { $body['cc']  = implode( ', ', $cc ); }
		if ( $bcc ) { $body['bcc'] = implode( ', ', $bcc ); }
		if ( $reply_to ) { $body['h:Reply-To'] = $reply_to; }

		$response = wp_remote_post( $endpoint, [
			'headers' => [ 'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ) ],
			'body'    => $body,
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'sent' => false, 'error' => $response->get_error_message() ];
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return [ 'sent' => false, 'error' => "Mailgun HTTP $code: " . wp_remote_retrieve_body( $response ) ];
		}
		return [ 'sent' => true, 'error' => '' ];
	}

	// Fallback: wp_mail()
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$from      = 'noreply@' . preg_replace( '/^www\./i', '', $site_host );
	$headers   = [
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $blog_name . ' <' . $from . '>',
	];
	if ( $reply_to ) { $headers[] = 'Reply-To: ' . $reply_to; }
	foreach ( $cc  as $addr ) { $headers[] = 'Cc: '  . $addr; }
	foreach ( $bcc as $addr ) { $headers[] = 'Bcc: ' . $addr; }

	$sent = wp_mail( $to, $subject, $html_body, $headers );
	return [ 'sent' => (bool) $sent, 'error' => $sent ? '' : 'wp_mail() returned false' ];
}

/* ── Branded email template helpers ── */

function hessqfe_email_header() {
	return '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;margin:0;padding:20px 0;background:#f4f5f7;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
  <tr><td style="background:#1a3a5c;padding:24px 28px;border-radius:6px 6px 0 0;">
    <div style="font-size:1.5rem;font-weight:700;letter-spacing:1px;">
      <span style="color:#f79cbe;">HESS</span><span style="color:#fff;">AIR</span>
      <span style="font-size:0.6rem;color:rgba(255,255,255,0.55);vertical-align:super;font-weight:400;">&#174;</span>
    </div>
    <div style="color:rgba(255,255,255,0.6);font-size:0.78rem;margin-top:4px;">Real Techs Wear Pink&#8482;</div>
  </td></tr>
  <tr><td style="background:#fff;padding:28px 28px 8px;">';
}

function hessqfe_email_footer() {
	return '  </td></tr>
  <tr><td style="background:#1a3a5c;padding:16px 28px;border-radius:0 0 6px 6px;text-align:center;">
    <p style="color:rgba(255,255,255,0.5);font-size:0.75rem;margin:0;">
      &#169; Hess Air Conditioning &nbsp;|&nbsp; 817 S. Alamo Road, Alamo, TX 78516 &nbsp;|&nbsp; 956-702-HESS (4377)
    </p>
  </td></tr>
</table></td></tr></table>
</body></html>';
}

function hessqfe_email_quote_badge( $qn ) {
	return '<div style="background:#f4f5f7;border-left:4px solid #f79cbe;border-radius:4px;padding:12px 16px;margin:16px 0;">
  <div style="font-size:0.7rem;color:#888;text-transform:uppercase;letter-spacing:0.6px;font-weight:600;">Quote Number</div>
  <div style="font-size:1.2rem;font-weight:700;color:#c0457a;letter-spacing:1.5px;margin-top:3px;">' . esc_html( $qn ) . '</div>
</div>';
}

function hessqfe_email_section_h( $title ) {
	return '<h3 style="color:#1a3a5c;font-size:0.95rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin:22px 0 10px;padding-bottom:6px;border-bottom:2px solid #f79cbe;">'
		. esc_html( $title ) . '</h3>';
}

function hessqfe_email_data_table( array $rows ) {
	$html = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #dde1e7;font-size:0.875rem;">';
	$i = 0;
	foreach ( $rows as $row ) {
		// Skip empty values so the layout stays clean
		if ( ! isset( $row[1] ) || $row[1] === '' || $row[1] === null ) { continue; }
		$bg = ( $i % 2 === 0 ) ? '#fff' : '#f0f4fa';
		$html .= '<tr style="background:' . $bg . ';">
			<td style="padding:8px 14px;color:#666;width:45%;">' . esc_html( $row[0] ) . '</td>
			<td style="padding:8px 14px;font-weight:600;">'       . esc_html( $row[1] ) . '</td>
		</tr>';
		$i++;
	}
	$html .= '</table>';
	return $html;
}

/**
 * Build the [label, value] rows for the "Pricing & Investment" email
 * section, mirroring the Step 2 summary page layout (breakdowns/notes
 * appended in parentheses).
 */
function hessqfe_pricing_rows( $pricing ) {
	$with_notes = function( $value, $notes ) {
		if ( $value === '' ) { return ''; }
		return $notes !== '' ? "{$value} ({$notes})" : $value;
	};

	return [
		[ 'HESSeRized Value Package',         $pricing['valuePackage'] ],
		[ 'Options',                          $with_notes( $pricing['options'],      $pricing['optionsBreakdown'] ) ],
		[ 'Procurement/Labor/Materials/Other', $with_notes( $pricing['installation'], $pricing['installationBreakdown'] ) ],
		[ 'Down Payment/Cash/Credit Card',    $with_notes( $pricing['downPayment'],  $pricing['downNotes'] ) ],
		[ 'Trade In',                         $with_notes( $pricing['tradeIn'],      $pricing['tradeInNotes'] ) ],
		[ 'Total Investment',                 $pricing['totalInvestment'] ],
		[ 'Amount Financed',                  $pricing['amountFinanced'] ],
		[ '0% Interest Financing',            $pricing['financing0pct'] ],
	];
}

/* ── Email body builders ── */

function hessqfe_build_admin_email_html( $quote_num, $associate, $name, $phone, $email, $address, $schedule, $comments, $unit, $pricing, $quote_post_id = 0, $signature = '', $existing = [] ) {
	$contact_rows = [
		[ 'Hess Associate', $associate ],
		[ 'Name',    $name ],
		[ 'Phone',   $phone ],
		[ 'Email',   $email ],
		[ 'Address', $address ],
		[ 'Timing',  $schedule ],
	];
	if ( $comments ) { $contact_rows[] = [ 'Notes', $comments ]; }
	if ( ! empty( $existing['brand'] ) || ! empty( $existing['model'] ) || ! empty( $existing['serial'] ) ) {
		$contact_rows[] = [ 'Existing Unit Brand',  $existing['brand']  ?? '' ];
		$contact_rows[] = [ 'Existing Model #',     $existing['model']  ?? '' ];
		$contact_rows[] = [ 'Existing Serial #',    $existing['serial'] ?? '' ];
		$contact_rows[] = [ 'Attic / Closet Unit',  $existing['atticCloset'] ?? '' ];
	}

	$capacity_display = $unit['capacity'] !== '' ? ( preg_match( '/ton/i', (string) $unit['capacity'] ) ? $unit['capacity'] : $unit['capacity'] . ' Ton' ) : '';

	$product_rows = [
		[ 'Unit ID',       $unit['model_id'] ],
		[ 'AHRI #',        $unit['ahri'] ],
		[ 'Brand',         $unit['brand'] ],
		[ 'System Type',   $unit['system'] ],
		[ 'Capacity',      $capacity_display ],
		[ 'Cap. Stg.',     $unit['stage'] ],
		[ 'SEER2 Rating',  $unit['seer2'] ],
		[ 'System Price',  $unit['price'] ],
	];
	if ( ! empty( $unit['price_taxed'] ) ) {
		$product_rows[] = [ 'Tax-Adjusted Price', $unit['price_taxed'] ];
	}
	$product_rows[] = [ 'Monthly Payment',  $unit['monthly'] ];
	$product_rows[] = [ 'Daily Investment', $unit['daily'] ];
	$product_rows[] = [ 'Outdoor Unit',     $unit['outdoor'] ];
	$product_rows[] = [ 'Indoor Unit',      $unit['indoor'] ];

	$html  = hessqfe_email_header();
	$html .= '<p style="margin:0 0 4px;"><strong>New quote request received.</strong></p>';
	$html .= '<p style="color:#666;margin:0 0 8px;font-size:0.88rem;">A customer has submitted a quote via the employee quote form.</p>';
	$html .= hessqfe_email_quote_badge( $quote_num );

	if ( $quote_post_id ) {
		$edit_url = admin_url( 'post.php?post=' . (int) $quote_post_id . '&action=edit' );
		$html .= '<p style="margin:0 0 16px;"><a href="' . esc_url( $edit_url ) . '" style="color:#c0457a;font-weight:700;text-decoration:none;">&rarr; Review in WordPress admin</a></p>';
	}

	$html .= hessqfe_email_section_h( 'Customer Contact' );
	$html .= hessqfe_email_data_table( $contact_rows );
	$html .= hessqfe_email_section_h( 'Quoted System' );
	$html .= hessqfe_email_data_table( $product_rows );
	$html .= hessqfe_email_section_h( 'Pricing & Investment' );
	$html .= hessqfe_email_data_table( hessqfe_pricing_rows( $pricing ) );

	if ( $signature ) {
		$html .= hessqfe_email_section_h( 'Customer Signature' );
		$html .= '<div style="border:1px solid #d8dde6;border-radius:4px;padding:8px;background:#fff;display:inline-block;">';
		$html .= '<img src="' . esc_attr( $signature ) . '" alt="Customer signature" style="max-width:100%;height:auto;display:block;" />';
		$html .= '</div>';
		$html .= '<p style="color:#666;font-size:0.78rem;margin:6px 0 0;">If the signature image does not render in this email, view it on the quote in the WordPress admin.</p>';
	}

	$html .= '<p style="margin:20px 0 8px;color:#666;font-size:0.82rem;">Automated notification — Hess Air Quote Form plugin.</p>';
	$html .= hessqfe_email_footer();
	return $html;
}

function hessqfe_build_customer_email_html( $quote_num, $name, $unit, $pricing ) {
	$capacity_display = $unit['capacity'] !== '' ? ( preg_match( '/ton/i', (string) $unit['capacity'] ) ? $unit['capacity'] : $unit['capacity'] . ' Ton' ) : '';

	$product_rows = [
		[ 'Unit ID',       $unit['model_id'] ],
		[ 'Brand',         $unit['brand'] ],
		[ 'System Type',   $unit['system'] ],
		[ 'Capacity',      $capacity_display ],
		[ 'Cap. Stg.',     $unit['stage'] ],
		[ 'SEER2 Rating',  $unit['seer2'] ],
		[ 'System Price',  $unit['price'] ],
	];
	if ( ! empty( $unit['price_taxed'] ) ) {
		$product_rows[] = [ 'Tax-Adjusted Price', $unit['price_taxed'] ];
	}
	$product_rows[] = [ 'Monthly Payment',  $unit['monthly'] ];
	$product_rows[] = [ 'Daily Investment', $unit['daily'] ];
	$product_rows[] = [ 'Outdoor Unit',     $unit['outdoor'] ];
	$product_rows[] = [ 'Indoor Unit',      $unit['indoor'] ];

	$html  = hessqfe_email_header();
	$html .= '<p style="margin:0 0 12px;">Hi <strong>' . esc_html( $name ) . '</strong>,</p>';
	$html .= '<p style="color:#444;margin:0 0 8px;">Thank you for your HVAC quote request. Below is a summary of your quote — please keep this for your records.</p>';
	$html .= hessqfe_email_quote_badge( $quote_num );
	$html .= hessqfe_email_section_h( 'Selected System' );
	$html .= hessqfe_email_data_table( $product_rows );
	$html .= hessqfe_email_section_h( 'Pricing & Investment' );
	$html .= hessqfe_email_data_table( hessqfe_pricing_rows( $pricing ) );
	$html .= '<p style="margin:24px 0 8px;color:#444;">A Hess Air team member will be in touch with you soon. Call us at <strong>956-702-HESS (4377)</strong> or reply to this email with any questions.</p>';
	$html .= '<p style="margin:0 0 20px;">Thank you for choosing Hess Air!</p>';
	$html .= hessqfe_email_footer();
	return $html;
}

/* ═════════════════════════════════════════════════════════════════════════
   QUOTE STORAGE — Custom Post Type for reviewing submitted quotes
   ═════════════════════════════════════════════════════════════════════════ */

/**
 * Lifecycle status options for a stored quote.
 */
function hessqfe_quote_statuses() {
	return [
		'new'       => 'New',
		'contacted' => 'Contacted',
		'scheduled' => 'Scheduled',
		'won'       => 'Won',
		'lost'      => 'Lost',
		'archived'  => 'Archived',
	];
}

/**
 * Register the `hessqfe_quote` custom post type. Not publicly queryable — it's
 * an internal record only visible in the WordPress admin.
 */
function hessqfe_register_quote_cpt() {
	register_post_type( 'hessqfe_quote', [
		'labels' => [
			'name'               => 'Equipment Quotes',
			'singular_name'      => 'Equipment Quote',
			'menu_name'          => 'Equipment Quotes',
			'add_new'            => 'Add Equipment Quote',
			'add_new_item'       => 'Add New Equipment Quote',
			'edit_item'          => 'Edit Equipment Quote',
			'new_item'           => 'New Equipment Quote',
			'view_item'          => 'View Equipment Quote',
			'search_items'       => 'Search Equipment Quotes',
			'not_found'          => 'No equipment quotes found.',
			'not_found_in_trash' => 'No equipment quotes in trash.',
			'all_items'          => 'All Equipment Quotes',
		],
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_admin_bar'   => false,
		'show_in_nav_menus'   => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'hierarchical'        => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'menu_position'       => 26,
		'menu_icon'           => 'dashicons-clipboard',
		'supports'            => [ 'title' ],
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
	] );
}

/**
 * Create a quote post from a submission payload. Returns the new post ID, or 0
 * on failure (errors are logged but never block the email flow).
 */
function hessqfe_store_quote( $payload ) {
	$unit    = is_array( $payload['unit'] ?? null )    ? $payload['unit']    : [];
	$pricing = is_array( $payload['pricing'] ?? null ) ? $payload['pricing'] : [];

	$title = sprintf(
		'%s — %s (%s)',
		$payload['quoteNumber'] ?: 'Quote',
		$payload['name']        ?: 'Unknown',
		$unit['brand'] ?? ''
	);

	$post_id = wp_insert_post( [
		'post_type'   => 'hessqfe_quote',
		'post_status' => 'publish',
		'post_title'  => wp_strip_all_tags( $title ),
	], true );

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		error_log( '[Hess Quote Form] Failed to store quote: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown' ) );
		return 0;
	}

	$meta = [
		'_hessqfe_quote_number'          => $payload['quoteNumber']   ?? '',
		'_hessqfe_associate'             => $payload['associate']    ?? '',
		'_hessqfe_existing_brand'        => $payload['existingBrand']       ?? '',
		'_hessqfe_existing_model'        => $payload['existingModel']       ?? '',
		'_hessqfe_existing_serial'       => $payload['existingSerial']      ?? '',
		'_hessqfe_existing_attic_closet' => $payload['existingAtticCloset'] ?? '',
		'_hessqfe_name'                  => $payload['name']        ?? '',
		'_hessqfe_phone'        => $payload['phone']       ?? '',
		'_hessqfe_email'        => $payload['email']       ?? '',
		'_hessqfe_address'      => $payload['address']     ?? '',
		'_hessqfe_schedule'     => $payload['schedule']    ?? '',
		'_hessqfe_comments'     => $payload['comments']    ?? '',
		'_hessqfe_status'       => 'new',
		'_hessqfe_submitted_at' => current_time( 'mysql' ),
		'_hessqfe_ip'           => hessqfe_get_client_ip(),
		'_hessqfe_signature'    => $payload['signature']   ?? '',
		// Unit fields
		'_hessqfe_unit_ahri'        => $unit['ahri']        ?? '',
		'_hessqfe_unit_model_id'    => $unit['model_id']    ?? '',
		'_hessqfe_unit_brand'       => $unit['brand']       ?? '',
		'_hessqfe_unit_system'      => $unit['system']      ?? '',
		'_hessqfe_unit_capacity'    => $unit['capacity']    ?? '',
		'_hessqfe_unit_tier'        => $unit['tier']        ?? '',
		'_hessqfe_unit_seer2'       => $unit['seer2']       ?? '',
		'_hessqfe_unit_stage'       => $unit['stage']       ?? '',
		'_hessqfe_unit_price'       => $unit['price']       ?? '',
		'_hessqfe_unit_price_taxed' => $unit['price_taxed'] ?? '',
		'_hessqfe_unit_monthly'     => $unit['monthly']     ?? '',
		'_hessqfe_unit_daily'       => $unit['daily']       ?? '',
		'_hessqfe_unit_outdoor'     => $unit['outdoor']     ?? '',
		'_hessqfe_unit_indoor'      => $unit['indoor']      ?? '',
		// Pricing fields
		'_hessqfe_pricing_value_package'          => $pricing['valuePackage']          ?? '',
		'_hessqfe_pricing_options'                => $pricing['options']               ?? '',
		'_hessqfe_pricing_options_breakdown'      => $pricing['optionsBreakdown']      ?? '',
		'_hessqfe_pricing_installation'           => $pricing['installation']          ?? '',
		'_hessqfe_pricing_installation_breakdown' => $pricing['installationBreakdown'] ?? '',
		'_hessqfe_pricing_down_payment'           => $pricing['downPayment']           ?? '',
		'_hessqfe_pricing_down_notes'             => $pricing['downNotes']             ?? '',
		'_hessqfe_pricing_trade_in'                => $pricing['tradeIn']              ?? '',
		'_hessqfe_pricing_trade_in_notes'          => $pricing['tradeInNotes']         ?? '',
		'_hessqfe_pricing_total_investment'       => $pricing['totalInvestment']       ?? '',
		'_hessqfe_pricing_amount_financed'        => $pricing['amountFinanced']        ?? '',
		'_hessqfe_pricing_financing_0pct'         => $pricing['financing0pct']         ?? '',
	];
	foreach ( $meta as $k => $v ) {
		update_post_meta( $post_id, $k, $v );
	}

	return (int) $post_id;
}

function hessqfe_get_client_ip() {
	$keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
	foreach ( $keys as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			// First IP if forwarded list
			$ip = trim( explode( ',', $ip )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) { return $ip; }
		}
	}
	return '';
}

/* ── Admin list table customization ───────────────────────── */

function hessqfe_quote_list_columns( $cols ) {
	// Drop the default Date column so we can re-add it after our own, for a
	// cleaner left-to-right reading order.
	$date = $cols['date'] ?? '';
	unset( $cols['date'], $cols['title'] );

	$new = [];
	$new['cb']        = $cols['cb'] ?? '<input type="checkbox" />';
	$new['qnumber']   = 'Quote #';
	$new['customer']  = 'Customer';
	$new['contact']   = 'Contact';
	$new['unit']      = 'Unit';
	$new['price']     = 'Price';
	$new['schedule']  = 'Timing';
	$new['status']    = 'Status';
	$new['submitted'] = 'Submitted';
	return $new;
}

function hessqfe_quote_list_column_content( $column, $post_id ) {
	switch ( $column ) {
		case 'qnumber':
			$q = get_post_meta( $post_id, '_hessqfe_quote_number', true );
			$edit = get_edit_post_link( $post_id );
			echo '<strong><a href="' . esc_url( $edit ) . '">' . esc_html( $q ?: '—' ) . '</a></strong>';
			break;
		case 'customer':
			$name = get_post_meta( $post_id, '_hessqfe_name', true );
			echo esc_html( $name ?: '—' );
			break;
		case 'contact':
			$email = get_post_meta( $post_id, '_hessqfe_email', true );
			$phone = get_post_meta( $post_id, '_hessqfe_phone', true );
			if ( $email ) echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a><br/>';
			if ( $phone ) echo '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>';
			break;
		case 'unit':
			$brand  = get_post_meta( $post_id, '_hessqfe_unit_brand',    true );
			$sys    = get_post_meta( $post_id, '_hessqfe_unit_system',   true );
			$cap    = get_post_meta( $post_id, '_hessqfe_unit_capacity', true );
			$tier   = get_post_meta( $post_id, '_hessqfe_unit_tier',     true );
			$model  = get_post_meta( $post_id, '_hessqfe_unit_model_id', true );
			$line1 = trim( $brand . ( $sys ? ' — ' . $sys : '' ) );
			$line2 = trim( ( $cap ? $cap . ' ' : '' ) . ( $tier ? '· ' . $tier : '' ) );
			echo esc_html( $line1 ?: '—' );
			if ( $line2 )  echo '<br/><span style="color:#666;font-size:0.85em;">' . esc_html( $line2 ) . '</span>';
			if ( $model )  echo '<br/><code style="font-size:0.8em;">' . esc_html( $model ) . '</code>';
			break;
		case 'price':
			$price = get_post_meta( $post_id, '_hessqfe_unit_price',       true );
			$taxed = get_post_meta( $post_id, '_hessqfe_unit_price_taxed', true );
			echo esc_html( $price ?: '—' );
			if ( $taxed ) echo '<br/><span style="color:#666;font-size:0.85em;">tax: ' . esc_html( $taxed ) . '</span>';
			break;
		case 'schedule':
			echo esc_html( get_post_meta( $post_id, '_hessqfe_schedule', true ) ?: '—' );
			break;
		case 'status':
			$status = get_post_meta( $post_id, '_hessqfe_status', true ) ?: 'new';
			$labels = hessqfe_quote_statuses();
			$label  = $labels[ $status ] ?? ucfirst( $status );
			$colors = [
				'new'       => '#2271b1',
				'contacted' => '#dba617',
				'scheduled' => '#8a6200',
				'won'       => '#2a7a3b',
				'lost'      => '#b32d2e',
				'archived'  => '#646970',
			];
			$c = $colors[ $status ] ?? '#646970';
			echo '<span style="display:inline-block;padding:2px 10px;border-radius:12px;background:' . esc_attr( $c ) . ';color:#fff;font-size:0.75rem;font-weight:600;">' . esc_html( $label ) . '</span>';
			break;
		case 'submitted':
			$submitted = get_post_meta( $post_id, '_hessqfe_submitted_at', true );
			if ( ! $submitted ) { $submitted = get_the_date( 'Y-m-d H:i:s', $post_id ); }
			echo esc_html( mysql2date( 'M j, Y g:i a', $submitted ) );
			break;
	}
}

function hessqfe_quote_sortable_columns( $cols ) {
	$cols['qnumber']   = 'title';
	$cols['submitted'] = 'date';
	return $cols;
}

function hessqfe_quote_row_actions( $actions, $post ) {
	if ( $post->post_type !== 'hessqfe_quote' ) return $actions;
	// Remove default "View" since the CPT isn't public
	unset( $actions['view'], $actions['inline hide-if-no-js'] );
	return $actions;
}

/* ── Status filter above the list table ─────────────────── */

function hessqfe_quote_status_filter() {
	global $typenow;
	if ( $typenow !== 'hessqfe_quote' ) return;
	$current = isset( $_GET['hessqfe_status'] ) ? sanitize_key( $_GET['hessqfe_status'] ) : '';
	echo '<select name="hessqfe_status"><option value="">All statuses</option>';
	foreach ( hessqfe_quote_statuses() as $k => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $current, $k, false ), esc_html( $label ) );
	}
	echo '</select>';
}

function hessqfe_quote_filter_query( $query ) {
	global $pagenow, $typenow;
	if ( is_admin() && $pagenow === 'edit.php' && $typenow === 'hessqfe_quote' && ! empty( $_GET['hessqfe_status'] ) ) {
		$query->query_vars['meta_key']   = '_hessqfe_status';
		$query->query_vars['meta_value'] = sanitize_key( $_GET['hessqfe_status'] );
	}
	return $query;
}

/* ── Metabox: full quote detail on the edit screen ─────── */

function hessqfe_quote_add_meta_boxes() {
	add_meta_box(
		'hessqfe_quote_detail',
		'Quote Detail',
		'hessqfe_quote_render_detail_box',
		'hessqfe_quote',
		'normal',
		'high'
	);
	add_meta_box(
		'hessqfe_quote_status',
		'Status & Notes',
		'hessqfe_quote_render_status_box',
		'hessqfe_quote',
		'side',
		'high'
	);
}

function hessqfe_quote_render_detail_box( $post ) {
	wp_nonce_field( 'hessqfe_save_quote', 'hessqfe_quote_nonce' );
	$m = fn( $k ) => get_post_meta( $post->ID, $k, true );

	$rows = [
		[ 'Quote #',    $m( '_hessqfe_quote_number' ) ],
		[ 'Submitted',  $m( '_hessqfe_submitted_at' ) ? mysql2date( 'M j, Y g:i a', $m( '_hessqfe_submitted_at' ) ) : '' ],
		[ 'IP Address', $m( '_hessqfe_ip' ) ],
	];
	$customer = [
		[ 'Hess Associate', $m( '_hessqfe_associate' ) ],
		[ 'Name',     $m( '_hessqfe_name' ) ],
		[ 'Email',    $m( '_hessqfe_email' ) ],
		[ 'Phone',    $m( '_hessqfe_phone' ) ],
		[ 'Address',  $m( '_hessqfe_address' ) ],
		[ 'Timing',   $m( '_hessqfe_schedule' ) ],
		[ 'Comments', $m( '_hessqfe_comments' ) ],
		[ 'Existing Unit Brand',  $m( '_hessqfe_existing_brand' ) ],
		[ 'Existing Model #',     $m( '_hessqfe_existing_model' ) ],
		[ 'Existing Serial #',    $m( '_hessqfe_existing_serial' ) ],
		[ 'Attic / Closet Unit',  $m( '_hessqfe_existing_attic_closet' ) ],
	];
	$unit = [
		[ 'Model ID',     $m( '_hessqfe_unit_model_id' ) ],
		[ 'AHRI #',       $m( '_hessqfe_unit_ahri' ) ],
		[ 'Brand',        $m( '_hessqfe_unit_brand' ) ],
		[ 'System Type',  $m( '_hessqfe_unit_system' ) ],
		[ 'Capacity',     $m( '_hessqfe_unit_capacity' ) ],
		[ 'Tier',         $m( '_hessqfe_unit_tier' ) ],
		[ 'Stage',        $m( '_hessqfe_unit_stage' ) ],
		[ 'SEER2',        $m( '_hessqfe_unit_seer2' ) ],
		[ 'Outdoor Unit', $m( '_hessqfe_unit_outdoor' ) ],
		[ 'Indoor Unit',  $m( '_hessqfe_unit_indoor' ) ],
		[ 'System Price', $m( '_hessqfe_unit_price' ) ],
		[ 'Tax-adjusted', $m( '_hessqfe_unit_price_taxed' ) ],
		[ 'Monthly',      $m( '_hessqfe_unit_monthly' ) ],
		[ 'Daily',        $m( '_hessqfe_unit_daily' ) ],
	];

	$with_notes = function( $value, $notes ) {
		if ( $value === '' ) { return ''; }
		return $notes !== '' ? "{$value} ({$notes})" : $value;
	};
	$pricing = [
		[ 'HESSeRized Value Package',          $m( '_hessqfe_pricing_value_package' ) ],
		[ 'Options',                           $with_notes( $m( '_hessqfe_pricing_options' ),      $m( '_hessqfe_pricing_options_breakdown' ) ) ],
		[ 'Procurement/Labor/Materials/Other', $with_notes( $m( '_hessqfe_pricing_installation' ), $m( '_hessqfe_pricing_installation_breakdown' ) ) ],
		[ 'Down Payment/Cash/Credit Card',     $with_notes( $m( '_hessqfe_pricing_down_payment' ), $m( '_hessqfe_pricing_down_notes' ) ) ],
		[ 'Trade In',                          $with_notes( $m( '_hessqfe_pricing_trade_in' ),     $m( '_hessqfe_pricing_trade_in_notes' ) ) ],
		[ 'Total Investment',                  $m( '_hessqfe_pricing_total_investment' ) ],
		[ 'Amount Financed',                   $m( '_hessqfe_pricing_amount_financed' ) ],
		[ '0% Interest Financing',             $m( '_hessqfe_pricing_financing_0pct' ) ],
	];

	$render = function( $heading, $items ) {
		echo '<h3 style="margin:16px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;color:#1a3a5c;">' . esc_html( $heading ) . '</h3>';
		echo '<table class="widefat striped" style="border:1px solid #ccd0d4;"><tbody>';
		foreach ( $items as $r ) {
			$val = $r[1];
			if ( $val === '' || $val === null ) continue;
			echo '<tr><th style="width:170px;text-align:left;padding:8px 12px;">' . esc_html( $r[0] ) . '</th><td style="padding:8px 12px;">' . nl2br( esc_html( $val ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	};

	$render( 'Reference', $rows );
	$render( 'Customer',  $customer );
	$render( 'Selected System', $unit );
	$render( 'Pricing & Investment', $pricing );

	$signature = $m( '_hessqfe_signature' );
	if ( $signature && strpos( $signature, 'data:image/' ) === 0 ) {
		echo '<h3 style="margin:16px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;color:#1a3a5c;">Customer Signature</h3>';
		echo '<div style="border:1px solid #ccd0d4;background:#fff;padding:8px;display:inline-block;">';
		echo '<img src="' . esc_attr( $signature ) . '" alt="Customer signature" style="max-width:100%;height:auto;display:block;" />';
		echo '</div>';
	}
}

function hessqfe_quote_render_status_box( $post ) {
	$status  = get_post_meta( $post->ID, '_hessqfe_status', true ) ?: 'new';
	$notes   = get_post_meta( $post->ID, '_hessqfe_notes',  true );
	$labels  = hessqfe_quote_statuses();
	?>
	<p>
		<label for="hessqfe_status"><strong>Status</strong></label><br/>
		<select name="hessqfe_status" id="hessqfe_status" style="width:100%;">
			<?php foreach ( $labels as $k => $l ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="hessqfe_notes"><strong>Internal Notes</strong></label><br/>
		<textarea name="hessqfe_notes" id="hessqfe_notes" rows="6" style="width:100%;" placeholder="Follow-up notes, call log, next steps…"><?php echo esc_textarea( $notes ); ?></textarea>
	</p>
	<p class="description" style="margin:0;">Notes and status are internal only — never sent to the customer.</p>
	<?php
}

function hessqfe_quote_save_meta( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( $post->post_type !== 'hessqfe_quote' ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;
	if ( ! isset( $_POST['hessqfe_quote_nonce'] ) || ! wp_verify_nonce( $_POST['hessqfe_quote_nonce'], 'hessqfe_save_quote' ) ) return;

	if ( isset( $_POST['hessqfe_status'] ) ) {
		$status = sanitize_key( $_POST['hessqfe_status'] );
		if ( array_key_exists( $status, hessqfe_quote_statuses() ) ) {
			update_post_meta( $post_id, '_hessqfe_status', $status );
		}
	}
	if ( isset( $_POST['hessqfe_notes'] ) ) {
		update_post_meta( $post_id, '_hessqfe_notes', sanitize_textarea_field( wp_unslash( $_POST['hessqfe_notes'] ) ) );
	}
}
